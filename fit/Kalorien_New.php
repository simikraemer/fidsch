<?php
require_once 'auth.php';
require_once 'template.php';

$fitconn->set_charset('utf8mb4');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['move_to_previous_day'])) {
        // Timestamp eines bestehenden Eintrags auf Vortag 23:59 setzen
        $id = intval($_POST['move_to_previous_day']);
        $stmt = $fitconn->prepare("SELECT tstamp FROM kalorien WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($tstamp_alt);
        if ($stmt->fetch()) {
            $stmt->close();

            $dt = new DateTime($tstamp_alt);
            $dt->modify('-1 day')->setTime(23, 59);
            $newTstamp = $dt->format('Y-m-d H:i:s');

            $stmt = $fitconn->prepare("UPDATE kalorien SET tstamp = ? WHERE id = ?");
            $stmt->bind_param('si', $newTstamp, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt->close();
        }
    } else {
        $beschreibung = trim($_POST['beschreibung'] ?? '');
        $kalorien = intval($_POST['kalorien'] ?? 0);

        // Zeitlogik für normalen Insert
        $jetzt = new DateTime();
        if ((int)$jetzt->format('H') < 3) {
            $jetzt->modify('-1 day')->setTime(23, 59);
        }
        $tstamp = $jetzt->format('Y-m-d H:i:s');

        $stmt = $fitconn->prepare("INSERT INTO kalorien (beschreibung, kalorien, tstamp) VALUES (?, ?, ?)");
        $stmt->bind_param('sis', $beschreibung, $kalorien, $tstamp);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: /fit/kalorien");
    exit;
}

// Einträge aus der DB holen
$result = $fitconn->query("
    SELECT 
        MIN(id) AS id,
        beschreibung,
        kalorien,
        COUNT(*) AS anzahl
    FROM kalorien
    GROUP BY beschreibung, kalorien
    ORDER BY anzahl DESC
");
$eintraege = $result->fetch_all(MYSQLI_ASSOC);
$result->close();

// Heutige Einträge holen
$heute = date('Y-m-d');
$stmt = $fitconn->prepare("
    SELECT id, beschreibung, kalorien, tstamp
    FROM kalorien
    WHERE DATE(tstamp) = ?
    ORDER BY tstamp ASC
");
$stmt->bind_param('s', $heute);
$stmt->execute();
$heuteEintraege = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Gestern ermitteln und Einträge holen
$gestern = date('Y-m-d', strtotime('-1 day'));
$stmt = $fitconn->prepare("
    SELECT id, beschreibung, kalorien, tstamp
    FROM kalorien
    WHERE DATE(tstamp) = ?
    ORDER BY tstamp ASC
");
$stmt->bind_param('s', $gestern);
$stmt->execute();
$gesternEintraege = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>


<!DOCTYPE html>
<html lang="de">    
<head>
    <meta charset="UTF-8">
    <title>Kalorienzufuhr eintragen</title>
</head>
<body>

<div class="container">
    <h1 class="ueberschrift">Kalorienzufuhr eintragen</h1>

    <form method="post" class="form-block" action="/fit/kalorien">
        <label for="beschreibung">Beschreibung:</label>
        <input type="text" id="beschreibung" name="beschreibung" autocomplete="off">
        <ul id="vorschlaege" class="autocomplete-list"></ul>


        <label for="kalorien">Zugeführte Kalorien:</label>
        <input type="number" id="kalorien" name="kalorien" required>

        <button type="submit">Eintragen</button>
    </form>
</div>

<div class="container" style="display:flex; gap:20px; align-items:flex-start; max-width: 1200px;">

    <!-- Gestern -->
    <div style="flex:1;">
        <h2>📅 Gestern</h2>
        <table class="food-table">
            <thead>
                <tr>
                    <th>Zeitpunkt</th>
                    <th>Beschreibung</th>
                    <th>Kalorien</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $kalorienSummeGestern = 0;
                foreach ($gesternEintraege as $eintrag) {
                    $kalorienSummeGestern += intval($eintrag['kalorien']);
                }
                ?>
                <tr style="border-bottom: 3px solid black; font-weight: bold;">
                    <td></td>
                    <td style="white-space:nowrap;">SUMME</td>
                    <td style="white-space:nowrap;"><?= $kalorienSummeGestern ?> kcal</td>
                    <td></td>
                </tr>
                <?php foreach ($gesternEintraege as $eintrag): ?>
                    <tr>
                        <td><?= date('H:i', strtotime($eintrag['tstamp'])) ?></td>
                        <td><?= htmlspecialchars($eintrag['beschreibung']) ?></td>
                        <td><?= intval($eintrag['kalorien']) ?> kcal</td>
                        <td>
                            <form method="post" style="margin:0; display:inline;">
                                <input type="hidden" name="move_to_previous_day" value="<?= intval($eintrag['id']) ?>">
                                <button type="submit">Auf Vortag setzen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Heute -->
    <div style="flex:1;">
        <h2>📅 Heute</h2>
        <table class="food-table">
            <thead>
                <tr>
                    <th>Zeitpunkt</th>
                    <th>Beschreibung</th>
                    <th>Kalorien</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $kalorienSummeHeute = 0;
                foreach ($heuteEintraege as $eintrag) {
                    $kalorienSummeHeute += intval($eintrag['kalorien']);
                }
                ?>
                <tr style="border-bottom: 3px solid black; font-weight: bold;">
                    <td></td>
                    <td style="white-space:nowrap;">SUMME</td>
                    <td style="white-space:nowrap;"><?= $kalorienSummeHeute ?> kcal</td>
                    <td></td>
                </tr>
                <?php foreach ($heuteEintraege as $eintrag): ?>
                    <tr>
                        <td><?= date('H:i', strtotime($eintrag['tstamp'])) ?></td>
                        <td><?= htmlspecialchars($eintrag['beschreibung']) ?></td>
                        <td><?= intval($eintrag['kalorien']) ?> kcal</td>
                        <td>
                            <form method="post" style="margin:0; display:inline;">
                                <input type="hidden" name="move_to_previous_day" value="<?= intval($eintrag['id']) ?>">
                                <button type="submit">Auf Vortag setzen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>


</div>




<script>
    const daten = <?= json_encode(is_array($eintraege) ? $eintraege : []) ?>;
    const beschreibungsInput = document.getElementById('beschreibung');
    const kalorienInput = document.getElementById('kalorien');
    const vorschlaegeList = document.getElementById('vorschlaege');

    beschreibungsInput.addEventListener('input', () => {
        const eingabe = beschreibungsInput.value.toLowerCase();
        vorschlaegeList.innerHTML = '';

        if (eingabe.length === 0) return;

        const passende = daten.filter(e =>
            e.beschreibung.toLowerCase().includes(eingabe)
        );

        passende.forEach(e => {
            const li = document.createElement('li');
            li.textContent = e.beschreibung + ' (' + e.kalorien + ' kcal)';
            li.dataset.beschreibung = e.beschreibung;
            li.dataset.kalorien = e.kalorien;
            li.classList.add('autocomplete-item'); // eigene CSS-Klasse
            vorschlaegeList.appendChild(li);
        });
    });

    vorschlaegeList.addEventListener('click', (e) => {
        if (e.target.tagName === 'LI') {
            beschreibungsInput.value = e.target.dataset.beschreibung;
            kalorienInput.value = e.target.dataset.kalorien;
            vorschlaegeList.innerHTML = '';
        }
    });

    // Optional: Vorschläge verschwinden lassen bei Klick außerhalb
    document.addEventListener('click', (e) => {
        if (!vorschlaegeList.contains(e.target) && e.target !== beschreibungsInput) {
            vorschlaegeList.innerHTML = '';
        }
    });
</script>


</body>
</html>
