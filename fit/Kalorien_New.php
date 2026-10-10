<?php
// fit/Kalorien_New.php

// Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// DB (für POST & Queries)
require_once __DIR__ . '/../db.php';
$fitconn->set_charset('utf8mb4');
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// ---------------------- POST-VERARBEITUNG (kein Output davor!) ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $currentDateParam = $_POST['current_date'] ?? null;
    $redirectUrl = '/fit/kalorien';
    if ($currentDateParam) {
        $redirectUrl .= '?date=' . urlencode($currentDateParam);
    }

    if (isset($_POST['stretch_entry'])) {
        if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf'] ?? ''))) {
            http_response_code(400);
            exit('Ungültige Anfrage.');
        }
        $id = (int)$_POST['stretch_entry'];
        $tage = $_POST['stretch_days'] ?? [];
        if (!is_array($tage) || count($tage) > 28) {
            http_response_code(400);
            exit('Ungültige Tagesauswahl.');
        }
        try {
            if (!$fitconn->begin_transaction()) throw new RuntimeException('Transaktion fehlgeschlagen.');
            $stmt = $fitconn->prepare('SELECT DATE(tstamp) AS ursprung FROM kalorien WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new RuntimeException('Speichern fehlgeschlagen.');
            $original = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$original) throw new InvalidArgumentException('Eintrag nicht mehr vorhanden.');
            $start = new DateTimeImmutable($original['ursprung']);
            $ende = $start->modify('+27 days')->format('Y-m-d');
            $auswahl = [$original['ursprung'] => true];
            foreach ($tage as $tag) {
                if (!is_string($tag)) throw new InvalidArgumentException('Ungültiges Datum.');
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $tag);
                if (!$d || $d->format('Y-m-d') !== $tag || $tag < $original['ursprung'] || $tag > $ende) {
                    throw new InvalidArgumentException('Datum außerhalb der vier Wochen.');
                }
                $auswahl[$tag] = true;
            }
            $stmt = $fitconn->prepare('DELETE FROM kalorien_strecken_tage WHERE kalorien_id = ?');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new RuntimeException('Speichern fehlgeschlagen.');
            $stmt->close();
            // Nur Ursprung ausgewählt: wieder ein normaler, ungestreckter Eintrag.
            if (count($auswahl) > 1) {
                $stmt = $fitconn->prepare('INSERT INTO kalorien_strecken_tage (kalorien_id, tag) VALUES (?, ?)');
                $stmt->bind_param('is', $id, $tag);
                foreach (array_keys($auswahl) as $tag) if (!$stmt->execute()) throw new RuntimeException('Speichern fehlgeschlagen.');
                $stmt->close();
            }
            if (!$fitconn->commit()) throw new RuntimeException('Speichern fehlgeschlagen.');
        } catch (Throwable $e) {
            $fitconn->rollback();
            http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
            exit($e instanceof InvalidArgumentException ? $e->getMessage() : 'Speichern fehlgeschlagen. Bitte erneut versuchen.');
        }
        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    if (isset($_POST['move_to_previous_day'])) {
        // Timestamp eines bestehenden Eintrags auf Vortag 23:59 setzen
        $id = (int)$_POST['move_to_previous_day'];

        $stmt = $fitconn->prepare("SELECT tstamp FROM kalorien WHERE id = ? AND NOT EXISTS (SELECT 1 FROM kalorien_strecken_tage s WHERE s.kalorien_id = kalorien.id)");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($tstamp_alt);

        if ($stmt->fetch()) {
            $stmt->close();

            $dt = new DateTime($tstamp_alt);
            $dt->modify('-1 day')->setTime(23, 59);
            $newTstamp = $dt->format('Y-m-d H:i:s');

            $stmt = $fitconn->prepare("UPDATE kalorien SET tstamp = ? WHERE id = ? AND NOT EXISTS (SELECT 1 FROM kalorien_strecken_tage s WHERE s.kalorien_id = kalorien.id)");
            $stmt->bind_param('si', $newTstamp, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt->close();
        }

        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    if (isset($_POST['move_to_next_day'])) {
        // Timestamp eines bestehenden Eintrags auf Folgetag 00:01 setzen
        $id = (int)$_POST['move_to_next_day'];

        $stmt = $fitconn->prepare("SELECT tstamp FROM kalorien WHERE id = ? AND NOT EXISTS (SELECT 1 FROM kalorien_strecken_tage s WHERE s.kalorien_id = kalorien.id)");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($tstamp_alt);

        if ($stmt->fetch()) {
            $stmt->close();

            $dt = new DateTime($tstamp_alt);
            $dt->modify('+1 day')->setTime(0, 1);
            $newTstamp = $dt->format('Y-m-d H:i:s');

            $stmt = $fitconn->prepare("UPDATE kalorien SET tstamp = ? WHERE id = ? AND NOT EXISTS (SELECT 1 FROM kalorien_strecken_tage s WHERE s.kalorien_id = kalorien.id)");
            $stmt->bind_param('si', $newTstamp, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt->close();
        }

        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    if (isset($_POST['delete_entry'])) {
        $id = (int)$_POST['delete_entry'];

        $stmt = $fitconn->prepare("DELETE FROM kalorien WHERE id = ? AND NOT EXISTS (SELECT 1 FROM kalorien_strecken_tage s WHERE s.kalorien_id = kalorien.id)");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    // Neuer Eintrag (+ Nährwerte)
    $beschreibung = trim($_POST['beschreibung'] ?? '');
    $kategorie    = trim($_POST['kategorie'] ?? '');
    $kalorien     = (int)($_POST['kalorien'] ?? 0);
    $eiweiss      = ($_POST['eiweiss'] ?? '') === '' ? 0.0 : (float)$_POST['eiweiss'];
    $fett         = ($_POST['fett'] ?? '') === '' ? 0.0 : (float)$_POST['fett'];
    $kh           = ($_POST['kohlenhydrate'] ?? '') === '' ? 0.0 : (float)$_POST['kohlenhydrate'];
    $alkohol      = 0.0; // kein Input-Feld mehr
    $anzahl       = max(1, (int)($_POST['anzahl'] ?? 1)); // Standard = 1

    // Kategorie ist Pflicht und muss bereits in der DB existieren.
    $kategorieGueltig = false;
    if ($kategorie !== '') {
        $stmtKategorie = $fitconn->prepare("
            SELECT 1
            FROM kalorien
            WHERE kategorie = ?
            LIMIT 1
        ");
        if ($stmtKategorie) {
            $stmtKategorie->bind_param('s', $kategorie);
            $stmtKategorie->execute();
            $stmtKategorie->store_result();
            $kategorieGueltig = $stmtKategorie->num_rows > 0;
            $stmtKategorie->close();
        }
    }

    if (!$kategorieGueltig) {
        http_response_code(400);
        exit('Ungültige oder fehlende Kategorie.');
    }

    $jetzt = new DateTime();
    #// Zeitlogik: bis 03:00 Uhr der Vortag (23:59)
    #if ((int)$jetzt->format('H') < 3) {
    #    $jetzt->modify('-1 day')->setTime(23, 59);
    #}
    $tstamp = $jetzt->format('Y-m-d H:i:s');

    if ($beschreibung !== '' && $kalorien > 0) {
        $stmt = $fitconn->prepare("
            INSERT INTO kalorien (beschreibung, kategorie, kalorien, `eiweiß`, fett, kohlenhydrate, alkohol, tstamp)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssidddds', $beschreibung, $kategorie, $kalorien, $eiweiss, $fett, $kh, $alkohol, $tstamp);
        for ($i = 0; $i < $anzahl; $i++) {
            $stmt->execute();
        }
        $stmt->close();
    }

    header('Location: /fit/kalorien', true, 303);
    exit;
}

// ---------------------- AJAX-ENDPOINT FÜR EINEN TAG ----------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'tag') {
    $datum = $_GET['date'] ?? date('Y-m-d');

    $d = DateTime::createFromFormat('Y-m-d', $datum);
    if (!$d || $d->format('Y-m-d') !== $datum) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Ungültiges Datum']);
        exit;
    }

    // Einträge an diesem Tag
    $stmt = $fitconn->prepare("
        SELECT id, beschreibung, kalorien, tstamp, ursprung_tag, strecken_anzahl
        FROM kalorien_tageswerte
        WHERE DATE(tstamp) = ?
        ORDER BY tstamp ASC
    ");
    $stmt->bind_param('s', $datum);
    $stmt->execute();
    $result = $stmt->get_result();
    $eintraegeTag = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Alle ausgewählten Tage für die Bearbeitung von jedem Tagesanteil aus.
    $ids = array_map('intval', array_column($eintraegeTag, 'id'));
    $verteilungen = [];
    if ($ids) {
        $res = $fitconn->query('SELECT kalorien_id, tag FROM kalorien_strecken_tage WHERE kalorien_id IN (' . implode(',', $ids) . ') ORDER BY tag');
        while ($row = $res->fetch_assoc()) $verteilungen[(int)$row['kalorien_id']][] = $row['tag'];
        $res->free();
    }
    foreach ($eintraegeTag as &$eintrag) {
        $eintrag['stretch_days'] = $verteilungen[(int)$eintrag['id']] ?? [$eintrag['ursprung_tag']];
    }
    unset($eintrag);

    // Brutto-Kalorien dieses Tages
    $bruttoSumme = 0;
    foreach ($eintraegeTag as $e) {
        $bruttoSumme += (float)($e['kalorien'] ?? 0);
    }

    // Trainingsverbrauch dieses Tages
    $trainingSumme = 0;
    $stmt = $fitconn->prepare("
        SELECT COALESCE(SUM(kalorien), 0) AS training_summe
        FROM training
        WHERE DATE(tstamp) = ?
    ");
    $stmt->bind_param('s', $datum);
    $stmt->execute();
    $stmt->bind_result($trainingSummeDb);
    if ($stmt->fetch()) {
        $trainingSumme = (int)$trainingSummeDb;
    }
    $stmt->close();

    // Neue Gym-Sessions: nur abgeschlossene Trainings, am Startdatum verbuchen.
    // Identische Berechnungsgrundlage wie in Start.php (gespeicherte Satz-kcal).
    $gymSumme = 0;
    $stmt = $fitconn->prepare("
        SELECT COALESCE(SUM(COALESCE(gss.calories, 0)), 0) AS gym_summe
        FROM gym_sessions gs
        JOIN gym_session_sets gss ON gss.session_id = gs.id
        WHERE gs.finished_at IS NOT NULL
          AND DATE(gs.started_at) = ?
    ");
    $stmt->bind_param('s', $datum);
    $stmt->execute();
    $stmt->bind_result($gymSummeDb);
    if ($stmt->fetch()) {
        $gymSumme = (int)$gymSummeDb;
    }
    $stmt->close();

    // Netto = Kalorienzufuhr - altes Training - neues Gym.
    $nettoSumme = $bruttoSumme - $trainingSumme - $gymSumme;

    // Vorheriger Tag mit Einträgen
    $prev = null;
    $stmt = $fitconn->prepare("
        SELECT DATE(tstamp) AS tag
        FROM kalorien_tageswerte
        WHERE DATE(tstamp) < ?
        ORDER BY tstamp DESC
        LIMIT 1
    ");
    $stmt->bind_param('s', $datum);
    $stmt->execute();
    $stmt->bind_result($prevDate);
    if ($stmt->fetch()) {
        $prev = $prevDate;
    }
    $stmt->close();

    // Nächster Tag mit Einträgen
    $next = null;
    $stmt = $fitconn->prepare("
        SELECT DATE(tstamp) AS tag
        FROM kalorien_tageswerte
        WHERE DATE(tstamp) > ?
        ORDER BY tstamp ASC
        LIMIT 1
    ");
    $stmt->bind_param('s', $datum);
    $stmt->execute();
    $stmt->bind_result($nextDate);
    if ($stmt->fetch()) {
        $next = $nextDate;
    }
    $stmt->close();

    $heute   = date('Y-m-d');
    $gestern = date('Y-m-d', strtotime('-1 day'));

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'date'            => $datum,
        'heute'           => $heute,
        'gestern'         => $gestern,
        'entries'         => $eintraegeTag,
        'brutto_summe'    => $bruttoSumme,
        'training_summe'  => $trainingSumme,
        'gym_summe'       => $gymSumme,
        'netto_summe'     => $nettoSumme,
        'prev'            => $prev,
        'next'            => $next,
    ]);
    exit;
}

// ---------------------- DATEN FÜR GET-RENDERING ----------------------

// Aggregierte Vorschläge (Beschreibung/Kalorien + Nährwerte, mit Anzahl)
$result = $fitconn->query("
    SELECT 
        MIN(id) AS id,
        beschreibung,
        kategorie,
        kalorien,
        COUNT(*) AS anzahl,
        MAX(`eiweiß`)        AS eiweiss,
        MAX(`fett`)          AS fett,
        MAX(`kohlenhydrate`) AS kh
    FROM kalorien
    WHERE kategorie IS NOT NULL
      AND TRIM(kategorie) <> ''
    GROUP BY beschreibung, kategorie, kalorien
    ORDER BY anzahl DESC
");
$eintraege = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
if ($result) $result->close();

// Kategorien für das Pflicht-Dropdown direkt aus der DB laden.
$kategorien = [];
$resultKategorien = $fitconn->query("
    SELECT DISTINCT TRIM(kategorie) AS kategorie
    FROM kalorien
    WHERE kategorie IS NOT NULL
      AND TRIM(kategorie) <> ''
    ORDER BY kategorie ASC
");
if ($resultKategorien) {
    while ($row = $resultKategorien->fetch_assoc()) {
        $kategorien[] = $row['kategorie'];
    }
    $resultKategorien->close();
}

$heute   = date('Y-m-d');
$gestern = date('Y-m-d', strtotime('-1 day'));
$selected = $_GET['date'] ?? $heute;

$selectedDate = DateTime::createFromFormat('Y-m-d', $selected);
if (!$selectedDate || $selectedDate->format('Y-m-d') !== $selected) {
    $selected = $heute;
}

// ---------------------- RENDERING START ----------------------
$page_title = 'Kalorien eintragen';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>
<style id="kalorien-action-sizing">
/* Vier Aktionen: genug Platz für Beschriftung und Innenabstand. */
#kalorienPage .kalorien-day-entry .kalorien-day-actions.has-four-actions {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(max-content, 1fr)) !important;
    min-width: 340px !important;
    gap: 6px !important;
}
#kalorienPage .kalorien-day-entry .kalorien-day-actions.has-four-actions > form {
    display: flex;
    margin: 0;
    min-width: max-content !important;
}
#kalorienPage .kalorien-day-entry .kalorien-day-actions.has-four-actions button {
    display: flex !important;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    width: 100%;
    min-width: max-content !important;
    font-size: 11px !important;
    line-height: 1.2 !important;
    padding: 9px 10px !important;
    white-space: nowrap !important;
    text-align: center;
}
@media (max-width: 768px) {
    #kalorienPage .kalorien-day-entry .kalorien-day-actions.has-four-actions {
        min-width: 0 !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 5px !important;
    }
    #kalorienPage .kalorien-day-entry .kalorien-day-actions.has-four-actions > form,
    #kalorienPage .kalorien-day-entry .kalorien-day-actions.has-four-actions button {
        min-width: 0 !important;
    }
    #kalorienPage .kalorien-day-entry .kalorien-day-actions.has-four-actions button {
        font-size: 10px !important;
        padding: 4px 5px !important;
        white-space: normal !important;
    }
}
</style>
<div id="kalorienPage" class="container-duo kalorien-page">
    <div class="container kalorien-entry-card">
        <h1 class="ueberschrift kalorien-entry-title">Kalorienzufuhr eintragen</h1>

        <form method="post" class="form-block kalorien-entry-form" action="/fit/kalorien">
            <div class="input-group food-autocomplete-field">
                <label for="beschreibung">Beschreibung:</label>
                <input type="text" id="beschreibung" name="beschreibung" autocomplete="off">
                <ul id="vorschlaege" class="autocomplete-list"></ul>
            </div>

            <div class="input-group food-category-field">
                <label for="kategorie">Kategorie:</label>
                <select id="kategorie" name="kategorie" class="kategorie-select" required>
                    <option value="" selected disabled>Kategorie wählen …</option>
                    <?php foreach ($kategorien as $kategorieOption): ?>
                        <option value="<?= htmlspecialchars($kategorieOption, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($kategorieOption, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-row">
                <div class="input-group">
                    <label for="anzahl">Anzahl:</label>
                    <input type="number" id="anzahl" name="anzahl" value="1" min="1" required>
                </div>
                <div class="input-group">
                    <label>&nbsp;</label>
                    <button type="button" id="btn-100g-modal" style="width: 100%;">Aus /100g berechnen</button>
                </div>
            </div>

            <div class="form-separator"></div>

            <div class="input-row">
                <div class="input-group">
                    <label for="kalorien">Kalorien (kcal):</label>
                    <input type="number" id="kalorien" name="kalorien" required>
                    <div id="kcal-pruefsumme" style="margin-top:6px; font-size:0.9em; opacity:0.8;">
                        Prüfsumme: <span id="kcal-check">0</span> kcal
                    </div>
                </div>
                <div class="input-group">
                    <label for="fett">Fett (g):</label>
                    <input type="number" id="fett" name="fett" step="0.01" min="0">
                </div>
            </div>

            <div class="input-row">
                <div class="input-group">
                    <label for="kohlenhydrate">Kohlenhydrate (g):</label>
                    <input type="number" id="kohlenhydrate" name="kohlenhydrate" step="0.01" min="0">
                </div>
                <div class="input-group">
                    <label for="eiweiss">Eiweiß (g):</label>
                    <input type="number" id="eiweiss" name="eiweiss" step="0.01" min="0">
                </div>
            </div>

            <button type="submit">Eintragen</button>
        </form>
    </div>

    <div class="container kalorien-day-card">
        <div class="kalorien-day-nav" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <div style="display:flex; justify-content:center; align-items:center; gap:12px;">
                <button type="button" id="tag-zurueck" style="padding:4px 8px;">&laquo;</button>
                <input type="date" id="tag-date" style="padding:4px 6px;">
                <!-- <h2 id="tage-ueberschrift" style="margin:0;"><?= htmlspecialchars(date('d.m.Y'), ENT_QUOTES) ?></h2> -->
                <button type="button" id="tag-vor" style="padding:4px 8px;">&raquo;</button>
            </div>
            <button type="button" id="tag-heute" style="padding:4px 8px;">Heute</button>
        </div>

        <table class="food-table">
            <thead>
            <tr>
                <th>Zeitpunkt</th>
                <th>Beschreibung</th>
                <th style="white-space:nowrap;">Brutto-Kalorien</th>
                <th style="text-align:center;">Netto-Kalorien</th>
            </tr>
            </thead>
            <tbody id="tage-tbody">
                <!-- wird per JavaScript befüllt -->
            </tbody>
        </table>
    </div>    
</div>

<div id="stretch-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="stretch-title">
    <div class="modal-content kalorien-stretch-content">
        <button type="button" id="stretch-close" class="close-button" aria-label="Schließen">&times;</button>
        <h2 id="stretch-title">Strecken</h2>
        <form method="post" action="/fit/kalorien" id="stretch-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="stretch_entry" id="stretch-entry-id">
            <input type="hidden" name="current_date" id="stretch-current-date">
            <div id="stretch-weeks"></div>
            <button type="button" id="stretch-add-week">Weitere Woche</button>
            <div class="modal-actions">
                <button type="button" id="stretch-cancel" class="btn-secondary">Abbrechen</button>
                <button type="submit">Speichern</button>
            </div>
        </form>
    </div>
</div>

<div id="nutrition-modal" class="modal hidden">
    <div class="modal-content">
        <span id="modal-close-x" class="close-button">&times;</span>

        <h2 style="margin-top:0;">Werte aus /100g berechnen</h2>

        <div class="input-row">
            <div class="input-group">
                <label for="modal-gesamtgramm">Gesamteinheit (g):</label>
                <input type="number" id="modal-gesamtgramm" step="0.01" min="0" placeholder="z. B. 190">
            </div>
            <div class="input-group">
                <label for="modal-kalorien-100">Kalorien /100g:</label>
                <input type="number" id="modal-kalorien-100" step="0.01" min="0" placeholder="z. B. 250">
            </div>
        </div>

        <div class="input-row">
            <div class="input-group">
                <label for="modal-fett-100">Fett /100g:</label>
                <input type="number" id="modal-fett-100" step="0.01" min="0">
            </div>
            <div class="input-group">
                <label for="modal-kh-100">Kohlenhydrate /100g:</label>
                <input type="number" id="modal-kh-100" step="0.01" min="0">
            </div>
        </div>

        <div class="input-row">
            <div class="input-group">
                <label for="modal-eiweiss-100">Eiweiß /100g:</label>
                <input type="number" id="modal-eiweiss-100" step="0.01" min="0">
            </div>
            <div class="input-group">
                <label>&nbsp;</label>
                <div style="padding: 8px 0; opacity: 0.8;">Werte werden auf die gesamte Einheit hochgerechnet.</div>
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" id="modal-cancel" class="btn-secondary">Abbrechen</button>
            <button type="button" id="modal-apply">Okay</button>
        </div>
    </div>
</div>

<script>
    const daten = <?= json_encode(is_array($eintraege) ? $eintraege : [], JSON_UNESCAPED_UNICODE) ?>;

    const beschreibungsInput = document.getElementById('beschreibung');
    const kategorieInput    = document.getElementById('kategorie');
    const kalorienInput      = document.getElementById('kalorien');
    const anzahlInput        = document.getElementById('anzahl');
    const eiweissInput       = document.getElementById('eiweiss');
    const fettInput          = document.getElementById('fett');
    const khInput            = document.getElementById('kohlenhydrate');
    const vorschlaegeList    = document.getElementById('vorschlaege');

    const nutritionModal   = document.getElementById('nutrition-modal');
    const btn100gModal     = document.getElementById('btn-100g-modal');
    const modalCloseX      = document.getElementById('modal-close-x');
    const modalCancel      = document.getElementById('modal-cancel');
    const modalApply       = document.getElementById('modal-apply');
    const modalGesamtGramm = document.getElementById('modal-gesamtgramm');
    const modalKalorien100 = document.getElementById('modal-kalorien-100');
    const modalFett100     = document.getElementById('modal-fett-100');
    const modalKh100       = document.getElementById('modal-kh-100');
    const modalEiweiss100  = document.getElementById('modal-eiweiss-100');

    const PHONE_UI = !!(
        window.matchMedia
        && window.matchMedia('(max-width: 650px)').matches
    );

    if (beschreibungsInput && !PHONE_UI) {
        beschreibungsInput.focus({ preventScroll: true });
        if (typeof beschreibungsInput.select === 'function') beschreibungsInput.select();
    }

    // --- Autocomplete: Keyboard-Navigation (↑/↓/Enter/Esc) ---
    let acIndex = -1;

    function numberOrZero(v) {
        const n = parseFloat(String(v).replace(',', '.'));
        return isNaN(n) ? 0 : n;
    }

    function roundTo(value, digits = 2) {
        const factor = Math.pow(10, digits);
        return Math.round((value + Number.EPSILON) * factor) / factor;
    }

    function getAcItems() {
        return Array.from(vorschlaegeList.querySelectorAll('li.autocomplete-item'));
    }

    function clearSuggestions() {
        vorschlaegeList.innerHTML = '';
        acIndex = -1;
    }

    function setActiveIndex(nextIndex) {
        const items = getAcItems();
        if (!items.length) {
            acIndex = -1;
            return;
        }

        acIndex = Math.max(-1, Math.min(nextIndex, items.length - 1));

        items.forEach((li, i) => {
            if (i === acIndex) {
                li.classList.add('active');
                li.setAttribute('aria-selected', 'true');
                li.scrollIntoView({ block: 'nearest' });
            } else {
                li.classList.remove('active');
                li.removeAttribute('aria-selected');
            }
        });
    }

    function selectSuggestionFromLi(li) {
        if (!li) return;

        beschreibungsInput.value = li.dataset.beschreibung || '';
        if (kategorieInput && li.dataset.kategorie) {
            kategorieInput.value = li.dataset.kategorie;
        }
        kalorienInput.value      = li.dataset.kalorien || '';
        eiweissInput.value       = numberOrZero(li.dataset.eiweiss).toString();
        fettInput.value          = numberOrZero(li.dataset.fett).toString();
        khInput.value            = numberOrZero(li.dataset.kh).toString();

        clearSuggestions();
        recomputeChecksum();

        anzahlInput.focus();
        if (typeof anzahlInput.select === 'function') anzahlInput.select();
    }

    function renderSuggestions() {
        const eingabe = (beschreibungsInput.value || '').toLowerCase();
        clearSuggestions();
        if (!eingabe.length) return;

        const passende = daten.filter(e =>
            (e.beschreibung || '').toLowerCase().includes(eingabe)
        );

        passende.forEach(e => {
            const li = document.createElement('li');
            li.textContent = `${e.beschreibung} · ${e.kategorie || 'ohne Kategorie'} (${e.kalorien} kcal)`;
            li.dataset.beschreibung = e.beschreibung ?? '';
            li.dataset.kategorie    = e.kategorie ?? '';
            li.dataset.kalorien     = e.kalorien ?? 0;
            li.dataset.eiweiss      = e.eiweiss ?? 0;
            li.dataset.fett         = e.fett ?? 0;
            li.dataset.kh           = e.kh ?? 0;
            li.classList.add('autocomplete-item');
            vorschlaegeList.appendChild(li);
        });

        acIndex = -1;
    }

    if (beschreibungsInput) {
        beschreibungsInput.addEventListener('input', renderSuggestions);

        beschreibungsInput.addEventListener('keydown', (ev) => {
            const items = getAcItems();
            if (!items.length) return;

            if (ev.key === 'ArrowDown') {
                ev.preventDefault();
                const next = (acIndex + 1) >= items.length ? 0 : (acIndex + 1);
                setActiveIndex(next);
                return;
            }

            if (ev.key === 'ArrowUp') {
                ev.preventDefault();
                const next = (acIndex - 1) < 0 ? (items.length - 1) : (acIndex - 1);
                setActiveIndex(next);
                return;
            }

            if (ev.key === 'Enter') {
                if (acIndex >= 0 && items[acIndex]) {
                    ev.preventDefault();
                    selectSuggestionFromLi(items[acIndex]);
                }
                return;
            }

            if (ev.key === 'Escape') {
                ev.preventDefault();
                clearSuggestions();
            }
        });
    }

    if (vorschlaegeList) {
        vorschlaegeList.addEventListener('click', (e) => {
            const li = e.target && e.target.closest('li.autocomplete-item');
            if (li) selectSuggestionFromLi(li);
        });

        vorschlaegeList.addEventListener('mousemove', (e) => {
            const li = e.target && e.target.closest('li.autocomplete-item');
            if (!li) return;
            const items = getAcItems();
            const idx = items.indexOf(li);
            if (idx >= 0) setActiveIndex(idx);
        });
    }

    document.addEventListener('click', (e) => {
        if (!vorschlaegeList.contains(e.target) && e.target !== beschreibungsInput) {
            clearSuggestions();
        }
    });

    // --- Prüfsumme ---
    const kcalCheckSpan = document.getElementById('kcal-check');

    function recomputeChecksum() {
        const eiw  = numberOrZero(eiweissInput.value);
        const fett = numberOrZero(fettInput.value);
        const kh   = numberOrZero(khInput.value);
        const kcal = (eiw * 4) + (kh * 4) + (fett * 9);
        if (kcalCheckSpan) kcalCheckSpan.textContent = String(Math.round(kcal));
    }

    [eiweissInput, fettInput, khInput].forEach(el =>
        el && el.addEventListener('input', recomputeChecksum)
    );

    recomputeChecksum();

    // --- /100g Modal ---
    function openNutritionModal() {
        nutritionModal.classList.remove('hidden');
        if (!PHONE_UI && modalGesamtGramm) modalGesamtGramm.focus();
    }

    function closeNutritionModal() {
        nutritionModal.classList.add('hidden');
        if (btn100gModal) btn100gModal.focus();
    }

    function apply100gValues() {
        const gesamt = numberOrZero(modalGesamtGramm.value);
        const kcal100 = numberOrZero(modalKalorien100.value);
        const fett100 = numberOrZero(modalFett100.value);
        const kh100 = numberOrZero(modalKh100.value);
        const eiw100 = numberOrZero(modalEiweiss100.value);

        if (gesamt <= 0 || kcal100 <= 0) {
            alert('Bitte mindestens Gesamteinheit und Kalorien /100g sinnvoll ausfüllen.');
            return;
        }

        const faktor = gesamt / 100;

        kalorienInput.value = String(Math.round(kcal100 * faktor));
        fettInput.value = String(roundTo(fett100 * faktor, 2));
        khInput.value = String(roundTo(kh100 * faktor, 2));
        eiweissInput.value = String(roundTo(eiw100 * faktor, 2));

        recomputeChecksum();
        closeNutritionModal();
    }

    if (btn100gModal) {
        btn100gModal.addEventListener('click', openNutritionModal);
    }

    if (modalCloseX) {
        modalCloseX.addEventListener('click', closeNutritionModal);
    }

    if (modalCancel) {
        modalCancel.addEventListener('click', closeNutritionModal);
    }

    if (modalApply) {
        modalApply.addEventListener('click', apply100gValues);
    }

    if (nutritionModal) {
        nutritionModal.addEventListener('click', (e) => {
            if (e.target === nutritionModal) {
                closeNutritionModal();
            }
        });
    }

    document.addEventListener('keydown', (e) => {
        if (!nutritionModal || nutritionModal.classList.contains('hidden')) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            closeNutritionModal();
            return;
        }

        if (e.key === 'Enter') {
            const target = e.target;
            const tag = target && target.tagName ? target.tagName.toLowerCase() : '';
            if (tag === 'input') {
                e.preventDefault();
                apply100gValues();
            }
        }
    });

    // -------------------- Tagesansicht mit Navigation via AJAX --------------------
    const tageTbody        = document.getElementById('tage-tbody');
    const btnTagZurueck    = document.getElementById('tag-zurueck');
    const btnTagVor        = document.getElementById('tag-vor');
    const btnTagHeute      = document.getElementById('tag-heute');
    const tageUeberschrift = document.getElementById('tage-ueberschrift');
    const dateInput        = document.getElementById('tag-date');

    const heuteStr   = <?= json_encode($heute, JSON_UNESCAPED_UNICODE) ?>;
    const gesternStr = <?= json_encode($gestern, JSON_UNESCAPED_UNICODE) ?>;

    let aktuellesDatum = <?= json_encode($selected, JSON_UNESCAPED_UNICODE) ?>;
    let prevDate = null;
    let nextDate = null;

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDatum(d) {
        const parts = String(d).split('-');
        if (parts.length !== 3) return String(d);
        return parts[2] + '.' + parts[1] + '.' + parts[0];
    }

    function updateButtons() {
        if (btnTagZurueck) btnTagZurueck.disabled = !prevDate;
        if (btnTagVor)     btnTagVor.disabled     = !nextDate;
    }

    function updateHeadline() {
        if (!tageUeberschrift) return;
        let text = formatDatum(aktuellesDatum);
        if (aktuellesDatum === heuteStr) {
            text += ' (Heute)';
        } else if (aktuellesDatum === gesternStr) {
            text += ' (Gestern)';
        }
        tageUeberschrift.textContent = text;
    }

    function formatKcal(value, decimals = 2) {
        return Number(value).toLocaleString('de-DE', { maximumFractionDigits: decimals });
    }
    let stretchEntries = new Map();
    const stretchModal = document.getElementById('stretch-modal');
    const stretchWeeks = document.getElementById('stretch-weeks');
    const stretchMore = document.getElementById('stretch-add-week');
    let stretchOrigin = '';
    let stretchSelected = new Set();
    let visibleWeeks = 1;
    let stretchTrigger = null;
    function stretchDate(offset) {
        const [y, m, d] = stretchOrigin.split('-').map(Number);
        const date = new Date(Date.UTC(y, m - 1, d + offset));
        return [date.toISOString().slice(0, 10), date];
    }
    function renderStretchWeeks() {
        stretchWeeks.replaceChildren();
        for (let week = 0; week < visibleWeeks; week++) {
            if (week > 0) stretchWeeks.appendChild(document.createElement('hr'));
            const row = document.createElement('div');
            row.className = 'kalorien-stretch-week';
            for (let day = 0; day < 7; day++) {
                const offset = week * 7 + day;
                const [value, date] = stretchDate(offset);
                const label = document.createElement('label');
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.name = 'stretch_days[]';
                input.value = value;
                input.checked = offset === 0 || stretchSelected.has(value);
                input.disabled = offset === 0;
                input.addEventListener('change', () => {
                    if (input.checked) stretchSelected.add(value);
                    else stretchSelected.delete(value);
                });
                const weekday = document.createElement('span');
                weekday.textContent = date.toLocaleDateString('de-DE', { weekday: 'short', timeZone: 'UTC' });
                const datum = document.createElement('span');
                datum.textContent = date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', timeZone: 'UTC' });
                label.append(input, weekday, datum);
                row.appendChild(label);
            }
            stretchWeeks.appendChild(row);
        }
        stretchMore.hidden = visibleWeeks >= 4;
    }
    function closeStretchModal() {
        stretchModal.classList.add('hidden');
        if (stretchTrigger) stretchTrigger.focus();
    }
    tageTbody.addEventListener('click', event => {
        const button = event.target.closest('.kalorien-stretch-button');
        if (!button) return;
        const entry = stretchEntries.get(Number(button.dataset.entryId));
        if (!entry) return;
        stretchTrigger = button;
        stretchOrigin = entry.ursprung_tag;
        stretchSelected = new Set(entry.stretch_days || [stretchOrigin]);
        visibleWeeks = 1;
        for (let offset = 0; offset < 28; offset++) {
            if (stretchSelected.has(stretchDate(offset)[0])) visibleWeeks = Math.max(visibleWeeks, Math.floor(offset / 7) + 1);
        }
        document.getElementById('stretch-entry-id').value = entry.id;
        document.getElementById('stretch-current-date').value = aktuellesDatum;
        renderStretchWeeks();
        stretchModal.classList.remove('hidden');
        document.getElementById('stretch-close').focus();
    });
    stretchMore.addEventListener('click', () => {
        visibleWeeks = Math.min(4, visibleWeeks + 1);
        renderStretchWeeks();
    });
    document.getElementById('stretch-close').addEventListener('click', closeStretchModal);
    document.getElementById('stretch-cancel').addEventListener('click', closeStretchModal);
    stretchModal.addEventListener('click', event => { if (event.target === stretchModal) closeStretchModal(); });
    document.addEventListener('keydown', event => {
        if (stretchModal.classList.contains('hidden')) return;
        if (event.key === 'Escape') { event.preventDefault(); closeStretchModal(); }
        if (event.key === 'Tab') {
            const focusable = Array.from(stretchModal.querySelectorAll('button, input')).filter(el => !el.disabled && el.getClientRects().length);
            const first = focusable[0], last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });

    function renderTagData(data) {
        const datum        = data.date;
        const eintraegeTag = data.entries || [];
        stretchEntries = new Map(eintraegeTag.map(e => [Number(e.id), e]));

        let fallbackBruttoSumme = 0;
        eintraegeTag.forEach(e => { fallbackBruttoSumme += Number(e.kalorien) || 0; });

        const bruttoSummeRaw = Number(data.brutto_summe);
        const nettoSummeRaw  = Number(data.netto_summe);

        const bruttoSumme = Number.isFinite(bruttoSummeRaw) ? bruttoSummeRaw : fallbackBruttoSumme;
        const nettoSumme  = Number.isFinite(nettoSummeRaw)  ? nettoSummeRaw  : bruttoSumme;

        let html = '';
        html += '<tr class="kalorien-day-summary" style="border-bottom: 3px solid black; font-weight: bold;">';
        html += '<td></td>';
        html += '<td style="white-space:nowrap;">SUMME</td>';
        html += '<td style="white-space:nowrap;">' + formatKcal(bruttoSumme, 0) + ' kcal</td>';
        html += '<td style="white-space:nowrap; text-align:center;">' + formatKcal(nettoSumme) + ' kcal</td>';
        html += '</tr>';

        eintraegeTag.forEach(e => {
            const zeit = (e.tstamp || '').substr(11, 5);
            const id   = Number(e.id) || 0;
            const kcal = Number(e.kalorien) || 0;

            html += '<tr class="kalorien-day-entry">';
            html += '<td>' + escHtml(zeit) + '</td>';
            html += '<td>' + escHtml(e.beschreibung || '') + (Number(e.strecken_anzahl) > 1 ? ' <span class="kalorien-stretch-share">(1/' + Number(e.strecken_anzahl) + ')</span>' : '') + '</td>';
            html += '<td>' + formatKcal(kcal, 0) + ' kcal</td>';
            html += '<td>';
            html += '<div class="kalorien-day-actions' + (Number(e.strecken_anzahl) > 1 ? ' is-stretched' : ' has-four-actions') + '" style="display:flex; gap:6px; align-items:stretch; justify-content:center;">';

            if (Number(e.strecken_anzahl) <= 1) {
            html += '<form method="post" style="margin:0;">'
                 +  '<input type="hidden" name="current_date" value="' + escHtml(datum) + '">'
                 +  '<input type="hidden" name="move_to_previous_day" value="' + id + '">'
                 +  '<button type="submit" style="height:100%;">Auf Vortag</button>'
                 +  '</form>';

            html += '<form method="post" style="margin:0;">'
                 +  '<input type="hidden" name="current_date" value="' + escHtml(datum) + '">'
                 +  '<input type="hidden" name="move_to_next_day" value="' + id + '">'
                 +  '<button type="submit" style="height:100%;">Auf Folgetag</button>'
                 +  '</form>';

            }
            html += '<button type="button" class="kalorien-stretch-button" data-entry-id="' + id + '">Strecken</button>';
            if (Number(e.strecken_anzahl) <= 1) {
            html += '<form method="post" style="margin:0;">'
                 +  '<input type="hidden" name="current_date" value="' + escHtml(datum) + '">'
                 +  '<input type="hidden" name="delete_entry" value="' + id + '">'
                 +  '<button type="submit" onclick="return confirm(\'Eintrag wirklich löschen?\');" style="height:100%;">Löschen</button>'
                 +  '</form>';

            }
            html += '</div>';
            html += '</td>';
            html += '</tr>';
        });

        if (tageTbody) tageTbody.innerHTML = html;

        aktuellesDatum = datum;
        prevDate       = data.prev || null;
        nextDate       = data.next || null;

        if (dateInput) dateInput.value = datum;

        updateHeadline();
        updateButtons();
    }

    async function loadDay(dateStr) {
        const url = new URL(window.location.href);
        url.searchParams.set('ajax', 'tag');
        url.searchParams.set('date', dateStr);

        try {
            const res = await fetch(url.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) {
                console.error('Fehler beim Laden des Tages', res.status);
                return;
            }
            const data = await res.json();
            if (data && !data.error) {
                renderTagData(data);
            } else {
                console.error('Antwort-Fehler:', data && data.error);
            }
        } catch (err) {
            console.error('AJAX-Fehler:', err);
        }
    }

    if (dateInput) {
        dateInput.value = aktuellesDatum;
        dateInput.addEventListener('change', () => {
            const val = dateInput.value;
            if (val) loadDay(val);
        });
    }

    if (btnTagZurueck) {
        btnTagZurueck.addEventListener('click', () => {
            if (prevDate) loadDay(prevDate);
        });
    }

    if (btnTagVor) {
        btnTagVor.addEventListener('click', () => {
            if (nextDate) loadDay(nextDate);
        });
    }

    if (btnTagHeute) {
        btnTagHeute.addEventListener('click', () => {
            loadDay(heuteStr);
        });
    }

    loadDay(aktuellesDatum);
</script>

</body>
</html>