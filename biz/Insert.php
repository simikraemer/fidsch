<?php
// biz/Insert.php — CSV-Import (fixed)

// 1) Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// 2) DB
require_once __DIR__ . '/../db.php';
$bizconn->set_charset('utf8mb4');

// 3) POST-Verarbeitung (kein Output davor!)
$importMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $file = $_FILES['csv']['tmp_name'];

    if (!is_uploaded_file($file)) {
        $importMessage = 'Datei-Upload fehlgeschlagen.';
    } else {
        $handle = fopen($file, 'r');
        if (!$handle) {
            $importMessage = 'Konnte Datei nicht lesen.';
        } else {
            // Header überspringen (Sparkasse trennt mit ;)
            fgetcsv($handle, 0, ';');

            $inserted = 0;
            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                // Leerzeilen überspringen
                if ($row === null || $row === [] || (count($row) === 1 && trim((string)$row[0]) === '')) {
                    continue;
                }

                // Erwartet 11 Spalten
                if (count($row) < 11) {
                    // unvollständige Zeile überspringen
                    continue;
                }

                // Sparkasse-CSV ist oft Latin-1 → nach UTF-8 konvertieren
                $row = array_map(
                    fn($v) => mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1'),
                    $row
                );

                // Zeile überspringen, wenn "Umsatz vorgemerkt" im Info-Feld steht
                if (stripos($row[10] ?? '', 'Umsatz vorgemerkt') !== false) {
                    continue;
                }

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

                // Buchungstag robust mit zweistelligem Jahr parsen (dd.mm.yy)
                $dt = DateTime::createFromFormat('d.m.y', $buchungstag);
                $errors = DateTime::getLastErrors();
                if ($dt === false || $errors['warning_count'] > 0 || $errors['error_count'] > 0) {
                    // ungültiges Datum → Zeile überspringen
                    continue;
                }
                $buchungstag = $dt->format('Y-m-d');

                // Valutadatum (ggf. dd.mm.yyyy) → Y-m-d
                $valutadatum = date('Y-m-d', strtotime(str_replace('.', '-', $valutadatum)));

                // Betrag normalisieren (Komma → Punkt) und in Float umwandeln
                $betrag_float = (float)str_replace(',', '.', $betrag);

                // Kategorie automatisch zuweisen (Heuristik)
                $kategorie_id = null;
                $zp  = strtolower((string)$zahlungspartner);
                $vwz = strtolower((string)$verwendungszweck);

                if (str_contains($zp, 'spotify')) $kategorie_id = 21;
                elseif (str_contains($zp, 'nobis printen')) $kategorie_id = 8;
                elseif (str_contains($zp, 'baeckerei')) $kategorie_id = 8;
                elseif (str_contains($zp, 'sb tank')) $kategorie_id = 13;
                elseif (str_contains($zp, 'parken')) $kategorie_id = 13;
                elseif (str_contains($zp, 'bernhard') && str_contains($zp, 'inga')) $kategorie_id = 25;
                elseif (str_contains($zp, 'landeshauptkasse nrw')) $kategorie_id = 1;
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

                // Dubletten-Prüfung
                $stmt = $bizconn->prepare("
                    SELECT id FROM transfers
                    WHERE buchungstag = ? AND betrag = ? AND verwendungszweck = ? AND zahlungspartner = ?
                ");
                $stmt->bind_param('sdss', $buchungstag, $betrag_float, $verwendungszweck, $zahlungspartner);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows === 0) {
                    $stmt->close();

                    $insert = $bizconn->prepare("
                        INSERT INTO transfers (
                            kategorie_id, auftragskonto, buchungstag, valutadatum, buchungstext,
                            verwendungszweck, zahlungspartner, iban, bic, betrag, waehrung, info
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                        )
                    ");

                    // WICHTIG: Typen-String muss exakt 12 Platzhaltern entsprechen:
                    // i (kategorie_id), 8×s, d, s, s  → 'issssssssdss'
                    $insert->bind_param(
                        'issssssssdss',
                        $kategorie_id,      // i
                        $auftragskonto,     // s
                        $buchungstag,       // s
                        $valutadatum,       // s
                        $buchungstext,      // s
                        $verwendungszweck,  // s
                        $zahlungspartner,   // s
                        $iban,              // s
                        $bic,               // s
                        $betrag_float,      // d
                        $waehrung,          // s
                        $info               // s
                    );

                    $insert->execute();
                    $insert->close();
                    $inserted++;
                } else {
                    $stmt->close();
                }
            }

            fclose($handle);
            $importMessage = "$inserted neue Einträge importiert.";
        }
    }
}

// 4) Rendering starten
$page_title = 'Transfers importieren';
require_once __DIR__ . '/../head.php';    // <!DOCTYPE html> … <body>
require_once __DIR__ . '/../navbar.php';  // Navbar
?>
<main class="container">
    <h1 class="ueberschrift">Transfers importieren</h1>

    <?php if ($importMessage !== ''): ?>
        <p style="text-align: center; font-weight: bold; color: <?= ($importMessage === 'Datei-Upload fehlgeschlagen.' || $importMessage === 'Konnte Datei nicht lesen.') ? 'var(--error)' : 'var(--success)' ?>;">
            <?= htmlspecialchars($importMessage, ENT_QUOTES) ?>
        </p>
    <?php endif; ?>

    <form class="form-block" method="post" enctype="multipart/form-data">
        <label for="csv-upload"><strong>CSV-Datei hochladen</strong></label>
        <input type="file" id="csv-upload" name="csv" accept=".csv" required>
        <p style="font-size: 0.9rem; color: #666;">
            Hinweis: Erwartet wird die Sparkasse-CSV (Excel-kompatibel, gefilterte Einträge).
        </p>
        <button type="submit">Hochladen</button>
    </form>
</main>

</body>
</html>
