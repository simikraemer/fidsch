<?php
// fit/Gewicht_New.php

// Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// DB (für POST und ggf. späteres Rendering)
require_once __DIR__ . '/../db.php';

// POST-Verarbeitung (kein Output davor!)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gewicht = isset($_POST['gewicht']) ? (float)$_POST['gewicht'] : 0.0;

    if ($gewicht > 0) {
        $stmt = $fitconn->prepare("INSERT INTO gewicht (gewicht) VALUES (?)");
        $stmt->bind_param('d', $gewicht);
        $stmt->execute();
        $stmt->close();

        header('Location: /fit/gewicht', true, 303);
        exit;
    }
}

// Rendering starten
$page_title = 'Gewicht eintragen';
require_once __DIR__ . '/../head.php';    // <!DOCTYPE html> … <body>
require_once __DIR__ . '/../navbar.php';  // nur die Navbar
?>
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
