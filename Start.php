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
    <script src="https://cdn.jsdelivr.net/npm/luxon@3.4.3/build/global/luxon.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.3.1"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.2.1"></script>
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
                borderColor: '#333',
                backgroundColor: 'rgba(51, 51, 51, 0.15)',
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
        plugins: {
            annotation: {
                annotations: {                    
                    ziel: {
                        type: 'line',
                        yMin: 300,
                        yMax: 300,
                        borderColor: 'green',
                        borderWidth: 2,
                        borderDash: [6, 4],
                    },
                    gruenZone: {
                        type: 'box',
                        yMax: 300,
                        backgroundColor: 'rgba(0, 200, 0, 0.1)',
                        borderWidth: 0
                    },
                    gelbZone: {
                        type: 'box',
                        yMin: 300,
                        yMax: 1000,
                        backgroundColor: 'rgba(255, 215, 0, 0.15)',
                        borderWidth: 0
                    },
                    rotZone: {
                        type: 'box',
                        yMin: 1000,
                        backgroundColor: 'rgba(255, 0, 0, 0.1)',
                        borderWidth: 0
                    }
                }
            }
        },
        scales: {
            x: {
                type: 'time',
                time: {
                    unit: 'day',
                    tooltipFormat: 'dd.MM.yyyy',
                },
                ticks: {
                    callback: function(value, index, ticks) {
                        const date = luxon.DateTime.fromMillis(value);
                        return (date.day === 1 || date.day === 15) ? date.toFormat('dd.MM.') : '';
                    },
                    autoSkip: false,
                    maxRotation: 0,
                    minRotation: 0,
                }
            },
            y: {
                beginAtZero: true
            }
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
                backgroundColor: 'rgba(51, 51, 51, 0.15)',
                fill: false,
                tension: 0.3,
                borderWidth: 2,
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
                    ziel: {
                        type: 'line',
                        yMin: 90,
                        yMax: 90,
                        borderColor: 'green',
                        borderWidth: 2,
                        borderDash: [6, 4],
                    },
                    zielunten: {
                        type: 'line',
                        yMin: 85,
                        yMax: 85,
                        borderColor: 'green',
                        borderWidth: 2,
                        borderDash: [6, 4]
                    },
                    zielbereich: {
                        type: 'box',
                        yMin: 85,
                        yMax: 90,
                        backgroundColor: 'rgba(0, 200, 0, 0.15)',
                        borderWidth: 0
                    },
                    ubergangszone: {
                        type: 'box',
                        yMin: 90,
                        yMax: 100,
                        backgroundColor: 'rgba(255, 215, 0, 0.15)', // Gelb
                        borderWidth: 0
                    },
                    unakzeptabel: {
                        type: 'box',
                        yMin: 100,
                        backgroundColor: 'rgba(255, 0, 0, 0.15)', // Rot
                        borderWidth: 0
                    },
                    ubergangszone_unten: {
                        type: 'box',
                        yMax: 85,
                        backgroundColor: 'rgba(255, 215, 0, 0.15)', // Gelb
                        borderWidth: 0
                    },
                }
            }
        },
        scales: {
            x: {
                type: 'time',
                time: {
                    unit: 'day',
                    tooltipFormat: 'dd.MM.yyyy',
                },
                ticks: {
                    callback: function(value) {
                        const date = luxon.DateTime.fromMillis(value);
                        return (date.day === 1 || date.day === 15) ? date.toFormat('dd.MM.') : '';
                    },
                    autoSkip: false,
                    maxRotation: 0,
                    minRotation: 0,
                }
            },
            y: {
                beginAtZero: false,
                min: 80
            }
        }
    }


});

</script>

</body>
</html>
