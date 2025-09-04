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
        $betrag_float = (float)$betrag;

        // Kategorie automatisch zuweisen
        $kategorie_id = null;
        $zp = strtolower($zahlungspartner);
        $vwz = strtolower($verwendungszweck);

        if (str_contains($zp, 'spotify')) $kategorie_id = 21;
        elseif (str_contains($zp, 'nobis printen')) $kategorie_id = 8;
        elseif (str_contains($zp, 'baeckerei')) $kategorie_id = 8;
        elseif (str_contains($zp, 'sb tank')) $kategorie_id = 13;
        elseif (str_contains($zp, 'parken')) $kategorie_id = 13;
        elseif (str_contains($zp, 'bernhard') && str_contains($zp, 'inga')) $kategorie_id = 25;
        elseif (str_contains($zp, 'landeshauptkasse nordrhein-westfalen') && str_contains($zp, 'lbv')) $kategorie_id = 1;
        elseif (str_contains($zp, 'westdeutscher rundfunk') || str_contains($zp, 'wdr')) $kategorie_id = 4;
        elseif (str_contains($zp, 'studierendenwerk aachen')) $kategorie_id = $betrag_float > 0 ? 22 : 2;
        elseif (str_contains($zp, 'weh e.v.')) $kategorie_id = 22;
        elseif (str_contains($zp, 'lidl')) $kategorie_id = 7;
        elseif (str_contains($zp, 'kaufland')) $kategorie_id = 7;
        elseif (str_contains($zp, 'rewe')) $kategorie_id = 7;
        elseif (str_contains($zp, 'techniker krankenkasse')) $kategorie_id = 3;
        elseif (str_contains($vwz, 'entgeltabrechnung')) $kategorie_id = 6;
        elseif (str_contains($vwz, 'takeaway.com')) $kategorie_id = 9;
        elseif (str_contains($vwz, 'lieferando')) $kategorie_id = 9;
        elseif (str_contains($vwz, 'google')) $kategorie_id = 20;

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
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )");

            $insert->bind_param(
                'issssssssds',
                $kategorie_id,
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
