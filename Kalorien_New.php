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

        if ($beschreibung !== '' && $kalorien > 0) {
            $stmt = $mysqli->prepare("INSERT INTO kalorien (beschreibung, kalorien) VALUES (?, ?)");
            $stmt->bind_param('si', $beschreibung, $kalorien);
            $stmt->execute();
            $stmt->close();
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Letzte 10 Einträge aus der DB holen
$result = $mysqli->query("SELECT id, beschreibung, kalorien, tstamp FROM kalorien ORDER BY tstamp DESC LIMIT 10");
$eintraege = $result->fetch_all(MYSQLI_ASSOC);
$result->close();

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
        <input type="text" id="beschreibung" name="beschreibung" required>

        <label for="kalorien">Zugeführte Kalorien:</label>
        <input type="number" id="kalorien" name="kalorien" required>

        <button type="submit">Eintragen</button>
    </form>
</div>
<div class="container">
    <h2>🍽️ Letzte 10 Einträge</h2>
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
            <?php foreach ($eintraege as $eintrag): ?>
                <tr>
                    <td><?= htmlspecialchars($eintrag['tstamp']) ?></td>
                    <td><?= htmlspecialchars($eintrag['beschreibung']) ?></td>
                    <td><?= intval($eintrag['kalorien']) ?> kcal</td>
                    <td>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="repeat_id" value="<?= intval($eintrag['id']) ?>">
                            <button type="submit">Erneut gegessen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>


</body>
</html>
