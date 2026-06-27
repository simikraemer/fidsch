<?php
/**
 * /wallpaper/Dashboards.php
 * Standalone Wallpaper-Dashboard: Ernährung + Finanzen.
 * Keine iframes, keine Original-Startseiten, keine Interaktion.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

if (isset($fitconn) && $fitconn instanceof mysqli) {
    $fitconn->set_charset('utf8mb4');
}
if (isset($bizconn) && $bizconn instanceof mysqli) {
    $bizconn->set_charset('utf8mb4');
}

$requestedYear = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');
$refreshSeconds = isset($_GET['refresh']) ? max(60, (int)$_GET['refresh']) : 3600;

function wd_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wd_json($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function wd_euro($value): string
{
    return number_format((float)$value, 2, ',', '.') . ' €';
}

function wd_date_range_days(int $year): array
{
    $days = [];
    $period = new DatePeriod(
        new DateTime(sprintf('%04d-01-01', $year)),
        new DateInterval('P1D'),
        new DateTime(sprintf('%04d-01-01', $year + 1))
    );

    foreach ($period as $date) {
        $days[] = $date->format('Y-m-d');
    }

    return $days;
}

function wd_avg_non_null(array $values, int $precision = 0)
{
    $filtered = array_values(array_filter($values, static fn($v) => $v !== null));
    if (!$filtered) {
        return 0;
    }
    return round(array_sum($filtered) / count($filtered), $precision);
}

function wd_kw_avg_series(array $allDays, array $dayMap, int $precision = 2): array
{
    $out = array_fill(0, count($allDays), null);
    $buckets = [];

    foreach ($allDays as $day) {
        $kw = date('o-W', strtotime($day));
        if (!isset($buckets[$kw])) {
            $buckets[$kw] = [];
        }
        if (isset($dayMap[$day])) {
            $buckets[$kw][] = $dayMap[$day];
        }
    }

    foreach ($buckets as $kw => $values) {
        if (!$values) {
            continue;
        }
        $avg = round(array_sum($values) / count($values), $precision);
        $daysInKw = array_values(array_filter($allDays, static fn($day) => date('o-W', strtotime($day)) === $kw));
        if (!$daysInKw) {
            continue;
        }
        $firstIndex = array_search($daysInKw[0], $allDays, true);
        $lastIndex = array_search(end($daysInKw), $allDays, true);
        if ($firstIndex !== false) {
            $out[$firstIndex] = $avg;
        }
        if ($lastIndex !== false && $lastIndex !== $firstIndex) {
            $out[$lastIndex] = $avg;
        }
    }

    return $out;
}

function wd_exponential_trend_series(array $values, int $precision = 1): array
{
    $trend = array_fill(0, count($values), null);
    $x = [];
    $lny = [];

    foreach ($values as $i => $value) {
        if ($value === null) {
            continue;
        }
        $value = (float)$value;
        if ($value <= 0) {
            continue;
        }
        $x[] = (float)$i;
        $lny[] = log($value);
    }

    if (count($x) <= 1) {
        return $trend;
    }

    $n = count($x);
    $sumX = array_sum($x);
    $sumY = array_sum($lny);
    $sumXY = 0.0;
    $sumXX = 0.0;

    for ($i = 0; $i < $n; $i++) {
        $sumXY += $x[$i] * $lny[$i];
        $sumXX += $x[$i] * $x[$i];
    }

    $den = ($n * $sumXX - $sumX * $sumX);
    if ($den == 0.0) {
        return $trend;
    }

    $b = ($n * $sumXY - $sumX * $sumY) / $den;
    $lnA = ($sumY - $b * $sumX) / $n;
    $a = exp($lnA);

    for ($i = 0; $i < count($values); $i++) {
        $trend[$i] = round($a * exp($b * $i), $precision);
    }

    return $trend;
}

function wd_sober_diff_text(?string $startDate): string
{
    if (!$startDate) {
        return '—';
    }

    try {
        $start = new DateTime($startDate);
        $today = new DateTime('today');
        if ($start > $today) {
            return '—';
        }
        $diff = $start->diff($today);
        $parts = [];
        if ($diff->y > 0) {
            $parts[] = $diff->y . 'J';
        }
        if ($diff->m > 0 || $diff->y > 0) {
            $parts[] = $diff->m . 'M';
        }
        $parts[] = $diff->d . 'T';
        return implode(' ', $parts);
    } catch (Throwable $e) {
        return '—';
    }
}

function wd_load_fit_dashboard(mysqli $fitconn, int $requestedYear): array
{
    $grundbedarf = 2500;
    $kalorienziel = 3000;
    $currentYear = (int)date('Y');

    $years = [];
    $yearsSql = "
        SELECT DISTINCT y FROM (
            SELECT YEAR(tstamp) AS y FROM kalorien
            UNION
            SELECT YEAR(tstamp) AS y FROM training
            UNION
            SELECT YEAR(tstamp) AS y FROM gewicht
        ) t
        WHERE y IS NOT NULL
        ORDER BY y DESC
    ";
    if ($res = $fitconn->query($yearsSql)) {
        while ($row = $res->fetch_assoc()) {
            $years[] = (int)$row['y'];
        }
        $res->free();
    }
    if (!$years) {
        $years = [$currentYear];
    }

    $year = $requestedYear;
    if (!in_array($year, $years, true)) {
        $year = in_array($currentYear, $years, true) ? $currentYear : $years[0];
    }

    $startDate = sprintf('%04d-01-01', $year);
    $endDate = sprintf('%04d-01-01', $year + 1);
    $allDays = wd_date_range_days($year);

    $bruttoDays = [];
    $stmt = $fitconn->prepare("\n        SELECT DATE(tstamp) AS tag, SUM(kalorien) AS kalorien\n        FROM kalorien\n        WHERE tstamp >= ? AND tstamp < ?\n        GROUP BY DATE(tstamp)\n    ");
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $bruttoDays[$row['tag']] = (int)$row['kalorien'];
    }
    $stmt->close();

    $macroDays = [
        'protein' => [],
        'fat' => [],
        'carbs' => [],
        'alcohol' => [],
    ];
    $stmt = $fitconn->prepare("\n        SELECT\n            DATE(tstamp) AS tag,\n            SUM(`eiweiß`)        AS eiweiss,\n            SUM(`fett`)          AS fett,\n            SUM(`kohlenhydrate`) AS kh,\n            SUM(`alkohol`)       AS alk\n        FROM kalorien\n        WHERE tstamp >= ? AND tstamp < ?\n        GROUP BY DATE(tstamp)\n    ");
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $day = $row['tag'];
        $macroDays['protein'][$day] = (float)$row['eiweiss'];
        $macroDays['fat'][$day] = (float)$row['fett'];
        $macroDays['carbs'][$day] = (float)$row['kh'];
        $macroDays['alcohol'][$day] = (float)$row['alk'];
    }
    $stmt->close();

    $nettoDays = [];
    $stmt = $fitconn->prepare("\n        SELECT DATE(tstamp) AS tag, SUM(kalorien) AS gesamt\n        FROM kalorien\n        WHERE tstamp >= ? AND tstamp < ?\n        GROUP BY DATE(tstamp)\n        UNION ALL\n        SELECT DATE(tstamp) AS tag, -SUM(kalorien) AS gesamt\n        FROM training\n        WHERE tstamp >= ? AND tstamp < ?\n        GROUP BY DATE(tstamp)\n        ORDER BY tag\n    ");
    $stmt->bind_param('ssss', $startDate, $endDate, $startDate, $endDate);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $day = $row['tag'];
        if (!isset($nettoDays[$day])) {
            $nettoDays[$day] = 0;
        }
        $nettoDays[$day] += (int)$row['gesamt'];
    }
    $stmt->close();

    $weightDays = [];
    $stmt = $fitconn->prepare("\n        SELECT DATE(tstamp) AS tag, AVG(gewicht) AS gewicht\n        FROM gewicht\n        WHERE tstamp >= ? AND tstamp < ?\n        GROUP BY DATE(tstamp)\n    ");
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $weightDays[$row['tag']] = round((float)$row['gewicht'], 1);
    }
    $stmt->close();

    $netto = [];
    $brutto = [];
    $supernetto = [];
    $weight = [];
    $protein = [];
    $fat = [];
    $carbs = [];
    $alcohol = [];
    $firstWeight = null;
    $lastWeight = null;

    foreach ($allDays as $day) {
        $netto[] = array_key_exists($day, $nettoDays) ? $nettoDays[$day] : null;
        $brutto[] = array_key_exists($day, $bruttoDays) ? $bruttoDays[$day] : null;
        $supernetto[] = array_key_exists($day, $nettoDays) ? ($nettoDays[$day] - $grundbedarf) : null;

        if (array_key_exists($day, $weightDays)) {
            if ($firstWeight === null) {
                $firstWeight = $weightDays[$day];
            }
            $lastWeight = $weightDays[$day];
            $weight[] = $weightDays[$day];
        } else {
            $weight[] = null;
        }

        $protein[] = array_key_exists($day, $macroDays['protein']) ? round($macroDays['protein'][$day], 2) : null;
        $fat[] = array_key_exists($day, $macroDays['fat']) ? round($macroDays['fat'][$day], 2) : null;
        $carbs[] = array_key_exists($day, $macroDays['carbs']) ? round($macroDays['carbs'][$day], 2) : null;
        $alcohol[] = array_key_exists($day, $macroDays['alcohol']) ? round($macroDays['alcohol'][$day], 2) : null;
    }

    $weightDiffText = '—';
    if ($firstWeight !== null && $lastWeight !== null) {
        $diff = $lastWeight - $firstWeight;
        $sign = ($diff > 0) ? '+' : (($diff < 0) ? '−' : '±');
        $weightDiffText = $sign . number_format(abs($diff), 1, ',', '') . ' kg';
    }

    return [
        'year' => $year,
        'years' => $years,
        'grundbedarf' => $grundbedarf,
        'kalorienziel' => $kalorienziel,
        'labels' => $allDays,
        'netto' => $netto,
        'brutto' => $brutto,
        'supernetto' => $supernetto,
        'nettoWeek' => wd_kw_avg_series($allDays, $nettoDays, 0),
        'weight' => $weight,
        'weightTrend' => wd_exponential_trend_series($weight, 1),
        'firstWeight' => $firstWeight,
        'lastWeight' => $lastWeight,
        'weightDiffText' => $weightDiffText,
        'nettoAvg' => (int)wd_avg_non_null($netto, 0),
        'protein' => $protein,
        'fat' => $fat,
        'carbs' => $carbs,
        'alcohol' => $alcohol,
        'proteinWeek' => wd_kw_avg_series($allDays, $macroDays['protein'], 2),
        'fatWeek' => wd_kw_avg_series($allDays, $macroDays['fat'], 2),
        'carbsWeek' => wd_kw_avg_series($allDays, $macroDays['carbs'], 2),
        'alcoholWeek' => wd_kw_avg_series($allDays, $macroDays['alcohol'], 2),
        'proteinAvg' => (int)wd_avg_non_null($protein, 0),
        'fatAvg' => (int)wd_avg_non_null($fat, 0),
        'carbsAvg' => (int)wd_avg_non_null($carbs, 0),
        'alcoholAvg' => (int)wd_avg_non_null($alcohol, 0),
        'soberCounters' => [
            ['label' => 'Nichtraucher', 'value' => wd_sober_diff_text('2020-11-29')],
            ['label' => 'Nüchtern', 'value' => wd_sober_diff_text('2025-11-16')],
            ['label' => 'Amammotarier', 'value' => wd_sober_diff_text('2026-01-01')],
        ],
    ];
}

function wd_load_biz_dashboard(mysqli $bizconn, int $requestedYear): array
{
    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');
    $closedCutoff = sprintf('%04d-%02d-01', $currentYear, $currentMonth);

    $years = [];
    if ($res = $bizconn->query("\n        SELECT DISTINCT YEAR(valutadatum) AS jahr\n        FROM transfers\n        WHERE valutadatum IS NOT NULL\n        ORDER BY jahr DESC\n    ")) {
        while ($row = $res->fetch_assoc()) {
            $years[] = (int)$row['jahr'];
        }
        $res->free();
    }

    $year = $requestedYear;
    if (!in_array($year, $years, true) && !empty($years)) {
        $year = $years[0];
    }
    if (!$year) {
        $year = $currentYear;
    }
    $isCurrentYear = ($year === $currentYear);

    $categories = [];
    if ($res = $bizconn->query("SELECT id, name FROM kategorien")) {
        while ($row = $res->fetch_assoc()) {
            $categories[(int)$row['id']] = $row['name'];
        }
        $res->free();
    }
    $unknownLabel = 'Unkategorisiert';

    $startDate = sprintf('%04d-01-01', $year);
    $startStmt = $bizconn->prepare("\n        SELECT COALESCE(SUM(betrag),0) AS summe\n        FROM transfers\n        WHERE valutadatum < ?\n    ");
    $startStmt->bind_param('s', $startDate);
    $startStmt->execute();
    $startStmt->bind_result($initialBalance);
    $startStmt->fetch();
    $startStmt->close();
    $initialBalance = (float)$initialBalance;

    $stmt = $bizconn->prepare("\n        SELECT valutadatum, betrag, kategorie_id\n        FROM transfers\n        WHERE YEAR(valutadatum) = ?\n        ORDER BY valutadatum ASC\n    ");
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $res = $stmt->get_result();

    $transfersByDate = [];
    $categorySums = [];
    $categorySumsClosed = [];
    $lastDateString = null;

    while ($row = $res->fetch_assoc()) {
        $dateKey = substr((string)$row['valutadatum'], 0, 10);
        $amount = (float)$row['betrag'];
        $lastDateString = $dateKey;

        if (!isset($transfersByDate[$dateKey])) {
            $transfersByDate[$dateKey] = 0.0;
        }
        $transfersByDate[$dateKey] += $amount;

        $category = $unknownLabel;
        if ($row['kategorie_id'] !== null) {
            $categoryId = (int)$row['kategorie_id'];
            if (isset($categories[$categoryId])) {
                $category = $categories[$categoryId];
            }
        }

        if (!isset($categorySums[$category])) {
            $categorySums[$category] = 0.0;
        }
        $categorySums[$category] += $amount;

        $closed = (!$isCurrentYear) || ($dateKey < $closedCutoff);
        if ($closed) {
            if (!isset($categorySumsClosed[$category])) {
                $categorySumsClosed[$category] = 0.0;
            }
            $categorySumsClosed[$category] += $amount;
        }
    }
    $stmt->close();

    $labels = wd_date_range_days($year);
    $lastDate = $lastDateString ? new DateTime($lastDateString) : new DateTime(sprintf('%04d-01-01', $year));
    $cum = $initialBalance;
    $daily = [];

    foreach ($labels as $day) {
        $cursor = new DateTime($day);
        if ($cursor <= $lastDate) {
            if (isset($transfersByDate[$day])) {
                $cum += $transfersByDate[$day];
            }
            $daily[] = round($cum, 2);
        } else {
            $daily[] = null;
        }
    }

    $monthly = array_fill(0, count($labels), null);
    foreach ($labels as $idx => $day) {
        $monthDay = substr($day, 8, 2);
        if ($monthDay === '01' && $daily[$idx] !== null) {
            $monthly[$idx] = $daily[$idx];
        }
    }

    $today = new DateTime('today');
    $eoyDate = new DateTime(sprintf('%04d-12-31', $year));
    if ($eoyDate <= $today) {
        $eoyIndex = count($labels) - 1;
        $lastKnown = null;
        for ($i = count($daily) - 1; $i >= 0; $i--) {
            if ($daily[$i] !== null) {
                $lastKnown = $daily[$i];
                break;
            }
        }
        $monthly[$eoyIndex] = $daily[$eoyIndex] ?? $lastKnown;
    }

    $incomeByCat = [];
    $expenseByCat = [];
    $incomeClosedByCat = [];
    $expenseClosedByCat = [];

    foreach ($categorySums as $category => $sum) {
        if ($sum > 0) {
            $incomeByCat[$category] = round((float)$sum, 2);
        } elseif ($sum < 0) {
            $expenseByCat[$category] = round(abs((float)$sum), 2);
        }
    }
    foreach ($categorySumsClosed as $category => $sum) {
        if ($sum > 0) {
            $incomeClosedByCat[$category] = round((float)$sum, 2);
        } elseif ($sum < 0) {
            $expenseClosedByCat[$category] = round(abs((float)$sum), 2);
        }
    }
    arsort($incomeByCat);
    arsort($expenseByCat);

    $endOfYear = sprintf('%04d-12-31', $year);
    $sumStmt = $bizconn->prepare("\n        SELECT COALESCE(SUM(betrag), 0) AS summe\n        FROM transfers\n        WHERE valutadatum IS NOT NULL\n          AND valutadatum <= ?\n    ");
    $sumStmt->bind_param('s', $endOfYear);
    $sumStmt->execute();
    $sumStmt->bind_result($allUntilEoy);
    $sumStmt->fetch();
    $sumStmt->close();
    $accountUntilEoy = (float)$allUntilEoy;

    $externalBalances = [];
    $balanceCutoff = sprintf('%04d-12-31 23:59:59', $year);
    $stmtBalances = $bizconn->prepare("\n        SELECT ks.konto, ks.betrag, ks.eingetragen_am\n        FROM konto_staende ks\n        WHERE ks.eingetragen_am <= ?\n          AND NOT EXISTS (\n              SELECT 1\n              FROM konto_staende newer\n              WHERE newer.konto = ks.konto\n                AND newer.eingetragen_am <= ?\n                AND (\n                    newer.eingetragen_am > ks.eingetragen_am\n                    OR (\n                        newer.eingetragen_am = ks.eingetragen_am\n                        AND newer.id > ks.id\n                    )\n                )\n          )\n          AND ks.betrag <> 0\n        ORDER BY ks.betrag DESC\n    ");
    if ($stmtBalances !== false) {
        $stmtBalances->bind_param('ss', $balanceCutoff, $balanceCutoff);
        $stmtBalances->execute();
        $resBalances = $stmtBalances->get_result();
        while ($row = $resBalances->fetch_assoc()) {
            $externalBalances[] = $row;
        }
        $stmtBalances->close();
    }

    $externalSum = 0.0;
    foreach ($externalBalances as $balance) {
        $externalSum += (float)$balance['betrag'];
    }

    $saldoTotal = $accountUntilEoy + $externalSum;
    $details = [['label' => 'Konto', 'value' => wd_euro($accountUntilEoy)]];
    foreach ($externalBalances as $balance) {
        $details[] = ['label' => (string)$balance['konto'], 'value' => wd_euro((float)$balance['betrag'])];
    }

    $incomeLabels = array_keys($incomeByCat);
    $expenseLabels = array_keys($expenseByCat);
    $incomeValues = array_values($incomeByCat);
    $expenseValues = array_values($expenseByCat);

    $incomeClosedValues = [];
    foreach ($incomeLabels as $label) {
        $incomeClosedValues[] = round((float)($incomeClosedByCat[$label] ?? 0.0), 2);
    }
    $expenseClosedValues = [];
    foreach ($expenseLabels as $label) {
        $expenseClosedValues[] = round((float)($expenseClosedByCat[$label] ?? 0.0), 2);
    }

    return [
        'year' => $year,
        'years' => $years,
        'labels' => $labels,
        'daily' => $daily,
        'monthly' => $monthly,
        'saldoTotal' => $saldoTotal,
        'saldoDetails' => $details,
        'incomeLabels' => $incomeLabels,
        'incomeValues' => $incomeValues,
        'incomeClosedValues' => $incomeClosedValues,
        'expenseLabels' => $expenseLabels,
        'expenseValues' => $expenseValues,
        'expenseClosedValues' => $expenseClosedValues,
        'incomeTotal' => array_sum($incomeValues),
        'expenseTotal' => array_sum($expenseValues),
    ];
}

$fit = wd_load_fit_dashboard($fitconn, $requestedYear);
$biz = wd_load_biz_dashboard($bizconn, $requestedYear);

$payload = [
    'refreshSeconds' => $refreshSeconds,
    'fit' => $fit,
    'biz' => $biz,
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="<?= (int)$refreshSeconds ?>">
    <title>FIJI Wallpaper Dashboards</title>
    <link rel="stylesheet" href="/wallpaper/FIJIDARK.css?v=20260627_1328">
</head>
<body>
<div class="wallpaper-root">
    <section id="healthPage" class="lt-page dashboard-page wallpaper-section wallpaper-section-fit">
        <div class="lt-topbar">
            <h1 class="ueberschrift dashboard-title">
                <span class="dashboard-title-main">Ernährung <?= wd_h($fit['year']) ?></span>
                <span class="dashboard-title-soft">| <?= wd_h($fit['weightDiffText']) ?></span>
            </h1>

            <div class="dashboard-sober-counters" aria-label="Abstinenz-Counter">
                <?php foreach ($fit['soberCounters'] as $counter): ?>
                    <div class="dashboard-sober-counter">
                        <span class="dashboard-sober-label"><?= wd_h($counter['label']) ?></span>
                        <span class="dashboard-sober-value"><?= wd_h($counter['value']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ernährungsdiablock">
            <div class="dashboard-pies fit-main-grid">
                <div class="dashboard-pie-card">
                    <div class="dashboard-pie-kpi">
                        <span class="dashboard-pie-kpi-label">Kalorien</span>
                        <span class="dashboard-pie-kpi-value">Ø<?= (int)$fit['nettoAvg'] ?> kcal/Tag</span>
                    </div>
                    <div class="dashboard-pie-wrap"><canvas id="fitCaloriesChart"></canvas></div>
                </div>

                <div class="dashboard-pie-card">
                    <div class="dashboard-pie-kpi">
                        <span class="dashboard-pie-kpi-label">Körpergewicht</span>
                        <span class="dashboard-pie-kpi-value">
                            <?= $fit['firstWeight'] !== null ? wd_h($fit['firstWeight']) : '—' ?> kg → <?= $fit['lastWeight'] !== null ? wd_h($fit['lastWeight']) : '—' ?> kg
                        </span>
                    </div>
                    <div class="dashboard-pie-wrap"><canvas id="fitWeightChart"></canvas></div>
                </div>
            </div>

            <div class="dashboard-pies dashboard-pies-3 fit-macro-grid">
                <div class="dashboard-pie-card">
                    <div class="dashboard-pie-kpi">
                        <span class="dashboard-pie-kpi-label">Protein</span>
                        <span class="dashboard-pie-kpi-value">Ø<?= (int)$fit['proteinAvg'] ?> g/Tag</span>
                    </div>
                    <div class="dashboard-pie-wrap"><canvas id="fitProteinChart"></canvas></div>
                </div>

                <div class="dashboard-pie-card">
                    <div class="dashboard-pie-kpi">
                        <span class="dashboard-pie-kpi-label">Fett</span>
                        <span class="dashboard-pie-kpi-value">Ø<?= (int)$fit['fatAvg'] ?> g/Tag</span>
                    </div>
                    <div class="dashboard-pie-wrap"><canvas id="fitFatChart"></canvas></div>
                </div>

                <div class="dashboard-pie-card">
                    <div class="dashboard-pie-kpi">
                        <span class="dashboard-pie-kpi-label">Carbs</span>
                        <span class="dashboard-pie-kpi-value">Ø<?= (int)$fit['carbsAvg'] ?> g/Tag</span>
                    </div>
                    <div class="dashboard-pie-wrap"><canvas id="fitCarbsChart"></canvas></div>
                </div>
            </div>
        </div>
    </section>

    <section id="statsPage" class="lt-page dashboard-page wallpaper-section wallpaper-section-biz">
        <div class="lt-topbar">
            <h1 class="ueberschrift dashboard-title">
                <span class="dashboard-title-main">Finanzen <?= wd_h($biz['year']) ?></span>
                <span class="dashboard-title-soft">| <?= wd_h(wd_euro($biz['saldoTotal'])) ?></span>
            </h1>

            <div class="dashboard-sober-counters" aria-label="Saldo-Details">
                <?php foreach ($biz['saldoDetails'] as $detail): ?>
                    <div class="dashboard-sober-counter">
                        <span class="dashboard-sober-label"><?= wd_h($detail['label']) ?></span>
                        <span class="dashboard-sober-value"><?= wd_h($detail['value']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="lt-chart-wrap finance-balance-wrap">
            <canvas id="bizBalanceChart"></canvas>
        </div>

        <div class="dashboard-pies dashboard-pies--asym finance-detail-grid">
            <div class="dashboard-pie-card">
                <div class="dashboard-pie-kpi">
                    <span class="dashboard-pie-kpi-label">Einnahmen</span>
                    <span class="dashboard-pie-kpi-value"><?= wd_h(wd_euro($biz['incomeTotal'])) ?></span>
                </div>
                <div class="dashboard-pie-wrap"><canvas id="bizIncomeChart"></canvas></div>
            </div>

            <div class="dashboard-pie-card">
                <div class="dashboard-pie-kpi">
                    <span class="dashboard-pie-kpi-label">Ausgaben (Logarithmisch)</span>
                    <span class="dashboard-pie-kpi-value"><?= wd_h(wd_euro($biz['expenseTotal'])) ?></span>
                </div>
                <div class="dashboard-pie-wrap"><canvas id="bizExpenseChart"></canvas></div>
            </div>
        </div>
    </section>

    <div class="wallpaper-footer">Reload: <?= date('H:i') ?> · alle <?= (int)round($refreshSeconds / 60) ?> min</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const WD = <?= wd_json($payload) ?>;
</script>
<script>
(() => {
  if (!window.Chart) {
    document.body.insertAdjacentHTML('beforeend', '<div class="chart-load-error">Chart.js konnte nicht geladen werden.</div>');
    return;
  }

  const COLORS = {
    bg: '#000000',
    card: '#080808',
    text: '#f4f7fb',
    muted: '#8f98a5',
    cross: 'rgba(170, 170, 170, 0.4)',
    grid: 'rgba(255,255,255,0.105)',
    gridSoft: 'rgba(255,255,255,0.055)',
    orange: '#ff6b00',
    white: '#a1a1a1',
    protein: '#00a8ff',
    fat: '#ffa000',
    carbs: '#00c853',
    red: 'rgba(140, 0, 0, 0.36)',
    yellow: 'rgba(138, 112, 0, 0.28)',
    green: 'rgba(0, 125, 28, 0.30)'
  };

  const MONTHS = ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.'];
  const fmtEuro = (v) => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(v || 0));

  Chart.defaults.color = COLORS.muted;
  Chart.defaults.font.family = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
  Chart.defaults.borderColor = COLORS.gridSoft;

  const backgroundBandsPlugin = {
    id: 'wallpaperBackgroundBands',
    beforeDatasetsDraw(chart, args, pluginOptions) {
      const bands = pluginOptions?.bands || [];
      const lines = pluginOptions?.lines || [];
      if ((!bands.length && !lines.length) || !chart.scales?.y) return;

      const {ctx, chartArea} = chart;
      const y = chart.scales.y;
      ctx.save();

      for (const band of bands) {
        const from = band.from == null ? y.min : Number(band.from);
        const to = band.to == null ? y.max : Number(band.to);
        const py1 = y.getPixelForValue(to);
        const py2 = y.getPixelForValue(from);
        const top = Math.max(chartArea.top, Math.min(py1, py2));
        const bottom = Math.min(chartArea.bottom, Math.max(py1, py2));
        if (bottom <= chartArea.top || top >= chartArea.bottom) continue;
        ctx.fillStyle = band.color;
        ctx.fillRect(chartArea.left, top, chartArea.right - chartArea.left, bottom - top);
      }

      for (const line of lines) {
        const value = Number(line.value);
        if (!Number.isFinite(value) || value < y.min || value > y.max) continue;
        const py = y.getPixelForValue(value);
        ctx.beginPath();
        ctx.strokeStyle = line.color || COLORS.white;
        ctx.lineWidth = line.width || 1;
        if (line.dash) ctx.setLineDash(line.dash);
        else ctx.setLineDash([]);
        ctx.moveTo(chartArea.left, py);
        ctx.lineTo(chartArea.right, py);
        ctx.stroke();
      }

      ctx.restore();
    }
  };

  const monthGridPlugin = {
    id: 'wallpaperMonthGrid',
    beforeDatasetsDraw(chart) {
      const x = chart.scales?.x;
      if (!x || !chart.data?.labels?.length) return;
      const {ctx, chartArea} = chart;
      ctx.save();
      ctx.strokeStyle = COLORS.grid;
      ctx.lineWidth = 1;
      chart.data.labels.forEach((label, index) => {
        if (typeof label === 'string' && label.slice(8, 10) === '01') {
          const px = x.getPixelForValue(index);
          ctx.beginPath();
          ctx.moveTo(px, chartArea.top);
          ctx.lineTo(px, chartArea.bottom);
          ctx.stroke();
        }
      });
      ctx.restore();
    }
  };

  Chart.register(backgroundBandsPlugin, monthGridPlugin);

  function monthTick(value) {
    const label = this.getLabelForValue(value);
    if (typeof label !== 'string') return '';
    if (label.slice(8, 10) !== '01') return '';
    const m = Number(label.slice(5, 7));
    return MONTHS[m - 1] || '';
  }

  function commonScales(yOptions = {}) {
    return {
      x: {
        type: 'category',
        grid: { display: false },
        ticks: {
          color: COLORS.muted,
          autoSkip: false,
          maxRotation: 0,
          minRotation: 0,
          callback: monthTick,
          font: { size: 11 }
        }
      },
      y: {
        beginAtZero: true,
        grid: { color: COLORS.gridSoft },
        ticks: { color: COLORS.muted, font: { size: 11 } },
        ...yOptions
      }
    };
  }

  function commonPlugins(extra = {}) {
    return {
      legend: { display: false },
      tooltip: { enabled: false },
      ...extra
    };
  }

  function commonLineOptions(yOptions = {}, pluginOptions = {}) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      normalized: true,
      interaction: { intersect: false, mode: 'nearest' },
      plugins: commonPlugins({ wallpaperBackgroundBands: pluginOptions }),
      scales: commonScales(yOptions)
    };
  }

  function linearTrend(values) {
    const xs = [];
    const ys = [];
    values.forEach((v, i) => {
      if (v !== null && !Number.isNaN(Number(v))) {
        xs.push(i);
        ys.push(Number(v));
      }
    });
    const out = Array(values.length).fill(null);
    if (xs.length < 2) return out;
    const n = xs.length;
    const sx = xs.reduce((a, b) => a + b, 0);
    const sy = ys.reduce((a, b) => a + b, 0);
    const sxy = xs.reduce((a, x, i) => a + x * ys[i], 0);
    const sxx = xs.reduce((a, x) => a + x * x, 0);
    const den = n * sxx - sx * sx;
    if (!den) return out;
    const slope = (n * sxy - sx * sy) / den;
    const intercept = (sy - slope * sx) / n;
    return values.map((v, i) => v === null ? null : Math.round((intercept + slope * i) * 100) / 100);
  }

  function makeMacroChart(canvasId, daily, weekly, color) {
    new Chart(document.getElementById(canvasId), {
      type: 'line',
      data: {
        labels: WD.fit.labels,
        datasets: [
          {
            data: daily,
            showLine: false,
            pointStyle: 'crossRot',
            pointRadius: 3,
            pointBorderWidth: 1.8,
            pointBorderColor: hexToRgba(color, 0.55),
            pointBackgroundColor: 'transparent',
            borderColor: color
          },
          {
            data: weekly,
            borderColor: color,
            borderWidth: 3,
            pointRadius: 0,
            tension: 0,
            spanGaps: true
          },
          {
            data: linearTrend(daily),
            borderColor: hexToRgba(color, 0.50),
            borderWidth: 1.6,
            borderDash: [4, 3],
            pointRadius: 0,
            tension: 0,
            spanGaps: true
          }
        ]
      },
      options: commonLineOptions({ beginAtZero: true })
    });
  }

  function hexToRgba(hex, alpha) {
    const match = String(hex).trim().match(/^#?([0-9a-f]{6})$/i);
    if (!match) return hex;
    const value = parseInt(match[1], 16);
    const r = (value >> 16) & 255;
    const g = (value >> 8) & 255;
    const b = value & 255;
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }

  new Chart(document.getElementById('fitCaloriesChart'), {
    type: 'line',
    data: {
      labels: WD.fit.labels,
      datasets: [
        {
          data: WD.fit.netto,
          showLine: false,
          pointStyle: 'crossRot',
          pointRadius: 3,
          pointBorderColor: COLORS.cross,
          pointBackgroundColor: 'transparent',
          pointBorderWidth: 1.8
        },
        {
          data: WD.fit.nettoWeek,
          borderColor: COLORS.white,
          borderWidth: 3,
          pointRadius: 0,
          tension: 0,
          spanGaps: true
        }
      ]
    },
    options: commonLineOptions(
      { beginAtZero: true },
      {
        bands: [
          { from: null, to: WD.fit.grundbedarf, color: COLORS.green },
          { from: WD.fit.grundbedarf, to: WD.fit.kalorienziel, color: COLORS.yellow },
          { from: WD.fit.kalorienziel, to: null, color: COLORS.red }
        ],
        lines: [
          { value: WD.fit.grundbedarf, color: '#00c853', width: 1, dash: [18, 6] },
          { value: WD.fit.kalorienziel, color: '#ff2a2a', width: 2, dash: [18, 6] }
        ]
      }
    )
  });

  new Chart(document.getElementById('fitWeightChart'), {
    type: 'line',
    data: {
      labels: WD.fit.labels,
      datasets: [
        {
          data: WD.fit.weight,
          borderColor: COLORS.white,
          borderWidth: 3,
          pointStyle: 'crossRot',
          pointRadius: 3,
          pointBorderColor: COLORS.cross,
          pointBackgroundColor: 'transparent',
          pointBorderWidth: 1.8,
          tension: 0.25,
          spanGaps: true
        },
        {
          data: WD.fit.weightTrend,
          borderColor: 'rgba(255,255,255,0.58)',
          borderWidth: 2,
          borderDash: [4, 3],
          pointRadius: 0,
          tension: 0,
          spanGaps: true
        }
      ]
    },
    options: commonLineOptions(
      { beginAtZero: false, min: 80 },
      {
        bands: [
          { from: null, to: 85, color: COLORS.yellow },
          { from: 85, to: 90, color: COLORS.green },
          { from: 90, to: 100, color: COLORS.yellow },
          { from: 100, to: null, color: COLORS.red }
        ],
        lines: [
          { value: 85, color: '#00c853', width: 1, dash: [18, 6] },
          { value: 90, color: '#00c853', width: 1, dash: [18, 6] }
        ]
      }
    )
  });

  makeMacroChart('fitProteinChart', WD.fit.protein, WD.fit.proteinWeek, COLORS.protein);
  makeMacroChart('fitFatChart', WD.fit.fat, WD.fit.fatWeek, COLORS.fat);
  makeMacroChart('fitCarbsChart', WD.fit.carbs, WD.fit.carbsWeek, COLORS.carbs);

  new Chart(document.getElementById('bizBalanceChart'), {
    type: 'line',
    data: {
      labels: WD.biz.labels,
      datasets: [
        {
          label: 'Kontostand',
          data: WD.biz.daily,
          borderColor: COLORS.white,
          borderWidth: 3,
          pointRadius: 0,
          tension: 0,
          spanGaps: false
        },
        {
          label: 'Monatspunkte',
          data: WD.biz.monthly,
          showLine: false,
          pointRadius: 6,
          pointHoverRadius: 6,
          pointBackgroundColor: COLORS.orange,
          pointBorderColor: '#000000',
          pointBorderWidth: 2
        }
      ]
    },
    options: commonLineOptions({
      beginAtZero: false,
      ticks: { color: COLORS.muted, font: { size: 11 }, callback: (v) => fmtEuro(v) }
    })
  });

  function topNWithRest(labels, values, closedValues, limit) {
    const rows = labels.map((label, i) => ({
      label,
      value: Number(values[i] || 0),
      closed: Number((closedValues && closedValues[i] != null) ? closedValues[i] : (values[i] || 0))
    })).filter(row => row.value > 0).sort((a, b) => b.value - a.value);

    const top = rows.slice(0, limit);
    const rest = rows.slice(limit);
    if (rest.length) {
      top.push({
        label: 'Rest',
        value: rest.reduce((s, r) => s + r.value, 0),
        closed: rest.reduce((s, r) => s + r.closed, 0)
      });
    }
    return top;
  }

  function truncateLabel(text, max = 9) {
    text = String(text || '');
  
    const short = {
      'Bar/PayPal': 'Umtausch',
      'Büroartikel': 'Büroart.',
      'Dekoration': 'Deko.',
      'Kontogebühr': 'Kontog.',
      'Lebensmittel': 'Lebensm.',
      'Lieferando': 'Liefer.',
      'Restaurant': 'Restau.',
      'Sonstiges': 'Sonst.',
    };
  
    return short[text] || text;
  }

  const incomeTop = topNWithRest(WD.biz.incomeLabels, WD.biz.incomeValues, WD.biz.incomeClosedValues, 5);
  new Chart(document.getElementById('bizIncomeChart'), {
    type: 'bar',
    data: {
      labels: incomeTop.map(row => row.label),
      datasets: [{
        data: incomeTop.map(row => row.value),
        backgroundColor: hexToRgba(COLORS.orange, 0.38),
        borderColor: COLORS.orange,
        borderWidth: 1.5
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      indexAxis: 'y',
      plugins: commonPlugins(),
      scales: {
        x: { beginAtZero: true, grid: { color: COLORS.gridSoft }, ticks: { color: COLORS.muted, callback: v => fmtEuro(v) } },
        y: { grid: { display: false }, ticks: { color: COLORS.muted, autoSkip: false, callback: function(value) { return truncateLabel(this.getLabelForValue(value), 17); } } }
      }
    }
  });

  const expenseRows = topNWithRest(WD.biz.expenseLabels, WD.biz.expenseValues, WD.biz.expenseClosedValues, 50);
  const expensePositive = expenseRows.map(row => row.value).filter(v => v > 0);
  let expenseMin = expensePositive.length ? Math.min(...expensePositive) : 1;
  expenseMin = Math.pow(10, Math.floor(Math.log10(expenseMin)));
  if (!Number.isFinite(expenseMin) || expenseMin <= 0) expenseMin = 1;

  new Chart(document.getElementById('bizExpenseChart'), {
    type: 'bar',
    data: {
      labels: expenseRows.map(row => row.label),
      datasets: [{
        data: expenseRows.map(row => row.value),
        backgroundColor: hexToRgba(COLORS.orange, 0.38),
        borderColor: COLORS.orange,
        borderWidth: 1.5,
        barPercentage: 0.9,
        categoryPercentage: 0.82
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      plugins: commonPlugins(),
      scales: {
        x: {
          grid: { color: COLORS.gridSoft },
          ticks: {
            color: COLORS.muted,
            autoSkip: false,
            maxRotation: 0,
            minRotation: 0,
            callback: function(value, index) {
                const label = truncateLabel(this.getLabelForValue(value), 8);
                return index % 2 === 0 ? [label, ''] : ['', label];
            }
          }
        },
        y: {
          type: 'logarithmic',
          min: expenseMin,
          grid: { color: COLORS.gridSoft },
          ticks: { color: COLORS.muted, callback: v => fmtEuro(v), maxTicksLimit: 5 }
        }
      }
    }
  });

  window.setTimeout(() => window.location.reload(), Math.max(60, Number(WD.refreshSeconds || 3600)) * 1000);
})();
</script>
</body>
</html>
