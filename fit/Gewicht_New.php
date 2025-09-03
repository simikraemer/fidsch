<?php
require_once '../template.php';
require_once '../auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' ) {
    $gewicht = floatval($_POST['gewicht'] ?? 0);

    if ($gewicht > 0) {
        $stmt = $mysqli->prepare("INSERT INTO gewicht (gewicht) VALUES (?)");
        $stmt->bind_param('d', $gewicht);
        $stmt->execute();
        $stmt->close();

        header("Location: /fit/gewicht");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Gewicht eintragen</title>
</head>
<body>

<div class="container">
    <h1 class="ueberschrift">Gewicht eintragen</h1>

    <form method="post" class="form-block" action="/fit/gewicht">
        <label for="gewicht">Gewicht (kg):</label>
        <input type="number" id="gewicht" name="gewicht" step="0.1" required>

        <button type="submit">Eintragen</button>
    </form>
</div>

</body>
</html>
