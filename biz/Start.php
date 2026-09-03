<?php
// biz/Start_v9.php
// Finanzdashboard. CSV-Uploads laufen zentral über upload_csv.php.

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
$bizconn->set_charset('utf8mb4');

/* =========================================================
 * Basiswerte
 * ========================================================= */
$CURRENT_YEAR   = (int)date('Y');
$CURRENT_MONTH  = (int)date('n'); // 1..12
$CLOSED_CUTOFF  = sprintf('%04d-%02d-01', $CURRENT_YEAR, $CURRENT_MONTH); // 1. Tag aktueller Monat
$ALL_MODE_START  = '2022-08-01'; // Im Modus \"Alle\" beginnt die Auswertung hier.


function parseDashboardAmount(?string $value): ?float
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $value = str_replace(["\xC2\xA0", ' ', '€'], '', $value);

    if (str_contains($value, ',')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    if (!is_numeric($value)) {
        return null;
    }

    return (float)$value;
}

/**
 * Baut aus den sporadischen Einträgen in konto_staende einen fortgeschriebenen
 * Verlauf. Jeder Kontowert gilt ab seinem Eintragszeitpunkt so lange, bis für
 * dasselbe Konto ein neuer Wert eingetragen wird.
 *
 * Rückgabe:
 * - initial_total: Summe aller zuletzt bekannten Kontostände direkt vor rangeStart
 * - delta_by_date: Änderungen der Gesamtsumme je Kalendertag im sichtbaren Bereich
 * - delta_by_month: Änderungen der Gesamtsumme je Monat im sichtbaren Bereich
 * - last_date: letzter Eintrag im sichtbaren Bereich
 */
function buildExternalBalanceTimeline($bizconn, string $rangeStart, string $rangeEnd): array
{
    $startTs = strlen($rangeStart) === 10 ? $rangeStart . ' 00:00:00' : $rangeStart;
    $endTs   = strlen($rangeEnd) === 10 ? $rangeEnd . ' 23:59:59' : $rangeEnd;

    $initialTotal = 0.0;
    $deltaByDate = [];
    $deltaByMonth = [];
    $lastDate = null;
    $lastByAccount = [];

    $stmt = $bizconn->prepare("
        SELECT konto, betrag, eingetragen_am, id
        FROM konto_staende
        WHERE eingetragen_am <= ?
        ORDER BY eingetragen_am ASC, id ASC
    ");

    if ($stmt === false) {
        return [
            'initial_total' => 0.0,
            'delta_by_date' => [],
            'delta_by_month' => [],
            'last_date' => null,
        ];
    }

    $stmt->bind_param('s', $endTs);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $konto = (string)$row['konto'];
        $amount = (float)$row['betrag'];
        $enteredAt = (string)$row['eingetragen_am'];

        $previous = (float)($lastByAccount[$konto] ?? 0.0);
        $delta = $amount - $previous;
        $lastByAccount[$konto] = $amount;

        if ($enteredAt < $startTs) {
            $initialTotal += $delta;
            continue;
        }

        $dateKey = substr($enteredAt, 0, 10);
        $monthKey = substr($enteredAt, 0, 7);

        if (!isset($deltaByDate[$dateKey])) {
            $deltaByDate[$dateKey] = 0.0;
        }
        $deltaByDate[$dateKey] += $delta;

        if (!isset($deltaByMonth[$monthKey])) {
            $deltaByMonth[$monthKey] = 0.0;
        }
        $deltaByMonth[$monthKey] += $delta;

        if ($lastDate === null || $dateKey > $lastDate) {
            $lastDate = $dateKey;
        }
    }

    $stmt->close();

    return [
        'initial_total' => $initialTotal,
        'delta_by_date' => $deltaByDate,
        'delta_by_month' => $deltaByMonth,
        'last_date' => $lastDate,
    ];
}

/* =========================================================
 * AJAX: externen Kontostand aktualisieren
 * Wie in Konten.php wird kein bestehender Verlauf überschrieben,
 * sondern ein neuer Kontostand für das Konto gespeichert.
 * ========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_GET['ajax'])
    && $_GET['ajax'] === 'update_account_balance'
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');

    $konto = trim((string)($_POST['konto'] ?? ''));
    $betragRaw = trim((string)($_POST['betrag'] ?? ''));
    $betrag = parseDashboardAmount($betragRaw);

    if ($konto === '' || mb_strlen($konto, 'UTF-8') > 100) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'bad_account',
            'message' => 'Das ausgewählte Konto ist ungültig.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($betrag === null) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'bad_amount',
            'message' => 'Bitte einen gültigen Kontostand angeben.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtExistingKonto = $bizconn->prepare("
        SELECT 1
        FROM konto_staende
        WHERE konto = ?
        LIMIT 1
    ");

    if ($stmtExistingKonto === false) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'prepare_failed',
            'message' => 'Das Konto konnte nicht geprüft werden.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtExistingKonto->bind_param('s', $konto);
    $stmtExistingKonto->execute();
    $stmtExistingKonto->store_result();
    $kontoExists = $stmtExistingKonto->num_rows > 0;
    $stmtExistingKonto->close();

    if (!$kontoExists) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'error' => 'account_not_found',
            'message' => 'Das ausgewählte Konto wurde nicht gefunden.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $info = '';
    $stmtUpdateKonto = $bizconn->prepare("
        INSERT INTO konto_staende (
            konto,
            betrag,
            info
        ) VALUES (
            ?, ?, ?
        )
    ");

    if ($stmtUpdateKonto === false) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'prepare_failed',
            'message' => 'Der Kontostand konnte nicht gespeichert werden.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $stmtUpdateKonto->bind_param('sds', $konto, $betrag, $info);
        $stmtUpdateKonto->execute();
        $newId = (int)$stmtUpdateKonto->insert_id;
        $stmtUpdateKonto->close();

        echo json_encode([
            'ok' => true,
            'id' => $newId,
            'konto' => $konto,
            'betrag' => round($betrag, 2)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        $stmtUpdateKonto->close();

        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'save_failed',
            'message' => 'Der Kontostand konnte nicht gespeichert werden.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* =========================================================
 * AJAX: Kategorien-Jahresverlauf
 * ========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cat_years') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');

    $kind  = (string)($_GET['kind'] ?? '');
    $cat   = trim((string)($_GET['cat'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 10);
    if ($limit < 1 || $limit > 10) $limit = 10;

    if (!in_array($kind, ['income', 'expense'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_kind'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($cat === '' || mb_strlen($cat) > 128) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_cat'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $MIN_YEAR = 2023;

    $yrs = [];
    $yrsRes = $bizconn->query("
        SELECT DISTINCT YEAR(valutadatum) AS y
        FROM transfers
        WHERE valutadatum IS NOT NULL
          AND YEAR(valutadatum) >= {$MIN_YEAR}
        ORDER BY y DESC
    ");
    while ($r = $yrsRes->fetch_assoc()) {
        $y = (int)$r['y'];
        if ($y >= $MIN_YEAR) $yrs[] = $y;
    }
    if (!$yrs) {
        echo json_encode([
            'ok' => true,
            'kind' => $kind,
            'cat' => $cat,
            'cat_param' => null,
            'years' => [],
            'values' => [],
            'values_closed' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $yrs = array_slice($yrs, 0, $limit);
    sort($yrs);

    $whereCat = '';
    $params   = [];
    $types    = '';

    // Rücksprungziel für Klick aus Jahresdetail: Kategorie-ID oder 'unk'.
    $catParamReturn = null;
    $katIdBound     = null;

    if ($cat === 'unk' || mb_strtolower($cat) === mb_strtolower('Unkategorisiert')) {
        $whereCat = "t.kategorie_id IS NULL";
        $catParamReturn = 'unk';
        $katIdBound = null;
    } else {
        $katId = null;
        $stmt = $bizconn->prepare("SELECT id FROM kategorien WHERE name = ? LIMIT 1");
        $stmt->bind_param('s', $cat);
        $stmt->execute();
        $stmt->bind_result($katId);
        $stmt->fetch();
        $stmt->close();

        if (!$katId) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'cat_not_found'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $whereCat = "t.kategorie_id = ?";
        $params[] = (int)$katId;
        $types   .= 'i';

        $catParamReturn = (int)$katId;
        $katIdBound     = (int)$katId;
    }

    $in = implode(',', array_fill(0, count($yrs), '?'));
    foreach ($yrs as $y) { $params[] = (int)$y; $types .= 'i'; }

    $sql = "
        SELECT YEAR(t.valutadatum) AS y, COALESCE(SUM(t.betrag), 0) AS s
        FROM transfers t
        WHERE t.valutadatum IS NOT NULL
          AND {$whereCat}
          AND YEAR(t.valutadatum) IN ({$in})
        GROUP BY y
        ORDER BY y ASC
    ";

    $stmt = $bizconn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $map = [];
    foreach ($yrs as $y) $map[$y] = 0.0;

    while ($row = $res->fetch_assoc()) {
        $y = (int)$row['y'];
        $s = (float)$row['s'];
        $map[$y] = $s;
    }
    $stmt->close();

    // Im laufenden Jahr nur abgeschlossene Monate für Durchschnittswerte verwenden.
    $mapClosed = $map;
    if (in_array($CURRENT_YEAR, $yrs, true)) {
        $sqlClosed = "
            SELECT COALESCE(SUM(t.betrag), 0) AS s
            FROM transfers t
            WHERE t.valutadatum IS NOT NULL
              AND {$whereCat}
              AND YEAR(t.valutadatum) = ?
              AND t.valutadatum < ?
        ";

        if ($katIdBound !== null) {
            $stmtC = $bizconn->prepare($sqlClosed);
            $stmtC->bind_param('iis', $katIdBound, $CURRENT_YEAR, $CLOSED_CUTOFF);
        } else {
            $stmtC = $bizconn->prepare($sqlClosed);
            $stmtC->bind_param('is', $CURRENT_YEAR, $CLOSED_CUTOFF);
        }

        $stmtC->execute();
        $stmtC->bind_result($sClosed);
        $stmtC->fetch();
        $stmtC->close();

        $mapClosed[$CURRENT_YEAR] = (float)$sClosed;
    }

    $values = [];
    $valuesClosed = [];
    foreach ($yrs as $y) {
        $values[]       = round((float)$map[$y], 2);
        $valuesClosed[] = round((float)$mapClosed[$y], 2);
    }

    echo json_encode([
        'ok'        => true,
        'kind'      => $kind,
        'cat'       => $cat,
        'cat_param' => $catParamReturn,
        'years'     => $yrs,
        'values'    => $values,
        'values_closed' => $valuesClosed,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
 * Dashboard-Daten aufbauen
 * - Einzeljahr: bisherige Tages-/Monatslogik
 * - "Alle": Mehrjahresverlauf mit einem Datenpunkt pro Monat
 * ========================================================= */
function buildDashboardPageData($bizconn, $yearParam, $selectedKat, int $CURRENT_YEAR, string $CLOSED_CUTOFF, string $ALL_MODE_START): array
{
    $yearParam = trim((string)$yearParam);
    $allYearsMode = (mb_strtolower($yearParam, 'UTF-8') === 'all');

    // Verfügbare Jahre. Wie bisher werden im Dashboard nur Jahre > 2021 angeboten.
    $yearsRes = $bizconn->query("
        SELECT DISTINCT YEAR(valutadatum) AS jahr
        FROM transfers
        WHERE valutadatum IS NOT NULL
        ORDER BY jahr DESC
    ");

    $jahre = [];
    while ($r = $yearsRes->fetch_assoc()) {
        $y = (int)$r['jahr'];
        if ($y > 2021) {
            $jahre[] = $y;
        }
    }

    if (!$jahre) {
        $jahre[] = $CURRENT_YEAR;
    }

    $latestYear = (int)$jahre[0];

    if ($allYearsMode) {
        $jahr = 'all';
        $dataYear = $latestYear;
    } else {
        $requestedYear = (int)$yearParam;
        if (!in_array($requestedYear, $jahre, true)) {
            $requestedYear = $latestYear;
        }
        $jahr = $requestedYear;
        $dataYear = $requestedYear;
    }

    // Kategorien
    $kats = [];
    $katRes = $bizconn->query("SELECT id, name FROM kategorien");
    while ($k = $katRes->fetch_assoc()) {
        $kats[(int)$k['id']] = $k['name'];
    }

    $labelUnk = 'Unkategorisiert';

    if ($selectedKat !== 'all' && $selectedKat !== 'total' && $selectedKat !== 'unk') {
        $selectedKat = (int)$selectedKat;
        if (!isset($kats[$selectedKat])) {
            $selectedKat = 'all';
        }
    }

    $selectedKatLabel = 'Kontostand';
    if ($selectedKat === 'total') {
        $selectedKatLabel = 'Gesamt';
    } elseif ($selectedKat === 'unk') {
        $selectedKatLabel = $labelUnk;
    } elseif ($selectedKat !== 'all' && isset($kats[(int)$selectedKat])) {
        $selectedKatLabel = $kats[(int)$selectedKat];
    }

    $kategorieSummen = [];
    $kategorieSummenClosed = [];
    $transfersByDate = [];
    $catTransfersByDate = [];
    $transfersByMonth = [];
    $catTransfersByMonth = [];

    if ($allYearsMode) {
        $yearsAsc = $jahre;
        sort($yearsAsc);
        $firstYear = (int)$yearsAsc[0];
        $lastYear = (int)$yearsAsc[count($yearsAsc) - 1];

        // Der Mehrjahresmodus beginnt bewusst erst im August 2022.
        // Damit gelten Chart sowie Einnahmen-/Ausgaben-Aggregate im Modus "Alle"
        // einheitlich nur für diesen sichtbaren Zeitraum.
        $rangeStart = $ALL_MODE_START;
        $rangeEnd = sprintf('%04d-12-31', $lastYear);

        $stmt = $bizconn->prepare("
            SELECT valutadatum, betrag, kategorie_id
            FROM transfers
            WHERE valutadatum IS NOT NULL
              AND valutadatum >= ?
              AND valutadatum <= ?
            ORDER BY valutadatum ASC
        ");
        $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    } else {
        $stmt = $bizconn->prepare("
            SELECT valutadatum, betrag, kategorie_id
            FROM transfers
            WHERE YEAR(valutadatum) = ?
            ORDER BY valutadatum ASC
        ");
        $stmt->bind_param('i', $dataYear);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $lastTransferDate = null;

    while ($row = $res->fetch_assoc()) {
        $dRaw = (string)$row['valutadatum'];
        $dKey = substr($dRaw, 0, 10);
        $monthKey = substr($dKey, 0, 7);
        $betrag = (float)$row['betrag'];

        if ($lastTransferDate === null || $dKey > $lastTransferDate) {
            $lastTransferDate = $dKey;
        }

        $katName = $labelUnk;
        if ($row['kategorie_id'] !== null) {
            $katId = (int)$row['kategorie_id'];
            if (isset($kats[$katId])) {
                $katName = $kats[$katId];
            }
        }

        if (!isset($kategorieSummen[$katName])) {
            $kategorieSummen[$katName] = 0.0;
        }
        $kategorieSummen[$katName] += $betrag;

        $rowYear = (int)substr($dKey, 0, 4);
        $inClosed = ($rowYear < $CURRENT_YEAR)
            || ($rowYear === $CURRENT_YEAR && $dKey < $CLOSED_CUTOFF);

        if (!$allYearsMode && $dataYear !== $CURRENT_YEAR) {
            $inClosed = true;
        }

        if ($inClosed) {
            if (!isset($kategorieSummenClosed[$katName])) {
                $kategorieSummenClosed[$katName] = 0.0;
            }
            $kategorieSummenClosed[$katName] += $betrag;
        }

        $matchesSelectedCategory = false;
        if ($selectedKat === 'unk') {
            $matchesSelectedCategory = ($row['kategorie_id'] === null);
        } elseif ($selectedKat !== 'all' && $selectedKat !== 'total') {
            $matchesSelectedCategory = ((int)$row['kategorie_id'] === (int)$selectedKat);
        }

        if ($allYearsMode) {
            if (!isset($transfersByMonth[$monthKey])) {
                $transfersByMonth[$monthKey] = 0.0;
            }
            $transfersByMonth[$monthKey] += $betrag;

            if ($matchesSelectedCategory) {
                if (!isset($catTransfersByMonth[$monthKey])) {
                    $catTransfersByMonth[$monthKey] = 0.0;
                }
                $catTransfersByMonth[$monthKey] += $betrag;
            }
        } else {
            if (!isset($transfersByDate[$dKey])) {
                $transfersByDate[$dKey] = 0.0;
            }
            $transfersByDate[$dKey] += $betrag;

            if ($matchesSelectedCategory) {
                if (!isset($catTransfersByDate[$dKey])) {
                    $catTransfersByDate[$dKey] = 0.0;
                }
                $catTransfersByDate[$dKey] += $betrag;
            }
        }
    }
    $stmt->close();

    // Einnahmen/Ausgaben je Kategorie
    $incomeByCat = [];
    $expenseByCat = [];
    foreach ($kategorieSummen as $kat => $summe) {
        if ($summe > 0) {
            $incomeByCat[$kat] = $summe;
        } elseif ($summe < 0) {
            $expenseByCat[$kat] = abs($summe);
        }
    }
    arsort($incomeByCat);
    arsort($expenseByCat);

    $incomeByCatClosed = [];
    $expenseByCatClosed = [];
    foreach ($kategorieSummenClosed as $kat => $summe) {
        if ($summe > 0) {
            $incomeByCatClosed[$kat] = $summe;
        } elseif ($summe < 0) {
            $expenseByCatClosed[$kat] = abs($summe);
        }
    }
    arsort($incomeByCatClosed);
    arsort($expenseByCatClosed);

    $incomeLabels = array_keys($incomeByCat);
    $expenseLabels = array_keys($expenseByCat);
    $incomeValues = array_map(fn($v) => round((float)$v, 2), array_values($incomeByCat));
    $expenseValues = array_map(fn($v) => round((float)$v, 2), array_values($expenseByCat));

    $incomeValuesClosed = [];
    foreach ($incomeLabels as $lab) {
        $incomeValuesClosed[] = round((float)($incomeByCatClosed[$lab] ?? 0.0), 2);
    }

    $expenseValuesClosed = [];
    foreach ($expenseLabels as $lab) {
        $expenseValuesClosed[] = round((float)($expenseByCatClosed[$lab] ?? 0.0), 2);
    }

    $dailySeries = [];
    $monthlyPoints = [];
    $catDailySeries = [];
    $catMonthlySeries = [];
    $totalDailySeries = [];
    $totalMonthlyPoints = [];

    if ($allYearsMode) {
        $yearsAsc = $jahre;
        sort($yearsAsc);
        $firstYear = (int)$yearsAsc[0];
        $lastYear = (int)$yearsAsc[count($yearsAsc) - 1];
        $rangeStart = $ALL_MODE_START;

        // Kontostand direkt vor dem ersten sichtbaren Monat. Frühere Buchungen
        // fließen weiterhin in den Anfangssaldo ein, werden aber im Alle-Modus
        // weder als eigene Monate noch in Einnahmen/Ausgaben mitaggregiert.
        $startStmt = $bizconn->prepare("
            SELECT COALESCE(SUM(betrag), 0)
            FROM transfers
            WHERE valutadatum < ?
        ");
        $startStmt->bind_param('s', $rangeStart);
        $startStmt->execute();
        $startStmt->bind_result($anfangsbestand);
        $startStmt->fetch();
        $startStmt->close();
        $anfangsbestand = (float)$anfangsbestand;

        $externalTimeline = buildExternalBalanceTimeline(
            $bizconn,
            $rangeStart,
            sprintf('%04d-12-31', $lastYear)
        );
        $externalCum = (float)$externalTimeline['initial_total'];
        $externalDeltaByMonth = $externalTimeline['delta_by_month'];

        if ($lastYear < $CURRENT_YEAR) {
            // Abgeschlossene historische Auswahl: bis Dezember auffüllen.
            $lastMonth = new DateTime(sprintf('%04d-12-01', $lastYear));
        } elseif ($lastYear === $CURRENT_YEAR) {
            // Laufendes Jahr: bis zum aktuellen Monat, auch wenn dort noch keine Buchung vorliegt.
            $lastMonth = new DateTime(sprintf('%04d-%02d-01', $CURRENT_YEAR, (int)date('n')));
        } else {
            $lastMonth = $lastTransferDate
                ? new DateTime(substr($lastTransferDate, 0, 7) . '-01')
                : new DateTime(sprintf('%04d-01-01', $firstYear));
        }

        $cursor = new DateTime($rangeStart);
        $cursor->modify('first day of this month');
        $cum = $anfangsbestand;
        $catCum = 0.0;

        while ($cursor <= $lastMonth) {
            $monthKey = $cursor->format('Y-m');
            $monthlyTotal = (float)($transfersByMonth[$monthKey] ?? 0.0);
            $monthlyCategoryTotal = (float)($catTransfersByMonth[$monthKey] ?? 0.0);

            $cum += $monthlyTotal;
            $x = $cursor->format('Y-m-t');

            $dailySeries[$x] = round($cum, 2);
            $monthlyPoints[$x] = round($cum, 2);

            // Externe Konten werden bis zum nächsten Eintrag fortgeschrieben.
            $externalCum += (float)($externalDeltaByMonth[$monthKey] ?? 0.0);
            $totalValue = $cum + $externalCum;
            $totalDailySeries[$x] = round($totalValue, 2);
            $totalMonthlyPoints[$x] = round($totalValue, 2);

            if ($selectedKat !== 'all' && $selectedKat !== 'total') {
                // cat_daily bleibt kumuliert; cat_monthly behält die bisherige
                // Semantik der Monats-Säulen, wird im Alle-Modus aber als Linie gezeichnet.
                $catCum += $monthlyCategoryTotal;
                $catDailySeries[$x] = round($catCum, 2);
                $catMonthlySeries[$x] = round($monthlyCategoryTotal, 2);
            }

            $cursor->modify('first day of next month');
        }

        /*
         * Im Mehrjahresmodus wirken Kontostand/Gesamt im laufenden Monat
         * künstlich zu niedrig, weil z. B. Gehalt erst am Monatsende kommt.
         * Deshalb wird für genau diese beiden Ansichten der noch nicht
         * abgeschlossene aktuelle Monat vollständig ausgeblendet.
         * Ab dem 1. des Folgemonats erscheint er automatisch als abgeschlossener Monat.
         */
        if (
            $lastYear === $CURRENT_YEAR
            && ($selectedKat === 'all' || $selectedKat === 'total')
        ) {
            $currentMonthEnd = (new DateTime(sprintf(
                '%04d-%02d-01',
                $CURRENT_YEAR,
                (int)date('n')
            )))->format('Y-m-t');

            unset(
                $dailySeries[$currentMonthEnd],
                $monthlyPoints[$currentMonthEnd],
                $totalDailySeries[$currentMonthEnd],
                $totalMonthlyPoints[$currentMonthEnd]
            );
        }
    } else {
        $startDate = sprintf('%04d-01-01', $dataYear);
        $startStmt = $bizconn->prepare("
            SELECT COALESCE(SUM(betrag), 0)
            FROM transfers
            WHERE valutadatum < ?
        ");
        $startStmt->bind_param('s', $startDate);
        $startStmt->execute();
        $startStmt->bind_result($anfangsbestand);
        $startStmt->fetch();
        $startStmt->close();
        $anfangsbestand = (float)$anfangsbestand;

        $rangeEnd = sprintf('%04d-12-31', $dataYear);
        $externalTimeline = buildExternalBalanceTimeline($bizconn, $startDate, $rangeEnd);
        $externalCum = (float)$externalTimeline['initial_total'];
        $externalDeltaByDate = $externalTimeline['delta_by_date'];
        $lastExternalDate = $externalTimeline['last_date'];

        $lastDate = $lastTransferDate
            ? new DateTime($lastTransferDate)
            : new DateTime(sprintf('%04d-01-01', $dataYear));

        $lastTotalDate = clone $lastDate;
        if ($lastExternalDate !== null) {
            $externalDateObj = new DateTime($lastExternalDate);
            if ($externalDateObj > $lastTotalDate) {
                $lastTotalDate = $externalDateObj;
            }
        }

        $start = new DateTime(sprintf('%04d-01-01', $dataYear));
        $end = new DateTime(sprintf('%04d-12-31', $dataYear));
        $cursor = clone $start;
        $cumDaily = $anfangsbestand;

        while ($cursor <= $end) {
            $dstr = $cursor->format('Y-m-d');

            // Der Bankkontostand wird intern über das ganze Jahr fortgeschrieben;
            // sichtbar bleibt er wie bisher nur bis zum letzten Transfer.
            $cumDaily += (float)($transfersByDate[$dstr] ?? 0.0);

            if ($cursor <= $lastDate) {
                $dailySeries[$dstr] = round($cumDaily, 2);
            } else {
                $dailySeries[$dstr] = null;
            }

            $externalCum += (float)($externalDeltaByDate[$dstr] ?? 0.0);
            if ($cursor <= $lastTotalDate) {
                $totalDailySeries[$dstr] = round($cumDaily + $externalCum, 2);
            } else {
                $totalDailySeries[$dstr] = null;
            }

            $cursor->modify('+1 day');
        }

        if ($selectedKat !== 'all' && $selectedKat !== 'total') {
            $cursor2 = clone $start;
            $catCum = 0.0;
            while ($cursor2 <= $end) {
                $dstr = $cursor2->format('Y-m-d');
                if ($cursor2 <= $lastDate) {
                    $catCum += (float)($catTransfersByDate[$dstr] ?? 0.0);
                    $catDailySeries[$dstr] = round($catCum, 2);
                } else {
                    $catDailySeries[$dstr] = null;
                }
                $cursor2->modify('+1 day');
            }
        }

        // Monats-Punkte wie bisher.
        $firstOfYear = sprintf('%04d-01-01', $dataYear);
        $monthlyPoints[$firstOfYear] = $dailySeries[$firstOfYear] ?? $anfangsbestand;
        $totalMonthlyPoints[$firstOfYear] = $totalDailySeries[$firstOfYear] ?? ($anfangsbestand + (float)$externalTimeline['initial_total']);

        for ($m = 2; $m <= 12; $m++) {
            $d = sprintf('%04d-%02d-01', $dataYear, $m);
            if (array_key_exists($d, $dailySeries) && $dailySeries[$d] !== null) {
                $monthlyPoints[$d] = $dailySeries[$d];
            }
            if (array_key_exists($d, $totalDailySeries) && $totalDailySeries[$d] !== null) {
                $totalMonthlyPoints[$d] = $totalDailySeries[$d];
            }
        }

        $heute = new DateTime('today');
        $eoy = new DateTime(sprintf('%04d-12-31', $dataYear));
        $eoyStr = $eoy->format('Y-m-d');
        if ($eoy <= $heute) {
            $valEoy = $dailySeries[$eoyStr] ?? null;
            if ($valEoy === null) {
                $lastKnown = $anfangsbestand;
                foreach (array_reverse($dailySeries, true) as $val) {
                    if ($val !== null) {
                        $lastKnown = $val;
                        break;
                    }
                }
                $monthlyPoints[$eoyStr] = $lastKnown;
            } else {
                $monthlyPoints[$eoyStr] = $valEoy;
            }

            $valTotalEoy = $totalDailySeries[$eoyStr] ?? null;
            if ($valTotalEoy === null) {
                $lastKnownTotal = $anfangsbestand + (float)$externalTimeline['initial_total'];
                foreach (array_reverse($totalDailySeries, true) as $val) {
                    if ($val !== null) {
                        $lastKnownTotal = $val;
                        break;
                    }
                }
                $totalMonthlyPoints[$eoyStr] = $lastKnownTotal;
            } else {
                $totalMonthlyPoints[$eoyStr] = $valTotalEoy;
            }
        }
    }

    // "Gesamt" nutzt exakt dieselbe Chart-Semantik wie Kontostand, nur mit
    // den jeweils zuletzt bekannten Werten aus konto_staende oben drauf.
    if ($selectedKat === 'total') {
        $dailySeries = $totalDailySeries;
        $monthlyPoints = $totalMonthlyPoints;
        $catDailySeries = [];
        $catMonthlySeries = [];
    }

    // Header-Saldo: Einzeljahr bis 31.12.; bei "Alle" bis Ende des jüngsten vorhandenen Jahres.
    $saldoYear = $allYearsMode ? max($jahre) : $dataYear;
    $endOfSelection = sprintf('%04d-12-31', $saldoYear);

    $sumStmt = $bizconn->prepare("
        SELECT COALESCE(SUM(betrag), 0)
        FROM transfers
        WHERE valutadatum IS NOT NULL
          AND valutadatum <= ?
    ");
    $sumStmt->bind_param('s', $endOfSelection);
    $sumStmt->execute();
    $sumStmt->bind_result($summeAlleBisEOY);
    $sumStmt->fetch();
    $sumStmt->close();
    $kontostandBisEndeDesJahres = (float)$summeAlleBisEOY;

    $kontoStandCutoff = sprintf('%04d-12-31 23:59:59', $saldoYear);
    $externeKontostaende = [];

    $stmtKontoStaende = $bizconn->prepare("
        SELECT ks.konto, ks.betrag, ks.eingetragen_am
        FROM konto_staende ks
        WHERE ks.eingetragen_am <= ?
          AND NOT EXISTS (
              SELECT 1
              FROM konto_staende newer
              WHERE newer.konto = ks.konto
                AND newer.eingetragen_am <= ?
                AND (
                    newer.eingetragen_am > ks.eingetragen_am
                    OR (
                        newer.eingetragen_am = ks.eingetragen_am
                        AND newer.id > ks.id
                    )
                )
          )
          AND ks.betrag <> 0
        ORDER BY ks.betrag DESC
    ");

    if ($stmtKontoStaende !== false) {
        $stmtKontoStaende->bind_param('ss', $kontoStandCutoff, $kontoStandCutoff);
        $stmtKontoStaende->execute();
        $resKontoStaende = $stmtKontoStaende->get_result();
        while ($row = $resKontoStaende->fetch_assoc()) {
            $externeKontostaende[] = $row;
        }
        $stmtKontoStaende->close();
    }

    $summeExterneKontostaende = 0.0;
    foreach ($externeKontostaende as $kontoStand) {
        $summeExterneKontostaende += (float)$kontoStand['betrag'];
    }

    $dashboardSaldoGesamt = $kontostandBisEndeDesJahres + $summeExterneKontostaende;
    $dashboardSaldoDetails = [euro($kontostandBisEndeDesJahres)];
    $dashboardSaldoAccounts = [[
        'type' => 'main',
        'konto' => 'Konto',
        'betrag' => round($kontostandBisEndeDesJahres, 2),
        'value' => euro($kontostandBisEndeDesJahres),
    ]];

    foreach ($externeKontostaende as $kontoStand) {
        $dashboardSaldoDetails[] = $kontoStand['konto'] . ': ' . euro($kontoStand['betrag']);
        $dashboardSaldoAccounts[] = [
            'type' => 'external',
            'konto' => (string)$kontoStand['konto'],
            'betrag' => round((float)$kontoStand['betrag'], 2),
            'value' => euro($kontoStand['betrag']),
        ];
    }

    // Anzahl vollständig abgeschlossener Monate für Ø/Monat in den unteren Charts.
    $aggregationMonths = 0;
    if ($allYearsMode) {
        $allStart = new DateTime($ALL_MODE_START);
        $allStartYear = (int)$allStart->format('Y');
        $allStartMonth = (int)$allStart->format('n');

        foreach ($jahre as $y) {
            if ($y < $allStartYear) {
                continue;
            }

            $firstMonthForYear = ($y === $allStartYear) ? $allStartMonth : 1;

            if ($y < $CURRENT_YEAR) {
                $aggregationMonths += max(0, 13 - $firstMonthForYear);
            } elseif ($y === $CURRENT_YEAR) {
                $lastClosedMonth = max(0, (int)date('n') - 1);
                if ($lastClosedMonth >= $firstMonthForYear) {
                    $aggregationMonths += ($lastClosedMonth - $firstMonthForYear + 1);
                }
            }
        }
    } else {
        if ($dataYear < $CURRENT_YEAR) {
            $aggregationMonths = 12;
        } elseif ($dataYear === $CURRENT_YEAR) {
            $aggregationMonths = max(0, (int)date('n') - 1);
        }
    }

    $toXY = static function(array $series): array {
        $out = [];
        foreach ($series as $d => $v) {
            $out[] = ['x' => $d, 'y' => $v];
        }
        return $out;
    };

    return [
        'ok' => true,
        'jahr' => $jahr,
        'all_years' => $allYearsMode,
        'available_years' => array_values($jahre),
        'all_mode_start' => $ALL_MODE_START,
        'aggregation_months' => $aggregationMonths,
        'kategorie' => $selectedKat,
        'kategorie_label' => $selectedKatLabel,
        'kontostand_eoy' => round($kontostandBisEndeDesJahres, 2),
        'dashboard_saldo_gesamt' => round($dashboardSaldoGesamt, 2),
        'dashboard_saldo_details' => $dashboardSaldoDetails,
        'dashboard_saldo_accounts' => $dashboardSaldoAccounts,
        'daily' => $toXY($dailySeries),
        'monthly' => $toXY($monthlyPoints),
        'cat_daily' => $toXY($catDailySeries),
        'cat_monthly' => $toXY($catMonthlySeries),
        'income_labels' => $incomeLabels,
        'income_values' => array_values($incomeValues),
        'expense_labels' => $expenseLabels,
        'expense_values' => array_values($expenseValues),
        'income_values_closed' => array_values($incomeValuesClosed),
        'expense_values_closed' => array_values($expenseValuesClosed),
        'categories' => $kats,
        'label_unk' => $labelUnk,
    ];
}

/* =========================================================
 * AJAX: Dashboard-Daten ohne Reload
 * ========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'page_data') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');

    $yearParam = (string)($_GET['jahr'] ?? date('Y'));
    $selectedKatAjax = $_GET['kategorie'] ?? 'all';

    $data = buildDashboardPageData(
        $bizconn,
        $yearParam,
        $selectedKatAjax,
        $CURRENT_YEAR,
        $CLOSED_CUTOFF,
        $ALL_MODE_START
    );

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
 * Initiale Daten für erstes Rendern
 * ========================================================= */
function euro($v) { return number_format((float)$v, 2, ',', '.').' €'; }

$initialYearParam = (string)($_GET['jahr'] ?? date('Y'));
$initialSelectedKat = $_GET['kategorie'] ?? 'all';

$dashboardData = buildDashboardPageData(
    $bizconn,
    $initialYearParam,
    $initialSelectedKat,
    $CURRENT_YEAR,
    $CLOSED_CUTOFF,
    $ALL_MODE_START
);

$jahr = $dashboardData['jahr'];
$allYearsMode = !empty($dashboardData['all_years']);
$jahre = $dashboardData['available_years'];
$selectedKat = $dashboardData['kategorie'];
$selectedKatLabel = $dashboardData['kategorie_label'];
$kats = $dashboardData['categories'];
$labelUnk = $dashboardData['label_unk'];

$dailyJson = json_encode($dashboardData['daily'], JSON_UNESCAPED_UNICODE);
$monthlyJson = json_encode($dashboardData['monthly'], JSON_UNESCAPED_UNICODE);
$catDailyJson = json_encode($dashboardData['cat_daily'], JSON_UNESCAPED_UNICODE);
$catMonthlyJson = json_encode($dashboardData['cat_monthly'], JSON_UNESCAPED_UNICODE);

$incomeLabels = $dashboardData['income_labels'];
$incomeValues = $dashboardData['income_values'];
$expenseLabels = $dashboardData['expense_labels'];
$expenseValues = $dashboardData['expense_values'];
$incomeValuesClosed = $dashboardData['income_values_closed'];
$expenseValuesClosed = $dashboardData['expense_values_closed'];

$incomeLabelsJson = json_encode($incomeLabels, JSON_UNESCAPED_UNICODE);
$incomeValuesJson = json_encode($incomeValues, JSON_UNESCAPED_UNICODE);
$expenseLabelsJson = json_encode($expenseLabels, JSON_UNESCAPED_UNICODE);
$expenseValuesJson = json_encode($expenseValues, JSON_UNESCAPED_UNICODE);
$incomeValuesClosedJson = json_encode($incomeValuesClosed, JSON_UNESCAPED_UNICODE);
$expenseValuesClosedJson = json_encode($expenseValuesClosed, JSON_UNESCAPED_UNICODE);

$incomeByCat = array_combine($incomeLabels, $incomeValues) ?: [];
$expenseByCat = array_combine($expenseLabels, $expenseValues) ?: [];

$dashboardSaldoGesamt = (float)$dashboardData['dashboard_saldo_gesamt'];
$dashboardSaldoDetails = $dashboardData['dashboard_saldo_details'];
$dashboardSaldoAccounts = $dashboardData['dashboard_saldo_accounts'];
$aggregationMonthsInitial = (int)$dashboardData['aggregation_months'];

function dashboardSaldoDetailParts(string $detail, int $index): array
{
    if ($index === 0) {
        return ['Konto', $detail];
    }

    $parts = explode(':', $detail, 2);

    if (count($parts) === 2) {
        return [trim($parts[0]), trim($parts[1])];
    }

    return ['Detail', $detail];
}

/* =========================================================
 * Rendering
 * ========================================================= */
$page_title = 'Finanzen';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>


<div id="statsPage" class="lt-page dashboard-page">
    <div class="lt-topbar">
        <h1 class="ueberschrift dashboard-title">
          <span class="dashboard-title-main" id="pageTitleYear">
            Finanzen <?= $allYearsMode ? 'Alle' : htmlspecialchars((string)$jahr, ENT_QUOTES, 'UTF-8') ?>
          </span>
          <span class="dashboard-title-soft" id="pageTitleSaldo">
            | <?= euro($dashboardSaldoGesamt) ?>
          </span>
        </h1>

        <div class="dashboard-sober-counters" id="pageTitleSaldoDetails" aria-label="Saldo-Details">
          <?php foreach ($dashboardSaldoAccounts as $kontoData): ?>
            <?php if (($kontoData['type'] ?? '') === 'main'): ?>
              <button
                type="button"
                class="dashboard-sober-counter dashboard-upload-trigger"
                id="kontoCsvUploadTrigger"
                title="CSV hochladen und direkt importieren"
                aria-label="CSV hochladen und direkt importieren"
              >
                <span class="dashboard-sober-label">Konto</span>
                <span class="dashboard-sober-value">
                  <?= htmlspecialchars((string)$kontoData['value'], ENT_QUOTES, 'UTF-8') ?>
                </span>
              </button>
            <?php else: ?>
              <button
                type="button"
                class="dashboard-sober-counter dashboard-upload-trigger dashboard-account-update-trigger"
                data-konto="<?= htmlspecialchars((string)$kontoData['konto'], ENT_QUOTES, 'UTF-8') ?>"
                data-betrag="<?= htmlspecialchars((string)$kontoData['betrag'], ENT_QUOTES, 'UTF-8') ?>"
                title="Kontostand aktualisieren"
                aria-label="Kontostand von <?= htmlspecialchars((string)$kontoData['konto'], ENT_QUOTES, 'UTF-8') ?> aktualisieren"
              >
                <span class="dashboard-sober-label">
                  <?= htmlspecialchars((string)$kontoData['konto'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="dashboard-sober-value">
                  <?= htmlspecialchars((string)$kontoData['value'], ENT_QUOTES, 'UTF-8') ?>
                </span>
              </button>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>

        <input type="file" id="kontoCsvUploadInput" name="csv" accept=".csv,text/csv" hidden>

        <div id="kontoUpdateModal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="kontoUpdateModalTitle">
          <div class="modal-content">
            <span class="close-button" id="kontoUpdateModalClose" role="button" tabindex="0" aria-label="Modal schließen">&times;</span>

            <h2 class="ueberschrift" id="kontoUpdateModalTitle">Kontostand aktualisieren</h2>

            <form id="kontoUpdateForm" class="form-block">
              <input type="hidden" id="kontoUpdateName" name="konto">

              <div class="input-group">
                <label for="kontoUpdateDisplay">Konto:</label>
                <input type="text" id="kontoUpdateDisplay" readonly>
              </div>

              <div class="input-group">
                <label for="kontoUpdateAmount">Neuer Kontostand:</label>
                <input
                  type="text"
                  id="kontoUpdateAmount"
                  name="betrag"
                  inputmode="decimal"
                  autocomplete="off"
                  placeholder="z. B. 1.254,51"
                  required
                >
              </div>

              <div class="modal-actions">
                <button type="button" class="btn-secondary" id="kontoUpdateCancel">Abbrechen</button>
                <button type="submit" id="kontoUpdateSubmit">Kontostand speichern</button>
              </div>
            </form>
          </div>
        </div>

        <form method="get" class="dashboard-filterform" id="statsFilterForm">
          <div class="lt-yearwrap">
            <label for="kategorie" class="lt-label">Kategorie</label>
            <select name="kategorie" id="kategorie" class="kategorie-select">
              <option value="all" <?= ($selectedKat === 'all') ? 'selected' : '' ?>>Kontostand</option>
              <option value="total" <?= ($selectedKat === 'total') ? 'selected' : '' ?>>Gesamt</option>
              <option value="unk" <?= ($selectedKat === 'unk') ? 'selected' : '' ?>>
                <?= htmlspecialchars($labelUnk, ENT_QUOTES, 'UTF-8') ?>
              </option>
              <?php foreach ($kats as $id => $name): ?>
                <option value="<?= (int)$id ?>" <?= ((string)$selectedKat === (string)$id) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="lt-yearwrap">
            <label for="jahr" class="lt-label">Jahr</label>
            <select name="jahr" id="jahr" class="kategorie-select">
              <option value="all" <?= $allYearsMode ? 'selected' : '' ?>>Alle</option>
              <?php foreach ($jahre as $j): ?>
                <option value="<?= (int)$j ?>" <?= (!$allYearsMode && (int)$j === (int)$jahr) ? 'selected' : '' ?>>
                  <?= (int)$j ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
    </div>

    <div class="lt-chart-wrap">
        <canvas id="saldoChart"></canvas>
    </div>

    <!-- <hr class="lt-hr"> -->

    <div class="dashboard-pies dashboard-pies--asym">
      <div class="dashboard-pie-card">
        <div class="dashboard-pie-kpi">
          <button
            type="button"
            class="chart-back"
            id="incomeBack"
            style="display:none"
            title="Zurück"
            aria-label="Zurück"
          >&larr;</button>

          <span class="dashboard-pie-kpi-label" id="incomeTitle">
            Einnahmen
          </span>

          <div
            class="dashboard-pie-kpi-valuewrap dashboard-kpi-breakdown"
            id="incomeKpiBreakdown"
            tabindex="0"
            aria-describedby="incomeKpiBreakdownTooltip"
          >
            <span class="dashboard-pie-kpi-value" id="incomeKpi">
              <?= euro(array_sum($incomeByCat)) ?>
            </span>

            <span class="dashboard-pie-kpi-sub" id="incomeKpiSub" hidden>
              Ø pro Monat
            </span>

            <div
              class="dashboard-kpi-breakdown-tooltip"
              id="incomeKpiBreakdownTooltip"
              role="tooltip"
              aria-hidden="true"
            >
              <div class="dashboard-kpi-breakdown-title" id="incomeKpiBreakdownTitle">Ø im gewählten Zeitraum</div>
              <div class="dashboard-kpi-breakdown-row">
                <span class="dashboard-kpi-breakdown-label">pro Tag</span>
                <strong class="dashboard-kpi-breakdown-value" id="incomeKpiPerDay">—</strong>
              </div>
              <div class="dashboard-kpi-breakdown-row">
                <span class="dashboard-kpi-breakdown-label">pro Woche</span>
                <strong class="dashboard-kpi-breakdown-value" id="incomeKpiPerWeek">—</strong>
              </div>
              <div class="dashboard-kpi-breakdown-row">
                <span class="dashboard-kpi-breakdown-label">pro Monat</span>
                <strong class="dashboard-kpi-breakdown-value" id="incomeKpiPerMonth">—</strong>
              </div>
              <div class="dashboard-kpi-breakdown-row">
                <span class="dashboard-kpi-breakdown-label">pro Jahr</span>
                <strong class="dashboard-kpi-breakdown-value" id="incomeKpiPerYear">—</strong>
              </div>
            </div>
          </div>
        </div>

        <div class="dashboard-pie-wrap">
          <canvas id="incomePie"></canvas>
        </div>
      </div>

      <div class="dashboard-pie-card">
        <div class="dashboard-pie-kpi">
          <button
            type="button"
            class="chart-back"
            id="expenseBack"
            style="display:none"
            title="Zurück"
            aria-label="Zurück"
          >&larr;</button>

          <span class="dashboard-pie-kpi-label" id="expenseTitle">
            Ausgaben (Logarithmisch)
          </span>

          <div
            class="dashboard-pie-kpi-valuewrap dashboard-kpi-breakdown"
            id="expenseKpiBreakdown"
            tabindex="0"
            aria-describedby="expenseKpiBreakdownTooltip"
          >
            <span class="dashboard-pie-kpi-value" id="expenseKpi">
              <?= euro(array_sum($expenseByCat)) ?>
            </span>

            <span class="dashboard-pie-kpi-sub" id="expenseKpiSub" hidden>
              Ø pro Monat
            </span>

            <div
              class="dashboard-kpi-breakdown-tooltip"
              id="expenseKpiBreakdownTooltip"
              role="tooltip"
              aria-hidden="true"
            >
              <div class="dashboard-kpi-breakdown-title" id="expenseKpiBreakdownTitle">Ø im gewählten Zeitraum</div>
              <div class="dashboard-kpi-breakdown-row">
                <span class="dashboard-kpi-breakdown-label">pro Tag</span>
                <strong class="dashboard-kpi-breakdown-value" id="expenseKpiPerDay">—</strong>
              </div>
              <div class="dashboard-kpi-breakdown-row">
                <span class="dashboard-kpi-breakdown-label">pro Woche</span>
                <strong class="dashboard-kpi-breakdown-value" id="expenseKpiPerWeek">—</strong>
              </div>
              <div class="dashboard-kpi-breakdown-row">
                <span class="dashboard-kpi-breakdown-label">pro Monat</span>
                <strong class="dashboard-kpi-breakdown-value" id="expenseKpiPerMonth">—</strong>
              </div>
              <div class="dashboard-kpi-breakdown-row">
                <span class="dashboard-kpi-breakdown-label">pro Jahr</span>
                <strong class="dashboard-kpi-breakdown-value" id="expenseKpiPerYear">—</strong>
              </div>
            </div>
          </div>
        </div>

        <div class="dashboard-pie-wrap">
          <canvas id="expensePie"></canvas>
        </div>
      </div>
    </div>

</div>


<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>

<script>
/* =========================================================
 * JS-State
 * ========================================================= */
let dailyData      = <?= $dailyJson ?>;
let monthlyData    = <?= $monthlyJson ?>;
let catDailyData   = <?= $catDailyJson ?>;
let catMonthlyData = <?= $catMonthlyJson ?>;

let selectedCategory      = <?= json_encode($selectedKat) ?>; // 'all' | 'total' | 'unk' | number
let selectedCategoryLabel = <?= json_encode($selectedKatLabel, JSON_UNESCAPED_UNICODE) ?>;
let chartYear             = <?= json_encode($jahr, JSON_UNESCAPED_UNICODE) ?>; // number | 'all'
let availableYears        = <?= json_encode(array_values($jahre), JSON_UNESCAPED_UNICODE) ?>;
let allModeStart          = <?= json_encode($dashboardData['all_mode_start'], JSON_UNESCAPED_UNICODE) ?>;
let aggregationMonths     = <?= (int)$aggregationMonthsInitial ?>;

if (chartYear !== 'all') {
  catMonthlyData = buildCatMonthlyBarsFromCumulative(catDailyData, chartYear);
}

let incomeLabels  = <?= $incomeLabelsJson ?>;
let incomeValues  = <?= $incomeValuesJson ?>;
let expenseLabels = <?= $expenseLabelsJson ?>;
let expenseValues = <?= $expenseValuesJson ?>;

let incomeValuesClosed  = <?= $incomeValuesClosedJson ?>;
let expenseValuesClosed = <?= $expenseValuesClosedJson ?>;

const fmtEuro = (v) => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(v || 0));
const fmtEuroAxis = (v) => new Intl.NumberFormat('de-DE', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 0,
  maximumFractionDigits: 0
}).format(Number(v || 0));
const NOW = new Date();
const PRIMARY = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#1e88e5';

function buildCatMonthlyBarsFromCumulative(catDaily, year) {
  const y = Number(year);
  const lastCumByMonth = new Array(12).fill(null);

  if (Array.isArray(catDaily)) {
    for (const pt of catDaily) {
      if (!pt || pt.y == null || pt.x == null) continue;

      const d = new Date(String(pt.x) + 'T00:00:00');
      if (d.getFullYear() !== y) continue;

      const m = d.getMonth(); // 0..11
      lastCumByMonth[m] = Number(pt.y);
    }
  }

  const out = [];
  let prev = 0;

  for (let m = 0; m < 12; m++) {
    const midMs = monthMidMsUTC(y, m); // <<< Säule in Monatsmitte

    if (lastCumByMonth[m] == null) {
      out.push({ x: midMs, y: null }); // Zukunft -> keine Säule
      continue;
    }

    const cur = Number(lastCumByMonth[m]);
    const sum = cur - prev;
    prev = cur;

    out.push({ x: midMs, y: Math.round(sum * 100) / 100 });
  }

  return out;
}


function $(id) { return document.getElementById(id); }

/* =========================================================
 * HELPERS
 * ========================================================= */
const sumArr = (arr) => (arr || []).reduce((a, b) => a + Number(b || 0), 0);

function completedMonthsForYear(year) {
  if (String(year) === 'all') {
    return Number(aggregationMonths || 0);
  }

  const y = Number(year);
  const nowY = NOW.getFullYear();
  if (y < nowY) return 12;
  if (y > nowY) return 0;
  return NOW.getMonth(); // Jan=0 => nur vollständig abgeschlossene Monate
}

function selectionTotalLabel() {
  return String(chartYear) === 'all' ? 'Gesamt alle Jahre' : `Gesamt ${chartYear}`;
}

function fmtMonthlyAvg(val, months) {
  const m = Number(months || 0);
  if (m <= 0) return '—';
  return fmtEuro(Number(val || 0) / m);
}

function dateUtcFromIso(iso) {
  const match = String(iso ?? '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!match) return null;

  return new Date(Date.UTC(
    Number(match[1]),
    Number(match[2]) - 1,
    Number(match[3])
  ));
}

function daysInclusiveUtc(start, end) {
  if (!(start instanceof Date) || !(end instanceof Date)) return 0;

  const startMs = Date.UTC(start.getUTCFullYear(), start.getUTCMonth(), start.getUTCDate());
  const endMs = Date.UTC(end.getUTCFullYear(), end.getUTCMonth(), end.getUTCDate());
  if (endMs < startMs) return 0;

  return Math.floor((endMs - startMs) / 86400000) + 1;
}

function kpiBreakdownPeriod() {
  const nowYear = NOW.getFullYear();
  const todayUtc = new Date(Date.UTC(nowYear, NOW.getMonth(), NOW.getDate()));

  if (String(chartYear) !== 'all') {
    const year = Number(chartYear);
    if (!Number.isFinite(year)) return null;

    const start = new Date(Date.UTC(year, 0, 1));
    const isCurrentYear = year === nowYear;
    const end = isCurrentYear
      ? todayUtc
      : new Date(Date.UTC(year, 11, 31));

    const days = daysInclusiveUtc(start, end);
    if (days <= 0) return null;

    return {
      days,
      weeks: days / 7,
      months: isCurrentYear ? days / (365.2425 / 12) : 12,
      years: isCurrentYear ? days / 365.2425 : 1,
      title: isCurrentYear
        ? `Ø ${year} bis heute`
        : `Ø im Jahr ${year}`
    };
  }

  const start = dateUtcFromIso(allModeStart);
  if (!start) return null;

  const years = Array.isArray(availableYears)
    ? availableYears.map(Number).filter(Number.isFinite)
    : [];
  const lastYear = years.length ? Math.max(...years) : nowYear;
  const end = lastYear >= nowYear
    ? todayUtc
    : new Date(Date.UTC(lastYear, 11, 31));

  const days = daysInclusiveUtc(start, end);
  if (days <= 0) return null;

  return {
    days,
    weeks: days / 7,
    months: days / (365.2425 / 12),
    years: days / 365.2425,
    title: 'Ø über den Gesamtzeitraum'
  };
}

function setKpiBreakdownEnabled(key, enabled) {
  const wrap = $(`${key}KpiBreakdown`);
  const tooltip = $(`${key}KpiBreakdownTooltip`);
  if (!wrap) return;

  wrap.classList.toggle('is-disabled', !enabled);
  wrap.tabIndex = enabled ? 0 : -1;

  if (tooltip) {
    tooltip.setAttribute('aria-hidden', enabled ? 'false' : 'true');
  }
}

function refreshKpiBreakdown(key) {
  const values = key === 'income' ? incomeValues : expenseValues;
  const total = sumArr(values);
  const period = kpiBreakdownPeriod();

  if (!period || !Number.isFinite(total)) {
    setKpiBreakdownEnabled(key, false);
    return;
  }

  const items = {
    Day: period.days,
    Week: period.weeks,
    Month: period.months,
    Year: period.years
  };

  Object.entries(items).forEach(([suffix, divisor]) => {
    const el = $(`${key}KpiPer${suffix}`);
    if (!el) return;
    el.textContent = divisor > 0 ? fmtEuro(total / divisor) : '—';
  });

  const title = $(`${key}KpiBreakdownTitle`);
  if (title) title.textContent = period.title;

  const wrap = $(`${key}KpiBreakdown`);
  if (wrap) {
    wrap.setAttribute(
      'aria-label',
      `${key === 'income' ? 'Einnahmen' : 'Ausgaben'} ${fmtEuro(total)}. ${period.title}. `
      + `Pro Tag ${fmtEuro(total / period.days)}, `
      + `pro Woche ${fmtEuro(total / period.weeks)}, `
      + `pro Monat ${fmtEuro(total / period.months)}, `
      + `pro Jahr ${fmtEuro(total / period.years)}.`
    );
  }

  setKpiBreakdownEnabled(key, true);
}

function hexToRgba(hex, a) {
  const m = String(hex).trim().match(/^#?([0-9a-f]{6})$/i);
  if (!m) return hex;
  const n = parseInt(m[1], 16);
  const r = (n >> 16) & 255;
  const g = (n >> 8) & 255;
  const b = n & 255;
  return `rgba(${r}, ${g}, ${b}, ${a})`;
}

function isClickableBar(chart, elements) {
  if (!elements?.length) return false;
  const el = elements[0];
  const label = chart.data.labels?.[el.index];
  return !!label && label !== 'Rest';
}

function truncateLabel(s, max = 14) {
  s = String(s ?? '');
  return s.length > max ? (s.slice(0, max - 1) + '…') : s;
}
function staggeredTick(label, index, maxLen = 14) {
  const t = truncateLabel(label, maxLen);
  return (index % 2 === 0) ? [t, ''] : ['', t];
}

function topNWithRestDual(labels, values, valuesClosed, N) {
  const pairs = labels
    .map((l, i) => ({
      l,
      v: Number(values[i] || 0),
      c: Number((valuesClosed && valuesClosed[i] != null) ? valuesClosed[i] : (values[i] || 0)),
    }))
    .filter(p => p.v > 0)
    .sort((a, b) => b.v - a.v);

  const top  = pairs.slice(0, N);
  const rest = pairs.slice(N);

  if (rest.length) {
    top.push({
      l: 'Rest',
      v: rest.reduce((s, p) => s + p.v, 0),
      c: rest.reduce((s, p) => s + p.c, 0),
    });
  }

  return {
    labels: top.map(p => p.l),
    values: top.map(p => Math.round(p.v * 100) / 100),
    valuesClosed: top.map(p => Math.round(p.c * 100) / 100),
  };
}

/* =========================================================
 * MID-MONTH LABELS PLUGIN (muss vor Chart-Erstellung registriert werden)
 * ========================================================= */
function daysInMonthUTC(year, monthIndex0) {
  return new Date(Date.UTC(year, monthIndex0 + 1, 0)).getUTCDate();
}
function monthStartMsUTC(year, monthIndex0) {
  return Date.UTC(year, monthIndex0, 1, 0, 0, 0);
}
function monthMidMsUTC(year, monthIndex0) {
  const dim = daysInMonthUTC(year, monthIndex0);
  const start = monthStartMsUTC(year, monthIndex0);
  const midDayOffset = Math.floor(dim / 2);
  return start + (midDayOffset * 86400000) + (12 * 3600000);
}
function fmtMonthDE(ms) {
  return new Intl.DateTimeFormat('de-DE', { month: 'short' })
    .format(new Date(ms))
    .replace('.', '');
}

const midPeriodLabelsPlugin = {
  id: 'midPeriodLabelsPlugin',
  afterDraw(chart) {
    const scale = chart?.scales?.x;
    if (!scale || scale.type !== 'time') return;

    const xOpts = scale.options || {};
    const showMonths = !!xOpts.midMonthLabels;
    const showYears  = !!xOpts.midYearLabels;
    if (!showMonths && !showYears) return;

    let fontStr = '12px sans-serif';
    try {
      if (Chart?.helpers?.toFont) fontStr = Chart.helpers.toFont(xOpts.ticks?.font).string;
    } catch (_) {}
    const color = xOpts.ticks?.color ?? Chart.defaults.color ?? '#666';

    const ctx = chart.ctx;
    ctx.save();
    ctx.font = fontStr;
    ctx.fillStyle = color;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'bottom';

    const labelY = scale.bottom - 2;

    if (showMonths) {
      const year = (typeof xOpts.midMonthLabelYear === 'number')
        ? xOpts.midMonthLabelYear
        : Number(chartYear);
      const compactW = xOpts.midMonthLabelCompactWidth ?? 420;
      const step = (typeof scale.width === 'number' && scale.width < compactW) ? 2 : 1;

      for (let m = 0; m < 12; m++) {
        if (step === 2 && (m % 2 === 1)) continue;
        const midMs = monthMidMsUTC(year, m);
        const x = scale.getPixelForValue(midMs);
        ctx.fillText(fmtMonthDE(midMs), x, labelY);
      }
    }

    if (showYears) {
      const years = Array.isArray(availableYears)
        ? [...availableYears].map(Number).filter(Number.isFinite).sort((a, b) => a - b)
        : [];

      const visibleMin = Number(scale.min);
      const visibleMax = Number(scale.max);

      for (const year of years) {
        const calendarStartMs = Date.UTC(year, 0, 1, 0, 0, 0);
        const calendarEndMs   = Date.UTC(year + 1, 0, 1, 0, 0, 0);

        // Beim ersten, nur teilweise sichtbaren Jahr (2022 ab August) wird die
        // Beschriftung in die Mitte des tatsächlich sichtbaren Jahresabschnitts
        // gesetzt. Alle Folgejahre bleiben exakt mittig im Kalenderjahr.
        const startMs = Math.max(calendarStartMs, visibleMin);
        const endMs   = Math.min(calendarEndMs, visibleMax);
        if (!(endMs > startMs)) continue;

        const midMs = startMs + ((endMs - startMs) / 2);
        const x = scale.getPixelForValue(midMs);
        ctx.fillText(String(year), x, labelY);
      }
    }

    ctx.restore();
  }
};
Chart.register(midPeriodLabelsPlugin);

/* =========================================================
 * TOP CHART (Saldo) – EINMALIGE Instanz + Update
 * ========================================================= */
function buildSaldoDatasets() {
  const ds = [];
  const allYears = String(chartYear) === 'all';

  if (allYears) {
    if (!selectedCategory || selectedCategory === 'all' || selectedCategory === 'total') {
      ds.push({
        label: selectedCategory === 'total' ? 'Verlauf Gesamt' : 'Verlauf Kontostand',
        data: monthlyData,
        borderColor: '#000',
        borderWidth: 3,
        pointRadius: 3,
        pointHoverRadius: 5,
        pointBackgroundColor: '#ff6b00',
        pointBorderColor: '#000',
        pointBorderWidth: 1,
        fill: false,
        tension: 0
      });
    } else if (Array.isArray(catMonthlyData) && catMonthlyData.length > 0) {
      ds.push({
        label: selectedCategoryLabel,
        data: catMonthlyData,
        borderColor: PRIMARY,
        borderWidth: 3,
        pointRadius: 3,
        pointHoverRadius: 5,
        pointBackgroundColor: PRIMARY,
        pointBorderColor: PRIMARY,
        pointBorderWidth: 1,
        fill: false,
        tension: 0
      });
    }

    return ds;
  }

  if (!selectedCategory || selectedCategory === 'all' || selectedCategory === 'total') {
    const isTotal = selectedCategory === 'total';

    ds.push(
      {
        label: isTotal ? 'Gesamt (Monatsbeginn)' : 'Kontostand (Monatsbeginn)',
        data: monthlyData,
        showLine: false,
        pointRadius: 8,
        pointBackgroundColor: '#ff6b00',
        pointBorderColor: '#000',
        pointBorderWidth: 2
      },
      {
        label: isTotal ? 'Verlauf Gesamt' : 'Verlauf Kontostand',
        data: dailyData,
        borderColor: '#000',
        borderWidth: 3,
        pointRadius: 0,
        fill: false,
        tension: 0
      }
    );
  } else if (Array.isArray(catMonthlyData) && catMonthlyData.length > 0) {
    const ORANGE = '#ff6b00';

    ds.push({
      type: 'bar',
      label: selectedCategoryLabel,
      data: catMonthlyData,
      backgroundColor: hexToRgba(ORANGE, 0.35),
      borderColor: ORANGE,
      borderWidth: 1,
      barPercentage: 0.55,
      categoryPercentage: 0.85
    });
  }

  return ds;
}

let saldoChart = null;
(function initSaldoChart() {
  const canvas = $('saldoChart');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');

  saldoChart = new Chart(ctx, {
    type: 'line',
    data: { datasets: buildSaldoDatasets() },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'nearest', axis: 'x', intersect: false },
      hover:       { mode: 'nearest', axis: 'x', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (c) => `${c.dataset.label}: ${fmtEuro(c.parsed.y)}`
          }
        }
      },
      scales: {
        x: {
          type: 'time',
          time: { unit: 'month', tooltipFormat: 'dd.MM.yyyy' },
          midMonthLabels: true,
          midYearLabels: false,
          midMonthLabelYear: (String(chartYear) === 'all' ? null : Number(chartYear)),
          midMonthLabelCompactWidth: 420,
          ticks: { autoSkip: false, maxRotation: 0, minRotation: 0, callback: () => ' ' }
        },
        y: {
          beginAtZero: (String(chartYear) !== 'all' && !!selectedCategory && selectedCategory !== 'all' && selectedCategory !== 'total'),
          ticks: { callback: (v) => fmtEuroAxis(v) }
        }
      }
    }
  });

  updateSaldoChartOnly();
})();

function updateSaldoChartOnly() {
  if (!saldoChart) return;

  const allYears = String(chartYear) === 'all';
  saldoChart.data.datasets = buildSaldoDatasets();

  const x = saldoChart.options.scales.x;
  const y = saldoChart.options.scales.y;

  if (allYears) {
    /*
     * Mehrjahresmodus:
     * - vertikale Rasterlinien liegen exakt auf dem 01.01. jedes Jahres
     * - Jahreszahlen werden separat exakt in die Mitte des Jahres gezeichnet
     * - damit erscheinen keine rohen Millisekunden-/Unix-Zeitwerte als Tick-Labels
     */
    x.time.unit = 'year';
    x.time.tooltipFormat = 'MM.yyyy';
    x.midMonthLabels = false;
    x.midMonthLabelYear = null;
    x.midYearLabels = true;

    x.ticks.autoSkip = false;
    x.ticks.maxRotation = 0;
    x.ticks.minRotation = 0;
    x.ticks.callback = () => ' ';

    const yearsAsc = Array.isArray(availableYears)
      ? [...availableYears].map(Number).filter(Number.isFinite).sort((a, b) => a - b)
      : [];

    if (yearsAsc.length > 0) {
      // Links beginnt "Alle" bewusst am 01.08.2022. Rechts bleibt das letzte
      // Jahr vollständig aufgespannt, damit dessen Jahreszahl mittig sitzt.
      const parsedAllStart = Date.parse(`${allModeStart}T00:00:00Z`);
      x.min = Number.isFinite(parsedAllStart)
        ? parsedAllStart
        : Date.UTC(yearsAsc[0], 0, 1, 0, 0, 0);
      x.max = Date.UTC(yearsAsc[yearsAsc.length - 1] + 1, 0, 1, 0, 0, 0);
    } else {
      delete x.min;
      delete x.max;
    }

    // Bei den beiden Saldo-Ansichten (Kontostand + Gesamt) im Alle-Modus
    // die Ordinate fest bei 0 beginnen lassen. Kategorien bleiben automatisch.
    y.beginAtZero = (!selectedCategory || selectedCategory === 'all' || selectedCategory === 'total');
  } else {
    x.time.unit = 'month';
    x.time.tooltipFormat = 'dd.MM.yyyy';
    x.midMonthLabels = true;
    x.midYearLabels = false;
    x.midMonthLabelYear = Number(chartYear);
    x.ticks.autoSkip = false;
    x.ticks.maxRotation = 0;
    x.ticks.minRotation = 0;
    x.ticks.callback = () => ' ';

    const isSaldoView = (!selectedCategory || selectedCategory === 'all' || selectedCategory === 'total');
    y.beginAtZero = !isSaldoView;

    if (!isSaldoView) {
      x.min = monthStartMsUTC(Number(chartYear), 0);
      x.max = monthStartMsUTC(Number(chartYear) + 1, 0);
    } else {
      delete x.min;
      delete x.max;
    }
  }

  saldoChart.update();
}

/* =========================================================
 * Dashboard-Refresh ohne Reload
 * ========================================================= */
let _pageReqId = 0;
let _csvUploadRunning = false;

async function fetchPageData(year, category) {
  const base = window.location.pathname;
  const qs = new URLSearchParams({
    ajax: 'page_data',
    jahr: String(year),
    kategorie: String(category ?? 'all'),
  });
  const r = await fetch(`${base}?${qs.toString()}`, {
    method: 'GET',
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' }
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok || !j?.ok) throw new Error(j?.error || `http_${r.status}`);
  return j;
}

function setUrlParams(category, year, push = true) {
  const u = new URL(window.location.href);
  u.searchParams.set('jahr', String(year));
  u.searchParams.set('kategorie', String(category ?? 'all'));
  if (push) history.pushState({}, '', u.toString());
  else history.replaceState({}, '', u.toString());
}

function setSelectValues(category, year) {
  const selCat = $('kategorie');
  const selYear = $('jahr');
  if (selCat) selCat.value = String(category ?? 'all');
  if (selYear) selYear.value = String(year);
}

function splitSaldoDetail(detail, index) {
  const raw = String(detail ?? '').trim();

  if (index === 0) {
    return {
      type: 'main',
      konto: 'Konto',
      betrag: null,
      value: raw
    };
  }

  const sep = raw.indexOf(':');

  if (sep !== -1) {
    return {
      type: 'external',
      konto: raw.slice(0, sep).trim(),
      betrag: null,
      value: raw.slice(sep + 1).trim()
    };
  }

  return {
    type: 'external',
    konto: 'Detail',
    betrag: null,
    value: raw
  };
}

function renderSaldoDetailBlocks(saldoAccounts = [], saldoDetails = []) {
  const wrap = $('pageTitleSaldoDetails');
  if (!wrap) return;

  wrap.innerHTML = '';

  let accounts = Array.isArray(saldoAccounts) ? saldoAccounts : [];

  if (accounts.length === 0 && Array.isArray(saldoDetails)) {
    accounts = saldoDetails.map((detail, index) => splitSaldoDetail(detail, index));
  }

  if (accounts.length === 0) {
    wrap.style.display = 'none';
    return;
  }

  wrap.style.display = '';

  accounts.forEach((account, index) => {
    const isMain = account?.type === 'main' || index === 0;
    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'dashboard-sober-counter dashboard-upload-trigger';

    if (isMain) {
      card.id = 'kontoCsvUploadTrigger';
      card.title = 'CSV hochladen und direkt importieren';
      card.setAttribute('aria-label', 'CSV hochladen und direkt importieren');
    } else {
      const accountName = String(account?.konto ?? '').trim();
      card.classList.add('dashboard-account-update-trigger');
      card.dataset.konto = accountName;
      card.dataset.betrag = String(account?.betrag ?? '');
      card.title = 'Kontostand aktualisieren';
      card.setAttribute('aria-label', `Kontostand von ${accountName} aktualisieren`);
    }

    const label = document.createElement('span');
    label.className = 'dashboard-sober-label';
    label.textContent = isMain ? 'Konto' : String(account?.konto ?? 'Konto');

    const value = document.createElement('span');
    value.className = 'dashboard-sober-value';
    value.textContent = String(account?.value ?? fmtEuro(account?.betrag ?? 0));

    card.appendChild(label);
    card.appendChild(value);
    wrap.appendChild(card);
  });
}

function updateHeader(year, saldoGesamt, saldoAccounts = [], saldoDetails = []) {
  const yEl = $('pageTitleYear');
  const sEl = $('pageTitleSaldo');

  if (yEl) {
    yEl.textContent = String(year) === 'all' ? 'Finanzen Alle' : `Finanzen ${year}`;
  }

  if (sEl) {
    sEl.textContent = `| ${fmtEuro(saldoGesamt)}`;
  }

  renderSaldoDetailBlocks(saldoAccounts, saldoDetails);
}

async function applySelection(category, year, opts = {}) {
  const { pushHistory = true, scrollTop = true } = opts;
  const reqId = ++_pageReqId;

  setSelectValues(category, year);
  setUrlParams(category, year, pushHistory);

  let j;
  try {
    j = await fetchPageData(year, category);
  } catch (e) {
    console.error(e);
    return;
  }
  if (reqId !== _pageReqId) return;

  chartYear = String(j.jahr) === 'all' ? 'all' : Number(j.jahr);
  availableYears = (j.available_years || []).map(Number);
  allModeStart = String(j.all_mode_start || allModeStart || '2022-08-01');
  aggregationMonths = Number(j.aggregation_months || 0);

  selectedCategory = j.kategorie;
  selectedCategoryLabel = j.kategorie_label;

  dailyData = j.daily || [];
  monthlyData = j.monthly || [];
  catDailyData = j.cat_daily || [];

  if (chartYear === 'all') {
    catMonthlyData = j.cat_monthly || catDailyData || [];
  } else {
    catMonthlyData = buildCatMonthlyBarsFromCumulative(catDailyData, chartYear);
  }

  setSelectValues(selectedCategory, chartYear);

  incomeLabels = j.income_labels || [];
  incomeValues = (j.income_values || []).map(Number);
  incomeValuesClosed = (j.income_values_closed || []).map(Number);

  expenseLabels = j.expense_labels || [];
  expenseValues = (j.expense_values || []).map(Number);
  expenseValuesClosed = (j.expense_values_closed || []).map(Number);

  // Fallback (falls Backend mal leer liefert)
  if (!incomeValuesClosed.length) incomeValuesClosed = incomeValues.slice();
  if (!expenseValuesClosed.length) expenseValuesClosed = expenseValues.slice();

  updateHeader(
    chartYear,
    j.dashboard_saldo_gesamt ?? j.kontostand_eoy,
    j.dashboard_saldo_accounts || [],
    j.dashboard_saldo_details || []
  );

  // reset bottom charts (pies)
  destroyChart('income');
  destroyChart('expense');

  ui.income.origTitle  = 'Einnahmen';
  ui.expense.origTitle = 'Ausgaben (Logarithmisch)';
  ui.income.origKpi    = fmtEuro(sumArr(incomeValues));
  ui.expense.origKpi   = fmtEuro(sumArr(expenseValues));

  setTitle('income', ui.income.origTitle);
  setTitle('expense', ui.expense.origTitle);
  setKpi('income', ui.income.origKpi);
  setKpi('expense', ui.expense.origKpi);

  makeIncomeOverview();
  makeExpenseOverview();
  updateSaldoChartOnly();

  if (scrollTop) {
    const top = $('statsPage');
    if (top) top.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

/* =========================================================
 * Externe Kontostände aktualisieren
 * ========================================================= */
let _accountUpdateRunning = false;

function formatAccountAmountInput(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) return '';

  return new Intl.NumberFormat('de-DE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(number);
}

function openAccountUpdateModal(trigger) {
  const modal = $('kontoUpdateModal');
  const accountInput = $('kontoUpdateName');
  const accountDisplay = $('kontoUpdateDisplay');
  const amountInput = $('kontoUpdateAmount');

  if (!modal || !accountInput || !accountDisplay || !amountInput) return;

  const account = String(trigger?.dataset?.konto ?? '').trim();
  const amount = trigger?.dataset?.betrag ?? '';

  if (!account) return;

  accountInput.value = account;
  accountDisplay.value = account;
  amountInput.value = formatAccountAmountInput(amount);

  modal.classList.remove('hidden');

  window.setTimeout(() => {
    amountInput.focus();
    amountInput.select();
  }, 0);
}

function closeAccountUpdateModal() {
  if (_accountUpdateRunning) return;

  const modal = $('kontoUpdateModal');
  const form = $('kontoUpdateForm');

  if (modal) modal.classList.add('hidden');
  if (form) form.reset();
}

async function submitAccountUpdate(form) {
  if (!form || _accountUpdateRunning) return;

  const submitButton = $('kontoUpdateSubmit');
  const account = $('kontoUpdateName')?.value?.trim() ?? '';
  const amount = $('kontoUpdateAmount')?.value?.trim() ?? '';

  if (!account || !amount) return;

  _accountUpdateRunning = true;
  if (submitButton) submitButton.disabled = true;

  try {
    const formData = new FormData();
    formData.append('konto', account);
    formData.append('betrag', amount);

    const updateUrl = new URL(window.location.pathname, window.location.origin);
    updateUrl.searchParams.set('ajax', 'update_account_balance');

    const response = await fetch(updateUrl.toString(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      body: formData
    });

    const result = await response.json().catch(() => ({}));

    if (!response.ok || !result?.ok) {
      throw new Error(result?.message || result?.error || `http_${response.status}`);
    }

    const modal = $('kontoUpdateModal');
    if (modal) modal.classList.add('hidden');
    form.reset();

    showCsvUploadToast(`Kontostand für ${result.konto} wurde gespeichert.`, 'success', true);

    await applySelection(
      $('kategorie')?.value ?? selectedCategory ?? 'all',
      $('jahr')?.value ?? chartYear,
      { pushHistory: false, scrollTop: false }
    );
  } catch (e) {
    console.error(e);
    showCsvUploadToast(e?.message || 'Kontostand konnte nicht gespeichert werden.', 'error', true);
  } finally {
    _accountUpdateRunning = false;
    if (submitButton) submitButton.disabled = false;
  }
}

/* =========================================================
 * CSV Upload
 * ========================================================= */
let _csvToastTimer = null;

function csvImportedMessage(inserted) {
  const count = Number(inserted || 0);
  return count === 1
    ? '1 neuer Eintrag importiert.'
    : `${count} neue Einträge importiert.`;
}

function showCsvUploadToast(message, type = 'info', autohide = true) {
  let toast = $('csvUploadToast');

  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'csvUploadToast';
    toast.className = 'csv-upload-toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    document.body.appendChild(toast);
  }

  if (_csvToastTimer) {
    window.clearTimeout(_csvToastTimer);
    _csvToastTimer = null;
  }

  toast.textContent = message;
  toast.className = `csv-upload-toast csv-upload-toast--${type} is-visible`;

  if (autohide) {
    _csvToastTimer = window.setTimeout(() => {
      toast.classList.remove('is-visible');
      _csvToastTimer = null;
    }, 3000);
  }
}

async function uploadCsvFile(file) {
  if (!file || _csvUploadRunning) return;

  _csvUploadRunning = true;
  showCsvUploadToast('CSV wird importiert …', 'info', false);

  const trigger = $('kontoCsvUploadTrigger');
  if (trigger) trigger.disabled = true;

  try {
    const fd = new FormData();
    fd.append('csv', file);

    const uploadUrl = new URL('upload_csv.php', window.location.href);
    const response = await fetch(uploadUrl.toString(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      body: fd
    });

    const result = await response.json().catch(() => ({}));

    if (!response.ok || !result?.ok) {
      const message = result?.error === 'wrong_account'
        ? (result?.message || 'Falsches Konto.')
        : (result?.message || result?.error || `http_${response.status}`);

      throw new Error(message);
    }

    showCsvUploadToast(csvImportedMessage(result.inserted), 'success', true);

    await applySelection(
      $('kategorie')?.value ?? selectedCategory ?? 'all',
      $('jahr')?.value ?? chartYear,
      { pushHistory: false, scrollTop: false }
    );
  } catch (e) {
    console.error(e);
    showCsvUploadToast(e?.message || 'CSV-Import fehlgeschlagen.', 'error', true);
  } finally {
    _csvUploadRunning = false;
    const freshTrigger = $('kontoCsvUploadTrigger');
    if (freshTrigger) freshTrigger.disabled = false;
  }
}

/* =========================================================
 * YEAR SERIES (Detail-Chart unten)
 * ========================================================= */
const TOP_N_INCOME  = 5;
const TOP_N_EXPENSE = 50;
const YEAR_LIMIT    = 10;

async function fetchYearSeries(kind, catLabel) {
  const base = window.location.pathname;
  const qs = new URLSearchParams({
    ajax: 'cat_years',
    kind,
    cat: catLabel === 'Unkategorisiert' ? 'unk' : catLabel,
    limit: String(YEAR_LIMIT),
  });
  const r = await fetch(`${base}?${qs.toString()}`, {
    method: 'GET',
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' }
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok || !j?.ok) throw new Error(j?.error || `http_${r.status}`);
  return j; // {years:[], values:[], cat_param: ...}
}

/* =========================================================
 * UI + BOTTOM CHARTS (Einnahmen/Ausgaben)
 * ========================================================= */
const ui = {
  income: {
    canvasId: 'incomePie',
    backId:   'incomeBack',
    titleId:  'incomeTitle',
    kpiId:    'incomeKpi',
    kind:     'income',
    chart:    null,
    origTitle: null,
    origKpi:   null,
    mode:     'overview'
  },
  expense: {
    canvasId: 'expensePie',
    backId:   'expenseBack',
    titleId:  'expenseTitle',
    kpiId:    'expenseKpi',
    kind:     'expense',
    chart:    null,
    origTitle: null,
    origKpi:   null,
    mode:     'overview'
  }
};

function setBackVisible(key, on) {
  const b = $(ui[key].backId);
  if (b) b.style.display = on ? '' : 'none';
}
function setTitle(key, t) {
  const el = $(ui[key].titleId);
  if (el) el.textContent = t;
}
function setKpi(key, t) {
  const el = $(ui[key].kpiId);
  if (el) el.textContent = t;
}

function destroyChart(key) {
  if (ui[key].chart) {
    ui[key].chart.destroy();
    ui[key].chart = null;
  }
}
function setKpiSubVisible(key, visible) {
  const el = $(`${key}KpiSub`);

  if (el) {
    el.hidden = !visible;
  }
}

function makeIncomeOverview() {
  const key = 'income';
  destroyChart(key);
  ui[key].mode = 'overview';
  setBackVisible(key, false);
  setTitle(key, ui[key].origTitle);
  setKpi(key, ui[key].origKpi);
  setKpiSubVisible(key, false);
  refreshKpiBreakdown(key);

  const top = topNWithRestDual(incomeLabels, incomeValues, incomeValuesClosed, TOP_N_INCOME);
  const total = sumArr(top.values);

  ui[key].chart = new Chart($(ui[key].canvasId).getContext('2d'), {
    type: 'bar',
    data: {
      labels: top.labels,
      datasets: [{
        data: top.values,
        backgroundColor: (ctx) => {
          const label = ctx.chart.data.labels?.[ctx.dataIndex];
          return (label && label !== 'Rest') ? hexToRgba(PRIMARY, 0.35) : 'rgba(0,0,0,0.08)';
        },
        borderColor: (ctx) => {
          const label = ctx.chart.data.labels?.[ctx.dataIndex];
          return (label && label !== 'Rest') ? PRIMARY : 'rgba(0,0,0,0.15)';
        },
        borderWidth: 1,
        hoverBackgroundColor: (ctx) => {
          const label = ctx.chart.data.labels?.[ctx.dataIndex];
          return (label && label !== 'Rest') ? PRIMARY : 'rgba(0,0,0,0.08)';
        },
        hoverBorderColor: (ctx) => {
          const label = ctx.chart.data.labels?.[ctx.dataIndex];
          return (label && label !== 'Rest') ? PRIMARY : 'rgba(0,0,0,0.15)';
        },
        hoverBorderWidth: (ctx) => {
          const label = ctx.chart.data.labels?.[ctx.dataIndex];
          return (label && label !== 'Rest') ? 2 : 1;
        },
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      onHover: (evt, elements, chart) => {
        chart.canvas.style.cursor = isClickableBar(chart, elements) ? 'pointer' : 'default';
      },
      indexAxis: 'y',
      scales: {
        x: { beginAtZero: true, ticks: { callback: (v) => fmtEuroAxis(v) } },
        y: { ticks: { autoSkip: false } }
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const val = Number(ctx.parsed.x);
              const idx = ctx.dataIndex;
              const valClosed = Number(top.valuesClosed?.[idx] ?? val);

              const pct = total ? (val / total * 100) : 0;

              const months = completedMonthsForYear(chartYear);
              const perMonth = fmtMonthlyAvg(valClosed, months);

              return [
                `Ø pro Monat: ${perMonth}`,
                `${selectionTotalLabel()}: ${fmtEuro(val)}`,
                `Anteil Einnahmen: ${pct.toFixed(1)} %`
              ];
            }
          }
        }
      },
      onClick: async (evt, elements, chart) => {
        if (!elements?.length) return;
        const label = chart.data.labels?.[elements[0].index];
        if (!label || label === 'Rest') return;
        await showYearDetail(key, label);
      }
    }
  });
}

function makeExpenseOverview() {
  const key = 'expense';
  destroyChart(key);
  ui[key].mode = 'overview';
  setBackVisible(key, false);
  setTitle(key, ui[key].origTitle);
  setKpi(key, ui[key].origKpi);
  setKpiSubVisible(key, false);
  refreshKpiBreakdown(key);

  const top = topNWithRestDual(expenseLabels, expenseValues, expenseValuesClosed, TOP_N_EXPENSE);
  const total = sumArr(top.values);
  const positive = top.values.filter(v => v > 0);
  const minVal = positive.length ? Math.min(...positive) : 0.01;

  let minY = Math.pow(10, Math.floor(Math.log10(minVal)));
  if (!isFinite(minY) || minY <= 0) minY = 0.01;

  ui[key].chart = new Chart($(ui[key].canvasId).getContext('2d'), {
    type: 'bar',
    data: {
      labels: top.labels,
      datasets: [{
        data: top.values,
        backgroundColor: (ctx) => {
          const label = ctx.chart.data.labels?.[ctx.dataIndex];
          return (label && label !== 'Rest') ? hexToRgba(PRIMARY, 0.35) : 'rgba(0,0,0,0.08)';
        },
        borderColor: (ctx) => {
          const label = ctx.chart.data.labels?.[ctx.dataIndex];
          return (label && label !== 'Rest') ? PRIMARY : 'rgba(0,0,0,0.15)';
        },
        borderWidth: 1,
        hoverBackgroundColor: (ctx) => {
          const label = ctx.chart.data.labels?.[ctx.dataIndex];
          return (label && label !== 'Rest') ? PRIMARY : 'rgba(0,0,0,0.08)';
        },
        hoverBorderColor: (ctx) => {
          const label = ctx.chart.data.labels?.[ctx.dataIndex];
          return (label && label !== 'Rest') ? PRIMARY : 'rgba(0,0,0,0.15)';
        },
        hoverBorderWidth: (ctx) => {
          const label = ctx.chart.data.labels?.[ctx.dataIndex];
          return (label && label !== 'Rest') ? 2 : 1;
        },
        barPercentage: 0.9,
        categoryPercentage: 0.8
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      onHover: (evt, elements, chart) => {
        chart.canvas.style.cursor = isClickableBar(chart, elements) ? 'pointer' : 'default';
      },
      layout: { padding: { bottom: 8 } },
      scales: {
        x: {
          ticks: {
            autoSkip: false,
            maxRotation: 0,
            minRotation: 0,
            padding: 6,
            callback: function(value, index) {
              const label = this.getLabelForValue(value);
              return staggeredTick(label, index, 14);
            }
          }
        },
        y: {
          type: 'logarithmic',
          min: minY,
          ticks: {
            callback: (v) => fmtEuroAxis(v),
            maxTicksLimit: 5
          }
        }
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: (items) => items?.[0]?.label ?? '',
            label: (ctx) => {
              const val = Number(ctx.parsed.y);
              const idx = ctx.dataIndex;
              const valClosed = Number(top.valuesClosed?.[idx] ?? val);

              const pct = total ? (val / total * 100) : 0;

              const months = completedMonthsForYear(chartYear);
              const perMonth = fmtMonthlyAvg(valClosed, months);

              return [
                `Ø pro Monat: ${perMonth}`,
                `${selectionTotalLabel()}: ${fmtEuro(val)}`,
                `Anteil Ausgaben: ${pct.toFixed(1)} %`
              ];
            }
          }
        }
      },
      onClick: async (evt, elements, chart) => {
        if (!elements?.length) return;
        const label = chart.data.labels?.[elements[0].index];
        if (!label || label === 'Rest') return;
        await showYearDetail(key, label);
      }
    }
  });
}

async function showYearDetail(key, catLabel) {
  destroyChart(key);
  ui[key].mode = 'detail';
  setBackVisible(key, true);

  const kind = ui[key].kind;
  const titleBase = (key === 'income') ? 'Einnahmen' : 'Ausgaben';

  setTitle(key, `${titleBase} - ${catLabel}`);
  setKpi(key, '…');
  setKpiSubVisible(key, false);
  setKpiBreakdownEnabled(key, false);

  let data;

  try {
    data = await fetchYearSeries(kind, catLabel);
  } catch (e) {
    console.error(e);
    setKpi(key, 'Fehler');

    if (key === 'income') {
      makeIncomeOverview();
    } else {
      makeExpenseOverview();
    }

    return;
  }

  const catParamForJump =
    data && data.cat_param != null
      ? data.cat_param
      : null;

  const years = (data.years || []).map(Number);

  let valuesFull = (data.values || []).map(Number);
  let valuesClosed = (
    data.values_closed
    || data.values
    || []
  ).map(Number);

  /*
   * Ausgaben kommen aus PHP als negative Werte.
   * Für die Darstellung werden sie positiv angezeigt.
   */
  if (key === 'expense') {
    valuesFull = valuesFull.map(value => value * -1);
    valuesClosed = valuesClosed.map(value => value * -1);
  }

  /*
   * Grundlage des Diagramms:
   * Jahreswert geteilt durch die Anzahl abgeschlossener Monate.
   *
   * Vergangene Jahre: 12 Monate
   * Aktuelles Jahr: nur vollständig abgeschlossene Monate
   */
  const yearlyMonthlyAverages = years.map((year, index) => {
    const months = completedMonthsForYear(year);

    if (months <= 0) {
      return null;
    }

    const closedValue = Number(valuesClosed[index] ?? 0);
    const average = closedValue / months;

    return Math.round(average * 100) / 100;
  });

  /*
   * KPI oben rechts:
   * arithmetischer Mittelwert aller dargestellten
   * Jahres-Monatsdurchschnitte.
   */
  const validAverages = yearlyMonthlyAverages.filter(
    value => Number.isFinite(value)
  );

  const overallAverage = validAverages.length > 0
    ? sumArr(validAverages) / validAverages.length
    : 0;

  setKpi(key, fmtEuro(overallAverage));
  setKpiSubVisible(key, true);

  ui[key].chart = new Chart(
    $(ui[key].canvasId).getContext('2d'),
    {
      type: 'line',

      data: {
        labels: years.map(String),

        datasets: [{
          label: `Ø pro Monat (${catLabel})`,
          data: yearlyMonthlyAverages,

          borderColor: PRIMARY,
          borderWidth: 3,

          tension: 1,

          pointRadius: 5,
          pointHoverRadius: 6,
          pointHitRadius: 10,

          fill: true,
          backgroundColor: hexToRgba(PRIMARY, 0.12),

          cubicInterpolationMode: 'monotone',
          spanGaps: false
        }]
      },

      options: {
        responsive: true,
        maintainAspectRatio: false,

        interaction: {
          mode: 'nearest',
          axis: 'x',
          intersect: false
        },

        hover: {
          mode: 'nearest',
          axis: 'x',
          intersect: false
        },

        plugins: {
          tooltip: {
            mode: 'x',
            intersect: false,

            callbacks: {
              label: (ctx) => {
                const index = ctx.dataIndex;
                const year = Number(ctx.label);

                const monthlyAverage = Number(
                  yearlyMonthlyAverages[index] ?? 0
                );

                const absoluteValue = Number(
                  valuesFull[index] ?? 0
                );

                const closedValue = Number(
                  valuesClosed[index] ?? absoluteValue
                );

                const months = completedMonthsForYear(year);

                const monthLabel = months === 12
                  ? 'Gesamtes Jahr'
                  : months === 1
                    ? '1 abgeschlossener Monat'
                    : `${months} abgeschlossene Monate`;

                return [
                  `Ø pro Monat: ${fmtEuro(monthlyAverage)}`,
                  `Gesamt ${year}: ${fmtEuro(absoluteValue)}`,
                  `Berechnungsbasis: ${fmtEuro(closedValue)}`,
                  `Zeitraum: ${monthLabel}`
                ];
              }
            }
          }
        },

        scales: {
          x: {
            ticks: {
              maxRotation: 0,
              minRotation: 0
            }
          },

          y: {
            beginAtZero: true,

            ticks: {
              callback: value => fmtEuroAxis(value)
            }
          }
        },

        onClick: (evt, elements, chart) => {
          if (!elements?.length) {
            return;
          }

          const year = Number(
            chart.data.labels?.[elements[0].index]
          );

          if (!Number.isFinite(year) || year < 1900) {
            return;
          }

          if (catParamForJump == null) {
            return;
          }

          applySelection(
            catParamForJump,
            year,
            {
              pushHistory: true,
              scrollTop: true
            }
          );
        }
      }
    }
  );

  const backBtn = $(ui[key].backId);

  if (backBtn) {
    backBtn.onclick = () => {
      backBtn.onclick = null;

      if (key === 'income') {
        makeIncomeOverview();
      } else {
        makeExpenseOverview();
      }
    };
  }
}

/* =========================================================
 * Initialisierung
 * ========================================================= */
document.addEventListener('DOMContentLoaded', () => {
  ui.income.origTitle  = $(ui.income.titleId)?.textContent ?? 'Einnahmen';
  ui.income.origKpi    = $(ui.income.kpiId)?.textContent ?? fmtEuro(sumArr(incomeValues));
  ui.expense.origTitle = $(ui.expense.titleId)?.textContent ?? 'Ausgaben (Logarithmisch)';
  ui.expense.origKpi   = $(ui.expense.kpiId)?.textContent ?? fmtEuro(sumArr(expenseValues));

  makeIncomeOverview();
  makeExpenseOverview();

  const selCat = $('kategorie');
  const selYear = $('jahr');
  const form = $('statsFilterForm');
  const uploadInput = $('kontoCsvUploadInput');
  const saldoDetails = $('pageTitleSaldoDetails');
  const accountUpdateModal = $('kontoUpdateModal');
  const accountUpdateForm = $('kontoUpdateForm');
  const accountUpdateClose = $('kontoUpdateModalClose');
  const accountUpdateCancel = $('kontoUpdateCancel');

  if (saldoDetails) {
    saldoDetails.addEventListener('click', (e) => {
      const uploadTrigger = e.target.closest('#kontoCsvUploadTrigger');
      if (uploadTrigger) {
        if (!_csvUploadRunning && uploadInput) uploadInput.click();
        return;
      }

      const accountTrigger = e.target.closest('.dashboard-account-update-trigger');
      if (accountTrigger) {
        openAccountUpdateModal(accountTrigger);
      }
    });
  }

  if (accountUpdateForm) {
    accountUpdateForm.addEventListener('submit', (e) => {
      e.preventDefault();
      submitAccountUpdate(accountUpdateForm);
    });
  }

  if (accountUpdateClose) {
    accountUpdateClose.addEventListener('click', closeAccountUpdateModal);
    accountUpdateClose.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        closeAccountUpdateModal();
      }
    });
  }

  if (accountUpdateCancel) {
    accountUpdateCancel.addEventListener('click', closeAccountUpdateModal);
  }

  if (accountUpdateModal) {
    accountUpdateModal.addEventListener('click', (e) => {
      if (e.target === accountUpdateModal) closeAccountUpdateModal();
    });
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && accountUpdateModal && !accountUpdateModal.classList.contains('hidden')) {
      closeAccountUpdateModal();
    }
  });

  if (uploadInput) {
    uploadInput.addEventListener('change', () => {
      const file = uploadInput.files?.[0] || null;
      uploadInput.value = '';
      uploadCsvFile(file);
    });
  }

  if (form) {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      applySelection(selCat?.value ?? 'all', selYear?.value ?? chartYear, { pushHistory: true, scrollTop: false });
    });
  }

  if (selCat) {
    selCat.addEventListener('change', () => {
      applySelection(selCat.value, selYear?.value ?? chartYear, { pushHistory: true, scrollTop: false });
    });
  }
  if (selYear) {
    selYear.addEventListener('change', () => {
      applySelection(selCat?.value ?? 'all', selYear.value, { pushHistory: true, scrollTop: false });
    });
  }
});

window.addEventListener('popstate', () => {
  const u = new URL(window.location.href);
  const yearRaw = u.searchParams.get('jahr') || String(chartYear);
  const year = yearRaw === 'all' ? 'all' : Number(yearRaw);
  const cat = (u.searchParams.get('kategorie') || 'all');
  applySelection(cat, year, { pushHistory: false, scrollTop: false });
});
</script>


</body>
</html>