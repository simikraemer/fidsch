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

$modus = isset($_GET['modus']) ? (string)$_GET['modus'] : 'verlauf';
$gueltigeModi = ['verlauf', 'wochentage'];
if (!in_array($modus, $gueltigeModi, true)) {
    $modus = 'verlauf';
}
$isWochentageMode = ($modus === 'wochentage');

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
    while ($r = $res->fetch_assoc()) {
        $verfuegbareJahre[] = (int)$r['y'];
    }
    $res->free();
}
if (!$verfuegbareJahre) {
    $verfuegbareJahre = [$aktJahr];
}

$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : $aktJahr;

// Default: aktuelles Jahr, aber nur wenn vorhanden; sonst neuestes Jahr mit Daten
if (!in_array($jahr, $verfuegbareJahre, true)) {
    $jahr = in_array($aktJahr, $verfuegbareJahre, true) ? $aktJahr : $verfuegbareJahre[0];
}

$startDate = sprintf('%04d-01-01', $jahr);
$endDate   = sprintf('%04d-01-01', $jahr + 1); // exklusives Ende

function weekdayAvgFromDateMap(array $dateMap, int $precision = 2): array
{
    $sum   = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0, 6 => 0.0, 7 => 0.0];
    $count = [1 => 0,   2 => 0,   3 => 0,   4 => 0,   5 => 0,   6 => 0,   7 => 0];

    foreach ($dateMap as $tag => $value) {
        if ($value === null) {
            continue;
        }
        $wochentag = (int)date('N', strtotime($tag)); // 1=Montag ... 7=Sonntag
        $sum[$wochentag] += (float)$value;
        $count[$wochentag]++;
    }

    $out = [];
    for ($i = 1; $i <= 7; $i++) {
        $out[] = $count[$i] > 0 ? round($sum[$i] / $count[$i], $precision) : null;
    }

    return $out;
}

function computeExponentialTrendSeries(array $values, int $precision = 1): array
{
    $trend = array_fill(0, count($values), null);

    $x = [];
    $lny = [];

    foreach ($values as $i => $v) {
        if ($v === null) {
            continue;
        }
        $v = (float)$v;
        if ($v <= 0) {
            continue;
        }

        $x[]   = (float)$i;
        $lny[] = log($v);
    }

    if (count($x) <= 1) {
        return $trend;
    }

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
    if ($den == 0.0) {
        return $trend;
    }

    $b   = ($n * $sumXY - $sumX * $sumY) / $den;
    $lnA = ($sumY - $b * $sumX) / $n;
    $a   = exp($lnA);

    for ($i = 0; $i < count($values); $i++) {
        $trend[$i] = round($a * exp($b * $i), $precision);
    }

    return $trend;
}

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
    if (!isset($nettoTage[$tag])) {
        $nettoTage[$tag] = 0;
    }
    $nettoTage[$tag] += $kalorien;
}
$stmt->close();

$nettoSumme         = array_sum($nettoTage);
$tageMitNettoWerten = count($nettoTage);
$nettoDurchschnitt  = $tageMitNettoWerten > 0 ? round($nettoSumme / $tageMitNettoWerten) : 0;

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
foreach ($periode as $datum) {
    $alleTage[] = $datum->format('Y-m-d');
}

// 8a) Serien für Charts vorbereiten
$nettoWerte      = [];
$supernettoWerte = [];
$bruttoWerte     = [];
$gewichtWerte    = [];
$eiweissWerte    = [];
$fettWerte       = [];
$khWerte         = [];
$alkWerte        = [];

$letztesGewicht = null;
$erstesGewicht  = null;
$maxGewicht     = null;

foreach ($alleTage as $tag) {
    $nettoWerte[]      = array_key_exists($tag, $nettoTage)  ? $nettoTage[$tag]                : null;
    $bruttoWerte[]     = array_key_exists($tag, $bruttoTage) ? $bruttoTage[$tag]               : null;
    $supernettoWerte[] = array_key_exists($tag, $nettoTage)  ? $nettoTage[$tag] - $grundbedarf : null;

    if (array_key_exists($tag, $gewichtTage)) {
        $tagesGewicht = $gewichtTage[$tag];
        if ($erstesGewicht === null) {
            $erstesGewicht = $tagesGewicht;
        }
        if ($maxGewicht === null || $maxGewicht < $tagesGewicht) {
            $maxGewicht = $tagesGewicht;
        }
        $gewichtWerte[] = $tagesGewicht;
        $letztesGewicht = $tagesGewicht;
    } else {
        $gewichtWerte[] = null;
    }

    $eiweissWerte[] = array_key_exists($tag, $eiweissTage) ? round($eiweissTage[$tag], 2) : null;
    $fettWerte[]    = array_key_exists($tag, $fettTage)    ? round($fettTage[$tag], 2)    : null;
    $khWerte[]      = array_key_exists($tag, $khTage)      ? round($khTage[$tag], 2)      : null;
    $alkWerte[]     = array_key_exists($tag, $alkTage)     ? round($alkTage[$tag], 2)     : null;
}

$gewichtsDiffText = '—';
if ($erstesGewicht !== null && $letztesGewicht !== null) {
    $diff = $letztesGewicht - $erstesGewicht;
    $sign = ($diff > 0) ? '+' : (($diff < 0) ? '−' : '±');
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
foreach ($alleTage as $tag) {
    $kw = date('o-W', strtotime($tag));
    if (!isset($kwSammler[$kw])) {
        $kwSammler[$kw] = [];
    }
    if (isset($nettoTage[$tag])) {
        $kwSammler[$kw][] = $nettoTage[$tag];
    }
}
foreach ($kwSammler as $kw => $werte) {
    if (!count($werte)) {
        continue;
    }
    $avg      = round(array_sum($werte) / count($werte));
    $tageInKW = array_values(array_filter($alleTage, fn($t) => date('o-W', strtotime($t)) === $kw));
    if (!$tageInKW) {
        continue;
    }
    $firstIndex = array_search($tageInKW[0], $alleTage, true);
    $lastIndex  = array_search(end($tageInKW), $alleTage, true);
    if ($firstIndex !== false) {
        $nettoKWavg[$firstIndex] = $avg;
    }
    if ($lastIndex !== false && $lastIndex !== $firstIndex) {
        $nettoKWavg[$lastIndex] = $avg;
    }
}

// 9b) Wochenmittel für Nährwerte (Gramm)
function kwAvgSerie(array $alleTage, array $tageMap): array
{
    $out       = array_fill(0, count($alleTage), null);
    $sammlerKW = [];

    foreach ($alleTage as $tag) {
        $kw = date('o-W', strtotime($tag));
        if (!isset($sammlerKW[$kw])) {
            $sammlerKW[$kw] = [];
        }
        if (isset($tageMap[$tag])) {
            $sammlerKW[$kw][] = $tageMap[$tag];
        }
    }

    foreach ($sammlerKW as $kw => $werte) {
        if (!count($werte)) {
            continue;
        }
        $avg      = round(array_sum($werte) / count($werte), 2);
        $tageInKW = array_values(array_filter($alleTage, fn($t) => date('o-W', strtotime($t)) === $kw));
        if (!$tageInKW) {
            continue;
        }
        $firstIndex = array_search($tageInKW[0], $alleTage, true);
        $lastIndex  = array_search(end($tageInKW), $alleTage, true);
        if ($firstIndex !== false) {
            $out[$firstIndex] = $avg;
        }
        if ($lastIndex !== false && $lastIndex !== $firstIndex) {
            $out[$lastIndex] = $avg;
        }
    }

    return $out;
}

$eiweissKWavg = kwAvgSerie($alleTage, $eiweissTage);
$fettKWavg    = kwAvgSerie($alleTage, $fettTage);
$khKWavg      = kwAvgSerie($alleTage, $khTage);
$alkKWavg     = kwAvgSerie($alleTage, $alkTage);

// 10) Anzeige-Serien je nach Modus
$wochentagLabels = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

$anzeigeLabels     = $alleTage;
$anzeigeNetto      = $nettoWerte;
$anzeigeSupernetto = $supernettoWerte;
$anzeigeBrutto     = $bruttoWerte;
$anzeigeGewicht    = $gewichtWerte;
$anzeigeEiweiss    = $eiweissWerte;
$anzeigeFett       = $fettWerte;
$anzeigeKh         = $khWerte;
$anzeigeAlk        = $alkWerte;

$anzeigeNettoKW   = $nettoKWavg;
$anzeigeEiweissKW = $eiweissKWavg;
$anzeigeFettKW    = $fettKWavg;
$anzeigeKhKW      = $khKWavg;
$anzeigeAlkKW     = $alkKWavg;

if ($isWochentageMode) {
    $anzeigeLabels  = $wochentagLabels;
    $anzeigeNetto   = weekdayAvgFromDateMap($nettoTage, 0);
    $anzeigeBrutto  = weekdayAvgFromDateMap($bruttoTage, 0);
    $anzeigeEiweiss = weekdayAvgFromDateMap($eiweissTage, 2);
    $anzeigeFett    = weekdayAvgFromDateMap($fettTage, 2);
    $anzeigeKh      = weekdayAvgFromDateMap($khTage, 2);
    $anzeigeAlk     = weekdayAvgFromDateMap($alkTage, 2);

    $anzeigeGewicht = [];
    $anzeigeNettoKW   = array_fill(0, 7, null);
    $anzeigeEiweissKW = array_fill(0, 7, null);
    $anzeigeFettKW    = array_fill(0, 7, null);
    $anzeigeKhKW      = array_fill(0, 7, null);
    $anzeigeAlkKW     = array_fill(0, 7, null);

    $anzeigeSupernetto = array_map(
        fn($v) => $v !== null ? (int)round($v - $grundbedarf) : null,
        $anzeigeNetto
    );
}

// Trendlinie nur im Zeitverlauf
$trendWerte = $isWochentageMode ? [] : computeExponentialTrendSeries($anzeigeGewicht, 1);

// 11) JSON für Charts
$labels         = json_encode($anzeigeLabels, JSON_UNESCAPED_UNICODE);
$nettoJson      = json_encode($anzeigeNetto);
$supernettoJson = json_encode($anzeigeSupernetto);
$bruttoJson     = json_encode($anzeigeBrutto);
$gewichtJson    = json_encode($anzeigeGewicht);
$trendJson      = json_encode($trendWerte);
$nettoKWJson    = json_encode($anzeigeNettoKW);

$eiweissJson = json_encode($anzeigeEiweiss);
$fettJson    = json_encode($anzeigeFett);
$khJson      = json_encode($anzeigeKh);
$alkJson     = json_encode($anzeigeAlk);

$eiweissKWJson = json_encode($anzeigeEiweissKW);
$fettKWJson    = json_encode($anzeigeFettKW);
$khKWJson      = json_encode($anzeigeKhKW);
$alkKWJson     = json_encode($anzeigeAlkKW);

// 12) Rendering starten (kein Output davor!)
$page_title = 'Ernährung';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div id="healthPage" class="lt-page dashboard-page">
  <div class="lt-topbar">
    <h1 class="ueberschrift dashboard-title">
      <span class="dashboard-title-main">Ernährung <?= htmlspecialchars((string)$jahr, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="dashboard-title-soft">| <?= htmlspecialchars($gewichtsDiffText, ENT_QUOTES, 'UTF-8') ?></span>
    </h1>

    <form method="get" id="zeitForm" class="dashboard-filterform">
      <div class="lt-yearwrap">
        <label for="modus" class="lt-label">Modus</label>
        <select id="modus" name="modus" class="kategorie-select" onchange="this.form.submit()">
          <option value="verlauf" <?= $modus === 'verlauf' ? 'selected' : '' ?>>Jahr</option>
          <option value="wochentage" <?= $modus === 'wochentage' ? 'selected' : '' ?>>Wochentage</option>
        </select>
      </div>

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

  <div class="ernährungsdiablock">
    <div class="dashboard-pies<?= $isWochentageMode ? ' dashboard-pies--single' : '' ?>">
      <div class="dashboard-pie-card<?= $isWochentageMode ? ' dashboard-pie-card--full' : '' ?>">
        <div class="dashboard-pie-kpi">
          <span class="dashboard-pie-kpi-label">Kalorien</span>
          <span class="dashboard-pie-kpi-value">Ø<?= (int)$nettoDurchschnitt ?> kcal/Tag</span>
        </div>
        <div class="dashboard-pie-wrap">
          <canvas id="kalorienChart"></canvas>
        </div>
      </div>

      <?php if (!$isWochentageMode): ?>
      <div class="dashboard-pie-card">
        <div class="dashboard-pie-kpi">
          <span class="dashboard-pie-kpi-label">Körpergewicht</span>
          <span class="dashboard-pie-kpi-value">
            <?= ($erstesGewicht !== null ? $erstesGewicht : '—') ?> kg → <?= ($letztesGewicht !== null ? $letztesGewicht : '—') ?> kg
          </span>
        </div>
        <div class="dashboard-pie-wrap">
          <canvas id="gewichtChart"></canvas>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="dashboard-pies dashboard-pies-3">
      <div class="dashboard-pie-card">
        <div class="dashboard-pie-kpi">
          <span class="dashboard-pie-kpi-label">Protein</span>
          <span class="dashboard-pie-kpi-value">Ø<?= (int)$eiweissDurchschnitt ?> g/Tag</span>
        </div>
        <div class="dashboard-pie-wrap">
          <canvas id="eiweissChart"></canvas>
        </div>
      </div>

      <div class="dashboard-pie-card">
        <div class="dashboard-pie-kpi">
          <span class="dashboard-pie-kpi-label">Fett</span>
          <span class="dashboard-pie-kpi-value">Ø<?= (int)$fettDurchschnitt ?> g/Tag</span>
        </div>
        <div class="dashboard-pie-wrap">
          <canvas id="fettChart"></canvas>
        </div>
      </div>

      <div class="dashboard-pie-card">
        <div class="dashboard-pie-kpi">
          <span class="dashboard-pie-kpi-label">Carbs</span>
          <span class="dashboard-pie-kpi-value">Ø<?= (int)$khDurchschnitt ?> g/Tag</span>
        </div>
        <div class="dashboard-pie-wrap">
          <canvas id="khChart"></canvas>
        </div>
      </div>

      <!-- <div class="dashboard-pie-card">
        <div class="dashboard-pie-kpi">
          <span class="dashboard-pie-kpi-label">Alkohol</span>
          <span class="dashboard-pie-kpi-value">Ø<?= (int)$alkDurchschnitt ?> g/Tag</span>
        </div>
        <div class="dashboard-pie-wrap">
          <canvas id="alkChart"></canvas>
        </div>
      </div> -->
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/luxon@3.4.3/build/global/luxon.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.3.1"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.2.1"></script>

<script>
const chartMode      = <?= json_encode($modus, JSON_UNESCAPED_UNICODE) ?>;
const isWeekdayMode  = chartMode === 'wochentage';

const labels         = <?= $labels ?>;
const grundbedarf    = <?= (int)$grundbedarf ?>;
const kalorienziel   = <?= (int)$kalorienziel ?>;

const nettoData      = <?= $nettoJson ?>;
const bruttoData     = <?= $bruttoJson ?>;
const nettoKWData    = <?= $nettoKWJson ?>;
const gewichtData    = <?= $gewichtJson ?>;
const gewichtTrend   = <?= $trendJson ?>;

const eiweissTage    = <?= $eiweissJson ?>;
const fettTage       = <?= $fettJson ?>;
const khTage         = <?= $khJson ?>;
const alkTage        = <?= $alkJson ?>;

const eiweissKW      = <?= $eiweissKWJson ?>;
const fettKW         = <?= $fettKWJson ?>;
const khKW           = <?= $khKWJson ?>;
const alkKW          = <?= $alkKWJson ?>;

const weekdayShortLabels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

// Farbpalette für Nährwerte
const colorProtein = '#007bb4ff';
const colorFat     = '#ff9900ff';
const colorCarb    = '#008f07ff';
const colorAlc     = '#d60303ff';

luxon.Settings.defaultLocale = 'de';

function monthStartsFromLabels(allLabels) {
  if (!Array.isArray(allLabels) || allLabels.length === 0) return [];
  const first = luxon.DateTime.fromISO(allLabels[0]).startOf('month');
  const last  = luxon.DateTime.fromISO(allLabels[allLabels.length - 1]).startOf('month');
  const out = [];
  for (let cur = first; cur <= last; cur = cur.plus({ months: 1 })) out.push(cur);
  return out;
}

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

Chart.register(midMonthLabelsPlugin);

function monthXAxisScale() {
  return {
    type: 'time',
    time: {
      unit: 'month',
      tooltipFormat: 'dd.MM.yyyy'
    },
    midMonthLabels: true,
    midMonthLabelCompactWidth: 300,
    ticks: {
      autoSkip: false,
      maxRotation: 0,
      minRotation: 0,
      callback: () => ' '
    }
  };
}

function weekdayXAxisScale() {
  return {
    type: 'category',
    offset: true,
    grid: {
      offset: true
    },
    ticks: {
      autoSkip: false,
      maxRotation: 0,
      minRotation: 0
    }
  };
}

function xAxisScale() {
  return isWeekdayMode ? weekdayXAxisScale() : monthXAxisScale();
}

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
  const x = [];
  const y = [];

  for (let i = 0; i < values.length; i++) {
    const v = values[i];
    if (v !== null && !Number.isNaN(v)) {
      x.push(i);
      y.push(Number(v));
    }
  }

  const out = Array(values.length).fill(null);
  if (x.length < 2) return out;

  const n = x.length;
  const sumX = x.reduce((a, b) => a + b, 0);
  const sumY = y.reduce((a, b) => a + b, 0);
  const sumXY = x.reduce((a, xi, idx) => a + xi * y[idx], 0);
  const sumXX = x.reduce((a, xi) => a + xi * xi, 0);
  const den = (n * sumXX - sumX * sumX);
  if (den === 0) return out;

  const slope = (n * sumXY - sumX * sumY) / den;
  const intercept = (sumY - slope * sumX) / n;

  for (let i = 0; i < values.length; i++) {
    out[i] = (values[i] === null) ? null : +(slope * i + intercept).toFixed(2);
  }

  return out;
}

const kalorienDatasets = isWeekdayMode
  ? [
      {
        label: 'Netto-Kalorien (Ø je Wochentag)',
        data: nettoData,
        fill: false,
        tension: 0.25,
        borderWidth: 3,
        borderColor: 'black',
        pointRadius: 4,
        spanGaps: true
      },
      {
        label: 'Brutto-Kalorien (Ø je Wochentag)',
        data: bruttoData,
        fill: false,
        tension: 0.25,
        borderWidth: 2,
        borderDash: [5, 5],
        borderColor: '#888',
        hidden: true,
        spanGaps: true
      }
    ]
  : [
      {
        label: 'Netto-Kalorien',
        data: nettoData,
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
        data: bruttoData,
        fill: false,
        tension: 0.2,
        borderWidth: 2,
        borderDash: [5, 5],
        borderColor: '#888',
        hidden: true
      },
      {
        label: 'Netto-Kalorien (Ø pro KW)',
        data: nettoKWData,
        fill: false,
        tension: 0,
        borderWidth: 3,
        borderColor: 'black',
        pointRadius: 0,
        spanGaps: true,
        yAxisID: 'y'
      }
    ];

new Chart(document.getElementById('kalorienChart').getContext('2d'), {
  type: 'line',
  data: {
    labels: labels,
    datasets: kalorienDatasets
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      annotation: {
        annotations: {
          gruenZone: {
            type: 'box',
            yMax: grundbedarf,
            backgroundColor: 'rgba(0,200,0,0.1)',
            borderWidth: 0
          },
          gelbZone: {
            type: 'box',
            yMin: grundbedarf,
            yMax: kalorienziel,
            backgroundColor: 'rgba(255,215,0,0.15)',
            borderWidth: 0
          },
          rotZone: {
            type: 'box',
            yMin: kalorienziel,
            backgroundColor: 'rgba(255,0,0,0.1)',
            borderWidth: 0
          },
          grundbedarfline: {
            type: 'line',
            yMin: grundbedarf,
            yMax: grundbedarf,
            borderColor: 'green',
            borderWidth: 1,
            borderDash: [25, 5]
          },
          kalorienzielfline: {
            type: 'line',
            yMin: kalorienziel,
            yMax: kalorienziel,
            borderColor: 'red',
            borderWidth: 2,
            borderDash: [25, 5]
          }
        }
      }
    },
    scales: {
      x: xAxisScale(),
      y: isWeekdayMode
        ? { beginAtZero: false, min: 1600 }
        : { beginAtZero: true }
    }
  }
});

if (!isWeekdayMode) {
  const gewichtDatasets = [
    {
      label: 'Gewicht (kg)',
      data: gewichtData,
      borderColor: '#333',
      backgroundColor: 'rgba(51,51,51,0.15)',
      fill: false,
      tension: 0.3,
      borderWidth: 3,
      spanGaps: true,
      pointStyle: 'crossRot',
      pointRadius: 4,
      pointBorderColor: 'rgba(0,0,0,0.4)',
      pointBorderWidth: 2,
      pointBackgroundColor: 'transparent'
    }
  ];

  if (Array.isArray(gewichtTrend) && gewichtTrend.some(v => v !== null)) {
    gewichtDatasets.push({
      label: 'Trendlinie',
      data: gewichtTrend,
      borderColor: 'grey',
      borderDash: [4, 2],
      borderWidth: 2,
      tension: 0,
      fill: false,
      pointRadius: 0,
      spanGaps: true
    });
  }

  new Chart(document.getElementById('gewichtChart').getContext('2d'), {
    type: 'line',
    data: {
      labels: labels,
      datasets: gewichtDatasets
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        annotation: {
          annotations: {
            ziel:                 { type: 'line', yMin: 90,  yMax: 90,  borderColor: 'green', borderWidth: 1, borderDash: [25,5] },
            zielunten:            { type: 'line', yMin: 85,  yMax: 85,  borderColor: 'green', borderWidth: 1, borderDash: [25,5] },
            zielbereich:          { type: 'box',  yMin: 85,  yMax: 90,  backgroundColor: 'rgba(0,200,0,0.15)', borderWidth: 0 },
            ubergangszone:        { type: 'box',  yMin: 90,  yMax: 100, backgroundColor: 'rgba(255,215,0,0.15)', borderWidth: 0 },
            unakzeptabel:         { type: 'box',  yMin: 100,             backgroundColor: 'rgba(255,0,0,0.15)', borderWidth: 0 },
            ubergangszone_unten:  { type: 'box',             yMax: 85,   backgroundColor: 'rgba(255,215,0,0.15)', borderWidth: 0 }
          }
        }
      },
      scales: {
        x: xAxisScale(),
        y: { beginAtZero: false, min: 80 }
      }
    }
  });
}
function makeMacroChart(canvasId, dailyData, weeklyData, color, label) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;

  const trend = computeTrend(dailyData);
  const macroLabels = isWeekdayMode ? weekdayShortLabels : labels;

  const datasets = isWeekdayMode
    ? [
        {
          label: 'Ø je Wochentag',
          data: dailyData,
          fill: false,
          tension: 0.25,
          borderWidth: 3,
          borderColor: color,
          pointRadius: 4,
          pointBorderColor: withAlpha(color, 0.75),
          pointBorderWidth: 2,
          pointBackgroundColor: 'transparent',
          spanGaps: true
        }
      ]
    : [
        {
          label: 'Tageswerte',
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
          label: 'Ø pro KW',
          data: weeklyData,
          fill: false,
          tension: 0,
          borderWidth: 3,
          borderColor: color,
          pointRadius: 0,
          spanGaps: true
        },
        {
          label: 'Trend',
          data: trend,
          fill: false,
          tension: 0,
          borderWidth: 2,
          borderColor: color,
          borderDash: [4, 2],
          pointRadius: 0,
          spanGaps: true
        }
      ];

  new Chart(canvas.getContext('2d'), {
    type: 'line',
    data: {
      labels: macroLabels,
      datasets: datasets
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { enabled: false }
      },
      scales: {
        x: xAxisScale(),
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