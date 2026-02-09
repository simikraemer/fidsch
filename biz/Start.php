<?php
// biz/Start.php (Jahresstatistik)

// 1) Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// 2) DB
require_once __DIR__ . '/../db.php';
$bizconn->set_charset('utf8mb4');

// === NEU: Cutoff für "abgeschlossene Monate" (aktuelles Jahr) ===
$CURRENT_YEAR   = (int)date('Y');
$CURRENT_MONTH  = (int)date('n'); // 1..12
$CLOSED_CUTOFF  = sprintf('%04d-%02d-01', $CURRENT_YEAR, $CURRENT_MONTH); // 1. Tag aktueller Monat

// 2.5) AJAX Kategorien Jahresverlauf
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

    // >>> cat_param zurückgeben (id oder 'unk')
    $catParamReturn = null;
    $katIdBound     = null; // <<< NEU (für closed-query)

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

    // === NEU: closed-values (im aktuellen Jahr nur bis < 1. aktueller Monat) ===
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
        'values_closed' => $valuesClosed, // <<< NEU
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
 * 2.6) AJAX Page-Update (ohne Reload) für oben + Pies
 * ========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'page_data') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');

    $jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');
    $selectedKat = $_GET['kategorie'] ?? 'all';
    if ($selectedKat !== 'all' && $selectedKat !== 'unk') {
        $selectedKat = (int)$selectedKat;
    }

    $isCurrentYear = ((int)$jahr === $CURRENT_YEAR);

    // verfügbare Jahre (nur vorhandene Daten)
    $yearsRes = $bizconn->query("
        SELECT DISTINCT YEAR(valutadatum) AS jahr
        FROM transfers
        WHERE valutadatum IS NOT NULL
        ORDER BY jahr DESC
    ");
    $jahre = [];
    while ($r = $yearsRes->fetch_assoc()) { $jahre[] = (int)$r['jahr']; }
    if (!in_array($jahr, $jahre, true) && !empty($jahre)) { $jahr = $jahre[0]; }
    $isCurrentYear = ((int)$jahr === $CURRENT_YEAR);

    // Kategorien mapping
    $kats = [];
    $katRes = $bizconn->query("SELECT id, name FROM kategorien");
    while ($k = $katRes->fetch_assoc()) { $kats[(int)$k['id']] = $k['name']; }
    $labelUnk = 'Unkategorisiert';

    $selectedKatLabel = 'Kontostand';
    if ($selectedKat === 'unk') {
        $selectedKatLabel = $labelUnk;
    } elseif ($selectedKat !== 'all' && isset($kats[(int)$selectedKat])) {
        $selectedKatLabel = $kats[(int)$selectedKat];
    }

    // Anfangsbestand bis 01.01.jahr
    $startDate = sprintf('%04d-01-01', $jahr);
    $startStmt = $bizconn->prepare("
        SELECT COALESCE(SUM(betrag),0) AS summe
        FROM transfers
        WHERE valutadatum < ?
    ");
    $startStmt->bind_param('s', $startDate);
    $startStmt->execute();
    $startStmt->bind_result($anfangsbestand);
    $startStmt->fetch();
    $startStmt->close();
    $anfangsbestand = (float)$anfangsbestand;

    // Transfers im Jahr (einmalig iterieren)
    $stmt = $bizconn->prepare("
        SELECT valutadatum, betrag, kategorie_id
        FROM transfers
        WHERE YEAR(valutadatum) = ?
        ORDER BY valutadatum ASC
    ");
    $stmt->bind_param('i', $jahr);
    $stmt->execute();
    $res = $stmt->get_result();

    $transfersByDate    = [];
    $catTransfersByDate = [];
    $kategorieSummen    = [];
    $kategorieSummenClosed = []; // <<< NEU

    while ($row = $res->fetch_assoc()) {
        $dRaw   = (string)$row['valutadatum'];
        $dKey   = substr($dRaw, 0, 10); // YYYY-MM-DD
        $betrag = (float)$row['betrag'];

        if (!isset($transfersByDate[$dKey])) $transfersByDate[$dKey] = 0.0;
        $transfersByDate[$dKey] += $betrag;

        // Summen je Kategorie
        $katName = $labelUnk;
        if ($row['kategorie_id'] !== null) {
            $katId = (int)$row['kategorie_id'];
            if (isset($kats[$katId])) $katName = $kats[$katId];
        }
        if (!isset($kategorieSummen[$katName])) $kategorieSummen[$katName] = 0.0;
        $kategorieSummen[$katName] += $betrag;

        // Closed-Monate Summen (nur aktuelles Jahr: < 1. aktueller Monat)
        $inClosed = (!$isCurrentYear) || ($dKey < $CLOSED_CUTOFF);
        if ($inClosed) {
            if (!isset($kategorieSummenClosed[$katName])) $kategorieSummenClosed[$katName] = 0.0;
            $kategorieSummenClosed[$katName] += $betrag;
        }

        // Kategorie-Zeitreihe (wenn ausgewählt)
        if ($selectedKat !== 'all') {
            $match = false;
            if ($selectedKat === 'unk') {
                $match = ($row['kategorie_id'] === null);
            } else {
                $match = ((int)$row['kategorie_id'] === (int)$selectedKat);
            }
            if ($match) {
                if (!isset($catTransfersByDate[$dKey])) $catTransfersByDate[$dKey] = 0.0;
                $catTransfersByDate[$dKey] += $betrag;
            }
        }
    }
    $stmt->close();

    // Einnahmen/Ausgaben trennen (für Pies)
    $incomeByCat  = [];
    $expenseByCat = [];
    foreach ($kategorieSummen as $kat => $summe) {
        if ($summe > 0) $incomeByCat[$kat] = $summe;
        elseif ($summe < 0) $expenseByCat[$kat] = abs($summe);
    }
    arsort($incomeByCat);
    arsort($expenseByCat);

    // NEU: closed-Varianten
    $incomeByCatClosed  = [];
    $expenseByCatClosed = [];
    foreach ($kategorieSummenClosed as $kat => $summe) {
        if ($summe > 0) $incomeByCatClosed[$kat] = $summe;
        elseif ($summe < 0) $expenseByCatClosed[$kat] = abs($summe);
    }
    arsort($incomeByCatClosed);
    arsort($expenseByCatClosed);

    // Labels in der Reihenfolge der "vollen" Arrays (damit JS stabil bleibt)
    $incomeLabels = array_keys($incomeByCat);
    $expenseLabels = array_keys($expenseByCat);

    $incomeValues = array_map(fn($v)=>round((float)$v,2), array_values($incomeByCat));
    $expenseValues = array_map(fn($v)=>round((float)$v,2), array_values($expenseByCat));

    $incomeValuesClosed = [];
    foreach ($incomeLabels as $lab) $incomeValuesClosed[] = round((float)($incomeByCatClosed[$lab] ?? 0.0), 2);

    $expenseValuesClosed = [];
    foreach ($expenseLabels as $lab) $expenseValuesClosed[] = round((float)($expenseByCatClosed[$lab] ?? 0.0), 2);

    // letztes Valutadatum in diesem Jahr
    $lastDateRow = $bizconn->query("
        SELECT MAX(valutadatum) AS lastdate
        FROM transfers
        WHERE YEAR(valutadatum) = {$jahr}
    ")->fetch_assoc();
    $lastDate = $lastDateRow['lastdate'] ? new DateTime($lastDateRow['lastdate']) : new DateTime("$jahr-01-01");

    // tägliche kumulierte Serie (gesamt)
    $dailySeries = [];
    $cumDaily   = $anfangsbestand;
    $start      = new DateTime("$jahr-01-01");
    $end        = new DateTime("$jahr-12-31");

    $cursor = clone $start;
    while ($cursor <= $end) {
        $dstr = $cursor->format('Y-m-d');
        if ($cursor <= $lastDate) {
            if (isset($transfersByDate[$dstr])) $cumDaily += $transfersByDate[$dstr];
            $dailySeries[$dstr] = round($cumDaily, 2);
        } else {
            $dailySeries[$dstr] = null;
        }
        $cursor->modify('+1 day');
    }

    // tägliche kumulierte Serie für ausgewählte Kategorie
    $catDailySeries = [];
    if ($selectedKat !== 'all') {
        $catCum  = 0.0;
        $cursor2 = clone $start;
        while ($cursor2 <= $end) {
            $dstr = $cursor2->format('Y-m-d');
            if ($cursor2 <= $lastDate) {
                if (isset($catTransfersByDate[$dstr])) $catCum += $catTransfersByDate[$dstr];
                $catDailySeries[$dstr] = round($catCum, 2);
            } else {
                $catDailySeries[$dstr] = null;
            }
            $cursor2->modify('+1 day');
        }
    }

    // Monats-Punkte
    $monthlyPoints = [];
    $firstOfYear = sprintf('%04d-01-01', $jahr);
    $monthlyPoints[$firstOfYear] = $dailySeries[$firstOfYear] ?? $anfangsbestand;

    for ($m = 2; $m <= 12; $m++) {
        $d = sprintf('%04d-%02d-01', $jahr, $m);
        if (array_key_exists($d, $dailySeries) && $dailySeries[$d] !== null) {
            $monthlyPoints[$d] = $dailySeries[$d];
        }
    }
    $heute  = new DateTime('today');
    $eoy    = new DateTime(sprintf('%04d-12-31', $jahr));
    $eoyStr = $eoy->format('Y-m-d');
    if ($eoy <= $heute) {
        $valEoy = $dailySeries[$eoyStr] ?? null;
        if ($valEoy === null) {
            $lastKnown = $anfangsbestand;
            foreach (array_reverse($dailySeries, true) as $dateKey => $val) {
                if ($val !== null) { $lastKnown = $val; break; }
            }
            $monthlyPoints[$eoyStr] = $lastKnown;
        } else {
            $monthlyPoints[$eoyStr] = $valEoy;
        }
    }

    // Kontostand bis EOY (gesamt, für Header)
    $endOfYear = sprintf('%04d-12-31', $jahr);
    $sumStmt = $bizconn->prepare("
        SELECT COALESCE(SUM(betrag), 0) AS summe
        FROM transfers
        WHERE valutadatum IS NOT NULL
          AND valutadatum <= ?
    ");
    $sumStmt->bind_param('s', $endOfYear);
    $sumStmt->execute();
    $sumStmt->bind_result($summeAlleBisEOY);
    $sumStmt->fetch();
    $sumStmt->close();
    $kontostandBisEndeDesJahres = (float)$summeAlleBisEOY;

    $toXY = static function(array $series): array {
        $out = [];
        foreach ($series as $d => $v) $out[] = ['x' => $d, 'y' => $v];
        return $out;
    };

    echo json_encode([
        'ok' => true,

        'jahr' => (int)$jahr,
        'kategorie' => $selectedKat,
        'kategorie_label' => $selectedKatLabel,
        'kontostand_eoy' => round($kontostandBisEndeDesJahres, 2),

        'daily' => $toXY($dailySeries),
        'monthly' => $toXY($monthlyPoints),
        'cat_daily' => $toXY($catDailySeries),

        // pies (voll)
        'income_labels' => $incomeLabels,
        'income_values' => array_values($incomeValues),
        'expense_labels' => $expenseLabels,
        'expense_values' => array_values($expenseValues),

        // pies (closed)
        'income_values_closed' => array_values($incomeValuesClosed),   // <<< NEU
        'expense_values_closed' => array_values($expenseValuesClosed), // <<< NEU
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3) Input + Datenbeschaffung (kein Output davor!)
$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');

$selectedKat = $_GET['kategorie'] ?? 'all';
if ($selectedKat !== 'all' && $selectedKat !== 'unk') {
    $selectedKat = (int)$selectedKat;
}

$isCurrentYear = ((int)$jahr === $CURRENT_YEAR);

// verfügbare Jahre (nur vorhandene Daten)
$yearsRes = $bizconn->query("
    SELECT DISTINCT YEAR(valutadatum) AS jahr
    FROM transfers
    WHERE valutadatum IS NOT NULL
    ORDER BY jahr DESC
");
$jahre = [];
while ($r = $yearsRes->fetch_assoc()) { $jahre[] = (int)$r['jahr']; }
if (!in_array($jahr, $jahre, true) && !empty($jahre)) { $jahr = $jahre[0]; }
$isCurrentYear = ((int)$jahr === $CURRENT_YEAR);

// alle Buchungen des Jahres
$stmt = $bizconn->prepare("
    SELECT valutadatum, betrag, kategorie_id
    FROM transfers
    WHERE YEAR(valutadatum) = ?
    ORDER BY valutadatum ASC
");
$stmt->bind_param('i', $jahr);
$stmt->execute();
$res = $stmt->get_result();

// Kategorie-Namen
$kats = [];
$katRes = $bizconn->query("SELECT id, name FROM kategorien");
while ($k = $katRes->fetch_assoc()) { $kats[(int)$k['id']] = $k['name']; }
$labelUnk = 'Unkategorisiert';

// Summen je Kategorie aufbauen (voll + closed)
$kategorieSummen = [];
$kategorieSummenClosed = []; // <<< NEU
while ($row = $res->fetch_assoc()) {
    $betrag  = (float)$row['betrag'];
    $dRaw    = (string)$row['valutadatum'];
    $dKey    = substr($dRaw, 0, 10); // YYYY-MM-DD

    $katName = $labelUnk;
    if ($row['kategorie_id'] !== null) {
        $katId = (int)$row['kategorie_id'];
        if (isset($kats[$katId])) $katName = $kats[$katId];
    }

    if (!isset($kategorieSummen[$katName])) $kategorieSummen[$katName] = 0.0;
    $kategorieSummen[$katName] += $betrag;

    $inClosed = (!$isCurrentYear) || ($dKey < $CLOSED_CUTOFF);
    if ($inClosed) {
        if (!isset($kategorieSummenClosed[$katName])) $kategorieSummenClosed[$katName] = 0.0;
        $kategorieSummenClosed[$katName] += $betrag;
    }
}

// Label für aktuell ausgewählte Kategorie
$selectedKatLabel = 'Alle Kategorien';
if ($selectedKat === 'unk') {
    $selectedKatLabel = $labelUnk;
} elseif ($selectedKat !== 'all' && isset($kats[(int)$selectedKat])) {
    $selectedKatLabel = $kats[(int)$selectedKat];
}

// Einnahmen/Ausgaben trennen (voll)
$incomeByCat  = [];
$expenseByCat = [];
foreach ($kategorieSummen as $kat => $summe) {
    if ($summe > 0) $incomeByCat[$kat] = $summe;
    elseif ($summe < 0) $expenseByCat[$kat] = abs($summe);
}

// Einnahmen/Ausgaben trennen (closed)
$incomeByCatClosed  = [];
$expenseByCatClosed = [];
foreach ($kategorieSummenClosed as $kat => $summe) {
    if ($summe > 0) $incomeByCatClosed[$kat] = $summe;
    elseif ($summe < 0) $expenseByCatClosed[$kat] = abs($summe);
}

// Anfangsbestand bis 01.01.jahr
$startDate = sprintf('%04d-01-01', $jahr);
$startStmt = $bizconn->prepare("
    SELECT COALESCE(SUM(betrag),0) AS summe
    FROM transfers
    WHERE valutadatum < ?
");
$startStmt->bind_param('s', $startDate);
$startStmt->execute();
$startStmt->bind_result($anfangsbestand);
$startStmt->fetch();
$startStmt->close();
$anfangsbestand = (float)$anfangsbestand;

// Transfers erneut für Zeitreihe (Cursor benötigt)
$stmt2 = $bizconn->prepare("
    SELECT valutadatum, betrag, kategorie_id
    FROM transfers
    WHERE YEAR(valutadatum) = ?
    ORDER BY valutadatum ASC
");
$stmt2->bind_param('i', $jahr);
$stmt2->execute();
$res2 = $stmt2->get_result();

// Transfers pro Datum gruppieren (gesamt + ausgewählte Kategorie)
$transfersByDate    = [];
$catTransfersByDate = [];

while ($row = $res2->fetch_assoc()) {
    $dRaw   = (string)$row['valutadatum'];
    $d      = substr($dRaw, 0, 10); // YYYY-MM-DD
    $betrag = (float)$row['betrag'];

    if (!isset($transfersByDate[$d])) $transfersByDate[$d] = 0.0;
    $transfersByDate[$d] += $betrag;

    if ($selectedKat !== 'all') {
        $match = false;
        if ($selectedKat === 'unk') {
            $match = ($row['kategorie_id'] === null);
        } else {
            $match = ((int)$row['kategorie_id'] === (int)$selectedKat);
        }
        if ($match) {
            if (!isset($catTransfersByDate[$d])) $catTransfersByDate[$d] = 0.0;
            $catTransfersByDate[$d] += $betrag;
        }
    }
}
$stmt2->close();

// letztes Valutadatum in diesem Jahr (unverändert)
$lastDateRow = $bizconn->query("
    SELECT MAX(valutadatum) AS lastdate
    FROM transfers
    WHERE YEAR(valutadatum) = {$jahr}
")->fetch_assoc();
$lastDate = $lastDateRow['lastdate'] ? new DateTime($lastDateRow['lastdate']) : new DateTime("$jahr-01-01");

// tägliche kumulierte Serie (gesamt, unverändert)
$dailySeries = [];
$cumDaily   = $anfangsbestand;
$start      = new DateTime("$jahr-01-01");
$end        = new DateTime("$jahr-12-31");

$cursor = clone $start;
while ($cursor <= $end) {
    $dstr = $cursor->format('Y-m-d');
    if ($cursor <= $lastDate) {
        if (isset($transfersByDate[$dstr])) $cumDaily += $transfersByDate[$dstr];
        $dailySeries[$dstr] = round($cumDaily, 2);
    } else {
        $dailySeries[$dstr] = null;
    }
    $cursor->modify('+1 day');
}

// tägliche kumulierte Serie für ausgewählte Kategorie
$catDailySeries = [];
if ($selectedKat !== 'all') {
    $catCum  = 0.0;
    $cursor2 = clone $start;
    while ($cursor2 <= $end) {
        $dstr = $cursor2->format('Y-m-d');
        if ($cursor2 <= $lastDate) {
            if (isset($catTransfersByDate[$dstr])) {
                $catCum += $catTransfersByDate[$dstr];
            }
            $catDailySeries[$dstr] = round($catCum, 2);
        } else {
            $catDailySeries[$dstr] = null;
        }
        $cursor2->modify('+1 day');
    }
}

// Monats-Punkte (01.01., jeder 1., ggf. 31.12. falls vergangen)
$monthlyPoints = [];
$firstOfYear = sprintf('%04d-01-01', $jahr);
$monthlyPoints[$firstOfYear] = $dailySeries[$firstOfYear] ?? $anfangsbestand;

for ($m = 2; $m <= 12; $m++) {
    $d = sprintf('%04d-%02d-01', $jahr, $m);
    if (array_key_exists($d, $dailySeries) && $dailySeries[$d] !== null) {
        $monthlyPoints[$d] = $dailySeries[$d];
    }
}
$heute  = new DateTime('today');
$eoy    = new DateTime(sprintf('%04d-12-31', $jahr));
$eoyStr = $eoy->format('Y-m-d');
if ($eoy <= $heute) {
    $valEoy = $dailySeries[$eoyStr] ?? null;
    if ($valEoy === null) {
        $lastKnown = $anfangsbestand;
        foreach (array_reverse($dailySeries, true) as $dateKey => $val) {
            if ($val !== null) { $lastKnown = $val; break; }
        }
        $monthlyPoints[$eoyStr] = $lastKnown;
    } else {
        $monthlyPoints[$eoyStr] = $valEoy;
    }
}

// JSON für Charts
$dailyJson = json_encode(
    array_map(fn($d,$v)=>['x'=>$d,'y'=>$v], array_keys($dailySeries), $dailySeries),
    JSON_UNESCAPED_UNICODE
);
$monthlyJson = json_encode(
    array_map(fn($d,$v)=>['x'=>$d,'y'=>$v], array_keys($monthlyPoints), $monthlyPoints),
    JSON_UNESCAPED_UNICODE
);
$catDailyJson = json_encode(
    array_map(fn($d,$v)=>['x'=>$d,'y'=>$v], array_keys($catDailySeries), $catDailySeries),
    JSON_UNESCAPED_UNICODE
);

// Pies vorbereiten (voll)
arsort($incomeByCat);
arsort($expenseByCat);

// Labels (voll) + Values (voll)
$incomeLabels = array_keys($incomeByCat);
$expenseLabels = array_keys($expenseByCat);

$incomeValues = array_map(fn($v)=>round((float)$v,2), array_values($incomeByCat));
$expenseValues = array_map(fn($v)=>round((float)$v,2), array_values($expenseByCat));

$incomeLabelsJson  = json_encode($incomeLabels, JSON_UNESCAPED_UNICODE);
$incomeValuesJson  = json_encode($incomeValues, JSON_UNESCAPED_UNICODE);
$expenseLabelsJson = json_encode($expenseLabels, JSON_UNESCAPED_UNICODE);
$expenseValuesJson = json_encode($expenseValues, JSON_UNESCAPED_UNICODE);

// Pies closed (aligned zu full-labels)
$incomeValuesClosed = [];
foreach ($incomeLabels as $lab) $incomeValuesClosed[] = round((float)($incomeByCatClosed[$lab] ?? 0.0), 2);
$expenseValuesClosed = [];
foreach ($expenseLabels as $lab) $expenseValuesClosed[] = round((float)($expenseByCatClosed[$lab] ?? 0.0), 2);

$incomeValuesClosedJson  = json_encode($incomeValuesClosed, JSON_UNESCAPED_UNICODE);
$expenseValuesClosedJson = json_encode($expenseValuesClosed, JSON_UNESCAPED_UNICODE);

// Kontostand bis EOY (gesamt)
$endOfYear = sprintf('%04d-12-31', $jahr);
$sumStmt = $bizconn->prepare("
    SELECT COALESCE(SUM(betrag), 0) AS summe
    FROM transfers
    WHERE valutadatum IS NOT NULL
      AND valutadatum <= ?
");
$sumStmt->bind_param('s', $endOfYear);
$sumStmt->execute();
$sumStmt->bind_result($summeAlleBisEOY);
$sumStmt->fetch();
$sumStmt->close();
$kontostandBisEndeDesJahres = (float)$summeAlleBisEOY;

function euro($v) { return number_format((float)$v, 2, ',', '.').' €'; }

// 4) Rendering starten
$page_title = 'Finanzen';
require_once __DIR__ . '/../head.php';    // <!DOCTYPE html> … <body>
require_once __DIR__ . '/../navbar.php';  // Navbar
?>


<div id="statsPage" class="lt-page dashboard-page">
    <div class="lt-topbar">
        <h1 class="ueberschrift dashboard-title">
          <span class="dashboard-title-main" id="pageTitleYear">
            Finanzen <?= htmlspecialchars((string)$jahr, ENT_QUOTES, 'UTF-8') ?>
          </span>
          <span class="dashboard-title-soft" id="pageTitleSaldo">
            | <?= euro($kontostandBisEndeDesJahres) ?>
          </span>
        </h1>

        <form method="get" class="dashboard-filterform" id="statsFilterForm">
          <div class="lt-yearwrap">
            <label for="kategorie" class="lt-label">Kategorie</label>
            <!-- onchange submit RAUS (JS übernimmt) -->
            <select name="kategorie" id="kategorie" class="kategorie-select">
              <option value="all" <?= ($selectedKat === 'all') ? 'selected' : '' ?>>Kontostand</option>
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
            <!-- onchange submit RAUS (JS übernimmt) -->
            <select name="jahr" id="jahr" class="kategorie-select">
              <?php foreach ($jahre as $j): ?>
                <?php if ((int)$j <= 2021) continue; ?>
                <option value="<?= (int)$j ?>" <?= ((int)$j === (int)$jahr) ? 'selected' : '' ?>>
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
          <button type="button" class="chart-back" id="incomeBack" style="display:none" title="Zurück" aria-label="Zurück">&larr;</button>
          <span class="dashboard-pie-kpi-label" id="incomeTitle">Einnahmen</span>
          <span class="dashboard-pie-kpi-value" id="incomeKpi"><?= euro(array_sum($incomeByCat)) ?></span>
        </div>
        <div class="dashboard-pie-wrap">
          <canvas id="incomePie"></canvas>
        </div>
      </div>

      <div class="dashboard-pie-card">
        <div class="dashboard-pie-kpi">
          <button type="button" class="chart-back" id="expenseBack" style="display:none" title="Zurück" aria-label="Zurück">&larr;</button>
          <span class="dashboard-pie-kpi-label" id="expenseTitle">Ausgaben (Logarithmisch)</span>
          <span class="dashboard-pie-kpi-value" id="expenseKpi"><?= euro(array_sum($expenseByCat)) ?></span>
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
 * STATE (alles was sich per AJAX ändert muss LET sein)
 * ========================================================= */
let dailyData      = <?= $dailyJson ?>;
let monthlyData    = <?= $monthlyJson ?>;
let catDailyData   = <?= $catDailyJson ?>;

let selectedCategory      = <?= json_encode($selectedKat) ?>; // 'all' | 'unk' | number
let selectedCategoryLabel = <?= json_encode($selectedKatLabel, JSON_UNESCAPED_UNICODE) ?>;
let chartYear             = <?= (int)$jahr ?>;
let catMonthlyData = buildCatMonthlyBarsFromCumulative(catDailyData, chartYear);

let incomeLabels  = <?= $incomeLabelsJson ?>;
let incomeValues  = <?= $incomeValuesJson ?>;
let expenseLabels = <?= $expenseLabelsJson ?>;
let expenseValues = <?= $expenseValuesJson ?>;

let incomeValuesClosed  = <?= $incomeValuesClosedJson ?>;
let expenseValuesClosed = <?= $expenseValuesClosedJson ?>;

const fmtEuro = (v) => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(v || 0));
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
  const y = Number(year);
  const nowY = NOW.getFullYear();
  if (y < nowY) return 12;
  if (y > nowY) return 0;
  return NOW.getMonth(); // Jan=0
}

function fmtMonthlyAvg(val, months) {
  const m = Number(months || 0);
  if (m <= 0) return '—';
  return fmtEuro(Number(val || 0) / m);
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

const midMonthLabelsPlugin = {
  id: 'midMonthLabelsPlugin',
  afterDraw(chart) {
    const scale = chart?.scales?.x;
    if (!scale || scale.type !== 'time') return;

    const xOpts = scale.options || {};
    if (!xOpts.midMonthLabels) return;

    const year = (typeof xOpts.midMonthLabelYear === 'number') ? xOpts.midMonthLabelYear : chartYear;
    const compactW = xOpts.midMonthLabelCompactWidth ?? 420;
    const step = (typeof scale.width === 'number' && scale.width < compactW) ? 2 : 1;

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

    const y = scale.bottom - 2;
    for (let m = 0; m < 12; m++) {
      if (step === 2 && (m % 2 === 1)) continue;
      const midMs = monthMidMsUTC(year, m);
      const x = scale.getPixelForValue(midMs);
      ctx.fillText(fmtMonthDE(midMs), x, y);
    }
    ctx.restore();
  }
};
Chart.register(midMonthLabelsPlugin);

/* =========================================================
 * TOP CHART (Saldo) – EINMALIGE Instanz + Update
 * ========================================================= */
function buildSaldoDatasets() {
  const ds = [];
  if (!selectedCategory || selectedCategory === 'all') {
    ds.push(
      {
        label: 'Kontostand (Monatsbeginn)',
        data: monthlyData,
        showLine: false,
        pointRadius: 8,
        pointBackgroundColor: '#ff6b00',
        pointBorderColor: '#000',
        pointBorderWidth: 2
      },
      {
        label: 'Verlauf Kontostand',
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

      // Design: orange, wie unten (nur ohne Rest-Logik)
      backgroundColor: hexToRgba(ORANGE, 0.35),
      borderColor: ORANGE,
      borderWidth: 1,

      // dünner
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
        tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${fmtEuro(c.parsed.y)}` } }
      },
      scales: {
        x: {
          type: 'time',
          time: { unit: 'month', tooltipFormat: 'dd.MM.yyyy' },
          midMonthLabels: true,
          midMonthLabelYear: chartYear,
          midMonthLabelCompactWidth: 420,
          ticks: { autoSkip: false, maxRotation: 0, minRotation: 0, callback: () => ' ' }
        },
        y: {
          beginAtZero: (!!selectedCategory && selectedCategory !== 'all'),
          ticks: { callback: (v) => fmtEuro(v) }
        }
      }
    }
  });
  updateSaldoChartOnly();
})();

function updateSaldoChartOnly() {
  saldoChart.data.datasets = buildSaldoDatasets();
  saldoChart.options.scales.x.midMonthLabelYear = chartYear;

  // Für Monats-Säulen: Null-Linie sinnvoll
  saldoChart.options.scales.y.beginAtZero = (!!selectedCategory && selectedCategory !== 'all');

  const x = saldoChart.options.scales.x;
  if (selectedCategory && selectedCategory !== 'all') {
    x.min = monthStartMsUTC(chartYear, 0);        // 01.01. YYYY
    x.max = monthStartMsUTC(chartYear + 1, 0);    // 01.01. YYYY+1
  } else {
    delete x.min;
    delete x.max;
  }

  saldoChart.update();
}

/* =========================================================
 * AJAX Page-Update (ohne Reload)
 * ========================================================= */
let _pageReqId = 0;

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

function updateHeader(year, kontostandEoy) {
  const yEl = $('pageTitleYear');
  const sEl = $('pageTitleSaldo');
  if (yEl) yEl.textContent = `Finanzen ${year}`;
  if (sEl) sEl.textContent = `| ${fmtEuro(kontostandEoy)}`;
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

  chartYear = Number(j.jahr);

  selectedCategory = j.kategorie;
  selectedCategoryLabel = j.kategorie_label;

  dailyData = j.daily || [];
  monthlyData = j.monthly || [];
  catDailyData = j.cat_daily || [];
  catMonthlyData = buildCatMonthlyBarsFromCumulative(catDailyData, chartYear);

  incomeLabels = j.income_labels || [];
  incomeValues = (j.income_values || []).map(Number);
  incomeValuesClosed = (j.income_values_closed || []).map(Number);

  expenseLabels = j.expense_labels || [];
  expenseValues = (j.expense_values || []).map(Number);
  expenseValuesClosed = (j.expense_values_closed || []).map(Number);

  // Fallback (falls Backend mal leer liefert)
  if (!incomeValuesClosed.length) incomeValuesClosed = incomeValues.slice();
  if (!expenseValuesClosed.length) expenseValuesClosed = expenseValues.slice();

  updateHeader(chartYear, j.kontostand_eoy);

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

function makeIncomeOverview() {
  const key = 'income';
  destroyChart(key);
  ui[key].mode = 'overview';
  setBackVisible(key, false);
  setTitle(key, ui[key].origTitle);
  setKpi(key, ui[key].origKpi);

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
        x: { beginAtZero: true, ticks: { callback: (v) => fmtEuro(v) } },
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
                `Gesamt ${chartYear}: ${fmtEuro(val)}`,
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
            callback: (v) => fmtEuro(v),
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
                `Gesamt ${chartYear}: ${fmtEuro(val)}`,
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

  let data;
  try {
    data = await fetchYearSeries(kind, catLabel);
  } catch (e) {
    console.error(e);
    setKpi(key, 'Fehler');
    (key === 'income') ? makeIncomeOverview() : makeExpenseOverview();
    return;
  }

  const catParamForJump = (data && data.cat_param != null) ? data.cat_param : null;

  const years = data.years || [];
  let valuesFull   = (data.values || []).map(Number);
  let valuesClosed = ((data.values_closed || data.values || [])).map(Number);

  if (key === 'expense') {
    valuesFull   = valuesFull.map(v => v * -1);
    valuesClosed = valuesClosed.map(v => v * -1);
  }

  const total = sumArr(valuesFull);
  setKpi(key, fmtEuro(total));

  ui[key].chart = new Chart($(ui[key].canvasId).getContext('2d'), {
    type: 'line',
    data: {
      labels: years.map(String),
      datasets: [{
        label: `${titleBase} (${catLabel})`,
        data: valuesFull,
        borderColor: PRIMARY,
        borderWidth: 3,
        tension: 1,
        pointRadius: 5,
        pointHoverRadius: 6,
        pointHitRadius: 10,
        fill: true,
        backgroundColor: hexToRgba(PRIMARY, 0.12),
        cubicInterpolationMode: 'monotone',
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'nearest', axis: 'x', intersect: false },
      hover:       { mode: 'nearest', axis: 'x', intersect: false },
      plugins: {
        tooltip: {
          mode: 'x',
          intersect: false,
          callbacks: {
            label: (ctx) => {
              const year = Number(ctx.label);
              const val  = Number(ctx.parsed.y);
              const idx  = ctx.dataIndex;

              const valClosed = Number(valuesClosed?.[idx] ?? val);

              const pct = total ? (val / total * 100) : 0;

              const months = completedMonthsForYear(year);
              const perMonth = fmtMonthlyAvg(valClosed, months);

              return [
                `Ø pro Monat: ${perMonth}`,
                `Gesamt ${year}: ${fmtEuro(val)}`,
                `Anteil ${titleBase}: ${pct.toFixed(1)} %`
              ];
            }
          }
        }
      },
      scales: {
        x: { ticks: { maxRotation: 0, minRotation: 0 } },
        y: { beginAtZero: true, ticks: { callback: (v) => fmtEuro(v) } }
      },
      onClick: (evt, elements, chart) => {
        if (!elements?.length) return;
        const year = Number(chart.data.labels?.[elements[0].index]);
        if (!Number.isFinite(year) || year < 1900) return;
        if (catParamForJump == null) return;
        applySelection(catParamForJump, year, { pushHistory: true, scrollTop: true });
      }
    }
  });

  const backBtn = $(ui[key].backId);
  if (backBtn) {
    backBtn.onclick = () => {
      backBtn.onclick = null;
      (key === 'income') ? makeIncomeOverview() : makeExpenseOverview();
    };
  }
}

/* =========================================================
 * INIT (nur einmal!)
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
  const year = Number(u.searchParams.get('jahr') || chartYear);
  const cat = (u.searchParams.get('kategorie') || 'all');
  applySelection(cat, year, { pushHistory: false, scrollTop: false });
});
</script>


</body>
</html>
