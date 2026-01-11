<?php
// biz/Stats.php (Jahresstatistik)

// 1) Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// 2) DB
require_once __DIR__ . '/../db.php';
$bizconn->set_charset('utf8mb4');

// 3) Input + Datenbeschaffung (kein Output davor!)
$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');

$selectedKat = $_GET['kategorie'] ?? 'all';
if ($selectedKat !== 'all' && $selectedKat !== 'unk') {
    $selectedKat = (int)$selectedKat;
}

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

// Label für aktuell ausgewählte Kategorie
$selectedKatLabel = 'Alle Kategorien';
if ($selectedKat === 'unk') {
    $selectedKatLabel = $labelUnk;
} elseif ($selectedKat !== 'all' && isset($kats[(int)$selectedKat])) {
    $selectedKatLabel = $kats[(int)$selectedKat];
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
    $d      = $row['valutadatum'];
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


<div id="statsPage" class="lt-page lt-page-konto">
    <div class="lt-topbar">
        <h1 class="ueberschrift konto-title">
            <span class="konto-title-main">Konto <?= htmlspecialchars((string)$jahr, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="konto-title-soft">| <?= euro($kontostandBisEndeDesJahres) ?></span>
        </h1>

        <form method="get" class="konto-filterform">
            <div class="lt-yearwrap">
                <label for="kategorie" class="lt-label">Kategorie</label>
                <select name="kategorie" id="kategorie" class="kategorie-select" onchange="this.form.submit()">
                    <option value="all" <?= ($selectedKat === 'all') ? 'selected' : '' ?>>
                        Kontostand
                    </option>
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
                <select name="jahr" id="jahr" class="kategorie-select" onchange="this.form.submit()">
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

    <hr class="lt-hr">

    <div class="konto-pies">
        <div class="konto-pie-card">
            <div class="konto-pie-kpi">
                <span class="konto-pie-kpi-label">Einnahmen</span>
                <span class="konto-pie-kpi-value"><?= euro(array_sum($incomeByCat)) ?></span>
            </div>
            <div class="konto-pie-wrap">
                <canvas id="incomePie"></canvas>
            </div>
        </div>

        <div class="konto-pie-card">
            <div class="konto-pie-kpi">
                <span class="konto-pie-kpi-label">Ausgaben</span>
                <span class="konto-pie-kpi-value"><?= euro(array_sum($expenseByCat)) ?></span>
            </div>
            <div class="konto-pie-wrap">
                <canvas id="expensePie"></canvas>
            </div>
        </div>
    </div>
</div>




<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>

<script>
const dailyData   = <?= $dailyJson ?>;
const monthlyData = <?= $monthlyJson ?>;
const catDailyData = <?= $catDailyJson ?>;
const selectedCategory       = <?= json_encode($selectedKat) ?>;
const selectedCategoryLabel  = <?= json_encode($selectedKatLabel, JSON_UNESCAPED_UNICODE) ?>;
const chartYear              = <?= (int)$jahr ?>;

const fmtEuro = (v) => new Intl.NumberFormat('de-DE',{style:'currency',currency:'EUR'}).format(Number(v||0));

/* --- NEU: Monats-Gitterlinien am Monatsanfang, Labels in Monatsmitte (deutsche Monatsabkürzung) --- */
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
  return start + (midDayOffset * 86400000) + (12 * 3600000); // +12:00 UTC
}
function fmtMonthDE(ms) {
  return new Intl.DateTimeFormat('de-DE', { month: 'short' })
    .format(new Date(ms))
    .replace('.', ''); // "Jan." -> "Jan"
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
    const step = (typeof scale.width === 'number' && scale.width < compactW) ? 2 : 1; // 12 oder 6 Labels

    // Tick-Font/Farbe übernehmen
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

// einmalig registrieren (vor dem ersten Chart)
Chart.register(midMonthLabelsPlugin);

/* <<< HIER ANPASSEN: Datensätze je nach Auswahl bauen >>> */
const datasets = [];

if (!selectedCategory || selectedCategory === 'all') {
  datasets.push(
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
} else if (Array.isArray(catDailyData) && catDailyData.length > 0) {
  datasets.push({
    label: 'Kategorie-Verlauf: ' + selectedCategoryLabel,
    data: catDailyData,
    borderColor: '#007bff',
    borderWidth: 2,
    pointRadius: 0,
    fill: false,
    tension: 0
  });
}

const saldoCtx = document.getElementById('saldoChart').getContext('2d');
new Chart(saldoCtx, {
  type: 'line',
  data: { datasets },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
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

        // Plugin-Flags
        midMonthLabels: true,
        midMonthLabelYear: chartYear,
        midMonthLabelCompactWidth: 420,

        // Gridlines bleiben an Monatsanfängen, Tick-Text verstecken (Labels malt Plugin)
        ticks: {
          autoSkip: false,
          maxRotation: 0,
          minRotation: 0,
          callback: () => ' '
        }
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
  maintainAspectRatio: false,
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
