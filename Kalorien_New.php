<?php
require_once 'template.php';
require_once 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['repeat_id'])) {
        // Wiederholung eines bestehenden Eintrags
        $stmt = $mysqli->prepare("SELECT beschreibung, kalorien FROM kalorien WHERE id = ?");
        $stmt->bind_param('i', $_POST['repeat_id']);
        $stmt->execute();
        $stmt->bind_result($beschreibung, $kalorien);
        if ($stmt->fetch()) {
            $stmt->close();

            $stmt = $mysqli->prepare("INSERT INTO kalorien (beschreibung, kalorien) VALUES (?, ?)");
            $stmt->bind_param('si', $beschreibung, $kalorien);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $beschreibung = trim($_POST['beschreibung'] ?? '');
        $kalorien = intval($_POST['kalorien'] ?? 0);

        $stmt = $mysqli->prepare("INSERT INTO kalorien (beschreibung, kalorien) VALUES (?, ?)");
        $stmt->bind_param('si', $beschreibung, $kalorien);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Einträge aus der DB holen
$result = $mysqli->query("
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
$stmt = $mysqli->prepare("
    SELECT id, beschreibung, kalorien, tstamp
    FROM kalorien
    WHERE DATE(tstamp) = ?
    ORDER BY tstamp ASC
");
$stmt->bind_param('s', $heute);
$stmt->execute();
$heuteEintraege = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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

    <form method="post" class="form-block">
        <label for="beschreibung">Beschreibung:</label>
        <input type="text" id="beschreibung" name="beschreibung" autocomplete="off">
        <ul id="vorschlaege" class="autocomplete-list"></ul>


        <label for="kalorien">Zugeführte Kalorien:</label>
        <input type="number" id="kalorien" name="kalorien" required>

        <button type="submit">Eintragen</button>
    </form>
</div>

<div class="container">
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
            $kalorienSumme = 0;
            foreach ($heuteEintraege as $eintrag):
                $kalorienSumme += intval($eintrag['kalorien']);
            ?>
                <tr>
                    <td><?= date('H:i', strtotime($eintrag['tstamp'])) ?></td>
                    <td><?= htmlspecialchars($eintrag['beschreibung']) ?></td>
                    <td><?= intval($eintrag['kalorien']) ?> kcal</td>
                    <td>
                        <?php /*
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="repeat_id" value="<?= intval($eintrag['id']) ?>">
                            <button type="submit">Erneut gegessen</button>
                        </form>
                        */ ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr style="border-top: 3px solid black; font-weight: bold;">
                <td></td>
                <td>SUMME</td>
                <td><?= $kalorienSumme ?> kcal</td>
                <td></td>
            </tr>
        </tbody>
    </table>
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
