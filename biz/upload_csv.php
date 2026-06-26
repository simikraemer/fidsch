<?php
// biz/upload_csv.php
// Zentrale CSV-Importlogik für Insert.php und AJAX-Uploads aus Start.php.

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$bizconn->set_charset('utf8mb4');

/* =========================================================
 * Konto-Schutz
 * ========================================================= */
const CSV_EXPECTED_AUFTRAGSKONTO = 'DE67305500001001238367';

const CSV_KNOWN_WRONG_ACCOUNTS = [
    'DE37390500001070334584' => 'WEH Hauskonto',
    'DE90390500001070334600' => 'WEH Netzkonto',
];

function csvImportNormalizeIbanLike(?string $value): string
{
    return strtoupper(preg_replace('/\s+/', '', trim((string)$value)) ?? '');
}

function csvImportFormatIbanPretty(string $iban): string
{
    $iban = csvImportNormalizeIbanLike($iban);
    return trim(chunk_split($iban, 4, ' '));
}

function csvImportDescribeAccount(string $auftragskonto): string
{
    $normalized = csvImportNormalizeIbanLike($auftragskonto);

    if (isset(CSV_KNOWN_WRONG_ACCOUNTS[$normalized])) {
        return CSV_KNOWN_WRONG_ACCOUNTS[$normalized] . ' (' . csvImportFormatIbanPretty($normalized) . ')';
    }

    if ($normalized !== '') {
        return 'Unbekanntes Konto (' . csvImportFormatIbanPretty($normalized) . ')';
    }

    return 'Kein Auftragskonto gefunden';
}

function csvImportNormalizeEncoding(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;

    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    return $value;
}

function csvImportCleanCell(?string $value): string
{
    return trim(csvImportNormalizeEncoding((string)$value));
}

function csvImportNormalizeHeader(string $value): string
{
    $value = csvImportCleanCell($value);
    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

function csvImportParseGermanDate(?string $value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    foreach (['d.m.y', 'd.m.Y'] as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();

        if (
            $dt !== false &&
            (
                $errors === false ||
                (
                    ($errors['warning_count'] ?? 0) === 0 &&
                    ($errors['error_count'] ?? 0) === 0
                )
            )
        ) {
            return $dt->format('Y-m-d');
        }
    }

    return null;
}

function csvImportParseGermanAmount(?string $value): ?float
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $value = str_replace(["\xC2\xA0", ' '], '', $value);
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);

    if (!is_numeric($value)) {
        return null;
    }

    return (float)$value;
}

function csvImportNormalizeTextForDuplicate(?string $value): string
{
    $value = mb_strtoupper(csvImportCleanCell((string)$value), 'UTF-8');
    $value = preg_replace('/\s+/u', '', $value) ?? $value;

    return $value;
}

function csvImportBuildHeaderMap(array $header): array
{
    $map = [];

    foreach ($header as $index => $name) {
        $map[csvImportNormalizeHeader((string)$name)] = $index;
    }

    return $map;
}

function csvImportGetField(array $row, array $headerMap, string ...$possibleNames): string
{
    foreach ($possibleNames as $name) {
        $normalized = csvImportNormalizeHeader($name);

        if (array_key_exists($normalized, $headerMap)) {
            $index = $headerMap[$normalized];
            return csvImportCleanCell((string)($row[$index] ?? ''));
        }
    }

    return '';
}

function csvImportDetectKategorieId(string $zahlungspartner, string $verwendungszweck, float $betrag): ?int
{
    $kategorie_id = null;
    $zp  = mb_strtolower($zahlungspartner, 'UTF-8');
    $vwz = mb_strtolower($verwendungszweck, 'UTF-8');

    if (str_contains($zp, 'spotify')) $kategorie_id = 21;
    elseif (str_contains($zp, 'nobis printen')) $kategorie_id = 8;
    elseif (str_contains($zp, 'baeckerei')) $kategorie_id = 8;
    elseif (str_contains($zp, 'bäckerei')) $kategorie_id = 8;
    elseif (str_contains($zp, 'sb tank')) $kategorie_id = 13;
    elseif (str_contains($zp, 'parken')) $kategorie_id = 13;
    elseif (str_contains($zp, 'bernhard') && str_contains($zp, 'inga')) $kategorie_id = 25;
    elseif (str_contains($zp, 'landeshauptkasse nrw')) $kategorie_id = 1;
    elseif (str_contains($zp, 'westdeutscher rundfunk') || str_contains($zp, 'wdr')) $kategorie_id = 4;
    elseif (str_contains($zp, 'studierendenwerk aachen')) $kategorie_id = $betrag > 0 ? 22 : 2;
    elseif (str_contains($zp, 'weh e.v.')) $kategorie_id = 22;
    elseif (str_contains($zp, 'lidl')) $kategorie_id = 7;
    elseif (str_contains($zp, 'kaufland')) $kategorie_id = 7;
    elseif (str_contains($zp, 'rewe')) $kategorie_id = 7;
    elseif (str_contains($zp, 'techniker krankenkasse')) $kategorie_id = 3;
    elseif (str_contains($vwz, 'entgeltabrechnung')) $kategorie_id = 6;
    elseif (str_contains($vwz, 'takeaway.com')) $kategorie_id = 9;
    elseif (str_contains($vwz, 'lieferando')) $kategorie_id = 9;
    elseif (str_contains($vwz, 'google')) $kategorie_id = 20;

    return $kategorie_id;
}

function csvImportTransfersFromUpload(mysqli $bizconn, array $upload): array
{
    $errors = [];
    $file = $upload['tmp_name'] ?? '';

    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file)) {
        return [
            'ok' => false,
            'error' => 'upload_failed',
            'message' => 'Datei-Upload fehlgeschlagen.',
            'inserted' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'errors' => $errors,
        ];
    }

    $handle = fopen((string)$file, 'r');

    if ($handle === false) {
        return [
            'ok' => false,
            'error' => 'read_failed',
            'message' => 'Konnte Datei nicht lesen.',
            'inserted' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'errors' => $errors,
        ];
    }

    $header = fgetcsv($handle, 0, ';');

    if ($header === false || $header === null) {
        fclose($handle);
        return [
            'ok' => false,
            'error' => 'empty_csv',
            'message' => 'CSV-Datei ist leer oder ungültig.',
            'inserted' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'errors' => $errors,
        ];
    }

    $header = array_map(fn($value) => csvImportCleanCell((string)$value), $header);
    $headerMap = csvImportBuildHeaderMap($header);

    $requiredHeaders = [
        'Auftragskonto',
        'Buchungstag',
        'Valutadatum',
        'Buchungstext',
        'Verwendungszweck',
        'Kontonummer/IBAN',
        'BIC (SWIFT-Code)',
        'Betrag',
        'Waehrung',
        'Info',
    ];

    $missingHeaders = [];

    foreach ($requiredHeaders as $requiredHeader) {
        if (!array_key_exists(csvImportNormalizeHeader($requiredHeader), $headerMap)) {
            $missingHeaders[] = $requiredHeader;
        }
    }

    if ($missingHeaders !== []) {
        fclose($handle);
        $errors[] = 'Fehlende Header: ' . implode(', ', $missingHeaders);

        return [
            'ok' => false,
            'error' => 'bad_format',
            'message' => 'CSV-Format nicht erkannt.',
            'inserted' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'errors' => $errors,
        ];
    }

    $rows = [];
    $lineNumber = 1;

    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $lineNumber++;

        if (
            $row === null ||
            $row === [] ||
            (count($row) === 1 && trim((string)$row[0]) === '')
        ) {
            continue;
        }

        $row = array_map(fn($value) => csvImportCleanCell((string)$value), $row);
        $auftragskonto = csvImportGetField($row, $headerMap, 'Auftragskonto');
        $normalizedAuftragskonto = csvImportNormalizeIbanLike($auftragskonto);

        if ($normalizedAuftragskonto !== CSV_EXPECTED_AUFTRAGSKONTO) {
            fclose($handle);

            return [
                'ok' => false,
                'error' => 'wrong_account',
                'message' => 'Falsches Konto: ' . csvImportDescribeAccount($auftragskonto),
                'inserted' => 0,
                'duplicates' => 0,
                'skipped' => 0,
                'errors' => [
                    "Zeile {$lineNumber}: Erwartet wurde " . csvImportFormatIbanPretty(CSV_EXPECTED_AUFTRAGSKONTO) . ", gefunden wurde " . csvImportDescribeAccount($auftragskonto) . '.',
                ],
            ];
        }

        $rows[] = [
            'lineNumber' => $lineNumber,
            'row' => $row,
        ];
    }

    $duplicateStmt = $bizconn->prepare("
        SELECT id, verwendungszweck
        FROM transfers
        WHERE buchungstag = ?
          AND betrag = ?
          AND buchungstext = ?
          AND IFNULL(iban, '') = ?
          AND IFNULL(bic, '') = ?
          AND info = ?
    ");

    $insertStmt = $bizconn->prepare("
        INSERT INTO transfers (
            kategorie_id,
            auftragskonto,
            buchungstag,
            valutadatum,
            buchungstext,
            verwendungszweck,
            zahlungspartner,
            iban,
            bic,
            betrag,
            waehrung,
            info
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ");

    if ($duplicateStmt === false || $insertStmt === false) {
        fclose($handle);
        $errors[] = $bizconn->error;

        return [
            'ok' => false,
            'error' => 'statement_failed',
            'message' => 'SQL-Statement konnte nicht vorbereitet werden.',
            'inserted' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'errors' => $errors,
        ];
    }

    $inserted = 0;
    $duplicates = 0;
    $skipped = 0;

    foreach ($rows as $preparedRow) {
        $lineNumber = (int)$preparedRow['lineNumber'];
        $row = $preparedRow['row'];

        try {
            $auftragskonto    = csvImportGetField($row, $headerMap, 'Auftragskonto');
            $buchungstagRaw   = csvImportGetField($row, $headerMap, 'Buchungstag');
            $valutadatumRaw   = csvImportGetField($row, $headerMap, 'Valutadatum');
            $buchungstext     = csvImportGetField($row, $headerMap, 'Buchungstext');
            $verwendungszweck = csvImportGetField($row, $headerMap, 'Verwendungszweck');
            $zahlungspartner  = csvImportGetField(
                $row,
                $headerMap,
                'Beguenstigter/Zahlungspflichtiger',
                'Begünstigter/Zahlungspflichtiger'
            );
            $iban             = strtoupper(str_replace(' ', '', csvImportGetField($row, $headerMap, 'Kontonummer/IBAN')));
            $bic              = strtoupper(str_replace(' ', '', csvImportGetField($row, $headerMap, 'BIC (SWIFT-Code)')));
            $betragRaw        = csvImportGetField($row, $headerMap, 'Betrag');
            $waehrung         = csvImportGetField($row, $headerMap, 'Waehrung');
            $info             = csvImportGetField($row, $headerMap, 'Info');

            if (stripos($info, 'Umsatz vorgemerkt') !== false) {
                $skipped++;
                continue;
            }

            $buchungstag = csvImportParseGermanDate($buchungstagRaw);

            if ($buchungstag === null) {
                $skipped++;
                $errors[] = "Zeile {$lineNumber}: Ungültiger Buchungstag '{$buchungstagRaw}'.";
                continue;
            }

            $valutadatumParam = csvImportParseGermanDate($valutadatumRaw);
            $betrag_float = csvImportParseGermanAmount($betragRaw);

            if ($betrag_float === null) {
                $skipped++;
                $errors[] = "Zeile {$lineNumber}: Ungültiger Betrag '{$betragRaw}'.";
                continue;
            }

            if (mb_strlen($iban, 'UTF-8') > 34) {
                $skipped++;
                $errors[] = "Zeile {$lineNumber}: IBAN zu lang ({$iban}).";
                continue;
            }

            if (mb_strlen($bic, 'UTF-8') > 15) {
                $skipped++;
                $errors[] = "Zeile {$lineNumber}: BIC zu lang ({$bic}).";
                continue;
            }

            if (mb_strlen($waehrung, 'UTF-8') > 10) {
                $skipped++;
                $errors[] = "Zeile {$lineNumber}: Währung zu lang ({$waehrung}).";
                continue;
            }

            $kategorie_id = csvImportDetectKategorieId($zahlungspartner, $verwendungszweck, $betrag_float);

            $duplicateStmt->bind_param(
                'sdssss',
                $buchungstag,
                $betrag_float,
                $buchungstext,
                $iban,
                $bic,
                $info
            );
            $duplicateStmt->execute();
            $result = $duplicateStmt->get_result();

            $duplicateFound = false;
            $normalizedCurrentVwz = csvImportNormalizeTextForDuplicate($verwendungszweck);

            while ($existing = $result->fetch_assoc()) {
                $normalizedExistingVwz = csvImportNormalizeTextForDuplicate((string)$existing['verwendungszweck']);

                if ($normalizedExistingVwz === $normalizedCurrentVwz) {
                    $duplicateFound = true;
                    break;
                }
            }

            $result->free();

            if ($duplicateFound) {
                $duplicates++;
                continue;
            }

            $insertStmt->bind_param(
                'issssssssdss',
                $kategorie_id,
                $auftragskonto,
                $buchungstag,
                $valutadatumParam,
                $buchungstext,
                $verwendungszweck,
                $zahlungspartner,
                $iban,
                $bic,
                $betrag_float,
                $waehrung,
                $info
            );

            $insertStmt->execute();
            $inserted++;
        } catch (mysqli_sql_exception $e) {
            $skipped++;
            $errors[] = "Zeile {$lineNumber}: SQL-Fehler: " . $e->getMessage();
        } catch (Throwable $e) {
            $skipped++;
            $errors[] = "Zeile {$lineNumber}: Fehler: " . $e->getMessage();
        }
    }

    $duplicateStmt->close();
    $insertStmt->close();
    fclose($handle);

    return [
        'ok' => true,
        'message' => "{$inserted} neue Einträge importiert.",
        'inserted' => $inserted,
        'duplicates' => $duplicates,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

function csvImportSendJson(array $result, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if (basename(__FILE__) === basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        csvImportSendJson([
            'ok' => false,
            'error' => 'method_not_allowed',
            'message' => 'Nur POST-Uploads sind erlaubt.',
            'inserted' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'errors' => [],
        ], 405);
    }

    $result = csvImportTransfersFromUpload($bizconn, $_FILES['csv'] ?? []);
    csvImportSendJson($result, ($result['ok'] ?? false) ? 200 : 400);
}
