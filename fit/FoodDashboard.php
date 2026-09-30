<?php
// fit/FoodDashboard_v12.php

// 1) Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// 2) DB
require_once __DIR__ . '/../db.php';
$fitconn->set_charset('utf8mb4');
$fitconn->query("USE `fit`");

date_default_timezone_set('Europe/Berlin');

// -----------------------------------------------------------------------------
// Zeitraum
// -----------------------------------------------------------------------------

// Verfügbare Kalenderjahre direkt aus den vorhandenen Kalorien-Daten laden.
$verfuegbareJahre = [];
$yearResult = $fitconn->query("
    SELECT DISTINCT YEAR(tstamp) AS jahr
    FROM kalorien
    WHERE tstamp IS NOT NULL
    ORDER BY jahr DESC
");

if ($yearResult) {
    while ($row = $yearResult->fetch_assoc()) {
        if ($row['jahr'] !== null) {
            $verfuegbareJahre[] = (int)$row['jahr'];
        }
    }
    $yearResult->free();
}

$zeitraum = isset($_GET['zeitraum']) ? trim((string)$_GET['zeitraum']) : '1m';

$isJahr = ctype_digit($zeitraum)
    && in_array((int)$zeitraum, $verfuegbareJahre, true);

if (!in_array($zeitraum, ['1m', '1y', 'all'], true) && !$isJahr) {
    $zeitraum = '1m';
    $isJahr = false;
}

// Drilldown-/Tab-/Tabellenfilter-Zustand über einen Zeitraumwechsel hinweg erhalten.
$requestedCategory = isset($_GET['kategorie']) ? trim((string)$_GET['kategorie']) : '';
$requestedMetric   = isset($_GET['metric']) ? trim((string)$_GET['metric']) : 'calories';

if (mb_strlen($requestedCategory, 'UTF-8') > 255) {
    $requestedCategory = '';
}

if (!in_array($requestedMetric, ['calories', 'protein', 'carbs', 'fat', 'entries'], true)) {
    $requestedMetric = 'calories';
}

// Der Gruppenfilter der Gerichte-Tabelle ist bewusst vom Diagramm-Drilldown getrennt.
// Bei alten URLs ohne filter_kategorie folgt er zunächst der Diagramm-Gruppe.
$requestedTableCategory = isset($_GET['filter_kategorie'])
    ? trim((string)$_GET['filter_kategorie'])
    : $requestedCategory;

if (mb_strlen($requestedTableCategory, 'UTF-8') > 255) {
    $requestedTableCategory = '';
}

// Mehrfachauswahl der Gruppen für den Gruppen-Zeitverlauf.
// Bei alten URLs ohne "gruppen" wird die bisherige Drilldown-Gruppe übernommen.
$requestedGroups = [];
if (isset($_GET['gruppen'])) {
    $decodedGroups = json_decode((string)$_GET['gruppen'], true);
    if (is_array($decodedGroups)) {
        foreach ($decodedGroups as $groupItem) {
            $groupName = trim((string)$groupItem);
            if ($groupName !== '' && mb_strlen($groupName, 'UTF-8') <= 255) {
                $requestedGroups[] = $groupName;
            }
        }
    }
}

if (!$requestedGroups && $requestedCategory !== '') {
    $requestedGroups[] = $requestedCategory;
}

$requestedGroups = array_values(array_unique($requestedGroups));
if (count($requestedGroups) > 25) {
    $requestedGroups = array_slice($requestedGroups, 0, 25);
}

// Mehrfachauswahl der Gerichte wird als JSON-Array aus {name, category} gehalten.
// Alte Arrays aus reinen Namen sowie der Einzelparameter "gericht" bleiben kompatibel.
$requestedFoods = [];
if (isset($_GET['gerichte'])) {
    $decodedFoods = json_decode((string)$_GET['gerichte'], true);
    if (is_array($decodedFoods)) {
        foreach ($decodedFoods as $foodItem) {
            $foodName = '';
            $foodCategory = '';

            if (is_string($foodItem)) {
                $foodName = trim($foodItem);
                $foodCategory = $requestedCategory;
            } elseif (is_array($foodItem)) {
                $foodName = trim((string)($foodItem['name'] ?? ''));
                $foodCategory = trim((string)($foodItem['category'] ?? ''));
            }

            if (
                $foodName !== ''
                && $foodCategory !== ''
                && mb_strlen($foodName, 'UTF-8') <= 255
                && mb_strlen($foodCategory, 'UTF-8') <= 255
            ) {
                $requestedFoods[] = [
                    'name' => $foodName,
                    'category' => $foodCategory,
                ];
            }
        }
    }
} elseif (isset($_GET['gericht'])) {
    $legacyFood = trim((string)$_GET['gericht']);
    if ($legacyFood !== '' && $requestedCategory !== '') {
        $requestedFoods[] = [
            'name' => $legacyFood,
            'category' => $requestedCategory,
        ];
    }
}

// Doppelte Auswahl derselben Kombination aus Gruppe + Gericht entfernen.
$uniqueRequestedFoods = [];
$seenRequestedFoods = [];
foreach ($requestedFoods as $foodItem) {
    $foodKey = $foodItem['category'] . "\0" . $foodItem['name'];
    if (isset($seenRequestedFoods[$foodKey])) {
        continue;
    }
    $seenRequestedFoods[$foodKey] = true;
    $uniqueRequestedFoods[] = $foodItem;
    if (count($uniqueRequestedFoods) >= 25) {
        break;
    }
}
$requestedFoods = $uniqueRequestedFoods;

// Falls nur die neue Mehrfachauswahl vorhanden ist, dient deren erstes Gericht als
// Rücksprung-Gruppe für den Diagramm-Drilldown.
if ($requestedCategory === '' && $requestedFoods) {
    $requestedCategory = (string)$requestedFoods[0]['category'];
}

if ($requestedCategory === '' && $requestedGroups) {
    $requestedCategory = (string)$requestedGroups[0];
}

if ($requestedCategory !== '' && !in_array($requestedCategory, $requestedGroups, true)) {
    array_unshift($requestedGroups, $requestedCategory);
    $requestedGroups = array_slice(array_values(array_unique($requestedGroups)), 0, 25);
}

if (!isset($_GET['filter_kategorie']) && $requestedTableCategory === '') {
    $requestedTableCategory = $requestedCategory;
}

$now = new DateTimeImmutable('now');
$startDate = null;
$endDate = $now->format('Y-m-d H:i:s');

if ($isJahr) {
    $jahr = (int)$zeitraum;

    $startDate = sprintf('%04d-01-01 00:00:00', $jahr);
    $endDate   = sprintf('%04d-01-01 00:00:00', $jahr + 1);

    $zeitraumLabel  = (string)$jahr;
    $zeitraumDetail = sprintf('01.01.%04d – 31.12.%04d', $jahr, $jahr);
} elseif ($zeitraum === '1y') {
    // "Letztes Jahr" = exakt die letzten 12 Monate.
    $startDate = $now->modify('-12 months')->format('Y-m-d H:i:s');

    $zeitraumLabel  = 'Letztes Jahr';
    $startObj        = new DateTimeImmutable($startDate);
    $zeitraumDetail = $startObj->format('d.m.Y') . ' – ' . $now->format('d.m.Y');
} elseif ($zeitraum === 'all') {
    $zeitraumLabel  = 'Insgesamt';
    $zeitraumDetail = 'Alle vorhandenen Daten';
} else {
    // "Letzter Monat" = exakt die letzten 30 Tage.
    $startDate = $now->modify('-30 days')->format('Y-m-d H:i:s');

    $zeitraumLabel  = 'Letzter Monat';
    $startObj        = new DateTimeImmutable($startDate);
    $zeitraumDetail = $startObj->format('d.m.Y') . ' – ' . $now->format('d.m.Y');
}

// -----------------------------------------------------------------------------
// Hilfsfunktionen
// -----------------------------------------------------------------------------
function foodDashboardFetchAll(mysqli $conn, string $sql, ?string $startDate, ?string $endDate): array
{
    $rows = [];

    if ($startDate !== null && $endDate !== null) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('DB-Fehler beim Vorbereiten: ' . $conn->error);
        }
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    $sql = preg_replace('/\s+WHERE\s+tstamp\s+>=\s+\\?\s+AND\s+tstamp\s+<\s+\\?/i', '', $sql, 1);
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('DB-Fehler: ' . $conn->error);
    }
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();

    return $rows;
}

function foodDashboardNormalizeRows(array $rows): array
{
    return array_map(static function (array $row): array {
        return [
            'name'      => (string)($row['name'] ?? ''),
            'category'  => (string)($row['category'] ?? ''),
            'entries'   => (int)($row['entries'] ?? 0),
            'calories'  => (float)($row['calories'] ?? 0),
            'protein'   => (float)($row['protein'] ?? 0),
            'carbs'     => (float)($row['carbs'] ?? 0),
            'fat'       => (float)($row['fat'] ?? 0),
            'alcohol'   => (float)($row['alcohol'] ?? 0),
        ];
    }, $rows);
}

function foodDashboardNormalizeFrequencyRows(array $rows): array
{
    return array_map(static function (array $row): array {
        return [
            'name'      => (string)($row['name'] ?? ''),
            'category'  => (string)($row['category'] ?? ''),
            'bucket'    => (string)($row['bucket'] ?? ''),
            'entries'   => (int)($row['entries'] ?? 0),
            'calories'  => (float)($row['calories'] ?? 0),
            'protein'   => (float)($row['protein'] ?? 0),
            'carbs'     => (float)($row['carbs'] ?? 0),
            'fat'       => (float)($row['fat'] ?? 0),
            'alcohol'   => (float)($row['alcohol'] ?? 0),
        ];
    }, $rows);
}

// -----------------------------------------------------------------------------
// Daten: Gruppen, Gerichte und Häufigkeitsverlauf
// -----------------------------------------------------------------------------
$categorySql = "
    SELECT
        COALESCE(NULLIF(TRIM(kategorie), ''), 'Ohne Kategorie') AS name,
        '' AS category,
        COUNT(*) AS entries,
        SUM(kalorien) AS calories,
        SUM(`eiweiß`) AS protein,
        SUM(`kohlenhydrate`) AS carbs,
        SUM(fett) AS fat,
        SUM(alkohol) AS alcohol
    FROM kalorien
    WHERE tstamp >= ? AND tstamp < ?
    GROUP BY COALESCE(NULLIF(TRIM(kategorie), ''), 'Ohne Kategorie')
    ORDER BY name ASC
";

$foodSql = "
    SELECT
        COALESCE(NULLIF(TRIM(beschreibung), ''), 'Ohne Beschreibung') AS name,
        COALESCE(NULLIF(TRIM(kategorie), ''), 'Ohne Kategorie') AS category,
        COUNT(*) AS entries,
        SUM(kalorien) AS calories,
        SUM(`eiweiß`) AS protein,
        SUM(`kohlenhydrate`) AS carbs,
        SUM(fett) AS fat,
        SUM(alkohol) AS alcohol
    FROM kalorien
    WHERE tstamp >= ? AND tstamp < ?
    GROUP BY
        COALESCE(NULLIF(TRIM(beschreibung), ''), 'Ohne Beschreibung'),
        COALESCE(NULLIF(TRIM(kategorie), ''), 'Ohne Kategorie')
    ORDER BY name ASC
";

$frequencyBucketExpr = $zeitraum === '1m'
    ? 'DATE(tstamp)'
    : "DATE_FORMAT(tstamp, '%Y-%m-01')";

$foodFrequencySql = "
    SELECT
        COALESCE(NULLIF(TRIM(beschreibung), ''), 'Ohne Beschreibung') AS name,
        COALESCE(NULLIF(TRIM(kategorie), ''), 'Ohne Kategorie') AS category,
        {$frequencyBucketExpr} AS bucket,
        COUNT(*) AS entries,
        SUM(kalorien) AS calories,
        SUM(`eiweiß`) AS protein,
        SUM(`kohlenhydrate`) AS carbs,
        SUM(fett) AS fat,
        SUM(alkohol) AS alcohol
    FROM kalorien
    WHERE tstamp >= ? AND tstamp < ?
    GROUP BY
        COALESCE(NULLIF(TRIM(beschreibung), ''), 'Ohne Beschreibung'),
        COALESCE(NULLIF(TRIM(kategorie), ''), 'Ohne Kategorie'),
        {$frequencyBucketExpr}
    ORDER BY bucket ASC, name ASC
";

$foodFrequencyRows = [];
$loadError = null;

try {
    $categoryRows = foodDashboardNormalizeRows(
        foodDashboardFetchAll($fitconn, $categorySql, $startDate, $endDate)
    );
    $foodRows = foodDashboardNormalizeRows(
        foodDashboardFetchAll($fitconn, $foodSql, $startDate, $endDate)
    );
    $foodFrequencyRows = foodDashboardNormalizeFrequencyRows(
        foodDashboardFetchAll($fitconn, $foodFrequencySql, $startDate, $endDate)
    );
} catch (Throwable $e) {
    $categoryRows = [];
    $foodRows = [];
    $foodFrequencyRows = [];
    $loadError = $e->getMessage();
}

// Sichtbarer Bereich der Häufigkeits-Zeitachse.
if ($zeitraum === '1m') {
    $chartStartDate = (new DateTimeImmutable((string)$startDate))->setTime(0, 0)->format('Y-m-d');
    $chartEndDate   = $now->modify('+1 day')->setTime(0, 0)->format('Y-m-d'); // exklusiv
    $frequencyUnit  = 'day';
} elseif ($zeitraum === '1y') {
    $chartStartDate = (new DateTimeImmutable((string)$startDate))
        ->modify('first day of this month')
        ->setTime(0, 0)
        ->format('Y-m-d');
    $chartEndDate = $now
        ->modify('first day of next month')
        ->setTime(0, 0)
        ->format('Y-m-d'); // exklusiv
    $frequencyUnit = 'month';
} elseif ($isJahr) {
    $chartStartDate = sprintf('%04d-01-01', (int)$zeitraum);
    $chartEndDate   = sprintf('%04d-01-01', (int)$zeitraum + 1); // exklusiv
    $frequencyUnit  = 'month';
} else {
    $minTimestamp = null;
    $maxTimestamp = null;

    $boundsResult = $fitconn->query("
        SELECT MIN(tstamp) AS min_ts, MAX(tstamp) AS max_ts
        FROM kalorien
        WHERE tstamp IS NOT NULL
    ");

    if ($boundsResult) {
        $boundsRow = $boundsResult->fetch_assoc();
        $minTimestamp = $boundsRow['min_ts'] ?? null;
        $maxTimestamp = $boundsRow['max_ts'] ?? null;
        $boundsResult->free();
    }

    if ($minTimestamp !== null && $maxTimestamp !== null) {
        $minObj = new DateTimeImmutable((string)$minTimestamp);
        $maxObj = new DateTimeImmutable((string)$maxTimestamp);

        $chartStartDate = $minObj->modify('first day of this month')->setTime(0, 0)->format('Y-m-d');
        $chartEndDate   = $maxObj->modify('first day of next month')->setTime(0, 0)->format('Y-m-d'); // exklusiv
    } else {
        $chartStartDate = $now->modify('first day of this month')->setTime(0, 0)->format('Y-m-d');
        $chartEndDate   = $now->modify('first day of next month')->setTime(0, 0)->format('Y-m-d');
    }

    $frequencyUnit = 'month';
}

$totalEntries = 0;
$totalCalories = 0.0;
$totalProtein = 0.0;
$totalCarbs = 0.0;
$totalFat = 0.0;
$totalAlcohol = 0.0;

foreach ($categoryRows as $row) {
    $totalEntries += $row['entries'];
    $totalCalories += $row['calories'];
    $totalProtein += $row['protein'];
    $totalCarbs += $row['carbs'];
    $totalFat += $row['fat'];
    $totalAlcohol += $row['alcohol'];
}

$page_title = 'Food Dashboard';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div id="foodDashboard" class="lt-page food-dashboard-page">
    <div class="lt-topbar food-dashboard-topbar">
        <div>
            <h1 class="ueberschrift food-dashboard-title">Food Dashboard</h1>
            <div class="food-dashboard-subtitle">
                <?= htmlspecialchars($zeitraumLabel, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($zeitraumDetail, ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>

        <form method="get" class="food-dashboard-period-form" id="foodDashboardPeriodForm">
            <input type="hidden" name="kategorie" id="foodDashboardPeriodCategory" value="<?= htmlspecialchars($requestedCategory, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="filter_kategorie" id="foodDashboardPeriodTableCategory" value="<?= htmlspecialchars($requestedTableCategory, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="gruppen" id="foodDashboardPeriodGroups" value="<?= htmlspecialchars(json_encode($requestedGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="gerichte" id="foodDashboardPeriodFoods" value="<?= htmlspecialchars(json_encode($requestedFoods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="metric" id="foodDashboardPeriodMetric" value="<?= htmlspecialchars($requestedMetric, ENT_QUOTES, 'UTF-8') ?>">

            <label for="zeitraum" class="lt-label">Zeitraum</label>
            <select id="zeitraum" name="zeitraum" class="kategorie-select">
                <option value="1m" <?= $zeitraum === '1m' ? 'selected' : '' ?>>Letzter Monat</option>
                <option value="1y" <?= $zeitraum === '1y' ? 'selected' : '' ?>>Letztes Jahr</option>

                <?php foreach ($verfuegbareJahre as $jahrOption): ?>
                    <option
                        value="<?= (int)$jahrOption ?>"
                        <?= $zeitraum === (string)$jahrOption ? 'selected' : '' ?>
                    >
                        <?= (int)$jahrOption ?>
                    </option>
                <?php endforeach; ?>

                <option value="all" <?= $zeitraum === 'all' ? 'selected' : '' ?>>Insgesamt</option>
            </select>
        </form>
    </div>

    <?php if (!empty($loadError)): ?>
        <div class="food-dashboard-error">
            <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="food-dashboard-metric-tabs" role="tablist" aria-label="Messwert auswählen">
        <button type="button" class="food-dashboard-metric-tab is-active" data-metric="calories">Kalorien</button>
        <button type="button" class="food-dashboard-metric-tab" data-metric="protein">Protein</button>
        <button type="button" class="food-dashboard-metric-tab" data-metric="carbs">Carbs</button>
        <button type="button" class="food-dashboard-metric-tab" data-metric="fat">Fett</button>
        <button type="button" class="food-dashboard-metric-tab" data-metric="entries">Anzahl</button>
    </div>

    <div class="food-dashboard-main-grid">
        <div class="food-dashboard-chart-card" id="foodDashboardChartCard">
            <div class="food-dashboard-card-head">
                <div class="food-dashboard-chart-title-row">
                    <button
                        type="button"
                        id="shareChartBack"
                        class="food-dashboard-back"
                        aria-label="Zurück zu den Gruppen"
                        title="Zurück zu den Gruppen"
                        hidden
                    ><span aria-hidden="true">←</span></button>
                    <h2 id="shareChartTitleText">Anteil der Gruppen an den Kalorien</h2>
                </div>
                <div id="foodDashboardLineLegend" class="food-dashboard-line-legend" hidden aria-label="Ausgewählte Gruppen oder Gerichte"></div>
                <strong id="shareChartTotal"><?= number_format($totalCalories, 0, ',', '.') ?> kcal</strong>
            </div>
            <div class="food-dashboard-chart-wrap" id="foodDashboardChartWrap">
                <div class="food-dashboard-chart-canvas-wrap">
                    <canvas id="foodShareChart"></canvas>
                </div>
                <div id="foodDashboardPieLegend" class="food-dashboard-pie-legend" aria-label="Diagrammlegende"></div>
            </div>
        </div>

        <section class="food-dashboard-table-card">
            <div class="food-dashboard-table-wrap">
                <table class="food-dashboard-table">
                    <thead>
                    <tr>
                        <th>Gruppe</th>
                        <th class="food-dashboard-num">Wert</th>
                    </tr>
                    </thead>
                    <tbody id="categoryRankingBody"></tbody>
                </table>
            </div>
        </section>

        <section class="food-dashboard-table-card">
            <div class="food-dashboard-table-wrap">
                <table class="food-dashboard-table">
                    <thead>
                    <tr>
                        <th>Gericht</th>
                        <th class="food-dashboard-num">Wert</th>
                    </tr>
                    </thead>
                    <tbody id="foodRankingBody"></tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/luxon@3.4.3/build/global/luxon.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.3.1"></script>
<script>
(() => {
    const categoryData = <?= json_encode($categoryRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const foodData = <?= json_encode($foodRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const foodFrequencyData = <?= json_encode($foodFrequencyRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const periodMode = <?= json_encode($zeitraum, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialCategory = <?= json_encode($requestedCategory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialTableCategory = <?= json_encode($requestedTableCategory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialGroups = <?= json_encode($requestedGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialFoods = <?= json_encode($requestedFoods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialMetric = <?= json_encode($requestedMetric, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const frequencyUnit = <?= json_encode($frequencyUnit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const frequencyChartStart = <?= json_encode($chartStartDate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const frequencyChartEnd = <?= json_encode($chartEndDate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const metricMeta = {
        calories: { label: 'Kalorien', totalLabel: 'Kalorien gesamt', unit: 'kcal', decimals: 0 },
        protein:  { label: 'Protein', totalLabel: 'Protein gesamt', unit: 'g', decimals: 1 },
        carbs:    { label: 'Carbs', totalLabel: 'Carbs gesamt', unit: 'g', decimals: 1 },
        fat:      { label: 'Fett', totalLabel: 'Fett gesamt', unit: 'g', decimals: 1 },
        entries:  { label: 'Anzahl', totalLabel: 'Einträge gesamt', unit: '', decimals: 0 }
    };

    const categoryBody = document.getElementById('categoryRankingBody');
    const foodBody = document.getElementById('foodRankingBody');
    const shareChartTitleText = document.getElementById('shareChartTitleText');
    const shareChartTotal = document.getElementById('shareChartTotal');
    const shareChartBack = document.getElementById('shareChartBack');
    const chartWrap = document.getElementById('foodDashboardChartWrap');
    const chartCard = document.getElementById('foodDashboardChartCard');
    const pieLegend = document.getElementById('foodDashboardPieLegend');
    const lineLegend = document.getElementById('foodDashboardLineLegend');
    const tabs = Array.from(document.querySelectorAll('.food-dashboard-metric-tab'));
    const periodForm = document.getElementById('foodDashboardPeriodForm');
    const periodSelect = document.getElementById('zeitraum');
    const periodCategoryInput = document.getElementById('foodDashboardPeriodCategory');
    const periodTableCategoryInput = document.getElementById('foodDashboardPeriodTableCategory');
    const periodGroupsInput = document.getElementById('foodDashboardPeriodGroups');
    const periodFoodsInput = document.getElementById('foodDashboardPeriodFoods');
    const periodMetricInput = document.getElementById('foodDashboardPeriodMetric');

    function normalizeGroupSelections(values, fallbackCategory = '') {
        const source = Array.isArray(values) ? values : [];
        const out = [];
        const seen = new Set();

        [...source, fallbackCategory].forEach(value => {
            const name = String(value ?? '').trim();
            if (!name || seen.has(name)) return;
            seen.add(name);
            out.push(name);
        });

        return out.slice(0, 25);
    }

    function normalizeFoodSelections(values, fallbackCategory = '') {
        if (!Array.isArray(values)) return [];

        const out = [];
        const seen = new Set();

        values.forEach(value => {
            let name = '';
            let category = '';

            if (typeof value === 'string') {
                name = value.trim();
                category = String(fallbackCategory || '').trim();
            } else if (value && typeof value === 'object') {
                name = String(value.name ?? '').trim();
                category = String(value.category ?? '').trim();
            }

            if (!name || !category) return;

            const key = `${category}\u0000${name}`;
            if (seen.has(key)) return;

            seen.add(key);
            out.push({ name, category });
        });

        return out.slice(0, 25);
    }

    let currentMetric = metricMeta[initialMetric] ? initialMetric : 'calories';
    let selectedCategory = initialCategory !== '' ? initialCategory : null;
    let selectedGroups = normalizeGroupSelections(initialGroups, selectedCategory ?? '');
    let tableCategoryFilter = initialTableCategory !== ''
        ? initialTableCategory
        : selectedCategory;
    let selectedFoods = normalizeFoodSelections(initialFoods, selectedCategory ?? '');
    let shareChart = null;

    if (selectedCategory === null && selectedFoods.length > 0) {
        selectedCategory = selectedFoods[0].category;
    }

    if (selectedCategory === null && selectedGroups.length > 0) {
        selectedCategory = selectedGroups[0];
    }

    if (selectedCategory !== null && !selectedGroups.includes(selectedCategory)) {
        selectedGroups = normalizeGroupSelections([selectedCategory, ...selectedGroups]);
    }

    if (window.luxon?.Settings) {
        window.luxon.Settings.defaultLocale = 'de';
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char]));
    }

    function numberValue(value) {
        const num = Number(value);
        return Number.isFinite(num) ? num : 0;
    }

    function isGroupSelected(groupName) {
        return selectedGroups.includes(groupName);
    }

    function isFoodSelected(foodName, foodCategory) {
        return selectedFoods.some(item =>
            item.name === foodName && item.category === foodCategory
        );
    }

    function selectedFoodIndex(foodName, foodCategory) {
        return selectedFoods.findIndex(item =>
            item.name === foodName && item.category === foodCategory
        );
    }

    function metricTotal(metric) {
        return categoryData.reduce((sum, row) => sum + numberValue(row[metric]), 0);
    }

    function formatMetric(value, metric) {
        if (metric === 'entries') {
            return formatEntries(value);
        }

        const meta = metricMeta[metric];
        const formatted = new Intl.NumberFormat('de-DE', {
            minimumFractionDigits: meta.decimals,
            maximumFractionDigits: meta.decimals
        }).format(numberValue(value));
        return `${formatted} ${meta.unit}`;
    }

    // In den beiden Ranking-Tabellen immer kompakt ohne Nachkommastellen.
    function formatTableMetric(value, metric) {
        const formatted = new Intl.NumberFormat('de-DE', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(numberValue(value));

        if (metric === 'entries') {
            return formatted;
        }

        return `${formatted} ${metricMeta[metric].unit}`;
    }

    function formatEntries(value) {
        const entries = Math.max(0, Math.round(numberValue(value)));
        const formatted = new Intl.NumberFormat('de-DE').format(entries);
        return `${formatted} ${entries === 1 ? 'Eintrag' : 'Einträge'}`;
    }

    function formatShare(value, total) {
        if (total <= 0) return '0,0 %';
        return new Intl.NumberFormat('de-DE', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1
        }).format((value / total) * 100) + ' %';
    }

    function rankedRows(rows, metric) {
        return rows
            .filter(row => numberValue(row[metric]) > 0)
            .slice()
            .sort((a, b) => {
                const metricDiff = numberValue(b[metric]) - numberValue(a[metric]);
                if (metricDiff !== 0) return metricDiff;
                return numberValue(b.entries) - numberValue(a.entries);
            });
    }

    function tableVisibleRowCount(body) {
        // Im Desktop-Layout ist die Höhe der drei Karten fest an die verfügbare
        // Viewport-Höhe gekoppelt. Dadurch können wir exakt so viele Tabellenzeilen
        // rendern, wie ohne Scrollen in die Karte passen. Die Daten selbst sind
        // bereits vollständig geladen; hier wird nur die sichtbare Anzahl begrenzt.
        if (window.matchMedia('(max-width: 1180px)').matches) {
            return 12;
        }

        const wrap = body?.closest('.food-dashboard-table-wrap');
        const table = body?.closest('.food-dashboard-table');
        const head = table?.querySelector('thead');
        const dashboard = document.getElementById('foodDashboard');

        if (!wrap || !dashboard) return 12;

        const rowHeightRaw = getComputedStyle(dashboard)
            .getPropertyValue('--food-dashboard-table-row-height');
        const rowHeight = Math.max(1, parseFloat(rowHeightRaw) || 34);
        const headHeight = head?.getBoundingClientRect().height || rowHeight;
        const availableHeight = Math.max(0, wrap.clientHeight - headHeight);

        return Math.max(1, Math.min(50, Math.floor(availableHeight / rowHeight)));
    }

    function renderCategoryRanking(metric) {
        // Die Gruppentabelle bleibt in jeder Drilldown-Ebene global. Die Anzahl
        // sichtbarer Zeilen richtet sich nach dem tatsächlich verfügbaren Platz.
        const rows = rankedRows(categoryData, metric)
            .slice(0, tableVisibleRowCount(categoryBody));

        if (!rows.length) {
            categoryBody.innerHTML = '<tr><td colspan="2" class="food-dashboard-empty">Keine Werte im gewählten Zeitraum.</td></tr>';
            return;
        }

        categoryBody.innerHTML = rows.map(row => {
            const value = numberValue(row[metric]);
            const isOverview = selectedCategory === null && selectedFoods.length === 0;
            const isFoodTimeline = selectedFoods.length > 0;
            const isSelected = isFoodTimeline
                ? (tableCategoryFilter !== null && row.name === tableCategoryFilter)
                : isGroupSelected(row.name);

            let rowTitle;
            if (isOverview) {
                rowTitle = `Gruppe ${row.name} anzeigen`;
            } else if (isFoodTimeline) {
                rowTitle = tableCategoryFilter === row.name
                    ? `Filter ${row.name} aufheben`
                    : `Gerichte nach ${row.name} filtern`;
            } else if (isGroupSelected(row.name) && selectedGroups.length > 1) {
                rowTitle = `${row.name} aus dem Gruppenvergleich entfernen`;
            } else if (isGroupSelected(row.name)) {
                rowTitle = tableCategoryFilter === row.name
                    ? `Filter ${row.name} aufheben`
                    : `Gerichte nach ${row.name} filtern`;
            } else {
                rowTitle = `${row.name} zum Gruppenvergleich hinzufügen`;
            }

            const rowClasses = [
                'food-dashboard-food-clickable',
                isSelected ? 'is-selected-food' : ''
            ].filter(Boolean).join(' ');

            return `
                <tr
                    class="${rowClasses}"
                    data-category-name="${escapeHtml(row.name)}"
                    tabindex="0"
                    role="button"
                    title="${escapeHtml(rowTitle)}"
                >
                    <td class="food-dashboard-name-cell" title="${escapeHtml(row.name)}"><strong>${escapeHtml(row.name)}</strong></td>
                    <td class="food-dashboard-num">${escapeHtml(formatTableMetric(value, metric))}</td>
                </tr>
            `;
        }).join('');
    }

    function renderFoodRanking(metric) {
        const sourceRows = tableCategoryFilter === null
            ? foodData
            : foodData.filter(row => row.category === tableCategoryFilter);

        const rows = rankedRows(sourceRows, metric)
            .slice(0, tableVisibleRowCount(foodBody));

        if (!rows.length) {
            foodBody.innerHTML = '<tr><td colspan="2" class="food-dashboard-empty">Keine Werte im gewählten Zeitraum.</td></tr>';
            return;
        }

        foodBody.innerHTML = rows.map(row => {
            const value = numberValue(row[metric]);
            const isSelected = isFoodSelected(row.name, row.category);
            const rowClasses = [
                'food-dashboard-food-clickable',
                isSelected ? 'is-selected-food' : ''
            ].filter(Boolean).join(' ');

            return `
                <tr
                    class="${rowClasses}"
                    data-food-name="${escapeHtml(row.name)}"
                    data-food-category="${escapeHtml(row.category)}"
                    tabindex="0"
                    role="button"
                    title="${isSelected ? 'Gericht abwählen' : 'Gericht auswählen'}: ${escapeHtml(row.name)}"
                >
                    <td class="food-dashboard-name-cell" title="${escapeHtml(row.name)}"><strong>${escapeHtml(row.name)}</strong></td>
                    <td class="food-dashboard-num">${escapeHtml(formatTableMetric(value, metric))}</td>
                </tr>
            `;
        }).join('');
    }

    function renderRankings(metric) {
        renderCategoryRanking(metric);
        renderFoodRanking(metric);
    }

    function destroyShareChart() {
        if (shareChart) {
            shareChart.destroy();
            shareChart = null;
        }
    }

    function setChartModeClass(isFrequencyDetail) {
        chartWrap?.classList.toggle('is-frequency-chart', isFrequencyDetail);
        chartCard?.classList.toggle('is-frequency-detail', isFrequencyDetail);
        if (pieLegend) {
            pieLegend.hidden = isFrequencyDetail;
        }
        if (lineLegend) {
            lineLegend.hidden = !isFrequencyDetail;
        }
    }

    function renderBalancedPieLegend(rows, colors) {
        if (!pieLegend) return;

        if (!rows.length) {
            pieLegend.innerHTML = '';
            return;
        }

        const columnCount = rows.length > 10 ? 2 : 1;
        const rowsPerColumn = Math.ceil(rows.length / columnCount);
        const columns = [];

        for (let columnIndex = 0; columnIndex < columnCount; columnIndex++) {
            const start = columnIndex * rowsPerColumn;
            const end = Math.min(start + rowsPerColumn, rows.length);
            const items = [];

            for (let index = start; index < end; index++) {
                const row = rows[index];
                items.push(`
                    <div class="food-dashboard-pie-legend-item" title="${escapeHtml(row.name)}">
                        <span class="food-dashboard-pie-legend-dot" style="background:${colors[index]}"></span>
                        <span class="food-dashboard-pie-legend-label">${escapeHtml(row.name)}</span>
                    </div>
                `);
            }

            columns.push(`<div class="food-dashboard-pie-legend-column">${items.join('')}</div>`);
        }

        pieLegend.innerHTML = columns.join('');
    }

    // Wie in Start.php: Rasterlinien liegen auf den Periodenanfängen, die sichtbaren
    // Beschriftungen werden separat exakt mittig zwischen zwei Trennern gezeichnet.
    const midPeriodLabelsPlugin = {
        id: 'foodDashboardMidPeriodLabels',
        afterDraw(chart) {
            const scale = chart?.scales?.x;
            if (!scale || scale.type !== 'time') return;

            const unit = scale.options?.midPeriodUnit;
            if (!['day', 'month', 'year'].includes(unit)) return;
            if (!window.luxon?.DateTime) return;

            const minMs = Number(scale.min);
            const maxMs = Number(scale.max);
            if (!Number.isFinite(minMs) || !Number.isFinite(maxMs) || maxMs <= minMs) return;

            const DateTime = window.luxon.DateTime;
            let cursor = DateTime.fromMillis(minMs).startOf(unit);
            const periods = [];

            while (cursor.toMillis() < maxMs) {
                const next = cursor.plus({ [`${unit}s`]: 1 });
                const visibleStart = Math.max(cursor.toMillis(), minMs);
                const visibleEnd = Math.min(next.toMillis(), maxMs);

                if (visibleEnd > visibleStart) {
                    periods.push({ start: cursor, visibleStart, visibleEnd });
                }

                cursor = next;
                if (periods.length > 5000) break;
            }

            if (!periods.length) return;

            const width = Number(scale.width) || 0;
            const targetPx = unit === 'day' ? 34 : (unit === 'month' ? 54 : 48);
            const labelsThatFit = Math.max(1, Math.floor(width / targetPx));
            const step = Math.max(1, Math.ceil(periods.length / labelsThatFit));

            let fontStr = '12px sans-serif';
            try {
                if (Chart?.helpers?.toFont) {
                    fontStr = Chart.helpers.toFont(scale.options?.ticks?.font).string;
                }
            } catch (_) {}

            const color = scale.options?.ticks?.color ?? Chart.defaults.color ?? '#666';
            const ctx = chart.ctx;

            ctx.save();
            ctx.font = fontStr;
            ctx.fillStyle = color;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';

            const labelY = scale.bottom - 2;

            periods.forEach((period, index) => {
                if (index % step !== 0) return;

                const midMs = period.visibleStart + ((period.visibleEnd - period.visibleStart) / 2);
                const x = scale.getPixelForValue(midMs);

                let text = '';
                if (unit === 'day') {
                    text = period.start.toFormat('dd');
                } else if (unit === 'month') {
                    text = period.start.setLocale('de').toFormat('MMM').replace('.', '');
                } else {
                    text = period.start.toFormat('yyyy');
                }

                ctx.fillText(text, x, labelY);
            });

            ctx.restore();
        }
    };

    Chart.register(midPeriodLabelsPlugin);

    function groupTimelineRowsForCategory(categoryName) {
        if (!categoryName) return [];
        return foodFrequencyData.filter(row => row.category === categoryName);
    }

    function buildGroupTimelineSeries(categoryName, metric) {
        if (!window.luxon?.DateTime || !categoryName) return [];

        const DateTime = window.luxon.DateTime;
        const byBucket = new Map();

        groupTimelineRowsForCategory(categoryName).forEach(row => {
            const bucket = String(row.bucket);
            byBucket.set(
                bucket,
                (byBucket.get(bucket) || 0) + numberValue(row[metric])
            );
        });

        let cursor = DateTime.fromISO(frequencyChartStart).startOf(frequencyUnit);
        const end = DateTime.fromISO(frequencyChartEnd).startOf(frequencyUnit);
        const points = [];

        while (cursor < end) {
            const bucket = cursor.toISODate();
            const value = byBucket.get(bucket) ?? 0;

            let pointX;
            if (frequencyUnit === 'day') {
                pointX = cursor.plus({ hours: 12 }).toMillis();
            } else {
                pointX = cursor.plus({ days: Math.floor(cursor.daysInMonth / 2), hours: 12 }).toMillis();
            }

            points.push({ x: pointX, y: value });
            cursor = frequencyUnit === 'day'
                ? cursor.plus({ days: 1 })
                : cursor.plus({ months: 1 });

            if (points.length > 5000) break;
        }

        return points;
    }

    function foodTimelineRowsForFood(foodSelection) {
        if (!foodSelection?.name || !foodSelection?.category) return [];

        return foodFrequencyData.filter(row =>
            row.category === foodSelection.category && row.name === foodSelection.name
        );
    }

    function buildFoodTimelineSeries(foodSelection, metric) {
        if (!window.luxon?.DateTime) return [];

        const DateTime = window.luxon.DateTime;
        const sparseRows = foodTimelineRowsForFood(foodSelection);
        const byBucket = new Map(
            sparseRows.map(row => [String(row.bucket), numberValue(row[metric])])
        );

        let cursor = DateTime.fromISO(frequencyChartStart).startOf(frequencyUnit);
        const end = DateTime.fromISO(frequencyChartEnd).startOf(frequencyUnit);
        const points = [];

        while (cursor < end) {
            const bucket = cursor.toISODate();
            const value = byBucket.get(bucket) ?? 0;

            let pointX;
            if (frequencyUnit === 'day') {
                pointX = cursor.plus({ hours: 12 }).toMillis();
            } else {
                pointX = cursor.plus({ days: Math.floor(cursor.daysInMonth / 2), hours: 12 }).toMillis();
            }

            points.push({ x: pointX, y: value });
            cursor = frequencyUnit === 'day'
                ? cursor.plus({ days: 1 })
                : cursor.plus({ months: 1 });

            if (points.length > 5000) break;
        }

        return points;
    }

    function frequencyXAxisConfig() {
        const allYears = periodMode === 'all';
        const axisUnit = allYears ? 'year' : frequencyUnit;

        return {
            type: 'time',
            min: frequencyChartStart,
            max: frequencyChartEnd,
            bounds: 'ticks',
            time: {
                unit: axisUnit,
                tooltipFormat: frequencyUnit === 'day' ? 'dd.MM.yyyy' : 'MM.yyyy'
            },
            midPeriodUnit: axisUnit,
            grid: {
                display: true,
                drawTicks: true
            },
            ticks: {
                autoSkip: false,
                maxRotation: 0,
                minRotation: 0,
                callback: () => ' '
            }
        };
    }

    function lineColor(index) {
        if (index === 0) return '#ff6b00';
        return `hsl(${Math.round(((index - 1) * 137.508 + 205) % 360)} 68% 48%)`;
    }

    function renderLineLegend(items) {
        if (!lineLegend) return;

        lineLegend.innerHTML = items.map(item => `
            <div class="food-dashboard-line-legend-item" title="${escapeHtml(item.title)}">
                <span class="food-dashboard-line-legend-swatch" style="background:${item.color}"></span>
                <span class="food-dashboard-line-legend-label">${escapeHtml(item.label)}</span>
            </div>
        `).join('');
    }

    function renderTimelineChart(metric, datasetMeta, titleText, backTitle, backAriaLabel) {
        const meta = metricMeta[metric];
        const total = datasetMeta.reduce(
            (sum, dataset) => sum + dataset.points.reduce(
                (datasetSum, point) => datasetSum + numberValue(point.y),
                0
            ),
            0
        );

        shareChartTitleText.textContent = titleText;
        shareChartTotal.textContent = formatMetric(total, metric);

        shareChartBack.hidden = false;
        shareChartBack.title = backTitle;
        shareChartBack.setAttribute('aria-label', backAriaLabel);
        setChartModeClass(true);
        if (pieLegend) pieLegend.innerHTML = '';
        renderLineLegend(datasetMeta);

        destroyShareChart();

        const canvas = document.getElementById('foodShareChart');
        if (!canvas) return;

        shareChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                datasets: datasetMeta.map(dataset => ({
                    label: dataset.label,
                    data: dataset.points,
                    parsing: false,
                    borderColor: dataset.color,
                    backgroundColor: dataset.color,
                    borderWidth: 3,
                    pointRadius: frequencyUnit === 'day' ? 3 : 4,
                    pointHoverRadius: 6,
                    pointHitRadius: 10,
                    pointBackgroundColor: dataset.color,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 1.5,
                    fill: false,
                    tension: 0.22,
                    cubicInterpolationMode: 'monotone',
                    spanGaps: false
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    axis: 'x',
                    intersect: false
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title(items) {
                                const rawX = items?.[0]?.parsed?.x;
                                if (!Number.isFinite(rawX) || !window.luxon?.DateTime) return '';

                                const date = window.luxon.DateTime.fromMillis(rawX);
                                return frequencyUnit === 'day'
                                    ? date.toFormat('dd.MM.yyyy')
                                    : date.toFormat('MM.yyyy');
                            },
                            label(context) {
                                return `${context.dataset.label}: ${formatMetric(context.parsed?.y, metric)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: frequencyXAxisConfig(),
                    y: {
                        beginAtZero: true,
                        ticks: metric === 'entries'
                            ? { precision: 0 }
                            : {},
                        title: {
                            display: false,
                            text: meta.label
                        }
                    }
                }
            }
        });
    }

    function renderGroupTimelineChart(metric) {
        const meta = metricMeta[metric];
        const datasetMeta = selectedGroups.map((categoryName, index) => ({
            name: categoryName,
            label: categoryName,
            title: categoryName,
            color: lineColor(index),
            points: buildGroupTimelineSeries(categoryName, metric)
        }));

        renderTimelineChart(
            metric,
            datasetMeta,
            `${selectedGroups.length} Gruppen · ${meta.label}`,
            `Zurück zur Gruppe ${selectedCategory ?? selectedGroups[0] ?? ''}`,
            `Zurück zur Gruppe ${selectedCategory ?? selectedGroups[0] ?? ''}`
        );
    }

    function renderFoodTimelineChart(metric) {
        const meta = metricMeta[metric];
        const nameCounts = selectedFoods.reduce((map, item) => {
            map.set(item.name, (map.get(item.name) || 0) + 1);
            return map;
        }, new Map());

        const datasetMeta = selectedFoods.map((foodSelection, index) => {
            const duplicateName = (nameCounts.get(foodSelection.name) || 0) > 1;
            const label = duplicateName
                ? `${foodSelection.name} · ${foodSelection.category}`
                : foodSelection.name;

            return {
                name: foodSelection.name,
                category: foodSelection.category,
                label,
                title: `${foodSelection.name} · ${foodSelection.category}`,
                color: lineColor(index),
                points: buildFoodTimelineSeries(foodSelection, metric)
            };
        });

        renderTimelineChart(
            metric,
            datasetMeta,
            selectedFoods.length === 1
                ? `${selectedFoods[0].name} · ${meta.label}`
                : `${selectedFoods.length} Gerichte · ${meta.label}`,
            `Zurück zur Gruppe ${selectedCategory ?? selectedFoods[0]?.category ?? ''}`,
            `Zurück zur Gruppe ${selectedCategory ?? selectedFoods[0]?.category ?? ''}`
        );
    }

    function renderPieChart(metric) {
        const meta = metricMeta[metric];
        const isCategoryDrilldown = selectedCategory !== null;

        let rows;
        let total;

        if (isCategoryDrilldown) {
            rows = rankedRows(
                foodData.filter(row => row.category === selectedCategory),
                metric
            );
            total = rows.reduce((sum, row) => sum + numberValue(row[metric]), 0);

            shareChartTitleText.textContent = `${selectedCategory} · ${meta.label}`;
            shareChartBack.hidden = false;
            shareChartBack.title = 'Zurück zu den Gruppen';
            shareChartBack.setAttribute('aria-label', 'Zurück zu den Gruppen');
        } else {
            rows = rankedRows(categoryData, metric);
            total = metricTotal(metric);

            shareChartTitleText.textContent = metric === 'entries'
                ? 'Anteil der Gruppen an den Einträgen'
                : `Anteil der Gruppen an ${meta.label === 'Kalorien' ? 'den Kalorien' : meta.label}`;
            shareChartBack.hidden = true;
            shareChartBack.title = 'Zurück zu den Gruppen';
            shareChartBack.setAttribute('aria-label', 'Zurück zu den Gruppen');
        }

        shareChartTotal.textContent = formatMetric(total, metric);
        setChartModeClass(false);
        destroyShareChart();

        const canvas = document.getElementById('foodShareChart');
        if (!canvas || !rows.length) {
            if (pieLegend) pieLegend.innerHTML = '';
            return;
        }

        const pieColors = rows.map((_, index) => `hsl(${Math.round((index * 137.508) % 360)} 68% 55%)`);
        renderBalancedPieLegend(rows, pieColors);

        shareChart = new Chart(canvas.getContext('2d'), {
            type: 'pie',
            data: {
                labels: rows.map(row => row.name),
                datasets: [{
                    data: rows.map(row => numberValue(row[metric])),
                    backgroundColor: pieColors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onClick(event, elements) {
                    if (!elements.length) return;

                    const index = elements[0].index;
                    const clickedRow = rows[index];
                    if (!clickedRow) return;

                    if (selectedCategory === null) {
                        // Startansicht: Diagramm-Drilldown und Tabellenfilter müssen
                        // dieselbe Gruppe übernehmen.
                        selectedCategory = clickedRow.name;
                        selectedGroups = [clickedRow.name];
                        tableCategoryFilter = clickedRow.name;
                        selectedFoods = [];
                    } else {
                        // Gruppenansicht: Gericht öffnen und die Tabelle auf die
                        // zugehörige Gruppe setzen, damit die Auswahl sichtbar bleibt.
                        selectedFoods = [{
                            name: clickedRow.name,
                            category: selectedCategory
                        }];
                        tableCategoryFilter = selectedCategory;
                    }

                    syncPeriodFormState();
                    renderChart(metric);
                    renderRankings(metric);
                },
                onHover(event, elements) {
                    const target = event.native?.target;
                    if (!target) return;
                    target.style.cursor = elements.length ? 'pointer' : 'default';
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                const value = numberValue(context.raw);
                                return `${context.label}: ${formatMetric(value, metric)} (${formatShare(value, total)})`;
                            }
                        }
                    }
                }
            }
        });
    }

    function renderChart(metric) {
        if (selectedCategory !== null && selectedFoods.length > 0) {
            renderFoodTimelineChart(metric);
            return;
        }

        if (selectedFoods.length === 0 && selectedGroups.length > 1) {
            renderGroupTimelineChart(metric);
            return;
        }

        renderPieChart(metric);
    }

    function syncPeriodFormState() {
        if (periodCategoryInput) periodCategoryInput.value = selectedCategory ?? '';
        if (periodTableCategoryInput) periodTableCategoryInput.value = tableCategoryFilter ?? '';
        if (periodGroupsInput) periodGroupsInput.value = JSON.stringify(selectedGroups);
        if (periodFoodsInput) periodFoodsInput.value = JSON.stringify(selectedFoods);
        if (periodMetricInput) periodMetricInput.value = currentMetric;
    }

    function applyMetric(metric) {
        if (!metricMeta[metric]) return;
        currentMetric = metric;
        syncPeriodFormState();

        tabs.forEach(tab => {
            const active = tab.dataset.metric === metric;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        renderChart(metric);
        renderRankings(metric);
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => applyMetric(tab.dataset.metric || 'calories'));
    });

    function openCategoryFromRow(row) {
        const categoryName = row?.dataset?.categoryName;
        if (!categoryName) return;

        const isOverview = selectedCategory === null && selectedFoods.length === 0;
        const isFoodTimeline = selectedFoods.length > 0;

        if (isOverview) {
            selectedCategory = categoryName;
            selectedGroups = [categoryName];
            tableCategoryFilter = categoryName;
            selectedFoods = [];

            syncPeriodFormState();
            renderChart(currentMetric);
            renderRankings(currentMetric);
            return;
        }

        if (isFoodTimeline) {
            // In der Gerichteansicht bleibt die Gruppentabelle weiterhin nur Filter
            // für die Gerichte-Tabelle; das Liniendiagramm der Gerichte bleibt unverändert.
            tableCategoryFilter = tableCategoryFilter === categoryName
                ? null
                : categoryName;

            syncPeriodFormState();
            renderRankings(currentMetric);
            return;
        }

        const groupIndex = selectedGroups.indexOf(categoryName);

        if (groupIndex >= 0) {
            if (selectedGroups.length === 1) {
                // Bei nur einer Gruppe bleibt das bisherige Verhalten erhalten:
                // derselbe Klick schaltet lediglich den Gerichte-Tabellenfilter um.
                tableCategoryFilter = tableCategoryFilter === categoryName
                    ? null
                    : categoryName;
            } else {
                // Aus dem Gruppenvergleich entfernen. Bei nur noch einer verbleibenden
                // Gruppe fällt die Ansicht automatisch auf deren Gerichte-Kreisdiagramm zurück.
                selectedGroups = selectedGroups.filter(name => name !== categoryName);

                if (selectedCategory === categoryName || !selectedGroups.includes(selectedCategory)) {
                    selectedCategory = selectedGroups[0] ?? null;
                }

                if (tableCategoryFilter === categoryName) {
                    tableCategoryFilter = selectedCategory;
                }
            }
        } else {
            // Weitere Gruppe zum Vergleich hinzufügen. Die Gerichte-Tabelle folgt
            // gleichzeitig der zuletzt angeklickten Gruppe.
            selectedGroups = [...selectedGroups, categoryName].slice(0, 25);
            tableCategoryFilter = categoryName;
        }

        syncPeriodFormState();
        renderChart(currentMetric);
        renderRankings(currentMetric);
    }

    function openFoodFromRow(row) {
        const foodName = row?.dataset?.foodName;
        const foodCategory = row?.dataset?.foodCategory;
        if (!foodName || !foodCategory) return;

        const index = selectedFoodIndex(foodName, foodCategory);
        const wasTimeline = selectedFoods.length > 0;

        if (!wasTimeline) {
            // Aus Gesamt- oder Gruppenansicht startet der Klick die Gerichteansicht.
            // Ein bereits aufgebauter Gruppenvergleich bleibt im Hintergrund erhalten,
            // damit der Zurück-Button wieder dorthin führen kann.
            if (selectedCategory === null) {
                selectedCategory = foodCategory;
            }
            if (!selectedGroups.length) {
                selectedGroups = [foodCategory];
            }
            tableCategoryFilter = foodCategory;
            selectedFoods = [{ name: foodName, category: foodCategory }];
        } else if (index >= 0) {
            // In der Gerichteansicht toggelt derselbe Eintrag die Linie wieder aus.
            selectedFoods = selectedFoods.filter((_, itemIndex) => itemIndex !== index);
        } else {
            // Weitere Gerichte dürfen auch aus einer anderen, nur für die Tabelle
            // gewählten Gruppe stammen. Das Diagramm selbst bleibt in der Gerichteansicht.
            selectedFoods = [
                ...selectedFoods,
                { name: foodName, category: foodCategory }
            ];
        }

        syncPeriodFormState();
        renderChart(currentMetric);
        renderRankings(currentMetric);
    }

    categoryBody.addEventListener('click', event => {
        const row = event.target.closest('tr[data-category-name]');
        if (!row || !categoryBody.contains(row)) return;
        openCategoryFromRow(row);
    });

    categoryBody.addEventListener('keydown', event => {
        if (!['Enter', ' '].includes(event.key)) return;

        const row = event.target.closest('tr[data-category-name]');
        if (!row || !categoryBody.contains(row)) return;

        event.preventDefault();
        openCategoryFromRow(row);
    });

    foodBody.addEventListener('click', event => {
        const row = event.target.closest('tr[data-food-name]');
        if (!row || !foodBody.contains(row)) return;
        openFoodFromRow(row);
    });

    foodBody.addEventListener('keydown', event => {
        if (!['Enter', ' '].includes(event.key)) return;

        const row = event.target.closest('tr[data-food-name]');
        if (!row || !foodBody.contains(row)) return;

        event.preventDefault();
        openFoodFromRow(row);
    });

    shareChartBack.addEventListener('click', () => {
        if (selectedFoods.length > 0) {
            selectedFoods = [];
            tableCategoryFilter = selectedGroups.length > 1
                ? (tableCategoryFilter ?? selectedCategory)
                : selectedCategory;
        } else if (selectedGroups.length > 1) {
            const fallbackGroup = selectedCategory && selectedGroups.includes(selectedCategory)
                ? selectedCategory
                : selectedGroups[0];

            selectedCategory = fallbackGroup ?? null;
            selectedGroups = fallbackGroup ? [fallbackGroup] : [];
            tableCategoryFilter = selectedCategory;
        } else {
            selectedCategory = null;
            selectedGroups = [];
            tableCategoryFilter = null;
        }

        syncPeriodFormState();
        renderChart(currentMetric);
        renderRankings(currentMetric);
    });

    periodSelect?.addEventListener('change', () => {
        syncPeriodFormState();
        periodForm?.submit();
    });

    // Bei einer Änderung der verfügbaren Kartenhöhe (Fenstergröße, Zoom,
    // Responsive-Wechsel) wird die sichtbare Zeilenzahl automatisch neu bestimmt.
    let rankingResizeFrame = 0;
    const rankingResizeObserver = new ResizeObserver(() => {
        cancelAnimationFrame(rankingResizeFrame);
        rankingResizeFrame = requestAnimationFrame(() => {
            renderRankings(currentMetric);
        });
    });

    [
        categoryBody.closest('.food-dashboard-table-card'),
        foodBody.closest('.food-dashboard-table-card')
    ].filter(Boolean).forEach(card => rankingResizeObserver.observe(card));

    applyMetric(currentMetric);
})();
</script>

</body>
</html>
