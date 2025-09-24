<?php
// fit/Kalorien_New.php

// Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// DB (für POST & Queries)
require_once __DIR__ . '/../db.php';
$fitconn->set_charset('utf8mb4');

// ---------------------- POST-VERARBEITUNG (kein Output davor!) ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['move_to_previous_day'])) {
        // Timestamp eines bestehenden Eintrags auf Vortag 23:59 setzen
        $id = (int)$_POST['move_to_previous_day'];

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

        header('Location: /fit/kalorien', true, 303);
        exit;
    }

    if (isset($_POST['delete_entry'])) {
        $id = (int)$_POST['delete_entry'];

        $stmt = $fitconn->prepare("DELETE FROM kalorien WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        header('Location: /fit/kalorien', true, 303);
        exit;
    }

    // Neuer Eintrag
    $beschreibung = trim($_POST['beschreibung'] ?? '');
    $kalorien     = (int)($_POST['kalorien'] ?? 0);
    $anzahl       = max(1, (int)($_POST['anzahl'] ?? 1)); // Standard = 1

    // Zeitlogik: bis 03:00 Uhr der Vortag (23:59)
    $jetzt = new DateTime();
    if ((int)$jetzt->format('H') < 3) {
        $jetzt->modify('-1 day')->setTime(23, 59);
    }
    $tstamp = $jetzt->format('Y-m-d H:i:s');

    if ($beschreibung !== '' && $kalorien > 0) {
        $stmt = $fitconn->prepare("INSERT INTO kalorien (beschreibung, kalorien, tstamp) VALUES (?, ?, ?)");
        $stmt->bind_param('sis', $beschreibung, $kalorien, $tstamp);
        for ($i = 0; $i < $anzahl; $i++) {
            $stmt->execute();
        }
        $stmt->close();
    }

    header('Location: /fit/kalorien', true, 303);
    exit;
}

// ---------------------- DATEN FÜR GET-RENDERING ----------------------

// Aggregierte Vorschläge (Beschreibung/Kalorien, mit Anzahl)
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
$eintraege = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
if ($result) $result->close();

// Heutige Einträge
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

// Gestern
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

// ---------------------- RENDERING START ----------------------
$page_title = 'Kalorien eintragen';
require_once __DIR__ . '/../head.php';     // <!DOCTYPE html> … <body>
require_once __DIR__ . '/../navbar.php';   // Navbar
?>
<div class="container">
    <h1 class="ueberschrift">Kalorienzufuhr eintragen</h1>

    <form method="post" class="form-block" action="/fit/kalorien">
        <label for="beschreibung">Beschreibung:</label>
        <input type="text" id="beschreibung" name="beschreibung" autocomplete="off">

        <ul id="vorschlaege" class="autocomplete-list"></ul>

        <label for="kalorien">Zugeführte Kalorien:</label>
        <input type="number" id="kalorien" name="kalorien" required>

        <label for="anzahl">Anzahl:</label>
        <input type="number" id="anzahl" name="anzahl" value="1" min="1" required>

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
                $kalorienSummeGestern += (int)$eintrag['kalorien'];
            }
            ?>
            <tr style="border-bottom: 3px solid black; font-weight: bold;">
                <td></td>
                <td style="white-space:nowrap;">SUMME</td>
                <td style="white-space:nowrap;"><?= (int)$kalorienSummeGestern ?> kcal</td>
                <td></td>
            </tr>
            <?php foreach ($gesternEintraege as $eintrag): ?>
                <tr>
                    <td><?= htmlspecialchars(date('H:i', strtotime($eintrag['tstamp'])), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($eintrag['beschreibung'] ?? '', ENT_QUOTES) ?></td>
                    <td><?= (int)$eintrag['kalorien'] ?> kcal</td>
                    <td>
                        <div style="display: flex; gap: 6px; align-items: stretch;">
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="move_to_previous_day" value="<?= (int)$eintrag['id'] ?>">
                                <button type="submit" style="height: 100%;">Auf Vortag</button>
                            </form>

                            <form method="post" style="margin:0;">
                                <input type="hidden" name="delete_entry" value="<?= (int)$eintrag['id'] ?>">
                                <button type="submit" onclick="return confirm('Eintrag wirklich löschen?');" style="height: 100%;">Löschen</button>
                            </form>
                        </div>
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
                $kalorienSummeHeute += (int)$eintrag['kalorien'];
            }
            ?>
            <tr style="border-bottom: 3px solid black; font-weight: bold;">
                <td></td>
                <td style="white-space:nowrap;">SUMME</td>
                <td style="white-space:nowrap;"><?= (int)$kalorienSummeHeute ?> kcal</td>
                <td></td>
            </tr>
            <?php foreach ($heuteEintraege as $eintrag): ?>
                <tr>
                    <td><?= htmlspecialchars(date('H:i', strtotime($eintrag['tstamp'])), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($eintrag['beschreibung'] ?? '', ENT_QUOTES) ?></td>
                    <td><?= (int)$eintrag['kalorien'] ?> kcal</td>
                    <td>
                        <div style="display: flex; gap: 6px; align-items: stretch;">
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="move_to_previous_day" value="<?= (int)$eintrag['id'] ?>">
                                <button type="submit" style="height: 100%;">Auf Vortag</button>
                            </form>

                            <form method="post" style="margin:0;">
                                <input type="hidden" name="delete_entry" value="<?= (int)$eintrag['id'] ?>">
                                <button type="submit" onclick="return confirm('Eintrag wirklich löschen?');" style="height: 100%;">Löschen</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
    const daten = <?= json_encode(is_array($eintraege) ? $eintraege : [], JSON_UNESCAPED_UNICODE) ?>;
    const beschreibungsInput = document.getElementById('beschreibung');
    const kalorienInput = document.getElementById('kalorien');
    const vorschlaegeList = document.getElementById('vorschlaege');

    beschreibungsInput.addEventListener('input', () => {
        const eingabe = (beschreibungsInput.value || '').toLowerCase();
        vorschlaegeList.innerHTML = '';
        if (!eingabe.length) return;

        const passende = daten.filter(e =>
            (e.beschreibung || '').toLowerCase().includes(eingabe)
        );

        passende.forEach(e => {
            const li = document.createElement('li');
            li.textContent = e.beschreibung + ' (' + e.kalorien + ' kcal)';
            li.dataset.beschreibung = e.beschreibung;
            li.dataset.kalorien = e.kalorien;
            li.classList.add('autocomplete-item');
            vorschlaegeList.appendChild(li);
        });
    });

    vorschlaegeList.addEventListener('click', (e) => {
        if (e.target && e.target.tagName === 'LI') {
            beschreibungsInput.value = e.target.dataset.beschreibung || '';
            kalorienInput.value = e.target.dataset.kalorien || '';
            vorschlaegeList.innerHTML = '';
        }
    });

    document.addEventListener('click', (e) => {
        if (!vorschlaegeList.contains(e.target) && e.target !== beschreibungsInput) {
            vorschlaegeList.innerHTML = '';
        }
    });
</script>

</body>
</html>
