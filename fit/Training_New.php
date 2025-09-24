<?php
require_once 'auth.php';
require_once 'db.php';

// POST-Verarbeitung (kein Output davor!)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $beschreibung = 'Kardio';
    $kalorien = (int)($_POST['kalorien'] ?? 0);

    if ($kalorien > 0) {
        $stmt = $fitconn->prepare("INSERT INTO training (beschreibung, kalorien) VALUES (?, ?)");
        $stmt->bind_param('si', $beschreibung, $kalorien);
        $stmt->execute();
        $stmt->close();

        header('Location: /fit/training', true, 303);
        exit;
    }
}

// Rendering starten
$page_title = 'Training eintragen';
require_once __DIR__ . '/../head.php';   // öffnet <!DOCTYPE html> … <body>
require_once __DIR__ . '/../navbar.php'; // nur die Navbar
?>

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
