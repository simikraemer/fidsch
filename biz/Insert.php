<?php
// biz/Insert.php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$bizconn->set_charset('utf8mb4');

$importMessage = '';
$importErrors = [];

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalizeEncoding(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;

    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    return $value;
}

function cleanCell(?string $value): string
{
    return trim(normalizeEncoding((string)$value));
}

function normalizeHeader(string $value): string
{
    $value = cleanCell($value);
    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function parseGermanDate(?string $value): ?string
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

function parseGermanAmount(?string $value): ?float
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

function normalizeTextForDuplicate(?string $value): string
{
    $value = mb_strtoupper(cleanCell((string)$value), 'UTF-8');
    $value = preg_replace('/\s+/u', '', $value) ?? $value;
    return $value;
}

function buildHeaderMap(array $header): array
{
    $map = [];

    foreach ($header as $index => $name) {
        $map[normalizeHeader((string)$name)] = $index;
    }

    return $map;
}

function getField(array $row, array $headerMap, string ...$possibleNames): string
{
    foreach ($possibleNames as $name) {
        $normalized = normalizeHeader($name);
        if (array_key_exists($normalized, $headerMap)) {
            $index = $headerMap[$normalized];
            return cleanCell((string)($row[$index] ?? ''));
        }
    }

    return '';
}

function detectKategorieId(string $zahlungspartner, string $verwendungszweck, float $betrag): ?int
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $file = $_FILES['csv']['tmp_name'] ?? '';

    if (!is_uploaded_file($file)) {
        $importMessage = 'Datei-Upload fehlgeschlagen.';
    } else {
        $handle = fopen($file, 'r');

        if ($handle === false) {
            $importMessage = 'Konnte Datei nicht lesen.';
        } else {
            $header = fgetcsv($handle, 0, ';');

            if ($header === false || $header === null) {
                fclose($handle);
                $importMessage = 'CSV-Datei ist leer oder ungültig.';
            } else {
                $header = array_map(
                    fn($value) => cleanCell((string)$value),
                    $header
                );
                $headerMap = buildHeaderMap($header);

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
                    if (!array_key_exists(normalizeHeader($requiredHeader), $headerMap)) {
                        $missingHeaders[] = $requiredHeader;
                    }
                }

                if ($missingHeaders !== []) {
                    fclose($handle);
                    $importMessage = 'CSV-Format nicht erkannt.';
                    $importErrors[] = 'Fehlende Header: ' . implode(', ', $missingHeaders);
                } else {
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
                        $importMessage = 'SQL-Statement konnte nicht vorbereitet werden.';
                        $importErrors[] = $bizconn->error;
                    } else {
                        $inserted = 0;
                        $duplicates = 0;
                        $skipped = 0;
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

                            try {
                                $row = array_map(
                                    fn($value) => cleanCell((string)$value),
                                    $row
                                );

                                $auftragskonto    = getField($row, $headerMap, 'Auftragskonto');
                                $buchungstagRaw   = getField($row, $headerMap, 'Buchungstag');
                                $valutadatumRaw   = getField($row, $headerMap, 'Valutadatum');
                                $buchungstext     = getField($row, $headerMap, 'Buchungstext');
                                $verwendungszweck = getField($row, $headerMap, 'Verwendungszweck');
                                $zahlungspartner  = getField(
                                    $row,
                                    $headerMap,
                                    'Beguenstigter/Zahlungspflichtiger',
                                    'Begünstigter/Zahlungspflichtiger'
                                );
                                $iban             = strtoupper(str_replace(' ', '', getField($row, $headerMap, 'Kontonummer/IBAN')));
                                $bic              = strtoupper(str_replace(' ', '', getField($row, $headerMap, 'BIC (SWIFT-Code)')));
                                $betragRaw        = getField($row, $headerMap, 'Betrag');
                                $waehrung         = getField($row, $headerMap, 'Waehrung');
                                $info             = getField($row, $headerMap, 'Info');

                                if (stripos($info, 'Umsatz vorgemerkt') !== false) {
                                    $skipped++;
                                    continue;
                                }

                                $buchungstag = parseGermanDate($buchungstagRaw);
                                if ($buchungstag === null) {
                                    $skipped++;
                                    $importErrors[] = "Zeile {$lineNumber}: Ungültiger Buchungstag '{$buchungstagRaw}'.";
                                    continue;
                                }

                                $valutadatum = parseGermanDate($valutadatumRaw);
                                $valutadatumParam = $valutadatum ?? null;

                                $betrag_float = parseGermanAmount($betragRaw);
                                if ($betrag_float === null) {
                                    $skipped++;
                                    $importErrors[] = "Zeile {$lineNumber}: Ungültiger Betrag '{$betragRaw}'.";
                                    continue;
                                }

                                if (mb_strlen($iban, 'UTF-8') > 34) {
                                    $skipped++;
                                    $importErrors[] = "Zeile {$lineNumber}: IBAN zu lang ({$iban}).";
                                    continue;
                                }

                                if (mb_strlen($bic, 'UTF-8') > 15) {
                                    $skipped++;
                                    $importErrors[] = "Zeile {$lineNumber}: BIC zu lang ({$bic}).";
                                    continue;
                                }

                                if (mb_strlen($waehrung, 'UTF-8') > 10) {
                                    $skipped++;
                                    $importErrors[] = "Zeile {$lineNumber}: Währung zu lang ({$waehrung}).";
                                    continue;
                                }

                                $kategorie_id = detectKategorieId($zahlungspartner, $verwendungszweck, $betrag_float);

                                // Neue robuste Dublettenprüfung:
                                // gleiche stabile Felder in SQL selektieren,
                                // dann `verwendungszweck` whitespace-unabhängig in PHP vergleichen
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
                                $normalizedCurrentVwz = normalizeTextForDuplicate($verwendungszweck);

                                while ($existing = $result->fetch_assoc()) {
                                    $normalizedExistingVwz = normalizeTextForDuplicate((string)$existing['verwendungszweck']);

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
                                $importErrors[] = "Zeile {$lineNumber}: SQL-Fehler: " . $e->getMessage();
                            } catch (Throwable $e) {
                                $skipped++;
                                $importErrors[] = "Zeile {$lineNumber}: Fehler: " . $e->getMessage();
                            }
                        }

                        $duplicateStmt->close();
                        $insertStmt->close();
                        fclose($handle);

                        /* $importMessage = "{$inserted} neue Einträge importiert. {$duplicates} Dubletten übersprungen. {$skipped} Zeilen übersprungen."; */
                        $importMessage = "{$inserted} neue Einträge importiert.";
                    }
                }
            }
        }
    }
}

$page_title = 'Transfers importieren';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>
<main class="container">
    <h1 class="ueberschrift">Transfers importieren</h1>

    <?php if ($importMessage !== ''): ?>
        <p style="text-align: center; font-weight: bold; color: <?= (
            str_contains($importMessage, 'fehlgeschlagen') ||
            str_contains($importMessage, 'Konnte Datei nicht lesen') ||
            str_contains($importMessage, 'nicht erkannt') ||
            str_contains($importMessage, 'leer') ||
            str_contains($importMessage, 'SQL-Statement konnte nicht vorbereitet werden')
        ) ? 'var(--error)' : 'var(--success)' ?>;">
            <?= esc($importMessage) ?>
        </p>
    <?php endif; ?>

    <?php if ($importErrors !== []): ?>
        <details open style="max-width: 1000px; margin: 1rem auto; padding: 1rem; border: 1px solid #ccc; border-radius: 0.5rem; background: #fff8f8;">
            <summary style="font-weight: bold; cursor: pointer;">Fehlerdetails anzeigen (<?= count($importErrors) ?>)</summary>
            <ul style="margin-top: 1rem; padding-left: 1.2rem;">
                <?php foreach ($importErrors as $error): ?>
                    <li style="margin-bottom: 0.4rem; color: var(--error);"><?= esc($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <form class="form-block" method="post" enctype="multipart/form-data">
        <label for="csv-upload"><strong>CSV-Datei hochladen</strong></label>
        <input type="file" id="csv-upload" name="csv" accept=".csv" required>
        <p style="font-size: 0.9rem; color: #666;">
            Hinweis: Erwartet wird die Sparkasse-CSV.
        </p>
        <button type="submit">Hochladen</button>
    </form>
</main>

</body>
</html>