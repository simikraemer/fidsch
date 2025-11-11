<?php
// biz/Stats.php (Jahresstatistik)

// 1) Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// 2) DB
require_once __DIR__ . '/../db.php';
$bizconn->set_charset('utf8mb4');

// 3) Input + Datenbeschaffung (kein Output davor!)
$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');

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

// Summen je Kategorie aufbauen
$kategorieSummen = [];
while ($row = $res->fetch_assoc()) {
    $betrag  = (float)$row['betrag'];
    $katName = $labelUnk;
    if ($row['kategorie_id'] !== null) {
        $katId = (int)$row['kategorie_id'];
        if (isset($kats[$katId])) $katName = $kats[$katId];
    }
    if (!isset($kategorieSummen[$katName])) $kategorieSummen[$katName] = 0.0;
    $kategorieSummen[$katName] += $betrag;
}

// Einnahmen/Ausgaben trennen
$incomeByCat  = [];
$expenseByCat = [];
foreach ($kategorieSummen as $kat => $summe) {
    if ($summe > 0) $incomeByCat[$kat] = $summe;
    elseif ($summe < 0) $expenseByCat[$kat] = abs($summe);
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
    SELECT valutadatum, betrag
    FROM transfers
    WHERE YEAR(valutadatum) = ?
    ORDER BY valutadatum ASC
");
$stmt2->bind_param('i', $jahr);
$stmt2->execute();
$res2 = $stmt2->get_result();

// Transfers pro Datum gruppieren
$transfersByDate = [];
while ($row = $res2->fetch_assoc()) {
    $d = $row['valutadatum'];
    if (!isset($transfersByDate[$d])) $transfersByDate[$d] = 0.0;
    $transfersByDate[$d] += (float)$row['betrag'];
}
$stmt2->close();

// letztes Valutadatum in diesem Jahr
$lastDateRow = $bizconn->query("
    SELECT MAX(valutadatum) AS lastdate
    FROM transfers
    WHERE YEAR(valutadatum) = {$jahr}
")->fetch_assoc();
$lastDate = $lastDateRow['lastdate'] ? new DateTime($lastDateRow['lastdate']) : new DateTime("$jahr-01-01");

// tägliche kumulierte Serie
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
        $dailySeries[$dstr] = null; // zukünftige Tage leer
    }
    $cursor->modify('+1 day');
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

// Pies vorbereiten
arsort($incomeByCat);
arsort($expenseByCat);
$incomeLabelsJson  = json_encode(array_keys($incomeByCat), JSON_UNESCAPED_UNICODE);
$incomeValuesJson  = json_encode(array_map(fn($v)=>round($v,2), array_values($incomeByCat)), JSON_UNESCAPED_UNICODE);
$expenseLabelsJson = json_encode(array_keys($expenseByCat), JSON_UNESCAPED_UNICODE);
$expenseValuesJson = json_encode(array_map(fn($v)=>round($v,2), array_values($expenseByCat)), JSON_UNESCAPED_UNICODE);

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
<div class="container" style="max-width: 1200px;">
    <h1 class="ueberschrift">Statistik <?= htmlspecialchars((string)$jahr, ENT_QUOTES) ?></h1>

    <!-- Jahr-Auswahl -->
    <form method="get" class="zeitbereich-form" style="margin-bottom: 1rem;">
        <div style="display: flex; justify-content: center;">
            <div class="input-group" style="max-width: 220px;">
                <select name="jahr" id="jahr" onchange="this.form.submit()">
                    <?php foreach ($jahre as $j): ?>
                        <?php if ((int)$j <= 2021) continue; ?>
                        <option value="<?= (int)$j ?>" <?= ((int)$j === (int)$jahr) ? 'selected' : '' ?>>
                            <?= (int)$j ?>
                        </option>
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
        <div class="chart-half-finance" style="flex:1 1 100%; max-width:100%;">
            <canvas id="saldoChart"></canvas>
        </div>
    </div>
</div>

<div class="container" style="max-width: 1200px;">
    <!-- Einnahmen / Ausgaben Pies -->
    <h2 class="ueberschrift" style="margin-top: 30px;">Einnahmen / Ausgaben <?= htmlspecialchars((string)$jahr, ENT_QUOTES) ?></h2>
    <div style="text-align:center; margin-bottom:10px;">
        <strong>Einnahmen:</strong> <?= euro(array_sum($incomeByCat)) ?> &nbsp; | &nbsp;
        <strong>Ausgaben:</strong> <?= euro(array_sum($expenseByCat)) ?>
    </div>
    <div class="chart-row">
        <div class="chart-half-finance">
            <canvas id="incomePie"></canvas>
        </div>
        <div class="chart-half-finance">
            <canvas id="expensePie"></canvas>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
<script>
const dailyData   = <?= $dailyJson ?>;
const monthlyData = <?= $monthlyJson ?>;

const fmtEuro = (v) => new Intl.NumberFormat('de-DE',{style:'currency',currency:'EUR'}).format(Number(v||0));

const saldoCtx = document.getElementById('saldoChart').getContext('2d');
new Chart(saldoCtx, {
  type: 'line',
  data: {
    datasets: [
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
    ]
  },
  options: {
    responsive: true,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: true },
      tooltip: {
        callbacks: {
          label: (ctx) => `${ctx.dataset.label}: ${fmtEuro(ctx.parsed.y)}`
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
        ticks: { callback: (v) => fmtEuro(v) }
      }
    }
  }
});

// PIE CHARTS
const incomeLabels  = <?= $incomeLabelsJson ?>;
const incomeValues  = <?= $incomeValuesJson ?>;
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
          const pct = total ? (val/total*100) : 0;
          return `${ctx.label}: ${fmtEuro(val)} (${pct.toFixed(1)}%)`;
        }
      }
    }
  }
});

new Chart(document.getElementById('incomePie').getContext('2d'), {
  type: 'pie',
  data: { labels: incomeLabels,  datasets: [{ data: incomeValues  }] },
  options: pieOpts(sum(incomeValues))
});
new Chart(document.getElementById('expensePie').getContext('2d'), {
  type: 'pie',
  data: { labels: expenseLabels, datasets: [{ data: expenseValues }] },
  options: pieOpts(sum(expenseValues))
});
</script>

</body>
</html>
