<?php
// fit/Training.php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
$fitconn->set_charset('utf8mb4');

// ---------------------- POST-VERARBEITUNG (kein Output davor!) ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Für Navigation nach dem Verschieben/Löschen: aktuellen Tag zurückgeben
    $currentDateParam = $_POST['current_date'] ?? null;
    $redirectUrl = '/fit/training';
    if ($currentDateParam) {
        $redirectUrl .= '?date=' . urlencode($currentDateParam);
    }

    // Eintrag auf Vortag verschieben
    if (isset($_POST['move_to_previous_day'])) {
        $id = (int)$_POST['move_to_previous_day'];

        $stmt = $fitconn->prepare("SELECT tstamp FROM training WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($tstamp_alt);

        if ($stmt->fetch()) {
            $stmt->close();

            $dt = new DateTime($tstamp_alt);
            $dt->modify('-1 day')->setTime(23, 59);
            $newTstamp = $dt->format('Y-m-d H:i:s');

            $stmt = $fitconn->prepare("UPDATE training SET tstamp = ? WHERE id = ?");
            $stmt->bind_param('si', $newTstamp, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt->close();
        }

        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    // Eintrag auf Folgetag verschieben
    if (isset($_POST['move_to_next_day'])) {
        $id = (int)$_POST['move_to_next_day'];

        $stmt = $fitconn->prepare("SELECT tstamp FROM training WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($tstamp_alt);

        if ($stmt->fetch()) {
            $stmt->close();

            $dt = new DateTime($tstamp_alt);
            $dt->modify('+1 day')->setTime(0, 1);
            $newTstamp = $dt->format('Y-m-d H:i:s');

            $stmt = $fitconn->prepare("UPDATE training SET tstamp = ? WHERE id = ?");
            $stmt->bind_param('si', $newTstamp, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt->close();
        }

        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    // Eintrag löschen
    if (isset($_POST['delete_entry'])) {
        $id = (int)$_POST['delete_entry'];

        $stmt = $fitconn->prepare("DELETE FROM training WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    // Neuer Trainingseintrag
    $beschreibung = trim($_POST['beschreibung'] ?? '');
    if ($beschreibung === '') {
        $beschreibung = 'Kardio';
    }
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
        SELECT id, beschreibung, kalorien, tstamp
        FROM training
        WHERE DATE(tstamp) = ?
        ORDER BY tstamp ASC
    ");
    $stmt->bind_param('s', $datum);
    $stmt->execute();
    $result = $stmt->get_result();
    $eintraegeTag = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Vorheriger Tag mit Einträgen
    $prev = null;
    $stmt = $fitconn->prepare("
        SELECT DATE(tstamp) AS tag
        FROM training
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
        FROM training
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
        'date'    => $datum,
        'heute'   => $heute,
        'gestern' => $gestern,
        'entries' => $eintraegeTag,
        'prev'    => $prev,
        'next'    => $next,
    ]);
    exit;
}

// ---------------------- DATEN FÜR GET-RENDERING ----------------------

// Vorschlagsliste (aggregiert nach Beschreibung/Kalorien)
$result = $fitconn->query("
    SELECT 
        MIN(id) AS id,
        beschreibung,
        kalorien,
        COUNT(*) AS anzahl
    FROM training
    GROUP BY beschreibung, kalorien
    ORDER BY anzahl DESC
");
$eintraege = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
if ($result) $result->close();

$heute   = date('Y-m-d');
$gestern = date('Y-m-d', strtotime('-1 day'));
$selected = $_GET['date'] ?? $heute;

// Rendering starten
$page_title = 'Training eintragen';
require_once __DIR__ . '/../head.php';   // öffnet <!DOCTYPE html> … <body>
require_once __DIR__ . '/../navbar.php'; // nur die Navbar
?>

<div class="container">
    <h1 class="ueberschrift">Kardiotraining eintragen</h1>

    <form method="post" class="form-block" action="/fit/training">
        <label for="beschreibung">Beschreibung:</label>
        <input type="text" id="beschreibung" name="beschreibung" autocomplete="off">

        <ul id="vorschlaege" class="autocomplete-list-tr"></ul>

        <label for="kalorien" style="margin-top:10px; display:block;">Verbrannte Kalorien (kcal):</label>
        <input type="number" id="kalorien" name="kalorien" required>

        <button type="submit" style="margin-top:15px;">Eintragen</button>
    </form>
</div>

<div class="container" style="max-width: 800px; margin-top: 25px;">

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
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
            <th>Kalorien</th>
            <th></th>
        </tr>
        </thead>
        <tbody id="tage-tbody">
            <!-- wird per JavaScript befüllt -->
        </tbody>
    </table>
</div>

<script>
    // -------------------- Autocomplete für Beschreibung --------------------
    const daten = <?= json_encode(is_array($eintraege) ? $eintraege : [], JSON_UNESCAPED_UNICODE) ?>;
    const beschreibungsInput = document.getElementById('beschreibung');
    const kalorienInput      = document.getElementById('kalorien');
    const vorschlaegeList    = document.getElementById('vorschlaege');

    beschreibungsInput.addEventListener('input', () => {
        const eingabe = (beschreibungsInput.value || '').toLowerCase();
        vorschlaegeList.innerHTML = '';
        if (!eingabe.length) return;

        const passende = daten.filter(e =>
            (e.beschreibung || '').toLowerCase().includes(eingabe)
        );

        passende.forEach(e => {
            const li = document.createElement('li');
            li.textContent = `${e.beschreibung} (${e.kalorien} kcal)`;
            li.dataset.beschreibung = e.beschreibung ?? '';
            li.dataset.kalorien     = e.kalorien ?? 0;
            li.classList.add('autocomplete-item');
            vorschlaegeList.appendChild(li);
        });
    });

    vorschlaegeList.addEventListener('click', (e) => {
        if (e.target && e.target.tagName === 'LI') {
            beschreibungsInput.value = e.target.dataset.beschreibung || '';
            kalorienInput.value      = e.target.dataset.kalorien || '';
            vorschlaegeList.innerHTML = '';
        }
    });

    document.addEventListener('click', (e) => {
        if (!vorschlaegeList.contains(e.target) && e.target !== beschreibungsInput) {
            vorschlaegeList.innerHTML = '';
        }
    });

    // -------------------- Tagesansicht mit Navigation (AJAX) --------------------
    const tageTbody       = document.getElementById('tage-tbody');
    const btnTagZurueck   = document.getElementById('tag-zurueck');
    const btnTagVor       = document.getElementById('tag-vor');
    const btnTagHeute     = document.getElementById('tag-heute');
    const tageUeberschrift = document.getElementById('tage-ueberschrift');
    const dateInput       = document.getElementById('tag-date');

    const heuteStr   = '<?= $heute ?>';
    const gesternStr = '<?= $gestern ?>';
    let   aktuellesDatum = '<?= $selected ?>';
    let   prevDate = null;
    let   nextDate = null;

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDatum(d) {
        const parts = d.split('-'); // YYYY-MM-DD
        if (parts.length !== 3) return d;
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

    function renderTagData(data) {
        const datum        = data.date;
        const eintraegeTag = data.entries || [];
        let summe = 0;
        eintraegeTag.forEach(e => {
            summe += Number(e.kalorien) || 0;
        });

        let html = '';
        html += '<tr style="border-bottom: 3px solid black; font-weight: bold;">';
        html += '<td></td>';
        html += '<td style="white-space:nowrap;">SUMME</td>';
        html += '<td style="white-space:nowrap;">' + summe + ' kcal</td>';
        html += '<td></td>';
        html += '</tr>';

        eintraegeTag.forEach(e => {
            const zeit = (e.tstamp || '').substr(11, 5);
            const id   = Number(e.id) || 0;
            const kcal = Number(e.kalorien) || 0;

            html += '<tr>';
            html += '<td>' + escHtml(zeit) + '</td>';
            html += '<td>' + escHtml(e.beschreibung || '') + '</td>';
            html += '<td>' + kcal + ' kcal</td>';
            html += '<td>';
            html += '<div style="display: flex; gap: 6px; align-items: stretch;">';

            html += '<form method="post" style="margin:0;">'
                 +  '<input type="hidden" name="current_date" value="' + escHtml(datum) + '">'
                 +  '<input type="hidden" name="move_to_previous_day" value="' + id + '">'
                 +  '<button type="submit" style="height: 100%;">Auf Vortag</button>'
                 +  '</form>';

            html += '<form method="post" style="margin:0;">'
                 +  '<input type="hidden" name="current_date" value="' + escHtml(datum) + '">'
                 +  '<input type="hidden" name="move_to_next_day" value="' + id + '">'
                 +  '<button type="submit" style="height: 100%;">Auf Folgetag</button>'
                 +  '</form>';

            html += '<form method="post" style="margin:0;">'
                 +  '<input type="hidden" name="current_date" value="' + escHtml(datum) + '">'
                 +  '<input type="hidden" name="delete_entry" value="' + id + '">'
                 +  '<button type="submit" onclick="return confirm(\'Eintrag wirklich löschen?\');" style="height: 100%;">Löschen</button>'
                 +  '</form>';

            html += '</div>';
            html += '</td>';
            html += '</tr>';
        });

        if (tageTbody) {
            tageTbody.innerHTML = html;
        }

        aktuellesDatum = datum;
        prevDate       = data.prev || null;
        nextDate       = data.next || null;

        if (dateInput) {
            dateInput.value = datum;
        }

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
                console.error('Antwort-Fehler:', data.error);
            }
        } catch (err) {
            console.error('AJAX-Fehler:', err);
        }
    }

    if (dateInput) {
        dateInput.value = aktuellesDatum;
        dateInput.addEventListener('change', () => {
            const val = dateInput.value;
            if (val) {
                loadDay(val);
            }
        });
    }

    if (btnTagZurueck) {
        btnTagZurueck.addEventListener('click', () => {
            if (prevDate) {
                loadDay(prevDate);
            }
        });
    }

    if (btnTagVor) {
        btnTagVor.addEventListener('click', () => {
            if (nextDate) {
                loadDay(nextDate);
            }
        });
    }

    if (btnTagHeute) {
        btnTagHeute.addEventListener('click', () => {
            loadDay(heuteStr);
        });
    }

    // Initialer Render: ausgewähltes Datum (Standard: heute)
    loadDay(aktuellesDatum);
</script>

</body>
</html>
