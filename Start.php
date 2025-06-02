<?php
require_once 'template.php';
require_once 'header.php';

// Zeitfenster: letzte 3 Monate
$startDate = (new DateTime('-3 months'))->format('Y-m-d');

// Brutto-Kalorien (nur Zufuhr)
$stmt = $mysqli->prepare("
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
    $bruttoTage[$row['tag']] = intval($row['kalorien']);
}
$stmt->close();

// Netto-Kalorien (Zufuhr - Verbrauch)
$stmt = $mysqli->prepare("
    SELECT DATE(tstamp) AS tag,
           SUM(kalorien) AS gesamt
    FROM kalorien
    WHERE tstamp >= ?
    GROUP BY DATE(tstamp)
    UNION ALL
    SELECT DATE(tstamp) AS tag,
           -SUM(kalorien) AS gesamt
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
    $tag = $row['tag'];
    $kalorien = intval($row['gesamt']);
    if (!isset($nettoTage[$tag])) {
        $nettoTage[$tag] = 0;
    }
    $nettoTage[$tag] += $kalorien;
}
$stmt->close();

// Gewichtsdaten sammeln
$gewichtQuery = "
    SELECT DATE(tstamp) AS tag, AVG(gewicht) AS gewicht
    FROM gewicht
    WHERE tstamp >= ?
    GROUP BY DATE(tstamp)
";

$stmt = $mysqli->prepare($gewichtQuery);
$stmt->bind_param('s', $startDate);
$stmt->execute();
$result = $stmt->get_result();

$gewichtTage = [];
while ($row = $result->fetch_assoc()) {
    $gewichtTage[$row['tag']] = floatval($row['gewicht']);
}
$stmt->close();

// Alle Tage im Zeitraum generieren
$alleTage = [];
$heute = new DateTime();
$intervall = new DateInterval('P1D');
$periode = new DatePeriod(new DateTime($startDate), $intervall, $heute);

foreach ($periode as $datum) {
    $alleTage[] = $datum->format('Y-m-d');
}

// Chart-Daten erzeugen
$nettoWerte = [];
$bruttoWerte = [];
$gewichtWerte = [];
$letztesGewicht = null;

foreach ($alleTage as $tag) {
    $nettoWerte[] = $nettoTage[$tag] ?? 0;
    $bruttoWerte[] = $bruttoTage[$tag] ?? 0;

    if (array_key_exists($tag, $gewichtTage)) {
        $letztesGewicht = $gewichtTage[$tag];
        $gewichtWerte[] = $letztesGewicht;
    } else {
        $gewichtWerte[] = null;
    }
}

// JSON für Chart.js
$labels = json_encode($alleTage);
$nettoJson = json_encode($nettoWerte);
$bruttoJson = json_encode($bruttoWerte);
$gewichtJson = json_encode($gewichtWerte);

?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Übersicht</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<div class="chart-row">
    <div class="chart-half">
        <h2 class="ueberschrift">Kalorien</h2>
        <canvas id="kalorienChart"></canvas>
    </div>
    <div class="chart-half">
        <h2 class="ueberschrift">Gewicht</h2>
        <canvas id="gewichtChart"></canvas>
    </div>
</div>

<script>
const labels = <?= $labels ?>;

// Kalorien-Diagramm mit Brutto/Netto
new Chart(document.getElementById('kalorienChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?= $labels ?>,
        datasets: [
            {
                label: 'Netto-Kalorien',
                data: <?= $nettoJson ?>,
                fill: true,
                tension: 0.3,
                borderWidth: 2,
                borderColor: 'rgba(0, 123, 255, 1)',          // kräftiges Blau
                backgroundColor: 'rgba(0, 123, 255, 0.3)'     // transparentes Blau
            },
            {
                label: 'Brutto-Kalorien',
                data: <?= $bruttoJson ?>,
                fill: false,
                tension: 0.2,
                borderWidth: 2,
                borderDash: [5, 5],
                borderColor: '#888'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { beginAtZero: true }
        }
    }
});


// Gewicht-Diagramm
new Chart(document.getElementById('gewichtChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Gewicht (kg)',
            data: <?= $gewichtJson ?>,
            borderColor: 'orange',
            backgroundColor: 'rgba(255,165,0,0.2)',
            fill: false,
            tension: 0.3,
            borderWidth: 2,
            spanGaps: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: false
            }
        }
    }
});
</script>

</body>
</html>
