<?php
// fit/Start.php (Übersicht)

// 1) Auth (diese Seite ist geschützt)
require_once __DIR__ . '/../auth.php';

// 2) DB für alle Abfragen
require_once __DIR__ . '/../db.php';

// 3) Variablen + Zeitraum bestimmen (POST-Verarbeitung gibt es hier nicht)
$grundbedarf  = 2200;
$kalorienziel = 3000;

$aktJahr = (int)(new DateTime())->format('Y');

// verfügbare Jahre (nur Jahre, in denen irgendeine Tabelle Daten hat)
$verfuegbareJahre = [];
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
    while ($r = $res->fetch_assoc()) $verfuegbareJahre[] = (int)$r['y'];
    $res->free();
}
if (!$verfuegbareJahre) $verfuegbareJahre = [$aktJahr]; // Fallback, falls DB leer

$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : $aktJahr;

// Default: aktuelles Jahr, aber nur wenn vorhanden; sonst neuestes Jahr mit Daten
if (!in_array($jahr, $verfuegbareJahre, true)) {
    $jahr = in_array($aktJahr, $verfuegbareJahre, true) ? $aktJahr : $verfuegbareJahre[0];
}

$startDate = sprintf('%04d-01-01', $jahr);
$endDate   = sprintf('%04d-01-01', $jahr + 1); // exklusives Ende

// 4) Brutto-Kalorien (nur Zufuhr)
$stmt = $fitconn->prepare("
    SELECT DATE(tstamp) AS tag, SUM(kalorien) AS kalorien
    FROM kalorien
    WHERE tstamp >= ? AND tstamp < ?
    GROUP BY DATE(tstamp)
");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

$bruttoTage = [];
while ($row = $result->fetch_assoc()) {
    $bruttoTage[$row['tag']] = (int)$row['kalorien'];
}
$stmt->close();

$bruttoSumme        = array_sum($bruttoTage);
$tageMitWerten      = count($bruttoTage);
$bruttoDurchschnitt = $tageMitWerten > 0 ? round($bruttoSumme / $tageMitWerten) : 0;

// 4b) Nährwerte (Tageswerte aus kalorien: Gramm-Summen je Tag)
$stmt = $fitconn->prepare("
    SELECT
        DATE(tstamp) AS tag,
        SUM(`eiweiß`)        AS eiweiss,
        SUM(`fett`)          AS fett,
        SUM(`kohlenhydrate`) AS kh,
        SUM(`alkohol`)       AS alk
    FROM kalorien
    WHERE tstamp >= ? AND tstamp < ?
    GROUP BY DATE(tstamp)
");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

$eiweissTage = [];
$fettTage    = [];
$khTage      = [];
$alkTage     = [];
while ($row = $result->fetch_assoc()) {
    $tag = $row['tag'];
    $eiweissTage[$tag] = (float)$row['eiweiss'];
    $fettTage[$tag]    = (float)$row['fett'];
    $khTage[$tag]      = (float)$row['kh'];
    $alkTage[$tag]     = (float)$row['alk'];
}
$stmt->close();

// 5) Netto-Kalorien (Zufuhr - Verbrauch)
$stmt = $fitconn->prepare("
    SELECT DATE(tstamp) AS tag, SUM(kalorien) AS gesamt
    FROM kalorien
    WHERE tstamp >= ? AND tstamp < ?
    GROUP BY DATE(tstamp)
    UNION ALL
    SELECT DATE(tstamp) AS tag, -SUM(kalorien) AS gesamt
    FROM training
    WHERE tstamp >= ? AND tstamp < ?
    GROUP BY DATE(tstamp)
    ORDER BY tag
");
$stmt->bind_param('ssss', $startDate, $endDate, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

$nettoTage = [];
while ($row = $result->fetch_assoc()) {
    $tag      = $row['tag'];
    $kalorien = (int)$row['gesamt'];
    if (!isset($nettoTage[$tag])) $nettoTage[$tag] = 0;
    $nettoTage[$tag] += $kalorien;
}
$stmt->close();

$nettoSumme           = array_sum($nettoTage);
$tageMitNettoWerten   = count($nettoTage);
$nettoDurchschnitt    = $tageMitNettoWerten > 0 ? round($nettoSumme / $tageMitNettoWerten) : 0;

// 6) Gewichtsdaten
$stmt = $fitconn->prepare("
    SELECT DATE(tstamp) AS tag, AVG(gewicht) AS gewicht
    FROM gewicht
    WHERE tstamp >= ? AND tstamp < ?
    GROUP BY DATE(tstamp)
");
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

$gewichtTage = [];
while ($row = $result->fetch_assoc()) {
    $gewichtTage[$row['tag']] = (float)$row['gewicht'];
}
$stmt->close();

// 7) Alle Tage im Zeitraum
$alleTage = [];
$periode  = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    new DateTime($endDate) // exklusiv
);
foreach ($periode as $datum) $alleTage[] = $datum->format('Y-m-d');

// 8a) Serien für Charts vorbereiten
$nettoWerte      = [];
$supernettoWerte = [];
$bruttoWerte     = [];
$gewichtWerte    = [];
// Nährwerte (Tageswerte) vorbereitet auf $alleTage
$eiweissWerte = [];
$fettWerte    = [];
$khWerte      = [];
$alkWerte     = [];

$letztesGewicht  = null;
$erstesGewicht   = null;
$maxGewicht      = null;

foreach ($alleTage as $tag) {
    $nettoWerte[]      = array_key_exists($tag, $nettoTage)  ? $nettoTage[$tag]               : null;
    $bruttoWerte[]     = array_key_exists($tag, $bruttoTage) ? $bruttoTage[$tag]              : null;
    $supernettoWerte[] = array_key_exists($tag, $nettoTage)  ? $nettoTage[$tag] - $grundbedarf : null;

    // Gewicht
    if (array_key_exists($tag, $gewichtTage)) {
        $tagesGewicht = $gewichtTage[$tag];
        if ($erstesGewicht === null) $erstesGewicht = $tagesGewicht;
        if ($maxGewicht === null || $maxGewicht < $tagesGewicht) $maxGewicht = $tagesGewicht;
        $gewichtWerte[] = $tagesGewicht;
        $letztesGewicht = $tagesGewicht;
    } else {
        $gewichtWerte[] = null;
    }

    // Nährwert-Tageswerte (Gramm)
    $eiweissWerte[] = array_key_exists($tag, $eiweissTage) ? round($eiweissTage[$tag], 2) : null;
    $fettWerte[]    = array_key_exists($tag, $fettTage)    ? round($fettTage[$tag], 2)    : null;
    $khWerte[]      = array_key_exists($tag, $khTage)      ? round($khTage[$tag], 2)      : null;
    $alkWerte[]     = array_key_exists($tag, $alkTage)     ? round($alkTage[$tag], 2)     : null;
}

$gewichtsDiffText = '—';

if ($erstesGewicht !== null && $letztesGewicht !== null) {
    $diff = $letztesGewicht - $erstesGewicht;   // letzter - erster
    $sign = ($diff > 0) ? '+' : (($diff < 0) ? '−' : '±'); // oder '0' ohne Vorzeichen, wenn du willst
    $gewichtsDiffText = $sign . number_format(abs($diff), 1, ',', '') . ' kg';
}

// 8b) Durchschnitte Nährwerte (g/Tag)
$avgNonNull = function(array $arr): int {
    $vals = array_values(array_filter($arr, fn($v) => $v !== null));
    $n = count($vals);
    return $n > 0 ? (int)round(array_sum($vals) / $n) : 0;
};

$eiweissDurchschnitt = $avgNonNull($eiweissWerte);
$fettDurchschnitt    = $avgNonNull($fettWerte);
$khDurchschnitt      = $avgNonNull($khWerte);
$alkDurchschnitt     = $avgNonNull($alkWerte);

// 9) Wochenmittel Netto (nur 1. und letzter Tag der KW beschriften)
$nettoKWavg = array_fill(0, count($alleTage), null);
$kwSammler  = [];
foreach ($alleTage as $i => $tag) {
    $kw = date('o-W', strtotime($tag));
    if (!isset($kwSammler[$kw])) $kwSammler[$kw] = [];
    if (isset($nettoTage[$tag])) $kwSammler[$kw][] = $nettoTage[$tag];
}
foreach ($kwSammler as $kw => $werte) {
    if (!count($werte)) continue;
    $avg      = round(array_sum($werte) / count($werte));
    $tageInKW = array_values(array_filter($alleTage, fn($t) => date('o-W', strtotime($t)) === $kw));
    if (!$tageInKW) continue;
    $firstIndex = array_search($tageInKW[0], $alleTage);
    $lastIndex  = array_search(end($tageInKW), $alleTage);
    if ($firstIndex !== false) $nettoKWavg[$firstIndex] = $avg;
    if ($lastIndex  !== false && $lastIndex !== $firstIndex) $nettoKWavg[$lastIndex] = $avg;
}

// 9b) Wochenmittel für Nährwerte (Gramm)
function kwAvgSerie(array $alleTage, array $tageMap): array {
    $out       = array_fill(0, count($alleTage), null);
    $sammlerKW = [];
    foreach ($alleTage as $i => $tag) {
        $kw = date('o-W', strtotime($tag));
        if (!isset($sammlerKW[$kw])) $sammlerKW[$kw] = [];
        if (isset($tageMap[$tag])) $sammlerKW[$kw][] = $tageMap[$tag];
    }
    foreach ($sammlerKW as $kw => $werte) {
        if (!count($werte)) continue;
        $avg      = round(array_sum($werte) / count($werte), 2);
        $tageInKW = array_values(array_filter($alleTage, fn($t) => date('o-W', strtotime($t)) === $kw));
        if (!$tageInKW) continue;
        $firstIndex = array_search($tageInKW[0], $alleTage);
        $lastIndex  = array_search(end($tageInKW), $alleTage);
        if ($firstIndex !== false) $out[$firstIndex] = $avg;
        if ($lastIndex  !== false && $lastIndex !== $firstIndex) $out[$lastIndex] = $avg;
    }
    return $out;
}

$eiweissKWavg = kwAvgSerie($alleTage, $eiweissTage);
$fettKWavg    = kwAvgSerie($alleTage, $fettTage);
$khKWavg      = kwAvgSerie($alleTage, $khTage);
$alkKWavg     = kwAvgSerie($alleTage, $alkTage);

// 10a) einfache Trendlinie über vorhandene Gewichtswerte
/* $x = $y = [];
for ($i = 0; $i < count($gewichtWerte); $i++) {
    if ($gewichtWerte[$i] !== null) { $x[] = $i; $y[] = $gewichtWerte[$i]; }
}
$trendWerte = array_fill(0, count($gewichtWerte), null);
if (count($x) > 1) {
    $n = count($x);
    $sumX = array_sum($x); $sumY = array_sum($y);
    $sumXY = 0; $sumXX = 0;
    for ($i = 0; $i < $n; $i++) { $sumXY += $x[$i]*$y[$i]; $sumXX += $x[$i]*$x[$i]; }
    $den = ($n*$sumXX - $sumX*$sumX);
    if ($den != 0) {
        $slope = ($n*$sumXY - $sumX*$sumY) / $den;
        $intercept = ($sumY - $slope*$sumX) / $n;
        for ($i = 0; $i < count($trendWerte); $i++) {
            $trendWerte[$i] = round($slope*$i + $intercept, 1);
        }
    }
} */

// 10b) exponentielle Trendlinie (y = a * e^(b*x)) über vorhandene Gewichtswerte
$trendWerte = array_fill(0, count($gewichtWerte), null);

$x = [];
$lny = [];

for ($i = 0; $i < count($gewichtWerte); $i++) {
    $v = $gewichtWerte[$i];
    if ($v === null) continue;
    if ($v <= 0) continue;                 // log() braucht > 0

    $x[]   = (float)$i;
    $lny[] = log((float)$v);
}

if (count($x) > 1) {
    $n = count($x);

    $sumX  = array_sum($x);
    $sumY  = array_sum($lny);

    $sumXY = 0.0;
    $sumXX = 0.0;
    for ($k = 0; $k < $n; $k++) {
        $sumXY += $x[$k] * $lny[$k];
        $sumXX += $x[$k] * $x[$k];
    }

    $den = ($n * $sumXX - $sumX * $sumX);
    if ($den != 0.0) {
        $b   = ($n * $sumXY - $sumX * $sumY) / $den;
        $lnA = ($sumY - $b * $sumX) / $n;
        $a   = exp($lnA);

        for ($i = 0; $i < count($trendWerte); $i++) {
            // Variante A: Trendlinie über das komplette Jahr (wie Excel "vorwärts projizieren")
            $trendWerte[$i] = round($a * exp($b * $i), 1);

            // Variante B: nur da Trend, wo Messwert existiert (falls du es so willst)
            // $trendWerte[$i] = ($gewichtWerte[$i] === null) ? null : round($a * exp($b * $i), 1);
        }
    }
}

// 11) JSON für Charts
$labels         = json_encode($alleTage);
$nettoJson      = json_encode($nettoWerte);
$supernettoJson = json_encode($supernettoWerte);
$bruttoJson     = json_encode($bruttoWerte);
$gewichtJson    = json_encode($gewichtWerte);
$trendJson      = json_encode($trendWerte);
$nettoKWJson    = json_encode($nettoKWavg);

// Nährwerte JSON (Tageswerte + KW-Durchschnitt)
$eiweissJson  = json_encode($eiweissWerte);
$fettJson     = json_encode($fettWerte);
$khJson       = json_encode($khWerte);
$alkJson      = json_encode($alkWerte);

$eiweissKWJson = json_encode($eiweissKWavg);
$fettKWJson    = json_encode($fettKWavg);
$khKWJson      = json_encode($khKWavg);
$alkKWJson     = json_encode($alkKWavg);

// 12) Rendering starten (kein Output davor!)
$page_title = 'Fitness';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div id="healthPage" class="lt-page lt-page-konto">
  <div class="lt-topbar">
    <h1 class="ueberschrift konto-title">
      <span class="konto-title-main">Ernährung <?= htmlspecialchars((string)$jahr, ENT_QUOTES, 'UTF-8')?></span>
      <span class="konto-title-soft">| <?= htmlspecialchars($gewichtsDiffText, ENT_QUOTES, 'UTF-8') ?></span>    
    </h1>

    <form method="get" id="zeitForm" class="konto-filterform">
      <div class="lt-yearwrap">
        <label for="jahr" class="lt-label">Jahr</label>
        <select id="jahr" name="jahr" class="kategorie-select" onchange="this.form.submit()">
          <?php foreach ($verfuegbareJahre as $y): ?>
            <option value="<?= (int)$y ?>" <?= ((int)$y === (int)$jahr ? 'selected' : '') ?>>
              <?= (int)$y ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>

  <!-- OBEN: 2 Charts nebeneinander, normaler lt-chart-wrap (keine Karten) -->
  <div class="lt-chart-wrap">
    <div class="lt-chart-split">
      <div class="lt-chart-split-item">
        <div class="konto-pie-kpi">
          <span class="konto-pie-kpi-label">Kalorien</span>
          <span class="konto-pie-kpi-value">Ø<?= (int)$nettoDurchschnitt ?> kcal/Tag</span>
        </div>
        <canvas id="kalorienChart"></canvas>
      </div>

      <div class="lt-chart-split-item">
        <div class="konto-pie-kpi">
          <span class="konto-pie-kpi-label">Körpergewicht</span>
          <span class="konto-pie-kpi-value">
            <?= ($erstesGewicht !== null ? $erstesGewicht : '—') ?> kg → <?= ($letztesGewicht !== null ? $letztesGewicht : '—') ?> kg
          </span>
        </div>
        <canvas id="gewichtChart"></canvas>
      </div>
    </div>
  </div>

  <hr class="lt-hr">

  <!-- UNTEN: 3 Cards nebeneinander (wie Einnahmen/Ausgaben), aber 3er-Grid -->
  <div class="konto-pies konto-pies-3">
    <div class="konto-pie-card">
      <div class="konto-pie-kpi">
        <span class="konto-pie-kpi-label">Protein</span>
        <span class="konto-pie-kpi-value">Ø<?= (int)$eiweissDurchschnitt ?> g/Tag</span>
      </div>
      <div class="konto-pie-wrap">
        <canvas id="eiweissChart"></canvas>
      </div>
    </div>

    <div class="konto-pie-card">
      <div class="konto-pie-kpi">
        <span class="konto-pie-kpi-label">Fett</span>
        <span class="konto-pie-kpi-value">Ø<?= (int)$fettDurchschnitt ?> g/Tag</span>
      </div>
      <div class="konto-pie-wrap">
        <canvas id="fettChart"></canvas>
      </div>
    </div>

    <div class="konto-pie-card">
      <div class="konto-pie-kpi">
        <span class="konto-pie-kpi-label">Carbs</span>
        <span class="konto-pie-kpi-value">Ø<?= (int)$khDurchschnitt ?> g/Tag</span>
      </div>
      <div class="konto-pie-wrap">
        <canvas id="khChart"></canvas>
      </div>
    </div>
  </div>
</div>


<!-- Scripts am Ende des Body -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/luxon@3.4.3/build/global/luxon.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.3.1"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.2.1"></script>


<script>
const labels        = <?= $labels ?>;
const grundbedarf   = <?= (int)$grundbedarf ?>;
const kalorienziel  = <?= (int)$kalorienziel ?>;

// zusätzliche Daten (Nährwerte)
const eiweissTage   = <?= $eiweissJson ?>;
const fettTage      = <?= $fettJson ?>;
const khTage        = <?= $khJson ?>;
const alkTage       = <?= $alkJson ?>;

const eiweissKW     = <?= $eiweissKWJson ?>;
const fettKW        = <?= $fettKWJson ?>;
const khKW          = <?= $khKWJson ?>;
const alkKW         = <?= $alkKWJson ?>;

// Farbpalette für Nährwerte (neu & gut sichtbar auf dunklem Hintergrund)
const colorProtein = '#007bb4ff';   // hellblau
const colorFat     = '#ff9900ff';   // orange
const colorCarb    = '#008f07ff';   // grün
const colorAlc     = '#d60303ff';   // violett

// Luxon: deutsche Monatsnamen
luxon.Settings.defaultLocale = 'de';

// Monats-Starts aus dem labels-Array ableiten (ISO-Strings)
function monthStartsFromLabels(allLabels) {
  if (!Array.isArray(allLabels) || allLabels.length === 0) return [];
  const first = luxon.DateTime.fromISO(allLabels[0]).startOf('month');
  const last  = luxon.DateTime.fromISO(allLabels[allLabels.length - 1]).startOf('month');
  const out = [];
  for (let cur = first; cur <= last; cur = cur.plus({ months: 1 })) out.push(cur);
  return out;
}

// Plugin: Labels in die Monatsmitte zeichnen (Gridlines bleiben an Monatsanfängen!)
const midMonthLabelsPlugin = {
  id: 'midMonthLabelsPlugin',
  afterDraw(chart) {
    const scale = chart.scales?.x;
    if (!scale || scale.type !== 'time') return;

    const xOpts = scale.options || {};
    if (!xOpts.midMonthLabels) return;

    const ctx = chart.ctx;
    const starts = monthStartsFromLabels(labels);
    if (!starts.length) return;

    const compactW = xOpts.midMonthLabelCompactWidth ?? 300;
    const step = (typeof scale.width === 'number' && scale.width < compactW) ? 2 : 1;

    // Font/Color wie Ticks
    let fontStr = '12px sans-serif';
    try {
      if (Chart?.helpers?.toFont) fontStr = Chart.helpers.toFont(xOpts.ticks?.font).string;
    } catch (_) {}
    const color = xOpts.ticks?.color ?? Chart.defaults.color ?? '#666';

    ctx.save();
    ctx.font = fontStr;
    ctx.fillStyle = color;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'bottom';

    // innerhalb der Achsenbox zeichnen (nicht außerhalb -> kein Clipping)
    const y = scale.bottom - 2;

    for (let i = 0; i < starts.length; i++) {
      if (step === 2 && (i % 2 === 1)) continue;

      const start = starts[i];
      const mid = start.plus({ days: Math.floor(start.daysInMonth / 2), hours: 12 });
      const x = scale.getPixelForValue(mid.toMillis());

      ctx.fillText(mid.setLocale('de').toFormat('MMM'), x, y);
    }

    ctx.restore();
  }
};

// einmalig registrieren (vor den Chart() Konstruktoren)
Chart.register(midMonthLabelsPlugin);

// X-Achse: Ticks (und damit Gridlines) bleiben an Monatsanfängen, Labels kommen aus Plugin
function monthXAxisScale() {
  return {
    type: 'time',
    time: {
      unit: 'month',
      tooltipFormat: 'dd.MM.yyyy'
    },

    // custom flags für das Plugin
    midMonthLabels: true,
    midMonthLabelCompactWidth: 300,

    ticks: {
      autoSkip: false,           // Gridlines an jedem Monat
      maxRotation: 0,
      minRotation: 0,
      callback: () => ' '        // built-in Label unsichtbar (Platz bleibt, Gridlines bleiben)
    }
  };
}


// Kalorien-Diagramm
new Chart(document.getElementById('kalorienChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Netto-Kalorien',
                data: <?= $nettoJson ?>,
                fill: false,
                showLine: false,
                pointRadius: 4,
                pointStyle: 'crossRot',
                pointBorderColor: 'rgba(0,0,0,0.4)',
                pointBorderWidth: 2,
                pointBackgroundColor: 'transparent'
            },
            {
                label: 'Brutto-Kalorien',
                data: <?= $bruttoJson ?>,
                fill: false,
                tension: 0.2,
                borderWidth: 2,
                borderDash: [5, 5],
                borderColor: '#888',
                hidden: true
            },
            {
                label: 'Netto-Kalorien (Ø pro KW)',
                data: <?= $nettoKWJson ?>,
                fill: false,
                tension: 0,
                borderWidth: 3,
                borderColor: 'black',
                pointRadius: 0,
                spanGaps: true,
                yAxisID: 'y'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            annotation: {
                annotations: {
                    gruenZone: { type: 'box', yMax: grundbedarf, backgroundColor: 'rgba(0,200,0,0.1)', borderWidth: 0 },
                    gelbZone:  { type: 'box', yMin: grundbedarf, yMax: kalorienziel, backgroundColor: 'rgba(255,215,0,0.15)', borderWidth: 0 },
                    rotZone:   { type: 'box', yMin: kalorienziel, backgroundColor: 'rgba(255,0,0,0.1)', borderWidth: 0 },
                    grundbedarfline: {
                        type: 'line', yMin: grundbedarf, yMax: grundbedarf, borderColor: 'green', borderWidth: 2, borderDash: [10,3],
                        label: { display: true, content: 'Grundbedarf', position: 'start', yAdjust: -10, backgroundColor: 'rgba(0,0,0,0)', color: 'green' }
                    },
                    kalorienzielfline: {
                        type: 'line', yMin: kalorienziel, yMax: kalorienziel, borderColor: 'red', borderWidth: 2, borderDash: [10,3],
                        label: { display: true, content: 'Kalorienlimit', position: 'start', yAdjust: -10, backgroundColor: 'rgba(0,0,0,0)', color: 'red' }
                    }
                }
            }
        },
        scales: {
            x: monthXAxisScale(),
            y: { beginAtZero: true }
        }
    }
});

// Gewicht-Diagramm
new Chart(document.getElementById('gewichtChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Gewicht (kg)',
                data: <?= $gewichtJson ?>,
                borderColor: '#333',
                backgroundColor: 'rgba(51,51,51,0.15)',
                fill: false,
                tension: 0.3,
                borderWidth: 3,
                spanGaps: true,

                pointStyle: 'crossRot',   // oder 'cross'
                pointRadius: 4,
                pointBorderColor: 'rgba(0,0,0,0.4)', // Kreuzfarbe
                pointBorderWidth: 2,
                pointBackgroundColor: 'transparent'
            },
            {
                label: 'Trendlinie',
                data: <?= $trendJson ?>,
                borderColor: 'grey',
                borderDash: [4,2],
                borderWidth: 2,
                tension: 0,
                fill: false,
                pointRadius: 0,
                spanGaps: true
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            annotation: {
                annotations: {
                    ziel:        { type: 'line', yMin: 90,  yMax: 90,  borderColor: 'green', borderWidth: 2, borderDash: [10,3],
                                   label: { display: true, content: 'Zielgewicht', position: 'start', yAdjust: -10, backgroundColor: 'rgba(0,0,0,0)', color: 'green' } },
                    zielunten:   { type: 'line', yMin: 85,  yMax: 85,  borderColor: 'green', borderWidth: 2, borderDash: [10,3] },
                    zielbereich: { type: 'box',  yMin: 85,  yMax: 90,  backgroundColor: 'rgba(0,200,0,0.15)', borderWidth: 0 },
                    ubergangszone:       { type: 'box', yMin: 90,  yMax: 100, backgroundColor: 'rgba(255,215,0,0.15)', borderWidth: 0 },
                    unakzeptabel:        { type: 'box', yMin: 100,                  backgroundColor: 'rgba(255,0,0,0.15)', borderWidth: 0 },
                    ubergangszone_unten: { type: 'box',           yMax: 85,  backgroundColor: 'rgba(255,215,0,0.15)', borderWidth: 0 }
                }
            }
        },
        scales: {
            x: monthXAxisScale(),
            y: { beginAtZero: false, min: 80 }
        }
    }
});

function withAlpha(color, alpha = 0.5) {
  if (typeof color !== 'string') return color;
  if (color.startsWith('#')) {
    let hex = color.slice(1);
    if (hex.length === 8) hex = hex.slice(0, 6);
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
    const num = parseInt(hex, 16);
    const r = (num >> 16) & 255, g = (num >> 8) & 255, b = num & 255;
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }
  if (color.startsWith('rgb(')) {
    const comps = color.slice(4, -1);
    return `rgba(${comps}, ${alpha})`;
  }
  if (color.startsWith('rgba(')) {
    const parts = color.slice(5, -1).split(',').map(s => s.trim());
    return `rgba(${parts[0]}, ${parts[1]}, ${parts[2]}, ${alpha})`;
  }
  return color;
}

function computeTrend(values) {
  const x = [], y = [];
  for (let i = 0; i < values.length; i++) {
    const v = values[i];
    if (v !== null && !Number.isNaN(v)) { x.push(i); y.push(Number(v)); }
  }
  const out = Array(values.length).fill(null);
  if (x.length < 2) return out;

  const n = x.length;
  const sumX = x.reduce((a,b)=>a+b,0);
  const sumY = y.reduce((a,b)=>a+b,0);
  const sumXY = x.reduce((a,xi,idx)=>a + xi*y[idx], 0);
  const sumXX = x.reduce((a,xi)=>a + xi*xi, 0);
  const den = (n*sumXX - sumX*sumX);
  if (den === 0) return out;

  const slope = (n*sumXY - sumX*sumY) / den;
  const intercept = (sumY - slope*sumX) / n;

  for (let i = 0; i < values.length; i++) {
    out[i] = (values[i] === null) ? null : +(slope*i + intercept).toFixed(2);
  }
  return out;
}

function makeMacroChart(canvasId, dailyData, weeklyData, color, label) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;

  const trend = computeTrend(dailyData);
  new Chart(canvas.getContext('2d'), {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        {
          label: `Tageswerte`,
          data: dailyData,
          showLine: false,
          pointRadius: 4,
          pointStyle: 'crossRot',
          pointBorderColor: withAlpha(color, 0.5),
          pointBackgroundColor: 'transparent',
          borderColor: color,
          fill: false
        },
        {
          label: `Ø pro KW`,
          data: weeklyData,
          fill: false,
          tension: 0,
          borderWidth: 3,
          borderColor: color,
          pointRadius: 0,
          spanGaps: true
        },
        {
          label: `Trend`,
          data: trend,
          fill: false,
          tension: 0,
          borderWidth: 2,
          borderColor: color,
          borderDash: [4, 2],
          pointRadius: 0,
          spanGaps: true
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },     // Legende aus
        tooltip: { enabled: false }     // Tooltip aus (optional)
      },
      scales: {
        x: monthXAxisScale(),
        y: {
          beginAtZero: true,
          title: { display: false, text: 'Gramm' }
        }
      }
    }
  });
}

makeMacroChart('eiweissChart', eiweissTage, eiweissKW, colorProtein, 'Eiweiß');
makeMacroChart('fettChart',    fettTage,    fettKW,    colorFat,     'Fett');
makeMacroChart('khChart',      khTage,      khKW,      colorCarb,    'Kohlenhydrate');
makeMacroChart('alkChart',     alkTage,     alkKW,     colorAlc,     'Alkohol');

function applyAlcoholToggle() {
  const checkbox   = document.getElementById('toggleAlk');
  const enabled    = checkbox && checkbox.checked;

  const proteinDiv = document.getElementById('macroProtein');
  const fatDiv     = document.getElementById('macroFat');
  const carbDiv    = document.getElementById('macroCarb');
  const alcDiv     = document.getElementById('macroAlc');

  const baseDivs   = [proteinDiv, fatDiv, carbDiv];

  if (alcDiv) alcDiv.style.display = enabled ? '' : 'none';

  if (enabled) {
    [...baseDivs, alcDiv].forEach(div => {
      if (!div) return;
      div.classList.remove('chart-third');
      if (!div.classList.contains('chart-quarter')) div.classList.add('chart-quarter');
    });
  } else {
    baseDivs.forEach(div => {
      if (!div) return;
      div.classList.remove('chart-quarter');
      if (!div.classList.contains('chart-third')) div.classList.add('chart-third');
    });
  }
}

const toggleAlkElement = document.getElementById('toggleAlk');
if (toggleAlkElement) {
  toggleAlkElement.checked = false;
  applyAlcoholToggle();
  toggleAlkElement.addEventListener('change', applyAlcoholToggle);
}
</script>


</body>
</html>
