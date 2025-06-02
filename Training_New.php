<?php
require_once 'template.php';
require_once 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $beschreibung = trim($_POST['beschreibung'] ?? '');
    $kalorien = intval($_POST['kalorien'] ?? 0);

    if ($beschreibung !== '' && $kalorien > 0) {
        $stmt = $mysqli->prepare("INSERT INTO training (beschreibung, kalorien) VALUES (?, ?)");
        $stmt->bind_param('si', $beschreibung, $kalorien);
        $stmt->execute();
        $stmt->close();

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Neue Kardio-Übung</title>
    <link rel="stylesheet" href="FIT.css">
</head>
<body>

<div class="container">
    <h1 class="ueberschrift">Neue Kardio-Übung eintragen</h1>

    <form method="post" class="form-block">
        <label for="beschreibung">Beschreibung:</label><br>
        <input type="text" id="beschreibung" name="beschreibung" required><br><br>

        <label for="kalorien">Verbrannte Kalorien:</label><br>
        <input type="number" id="kalorien" name="kalorien" required><br><br>

        <button type="submit">Eintragen</button>
    </form>
</div>

</body>
</html>
