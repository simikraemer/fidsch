<?php
// fit/FoodDashboard.php

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

if (!in_array($zeitraum, ['1m', 'all'], true) && !$isJahr) {
    $zeitraum = '1m';
    $isJahr = false;
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

    $sql = preg_replace('/\s+WHERE\s+tstamp\s+>=\s+\?\s+AND\s+tstamp\s+<\s+\?/i', '', $sql, 1);
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

// -----------------------------------------------------------------------------
// Daten: Gruppen und Gerichte
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

try {
    $categoryRows = foodDashboardNormalizeRows(
        foodDashboardFetchAll($fitconn, $categorySql, $startDate, $endDate)
    );
    $foodRows = foodDashboardNormalizeRows(
        foodDashboardFetchAll($fitconn, $foodSql, $startDate, $endDate)
    );
} catch (Throwable $e) {
    $categoryRows = [];
    $foodRows = [];
    $loadError = $e->getMessage();
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

$uniqueCategories = count($categoryRows);
$uniqueFoods = count($foodRows);

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

        <form method="get" class="food-dashboard-period-form">
            <label for="zeitraum" class="lt-label">Zeitraum</label>
            <select id="zeitraum" name="zeitraum" class="kategorie-select" onchange="this.form.submit()">
                <option value="1m" <?= $zeitraum === '1m' ? 'selected' : '' ?>>Letzter Monat</option>

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
    </div>

    <div class="food-dashboard-chart-card">
        <div class="food-dashboard-card-head">
            <div>
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
                <p id="shareChartDescription">Verteilung des gewählten Messwerts über alle Kategorien im Zeitraum. Gruppe anklicken für die Gerichte.</p>
            </div>
            <strong id="shareChartTotal"><?= number_format($totalCalories, 0, ',', '.') ?> kcal</strong>
        </div>
        <div class="food-dashboard-chart-wrap">
            <canvas id="foodShareChart"></canvas>
        </div>
    </div>

    <div class="food-dashboard-rankings">
        <section class="food-dashboard-table-card">
            <div class="food-dashboard-card-head">
                <div>
                    <h2>Gruppen</h2>
                    <p>Größter Anteil am ausgewählten Gesamtwert.</p>
                </div>
            </div>
            <div class="food-dashboard-table-wrap">
                <table class="food-dashboard-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Gruppe</th>
                        <th class="food-dashboard-num">Wert</th>
                        <th class="food-dashboard-num">Anteil</th>
                        <th class="food-dashboard-num">Einträge</th>
                    </tr>
                    </thead>
                    <tbody id="categoryRankingBody"></tbody>
                </table>
            </div>
        </section>

        <section class="food-dashboard-table-card">
            <div class="food-dashboard-card-head">
                <div>
                    <h2>Gerichte</h2>
                    <p>Einzelne Lebensmittel/Gerichte mit dem größten Anteil.</p>
                </div>
            </div>
            <div class="food-dashboard-table-wrap">
                <table class="food-dashboard-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Gericht</th>
                        <th>Gruppe</th>
                        <th class="food-dashboard-num">Wert</th>
                        <th class="food-dashboard-num">Anteil</th>
                        <th class="food-dashboard-num">Einträge</th>
                    </tr>
                    </thead>
                    <tbody id="foodRankingBody"></tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
    const categoryData = <?= json_encode($categoryRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const foodData = <?= json_encode($foodRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const metricMeta = {
        calories: { label: 'Kalorien', totalLabel: 'Kalorien gesamt', unit: 'kcal', decimals: 0 },
        protein:  { label: 'Protein', totalLabel: 'Protein gesamt', unit: 'g', decimals: 1 },
        carbs:    { label: 'Carbs', totalLabel: 'Carbs gesamt', unit: 'g', decimals: 1 },
        fat:      { label: 'Fett', totalLabel: 'Fett gesamt', unit: 'g', decimals: 1 }
    };

    const categoryBody = document.getElementById('categoryRankingBody');
    const foodBody = document.getElementById('foodRankingBody');
    const shareChartTitleText = document.getElementById('shareChartTitleText');
    const shareChartDescription = document.getElementById('shareChartDescription');
    const shareChartTotal = document.getElementById('shareChartTotal');
    const shareChartBack = document.getElementById('shareChartBack');
    const tabs = Array.from(document.querySelectorAll('.food-dashboard-metric-tab'));

    let currentMetric = 'calories';
    let selectedCategory = null;
    let shareChart = null;

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

    function metricTotal(metric) {
        return categoryData.reduce((sum, row) => sum + numberValue(row[metric]), 0);
    }

    function formatMetric(value, metric) {
        const meta = metricMeta[metric];
        const formatted = new Intl.NumberFormat('de-DE', {
            minimumFractionDigits: meta.decimals,
            maximumFractionDigits: meta.decimals
        }).format(numberValue(value));
        return `${formatted} ${meta.unit}`;
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

    function selectedCategoryMetricTotal(metric) {
        if (selectedCategory === null) {
            return metricTotal(metric);
        }

        return foodData
            .filter(row => row.category === selectedCategory)
            .reduce((sum, row) => sum + numberValue(row[metric]), 0);
    }

    function renderCategoryRanking(metric) {
        const total = metricTotal(metric);
        const allRows = rankedRows(categoryData, metric);
        let rows;

        if (selectedCategory !== null) {
            rows = allRows
                .map((row, index) => ({ ...row, globalRank: index + 1 }))
                .filter(row => row.name === selectedCategory);
        } else {
            rows = allRows.slice(0, 12).map((row, index) => ({ ...row, globalRank: index + 1 }));
        }

        if (!rows.length) {
            categoryBody.innerHTML = '<tr><td colspan="5" class="food-dashboard-empty">Keine Werte im gewählten Zeitraum.</td></tr>';
            return;
        }

        categoryBody.innerHTML = rows.map(row => {
            const value = numberValue(row[metric]);
            return `
                <tr>
                    <td class="food-dashboard-rank">${row.globalRank}</td>
                    <td><strong>${escapeHtml(row.name)}</strong></td>
                    <td class="food-dashboard-num">${escapeHtml(formatMetric(value, metric))}</td>
                    <td class="food-dashboard-num">${escapeHtml(formatShare(value, total))}</td>
                    <td class="food-dashboard-num">${new Intl.NumberFormat('de-DE').format(numberValue(row.entries))}</td>
                </tr>
            `;
        }).join('');
    }

    function renderFoodRanking(metric) {
        const sourceRows = selectedCategory === null
            ? foodData
            : foodData.filter(row => row.category === selectedCategory);

        const total = selectedCategory === null
            ? metricTotal(metric)
            : selectedCategoryMetricTotal(metric);

        const rows = rankedRows(sourceRows, metric).slice(0, 12);

        if (!rows.length) {
            foodBody.innerHTML = '<tr><td colspan="6" class="food-dashboard-empty">Keine Werte im gewählten Zeitraum.</td></tr>';
            return;
        }

        foodBody.innerHTML = rows.map((row, index) => {
            const value = numberValue(row[metric]);
            return `
                <tr>
                    <td class="food-dashboard-rank">${index + 1}</td>
                    <td><strong>${escapeHtml(row.name)}</strong></td>
                    <td><span class="food-dashboard-category-chip">${escapeHtml(row.category)}</span></td>
                    <td class="food-dashboard-num">${escapeHtml(formatMetric(value, metric))}</td>
                    <td class="food-dashboard-num">${escapeHtml(formatShare(value, total))}</td>
                    <td class="food-dashboard-num">${new Intl.NumberFormat('de-DE').format(numberValue(row.entries))}</td>
                </tr>
            `;
        }).join('');
    }

    function renderRankings(metric) {
        renderCategoryRanking(metric);
        renderFoodRanking(metric);
    }

    function renderChart(metric) {
        const meta = metricMeta[metric];
        const isDrilldown = selectedCategory !== null;

        let rows;
        let total;

        if (isDrilldown) {
            rows = rankedRows(
                foodData.filter(row => row.category === selectedCategory),
                metric
            );
            total = rows.reduce((sum, row) => sum + numberValue(row[metric]), 0);

            shareChartTitleText.textContent = `${selectedCategory} · ${meta.label}`;
            shareChartDescription.textContent = `Verteilung von ${meta.label} auf die Gerichte der Gruppe „${selectedCategory}“ im gewählten Zeitraum.`;
            shareChartBack.hidden = false;
        } else {
            rows = rankedRows(categoryData, metric);
            total = metricTotal(metric);

            shareChartTitleText.textContent = `Anteil der Gruppen an ${meta.label === 'Kalorien' ? 'den Kalorien' : meta.label}`;
            shareChartDescription.textContent = 'Verteilung des gewählten Messwerts über alle Kategorien im Zeitraum. Gruppe anklicken für die Gerichte.';
            shareChartBack.hidden = true;
        }

        shareChartTotal.textContent = formatMetric(total, metric);

        if (shareChart) {
            shareChart.destroy();
            shareChart = null;
        }

        const canvas = document.getElementById('foodShareChart');
        if (!canvas || !rows.length) return;

        shareChart = new Chart(canvas.getContext('2d'), {
            type: 'pie',
            data: {
                labels: rows.map(row => row.name),
                datasets: [{
                    data: rows.map(row => numberValue(row[metric])),
                    backgroundColor: rows.map((_, index) => `hsl(${Math.round((index * 137.508) % 360)} 68% 55%)`),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onClick(event, elements) {
                    if (selectedCategory !== null || !elements.length) return;

                    const index = elements[0].index;
                    const clickedRow = rows[index];
                    if (!clickedRow) return;

                    selectedCategory = clickedRow.name;
                    renderChart(metric);
                    renderRankings(metric);
                },
                onHover(event, elements) {
                    const target = event.native?.target;
                    if (!target) return;
                    target.style.cursor = selectedCategory === null && elements.length ? 'pointer' : 'default';
                },
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 14,
                            boxHeight: 14,
                            padding: 12,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 11 }
                        }
                    },
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

    function applyMetric(metric) {
        if (!metricMeta[metric]) return;
        currentMetric = metric;

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

    shareChartBack.addEventListener('click', () => {
        selectedCategory = null;
        renderChart(currentMetric);
        renderRankings(currentMetric);
    });

    applyMetric(currentMetric);
})();
</script>

</body>
</html>
