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

// Für Pie: Summen je Kategorie für Einnahmen und Ausgaben
$kats = [];
$katRes = $bizconn->query("SELECT id, name FROM kategorien");
while ($k = $katRes->fetch_assoc()) { $kats[(int)$k['id']] = $k['name']; }
$labelUnk = 'Unkategorisiert';

$kategorieSummen = [];
while ($row = $res->fetch_assoc()) {
    $betrag = (float)$row['betrag'];
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
    if ($summe > 0) $incomeByCat[$kat] = $summe;
    elseif ($summe < 0) $expenseByCat[$kat] = abs($summe);
}

// Anfangsbestand aus Vorjahren holen
$startRes = $bizconn->prepare("SELECT SUM(betrag) AS summe FROM transfers WHERE valutadatum < ?");
$startDate = "$jahr-01-01";
$startRes->bind_param("s", $startDate);
$startRes->execute();
$startRes->bind_result($anfangsbestand);
$startRes->fetch();
$startRes->close();
$anfangsbestand = (float)$anfangsbestand;

// ----------------------------
// tägliche Serie berechnen
// ----------------------------
$dailySeries = [];
$cumDaily = $anfangsbestand;
$start = new DateTime("$jahr-01-01");
$end   = new DateTime("$jahr-12-31");

// Transfers nach Datum gruppieren
$transfersByDate = [];
$res->data_seek(0);
while ($row = $res->fetch_assoc()) {
    $datum = $row['valutadatum'];
    if (!isset($transfersByDate[$datum])) $transfersByDate[$datum] = 0;
    $transfersByDate[$datum] += (float)$row['betrag'];
}

// letztes Valutadatum in diesem Jahr holen
$lastDateRow = $bizconn->query("
    SELECT MAX(valutadatum) AS lastdate
    FROM transfers
    WHERE YEAR(valutadatum) = $jahr
")->fetch_assoc();
$lastDate = new DateTime($lastDateRow['lastdate']);

// kumulieren
$cursor = clone $start;
while ($cursor <= $end) {
    $dstr = $cursor->format('Y-m-d');
    if ($cursor <= $lastDate) {
        if (isset($transfersByDate[$dstr])) {
            $cumDaily += $transfersByDate[$dstr];
        }
        $dailySeries[$dstr] = round($cumDaily, 2);
    } else {
        // nach letztem Datum keine Werte mehr eintragen
        $dailySeries[$dstr] = null;
    }
    $cursor->modify('+1 day');
}


// Monats-Punkte (01.01., jeden 1. des Monats, 31.12.)
// Monats-Punkte (01.01., jeden 1. des Monats, zusätzlich 31.12. falls in der Vergangenheit)
$monthlyPoints = [];

// 01.01. immer setzen (falls an dem Tag keine Buchung: Anfangsbestand)
$firstOfYear = $jahr.'-01-01';
$monthlyPoints[$firstOfYear] = $dailySeries[$firstOfYear] ?? $anfangsbestand;

// jeden 1. des Monats (ab Feb), aber nur wenn ein Wert vorhanden (nicht null)
for ($m = 2; $m <= 12; $m++) {
    $d = sprintf('%04d-%02d-01', $jahr, $m);
    if (array_key_exists($d, $dailySeries) && $dailySeries[$d] !== null) {
        $monthlyPoints[$d] = $dailySeries[$d];
    }
}

// 31.12. immer hinzufügen, WENN dieses Datum bereits in der Vergangenheit liegt
$heute  = new DateTime('today');
$eoy    = new DateTime(sprintf('%04d-12-31', $jahr));
$eoyStr = $eoy->format('Y-m-d');

if ($eoy <= $heute) {
    // Wert am 31.12.: wenn dailySeries dort null ist, den letzten bekannten Wert davor verwenden
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

// JSON für JS
$dailyJson = json_encode(
    array_map(fn($d,$v)=>['x'=>$d,'y'=>$v], array_keys($dailySeries), $dailySeries),
    JSON_UNESCAPED_UNICODE
);
$monthlyJson = json_encode(
    array_map(fn($d,$v)=>['x'=>$d,'y'=>$v], array_keys($monthlyPoints), $monthlyPoints),
    JSON_UNESCAPED_UNICODE
);


arsort($incomeByCat);
arsort($expenseByCat);
$incomeLabels = array_keys($incomeByCat);
$incomeValues = array_values($incomeByCat);
$expenseLabels = array_keys($expenseByCat);
$expenseValues = array_values($expenseByCat);

$incomeLabelsJson = json_encode($incomeLabels, JSON_UNESCAPED_UNICODE);
$incomeValuesJson = json_encode(array_map(fn($v)=>round($v,2), $incomeValues), JSON_UNESCAPED_UNICODE);
$expenseLabelsJson = json_encode($expenseLabels, JSON_UNESCAPED_UNICODE);
$expenseValuesJson = json_encode(array_map(fn($v)=>round($v,2), $expenseValues), JSON_UNESCAPED_UNICODE);

// === NEU: korrekter Kontostand aus ALLEN bisherigen Transfers bis Ende des ausgewählten Jahres ===
// (keine Änderung an der Ausgabe – nur neue Variable bereitstellen)
$kontostandBisEndeDesJahres = 0.0;
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

function euro($v) { return number_format((float)$v, 2, ',', '.')." €"; }
?>
<body>
<div class="container" style="max-width: 1200px;">
    <h1 class="ueberschrift">Statistik <?= htmlspecialchars((string)$jahr) ?></h1>

    <!-- Jahr-Auswahl -->
    <form method="get" class="zeitbereich-form" style="margin-bottom: 1rem;">
        <div style="display: flex; justify-content: center;">
            <div class="input-group" style="max-width: 220px;">
                <select name="jahr" id="jahr" onchange="this.form.submit()">
                    <?php foreach ($jahre as $j): ?>
                        <?php if ($j <= 2021) continue; ?>
                        <option value="<?= $j ?>" <?= ($j === $jahr) ? 'selected' : '' ?>><?= $j ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <!-- Kontostand Gesamt -->
    <div style="text-align:center; font-size:1.6rem; font-weight:700; color:#000; margin-top:10px;">
        Kontostand: <?= euro($kontostandBisEndeDesJahres) ?>
    </div>

    <!-- Liniendiagramm Kontostand -->
    <div class="chart-row" style="margin-top: 20px;">
        <div class="chart-half" style="flex:1 1 100%; max-width:100%;">
            <canvas id="saldoChart"></canvas>
        </div>
    </div>
</div>

<div class="container" style="max-width: 1200px;">
    <!-- Einnahmen / Ausgaben Pies -->
    <h2 class="ueberschrift" style="margin-top: 30px;">Einnahmen / Ausgaben <?= htmlspecialchars((string)$jahr) ?></h2>
    <div style="text-align:center; margin-bottom:10px;">
        <strong>Einnahmen:</strong> <?= euro(array_sum($incomeValues)) ?> &nbsp; | &nbsp;
        <strong>Ausgaben:</strong> <?= euro(array_sum($expenseValues)) ?>
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

<!-- Chart.js + Date Adapter -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
<script>
const dailyData = <?= $dailyJson ?>;
const monthlyData = <?= $monthlyJson ?>;

const fmtEuro = (v) => new Intl.NumberFormat('de-DE',{style:'currency',currency:'EUR'}).format(v);

const saldoCtx = document.getElementById('saldoChart').getContext('2d');
new Chart(saldoCtx, {
  type: 'line',
  data: {
    datasets: [
        {
        label: 'Kontostand (Monatsbeginn)',
        borderColor: '#ff6b00',
        data: monthlyData,
        showLine: false,          // keine Linie
        pointRadius: 10,           // Punktgröße
        pointBackgroundColor: '#ff6b00',  // Füllfarbe
        pointBorderColor: 'black',    // Randfarbe
        pointBorderWidth: 3           // Randdicke
        },

        {
        label: 'Verlauf Kontostand',
        data: dailyData,
        borderColor: '#000',
        borderWidth: 3,
        pointRadius: 0,
        fill: false,
        tension: 0
        },
    ]
  },
  options: {
    responsive: true,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: true },
      tooltip: {
        callbacks: {
          label: (ctx) => `${ctx.dataset.label}: ${fmtEuro(Number(ctx.parsed.y||0))}`
        }
      }
    },
    scales: {
      x: {
        type: 'time',
        time: { unit: 'month', tooltipFormat: 'dd.MM.yyyy' },
        ticks: { maxRotation: 0, autoSkip: true }
      },
      y: {
        beginAtZero: false,
        ticks: { callback: (v) => fmtEuro(Number(v)) }
      }
    }
  }
});

// PIE CHARTS
const incomeLabels = <?= $incomeLabelsJson ?>;
const incomeValues = <?= $incomeValuesJson ?>;
const expenseLabels = <?= $expenseLabelsJson ?>;
const expenseValues = <?= $expenseValuesJson ?>;
const sum = (arr) => arr.reduce((a,b)=>a+Number(b||0),0);

const pieOpts = (total) => ({
  responsive: true,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (ctx) => {
          const val = Number(ctx.parsed);
          const pct = total? (val/total*100):0;
          return `${ctx.label}: ${fmtEuro(val)} (${pct.toFixed(1)}%)`;
        }
      }
    }
  }
});

new Chart(document.getElementById('incomePie').getContext('2d'), {
  type: 'pie',
  data: { labels: incomeLabels, datasets: [{ data: incomeValues }] },
  options: pieOpts(sum(incomeValues))
});
new Chart(document.getElementById('expensePie').getContext('2d'), {
  type: 'pie',
  data: { labels: expenseLabels, datasets: [{ data: expenseValues }] },
  options: pieOpts(sum(expenseValues))
});
</script>
</body>
