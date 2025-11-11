<?php
// fit/Start.php (Übersicht)

// 1) Auth (diese Seite ist geschützt)
require_once __DIR__ . '/../auth.php';

// 2) DB für alle Abfragen
require_once __DIR__ . '/../db.php';

// 3) Variablen + Zeitraum bestimmen (POST-Verarbeitung gibt es hier nicht)
$grundbedarf  = 2200;
$kalorienziel = 3000;
$standard_Monatsanzeige = 3;

$monate    = isset($_GET['monate']) ? max(1, min(12, (int)$_GET['monate'])) : $standard_Monatsanzeige;
$startDate = (new DateTime("-{$monate} months"))->format('Y-m-d');

// 4) Brutto-Kalorien (nur Zufuhr)
$stmt = $fitconn->prepare("
    SELECT DATE(tstamp) AS tag, SUM(kalorien) AS kalorien
    FROM kalorien
    WHERE tstamp >= ?
    GROUP BY DATE(tstamp)
");
$stmt->bind_param('s', $startDate);
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
    WHERE tstamp >= ?
    GROUP BY DATE(tstamp)
");
$stmt->bind_param('s', $startDate);
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
    WHERE tstamp >= ?
    GROUP BY DATE(tstamp)
    UNION ALL
    SELECT DATE(tstamp) AS tag, -SUM(kalorien) AS gesamt
    FROM training
    WHERE tstamp >= ?
    GROUP BY DATE(tstamp)
    ORDER BY tag
");
$stmt->bind_param('ss', $startDate, $startDate);
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
    WHERE tstamp >= ?
    GROUP BY DATE(tstamp)
");
$stmt->bind_param('s', $startDate);
$stmt->execute();
$result = $stmt->get_result();

$gewichtTage = [];
while ($row = $result->fetch_assoc()) {
    $gewichtTage[$row['tag']] = (float)$row['gewicht'];
}
$stmt->close();

// 7) Alle Tage im Zeitraum
$alleTage = [];
$heute    = new DateTime();
$periode  = new DatePeriod(new DateTime($startDate), new DateInterval('P1D'), $heute);
foreach ($periode as $datum) $alleTage[] = $datum->format('Y-m-d');

// 8) Serien für Charts vorbereiten
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

// 10) einfache Trendlinie über vorhandene Gewichtswerte
$x = $y = [];
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
            $trendWerte[$i] = is_null($gewichtWerte[$i]) ? null : round($slope*$i + $intercept, 1);
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
<!-- Seiteninhalt -->
<div class="zeitbereich-container">
    <form method="get" id="zeitForm" class="zeitbereich-form">
        <label for="monatRange" class="zeitbereich-label">
            Zeitraum: <span id="monatWert"><?= htmlspecialchars((string)$monate, ENT_QUOTES) ?> Monate</span>
        </label>
        <input type="range"
               id="monatRange"
               name="monate"
               min="1"
               max="12"
               value="<?= htmlspecialchars((string)$monate, ENT_QUOTES) ?>"
               class="zeitbereich-slider"
               oninput="updateMonat(this.value)"
               onchange="document.getElementById('zeitForm').submit()">
    </form>
</div>

<div class="chart-row">
  <div class="chart-half">
    <h2 class="ueberschrift">Kalorien | Ø<?= (int)$nettoDurchschnitt ?> kcal/Tag</h2>
    <canvas id="kalorienChart"></canvas>
  </div>
  <div class="chart-half">
    <h2 class="ueberschrift">Gewicht | <?= ($maxGewicht !== null ? $maxGewicht : '—') . " kg -> " . ($letztesGewicht !== null ? $letztesGewicht : '—') ?> kg</h2>
    <canvas id="gewichtChart"></canvas>
  </div>
</div>

<div class="chart-row">
  <div class="chart-quarter">
    <h2 class="ueberschrift">Eiweiß</h2>
    <canvas id="eiweissChart"></canvas>
  </div>
  <div class="chart-quarter">
    <h2 class="ueberschrift">Fett</h2>
    <canvas id="fettChart"></canvas>
  </div>
  <div class="chart-quarter">
    <h2 class="ueberschrift">Kohlenhydrate</h2>
    <canvas id="khChart"></canvas>
  </div>
  <div class="chart-quarter">
    <h2 class="ueberschrift">Alkohol</h2>
    <canvas id="alkChart"></canvas>
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

// Kalorien-Diagramm (unverändert ohne Nährwerte)
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
            annotation: {
                annotations: {
                    gruenZone: { type: 'box', yMax: grundbedarf, backgroundColor: 'rgba(0,200,0,0.1)', borderWidth: 0 },
                    gelbZone:  { type: 'box', yMin: grundbedarf, yMax: kalorienziel, backgroundColor: 'rgba(255,215,0,0.15)', borderWidth: 0 },
                    rotZone:   { type: 'box', yMin: kalorienziel, backgroundColor: 'rgba(255,0,0,0.1)', borderWidth: 0 },
                    grundbedarfline: {
                        type: 'line', yMin: grundbedarf, yMax: grundbedarf, borderColor: 'green', borderWidth: 2, borderDash: [4,2],
                        label: { display: true, content: 'Grundbedarf', position: 'start', yAdjust: -10, backgroundColor: 'rgba(0,0,0,0)', color: 'green' }
                    },
                    kalorienzielfline: {
                        type: 'line', yMin: kalorienziel, yMax: kalorienziel, borderColor: 'red', borderWidth: 2, borderDash: [4,2],
                        label: { display: true, content: 'Kalorienlimit', position: 'start', yAdjust: -10, backgroundColor: 'rgba(0,0,0,0)', color: 'red' }
                    }
                }
            }
        },
        scales: {
            x: {
                type: 'time',
                time: { unit: 'day', tooltipFormat: 'dd.MM.yyyy' },
                ticks: {
                    source: 'auto', autoSkip: true, maxTicksLimit: 15,
                    callback: (value) => luxon.DateTime.fromMillis(value).toFormat('dd.MM.'),
                    maxRotation: 0, minRotation: 0
                }
            },
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
                pointBackgroundColor: '#333',
                pointBorderColor: 'transparent',
                pointBorderWidth: 0,
                pointRadius: 4
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
            annotation: {
                annotations: {
                    ziel:        { type: 'line', yMin: 90,  yMax: 90,  borderColor: 'green', borderWidth: 2, borderDash: [6,4],
                                   label: { display: true, content: 'Zielgewicht', position: 'start', yAdjust: -10, backgroundColor: 'rgba(0,0,0,0)', color: 'green' } },
                    zielunten:   { type: 'line', yMin: 85,  yMax: 85,  borderColor: 'green', borderWidth: 2, borderDash: [6,4] },
                    zielbereich: { type: 'box',  yMin: 85,  yMax: 90,  backgroundColor: 'rgba(0,200,0,0.15)', borderWidth: 0 },
                    ubergangszone:       { type: 'box', yMin: 90,  yMax: 100, backgroundColor: 'rgba(255,215,0,0.15)', borderWidth: 0 },
                    unakzeptabel:        { type: 'box', yMin: 100,                  backgroundColor: 'rgba(255,0,0,0.15)', borderWidth: 0 },
                    ubergangszone_unten: { type: 'box',           yMax: 85,  backgroundColor: 'rgba(255,215,0,0.15)', borderWidth: 0 }
                }
            }
        },
        scales: {
            x: {
                type: 'time',
                time: { unit: 'day', tooltipFormat: 'dd.MM.yyyy' },
                ticks: {
                    source: 'auto', autoSkip: true, maxTicksLimit: 15,
                    callback: (value) => luxon.DateTime.fromMillis(value).toFormat('dd.MM.'),
                    maxRotation: 0, minRotation: 0
                }
            },
            y: { beginAtZero: false, min: 80 }
        }
    }
});

function withAlpha(color, alpha = 0.5) {
  if (typeof color !== 'string') return color;
  // Hex -> rgba
  if (color.startsWith('#')) {
    let hex = color.slice(1);
    if (hex.length === 8) hex = hex.slice(0, 6);       // #RRGGBBAA -> #RRGGBB
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join(''); // #RGB -> #RRGGBB
    const num = parseInt(hex, 16);
    const r = (num >> 16) & 255, g = (num >> 8) & 255, b = num & 255;
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }
  // rgb() -> rgba()
  if (color.startsWith('rgb(')) {
    const comps = color.slice(4, -1); // "r, g, b"
    return `rgba(${comps}, ${alpha})`;
  }
  // rgba() -> rgba() mit neuer Alpha
  if (color.startsWith('rgba(')) {
    const parts = color.slice(5, -1).split(',').map(s => s.trim()); // [r,g,b,a]
    return `rgba(${parts[0]}, ${parts[1]}, ${parts[2]}, ${alpha})`;
  }
  return color;
}

// Hilfsfunktion: erzeugt ein Nährwert-Chart mit Tageskreuzen + durchgehender KW-Linie
function makeMacroChart(canvasId, dailyData, weeklyData, color, label) {
  new Chart(document.getElementById(canvasId).getContext('2d'), {
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
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: {
          type: 'time',
          time: { unit: 'day', tooltipFormat: 'dd.MM.yyyy' },
          ticks: {
            source: 'auto', autoSkip: true, maxTicksLimit: 15,
            callback: (value) => luxon.DateTime.fromMillis(value).toFormat('dd.MM.'),
            maxRotation: 0, minRotation: 0
          }
        },
        y: {
          beginAtZero: true,
          title: { display: true, text: 'Gramm' }
        }
      }
    }
  });
}

// Aufrufe für die vier Nährwert-Diagramme (NICHT hidden)
makeMacroChart('eiweissChart', eiweissTage, eiweissKW, colorProtein, 'Eiweiß');
makeMacroChart('fettChart',    fettTage,    fettKW,    colorFat,     'Fett');
makeMacroChart('khChart',      khTage,      khKW,      colorCarb,    'Kohlenhydrate');
makeMacroChart('alkChart',     alkTage,     alkKW,     colorAlc,     'Alkohol');

function updateMonat(val){ document.getElementById('monatWert').textContent = val; }
</script>

</body>
</html>
