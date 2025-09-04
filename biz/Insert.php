<?php
require_once 'auth.php';
require_once 'template.php'; // enthält auch db.php → $bizconn verfügbar

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $file = $_FILES['csv']['tmp_name'];

    if (!is_uploaded_file($file)) {
        die('Datei-Upload fehlgeschlagen.');
    }

    $handle = fopen($file, 'r');
    if (!$handle) {
        die('Konnte Datei nicht lesen.');
    }

    // Header überspringen
    fgetcsv($handle, 0, ';');

    $inserted = 0;
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $row = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1'), $row);
        [
            $auftragskonto,
            $buchungstag,
            $valutadatum,
            $buchungstext,
            $verwendungszweck,
            $zahlungspartner,
            $iban,
            $bic,
            $betrag,
            $waehrung,
            $info
        ] = $row;

        // Datum von DD.MM.YYYY → YYYY-MM-DD
        $buchungstag = date('Y-m-d', strtotime(str_replace('.', '-', $buchungstag)));
        $valutadatum = date('Y-m-d', strtotime(str_replace('.', '-', $valutadatum)));

        // Betrag mit Komma → Punkt
        $betrag = str_replace(',', '.', $betrag);

        // Doppelte prüfen
        $stmt = $bizconn->prepare("SELECT id FROM transfers WHERE buchungstag = ? AND betrag = ? AND verwendungszweck = ? AND zahlungspartner = ?");
        $stmt->bind_param('sdss', $buchungstag, $betrag, $verwendungszweck, $zahlungspartner);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 0) {
            $stmt->close();

            $insert = $bizconn->prepare("INSERT INTO transfers (
                kategorie_id, auftragskonto, buchungstag, valutadatum, buchungstext,
                verwendungszweck, zahlungspartner, iban, bic, betrag, waehrung, info
            ) VALUES (
                NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )");

            $insert->bind_param(
                'ssssssssdds',
                $auftragskonto,
                $buchungstag,
                $valutadatum,
                $buchungstext,
                $verwendungszweck,
                $zahlungspartner,
                $iban,
                $bic,
                $betrag,
                $waehrung,
                $info
            );

            $insert->execute();
            $inserted++;
            $insert->close();
        } else {
            $stmt->close();
        }
    }

    fclose($handle);
    echo "<p style='text-align: center; color: var(--success); font-weight: bold;'>$inserted neue Einträge importiert.</p>";
}
?>

<body>
    <main class="container">
        <h1 class="ueberschrift">Kontodaten importieren</h1>

        <form class="form-block" method="post" enctype="multipart/form-data">
            <label for="csv-upload"><strong>CSV-Datei hochladen</strong></label>
            <input type="file" id="csv-upload" name="csv" accept=".csv" required>
            <p style="font-size: 0.9rem; color: #666;">Hinweis: Erwartet wird die Sparkasse-CSV (Excel-kompatibel, gefilterte Einträge).</p>
            <button type="submit">Hochladen</button>
        </form>
    </main>
</body>
