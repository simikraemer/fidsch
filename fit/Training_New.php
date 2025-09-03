<?php
require_once '../template.php';
require_once '../auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $beschreibung = 'Kardio'; // Feste Beschreibung
    $kalorien = intval($_POST['kalorien'] ?? 0);

    if ($kalorien > 0) {
        $stmt = $mysqli->prepare("INSERT INTO training (beschreibung, kalorien) VALUES (?, ?)");
        $stmt->bind_param('si', $beschreibung, $kalorien);
        $stmt->execute();
        $stmt->close();

        header("Location: /fit/training");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Neue Kardio-Übung</title>
</head>
<body>

<div class="container">
    <h1 class="ueberschrift">Kardiotraining eintragen</h1>

    <form method="post" class="form-block" action="/fit/training">
        <label for="kalorien">Verbrannte Kalorien:</label><br>
        <input type="number" id="kalorien" name="kalorien" required><br><br>

        <button type="submit">Eintragen</button>
    </form>
</div>

</body>
</html>
