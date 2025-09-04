<?php
require_once 'auth.php';
require_once 'template.php';

$bizconn->set_charset('utf8mb4');

// Jahr aus GET, Default = aktuelles Jahr
$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');

// Verfügbare Jahre (nur die, für die es Daten gibt), absteigend
$yearsRes = $bizconn->query("SELECT DISTINCT YEAR(valutadatum) AS jahr FROM transfers WHERE valutadatum IS NOT NULL ORDER BY jahr DESC");
$jahre = [];
while ($r = $yearsRes->fetch_assoc()) { $jahre[] = (int)$r['jahr']; }
if (!in_array($jahr, $jahre) && !empty($jahre)) { $jahr = $jahre[0]; }

// Alle Buchungen des Jahres holen
$stmt = $bizconn->prepare("
    SELECT valutadatum, betrag, kategorie_id
    FROM transfers
    WHERE YEAR(valutadatum) = ?
    ORDER BY valutadatum ASC
");
$stmt->bind_param('i', $jahr);
$stmt->execute();
$res = $stmt->get_result();

$monatNamenKurz = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
$sumByMonth = array_fill(1, 12, 0.0);

// Für Pie: Summen je Kategorie für Einnahmen und Ausgaben
$kats = [];
$katRes = $bizconn->query("SELECT id, name FROM kategorien");
while ($k = $katRes->fetch_assoc()) { $kats[(int)$k['id']] = $k['name']; }
$labelUnk = 'Unkategorisiert';

$kategorieSummen = [];

while ($row = $res->fetch_assoc()) {
    $betrag = (float)$row['betrag'];
    $monat = (int)date('n', strtotime($row['valutadatum']));
    if ($monat >= 1 && $monat <= 12) {
        $sumByMonth[$monat] += $betrag;
    }

    $katName = $labelUnk;
    if (!is_null($row['kategorie_id'])) {
        $katId = (int)$row['kategorie_id'];
        if (isset($kats[$katId])) $katName = $kats[$katId];
    }

    if (!isset($kategorieSummen[$katName])) $kategorieSummen[$katName] = 0.0;
    $kategorieSummen[$katName] += $betrag;
}

$incomeByCat = [];
$expenseByCat = [];
foreach ($kategorieSummen as $kat => $summe) {
    if ($summe > 0) {
        $incomeByCat[$kat] = $summe;
    } elseif ($summe < 0) {
        $expenseByCat[$kat] = abs($summe);
    }
}


// Anfangsbestand aus Vorjahren holen
$startRes = $bizconn->prepare("
    SELECT SUM(betrag) AS summe FROM transfers WHERE valutadatum < ?
");
$startDate = "$jahr-01-01";
$startRes->bind_param("s", $startDate);
$startRes->execute();
$startRes->bind_result($anfangsbestand);
$startRes->fetch();
$startRes->close();

$anfangsbestand = (float)$anfangsbestand;
$cum = $anfangsbestand;
$cumSeries = [];

$heute = new DateTime();
$cum = $anfangsbestand;
$cumSeries = [];
$validXs = [];
$validYs = [];


$cumSeries[] = round($anfangsbestand, 2);
$validXs[] = 0;
$validYs[] = round($anfangsbestand, 2);

for ($m = 1; $m <= 12; $m++) {
    if ((int)$jahr === (int)$heute->format('Y') && $m > (int)$heute->format('n')) {
        $cumSeries[] = null; // Lücke im Chart
    } else {
        $cum += $sumByMonth[$m];
        $val = round($cum, 2);
        $cumSeries[] = $val;
        $validXs[] = $m;
        $validYs[] = $val;
    }
}


$kontostandJahr = 0.0;
foreach (array_reverse($cumSeries, true) as $val) {
    if (!is_null($val)) {
        $kontostandJahr = $val;
        break;
    }
}


$n = count($validXs);
$sumX = array_sum($validXs);
$sumY = array_sum($validYs);
$sumXY = 0.0;
$sumX2 = 0.0;
for ($i = 0; $i < $n; $i++) {
    $sumXY += $validXs[$i]*$validYs[$i];
    $sumX2 += $validXs[$i]*$validXs[$i];
}
$den = ($n * $sumX2 - $sumX*$sumX);
if ($den == 0) { $m = 0; $b = $validYs[0] ?? 0; } else {
    $m = ($n * $sumXY - $sumX * $sumY) / $den;
    $b = ($sumY - $m * $sumX) / $n;
}
$trend = [round($m * 0 + $b, 2)];
for ($i = 1; $i <= 12; $i++) {
    if ((int)$jahr === (int)$heute->format('Y') && $i > (int)$heute->format('n')) {
        $trend[] = null;
    } else {
        $trend[] = round($m * $i + $b, 2);
    }
}


arsort($incomeByCat);
arsort($expenseByCat);

$incomeLabels = array_keys($incomeByCat);
$incomeValues = array_values($incomeByCat);
$expenseLabels = array_keys($expenseByCat);
$expenseValues = array_values($expenseByCat);




$sumIncome = array_sum($incomeValues);
$sumExpense = array_sum($expenseValues);

// JSON für JS
$labelsJson = json_encode(array_merge([''], $monatNamenKurz), JSON_UNESCAPED_UNICODE);
$cumJson    = json_encode($cumSeries, JSON_UNESCAPED_UNICODE);
$trendJson  = json_encode($trend, JSON_UNESCAPED_UNICODE);

$incomeLabelsJson = json_encode($incomeLabels, JSON_UNESCAPED_UNICODE);
$incomeValuesJson = json_encode(array_map(fn($v)=>round($v,2), $incomeValues), JSON_UNESCAPED_UNICODE);

$expenseLabelsJson = json_encode($expenseLabels, JSON_UNESCAPED_UNICODE);
$expenseValuesJson = json_encode(array_map(fn($v)=>round($v,2), $expenseValues), JSON_UNESCAPED_UNICODE);

function euro($v) { return number_format((float)$v, 2, ',', '.')." €"; }
?>
<body>
    <div class="container" style="max-width: 1100px;">
        <h1 class="ueberschrift">Statistik <?= htmlspecialchars((string)$jahr) ?></h1>

        <!-- Jahr-Auswahl -->
        <form method="get" class="zeitbereich-form" style="margin-bottom: 1rem;">
            <div style="display: flex; justify-content: center;">
                <div class="input-group" style="max-width: 220px;">
                    <select name="jahr" id="jahr" onchange="this.form.submit()">
                        <?php foreach ($jahre as $j): ?>
                            <?php if ($j <= 2023) continue; ?>
                            <option value="<?= $j ?>" <?= ($j === $jahr) ? 'selected' : '' ?>><?= $j ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>



        <!-- Kontostand Gesamt -->
        <div style="text-align:center; font-size:1.6rem; font-weight:700; color:#000; margin-top:10px;">
            Kontostand: <?= euro($kontostandJahr) ?>
        </div>

        <!-- Liniendiagramm Kontostand -->
        <div class="chart-row" style="margin-top: 20px;">
            <div class="chart-half" style="flex:1 1 100%; max-width:100%;">
                <canvas id="saldoChart"></canvas>
            </div>
        </div>

    </div>

    <div class="container" style="max-width: 1100px;">

        <!-- Einnahmen / Ausgaben Pies -->
        <h2 class="ueberschrift" style="margin-top: 30px;">Einnahmen / Ausgaben</h2>
        <div style="text-align:center; margin-bottom:10px;">
            <strong>Einnahmen:</strong> <?= euro($sumIncome) ?> &nbsp; | &nbsp;
            <strong>Ausgaben:</strong> <?= euro($sumExpense) ?>
        </div>
        <div class="chart-row">
            <div class="chart-half">
                <canvas id="incomePie"></canvas>
            </div>
            <div class="chart-half">
                <canvas id="expensePie"></canvas>
            </div>
        </div>
    </div>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const labels = <?= $labelsJson ?>;
        const saldoData = <?= $cumJson ?>;
        const trendData = <?= $trendJson ?>;

        // Helper: Euro-Format
        const fmtEuro = (v) => {
            try {
                return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v);
            } catch {
                return (v.toFixed(2) + ' €').replace('.', ',');
            }
        };

        // SALDO LINE CHART
        const saldoCtx = document.getElementById('saldoChart').getContext('2d');
        new Chart(saldoCtx, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Kontostand',
                        data: saldoData,
                        fill: false,
                        tension: 0.2,
                        borderWidth: 3,
                        pointRadius: 3
                    },
                    {
                        label: 'Trend',
                        data: trendData,
                        fill: false,
                        borderWidth: 2,
                        borderDash: [6, 6],
                        pointRadius: 0
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.dataset.label}: ${fmtEuro(Number(ctx.parsed.y || 0))}`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (v) => fmtEuro(Number(v))
                        }
                    }
                }
            }
        });

        // PIE CHARTS: Einnahmen & Ausgaben
        const incomeLabels = <?= $incomeLabelsJson ?>;
        const incomeValues = <?= $incomeValuesJson ?>;
        const expenseLabels = <?= $expenseLabelsJson ?>;
        const expenseValues = <?= $expenseValuesJson ?>;

        const sum = (arr) => arr.reduce((a,b)=>a+Number(b||0), 0);
        const incomeTotal = sum(incomeValues);
        const expenseTotal = sum(expenseValues);

        const pieOpts = (total) => ({
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const val = Number(ctx.parsed);
                            const pct = total ? (val/total*100) : 0;
                            return `${ctx.label}: ${fmtEuro(val)} (${pct.toFixed(1)}%)`;
                        }
                    }
                }
            }
        });

        new Chart(document.getElementById('incomePie').getContext('2d'), {
            type: 'pie',
            data: {
                labels: incomeLabels,
                datasets: [{ data: incomeValues }]
            },
            options: pieOpts(incomeTotal)
        });

        new Chart(document.getElementById('expensePie').getContext('2d'), {
            type: 'pie',
            data: {
                labels: expenseLabels,
                datasets: [{ data: expenseValues }]
            },
            options: pieOpts(expenseTotal)
        });
    </script>
</body>
