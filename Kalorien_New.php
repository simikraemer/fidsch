<?php
require_once 'template.php';
require_once 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $beschreibung = trim($_POST['beschreibung'] ?? '');
    $kalorien = intval($_POST['kalorien'] ?? 0);

    if ($beschreibung !== '' && $kalorien > 0) {
        $stmt = $mysqli->prepare("INSERT INTO kalorien (beschreibung, kalorien) VALUES (?, ?)");
        $stmt->bind_param('si', $beschreibung, $kalorien);
        $stmt->execute();
        $stmt->close();

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$rezepte = [
    ['3 Eier, 100g Spinat, 3 Tomaten', 281],
    ['Nissin Ramen Spicy mit 100g Kimchi', 495],
    ['130g Thunfisch mit 100g Reis', 500],
    ['400g Hähnchenbrust', 450],
    ['450g Skyr + 1 Banane + 30g Haferflocken', 483],
    ['K-Classic Bami Goreng', 697],
];
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Kalorienzufuhr eintragen</title>
    <link rel="stylesheet" href="FIT.css">
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
    <h2>📋 Beispielgerichte</h2>
    <ul>
        <?php foreach ($rezepte as [$name, $kcal]): ?>
            <li><?= htmlspecialchars($name) ?> - <strong><?= $kcal ?> kcal</strong></li>
        <?php endforeach; ?>
    </ul>
</div>

</body>
</html>
