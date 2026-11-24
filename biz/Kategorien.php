<?php
// biz/Kategorien.php – Kategorien verwalten.

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$bizconn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['biz_kategorien_csrf'])) {
    $_SESSION['biz_kategorien_csrf'] = bin2hex(random_bytes(32));
}

function bizKategorieEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Gemeinsamer Startzeitpunkt für Gesamtsaldo und Monatsdurchschnitt: wie im Dashboard.
$averageStart = '2022-08-01';
$averageEnd = date('Y-m-01');
$averageMonths = max(0, ((int)date('Y') - 2022) * 12 + (int)date('n') - 8);

function bizKategorieRows(mysqli $db, string $start, string $end, int $months): array
{
    $stmt = $db->prepare("SELECT k.id, k.name, COUNT(t.id) AS transfer_count,
        COALESCE(SUM(CASE WHEN t.valutadatum >= ? THEN t.betrag ELSE 0 END), 0) AS total,
        COALESCE(SUM(CASE WHEN t.valutadatum >= ? AND t.valutadatum < ? THEN t.betrag ELSE 0 END), 0) AS closed_total
        FROM kategorien k LEFT JOIN transfers t ON t.kategorie_id = k.id
        GROUP BY k.id, k.name ORDER BY k.name ASC, k.id ASC");
    $stmt->bind_param('sss', $start, $start, $end);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['average'] = $months > 0 ? (float)$row['closed_total'] / $months : null;
    }
    unset($row);
    return $rows;
}

function bizKategorieJson(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction = false;
    try {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !is_string($body['csrf_token'] ?? null) ||
            !hash_equals($_SESSION['biz_kategorien_csrf'], $body['csrf_token'])) {
            throw new RuntimeException('Ungültige Anfrage. Bitte Seite neu laden.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $action = $body['action'] ?? '';
        $validateName = static function ($value): string {
            if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value), 'UTF-8') > 255) {
                throw new RuntimeException('Bitte einen gültigen Namen (1–255 Zeichen) eingeben.');
            }
            return trim($value);
        };
        $validateId = static function ($value): int {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new RuntimeException('Ungültige Kategorie.');
            return $id;
        };
        if ($action === 'create') {
            $name = $validateName($body['name'] ?? null);
            $stmt = $bizconn->prepare('INSERT INTO kategorien (name) VALUES (?)');
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $stmt->close();
            $message = 'Kategorie angelegt.';
        } elseif ($action === 'save') {
            $changes = $body['changes'] ?? null;
            if (!is_array($changes) || !$changes) throw new RuntimeException('Keine Änderungen vorhanden.');
            $bizconn->begin_transaction();
            $transaction = true;
            $stmt = $bizconn->prepare('UPDATE kategorien SET name = ? WHERE id = ?');
            $check = $bizconn->prepare('SELECT id FROM kategorien WHERE id = ? FOR UPDATE');
            $normalized = [];
            foreach ($changes as $change) {
                if (!is_array($change)) throw new RuntimeException('Ungültige Änderung.');
                $id = $validateId($change['id'] ?? null);
                $name = $validateName($change['name'] ?? null);
                if (isset($normalized[$id])) throw new RuntimeException('Doppelte Kategorie.');
                $normalized[$id] = $name;
            }
            ksort($normalized);
            // Temporäre eindeutige Namen erlauben auch das Tauschen zweier Namen.
            foreach ($normalized as $id => $name) {
                $check->bind_param('i', $id);
                $check->execute();
                if (!$check->get_result()->fetch_assoc()) throw new RuntimeException('Kategorie nicht gefunden.');
                $temporary = '__rename_' . bin2hex(random_bytes(24));
                $stmt->bind_param('si', $temporary, $id);
                $stmt->execute();
            }
            foreach ($normalized as $id => $name) {
                $stmt->bind_param('si', $name, $id);
                $stmt->execute();
            }
            $check->close();
            $stmt->close();
            $bizconn->commit();
            $transaction = false;
            $message = 'Änderungen gespeichert.';
        } elseif ($action === 'delete') {
            $id = $validateId($body['id'] ?? null);
            $stmt = $bizconn->prepare('DELETE FROM kategorien WHERE id = ? AND NOT EXISTS (SELECT 1 FROM transfers WHERE kategorie_id = ?)');
            $stmt->bind_param('ii', $id, $id);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            if ($deleted !== 1) throw new RuntimeException('Kategorie existiert nicht mehr oder wird noch verwendet.');
            $message = 'Kategorie gelöscht.';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
        bizKategorieJson(['ok' => true, 'message' => $message, 'rows' => bizKategorieRows($bizconn, $averageStart, $averageEnd, $averageMonths)]);
    } catch (Throwable $e) {
        if ($transaction) $bizconn->rollback();
        if ($e instanceof mysqli_sql_exception) {
            error_log('biz/Kategorien.php: ' . $e->getMessage());
            $message = match ((int)$e->getCode()) {
                1062 => 'Dieser Kategoriename existiert bereits.',
                1451 => 'Kategorie wird noch verwendet.',
                default => 'Datenbankänderung fehlgeschlagen.',
            };
        } else {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Anfrage fehlgeschlagen.';
        }
        bizKategorieJson(['ok' => false, 'message' => $message], 400);
    }
}

$kategorien = bizKategorieRows($bizconn, $averageStart, $averageEnd, $averageMonths);

$page_title = 'Kategorien verwalten';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<main id="bizKategorienPage" class="container biz-kategorien-page">
    <h1 class="ueberschrift">Kategorien</h1>
    <div class="biz-kategorien-toolbar">
        <form id="bizKatCreate" class="biz-kategorien-create-form">
            <input name="name" type="text" maxlength="255" placeholder="Neue Kategorie" aria-label="Neue Kategorie" required>
            <button type="submit">+ Kategorie</button>
        </form>
        <button id="bizKatSave" type="button" disabled>Änderungen speichern</button>
    </div>
    <p id="bizKatNotice" class="biz-kategorien-notice" role="status" hidden></p>
    <table class="food-table biz-kategorien-table">
        <thead><tr>
            <th scope="col" aria-sort="ascending"><button type="button" class="biz-kategorien-sort" data-sort="name">Name <span aria-hidden="true">↑</span></button></th>
            <th scope="col"><button type="button" class="biz-kategorien-sort" data-sort="transfer_count">Transfers <span aria-hidden="true">↕</span></button></th>
            <th scope="col"><button type="button" class="biz-kategorien-sort" data-sort="total">Gesamt <span aria-hidden="true">↕</span></button></th>
            <th scope="col"><button type="button" class="biz-kategorien-sort" data-sort="average">Ø / Monat <span aria-hidden="true">↕</span></button></th>
            <th scope="col"></th>
        </tr></thead>
        <tbody id="bizKatRows"></tbody>
    </table>
</main>
<script>
(() => {
    const csrf = <?= json_encode($_SESSION['biz_kategorien_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let rows = <?= json_encode($kategorien, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    const tbody = document.getElementById('bizKatRows');
    const save = document.getElementById('bizKatSave');
    const create = document.getElementById('bizKatCreate');
    const notice = document.getElementById('bizKatNotice');
    const drafts = new Map();
    let busy = false;
    let sortKey = 'name';
    let sortDirection = 1;
    const sortButtons = document.querySelectorAll('#bizKategorienPage .biz-kategorien-sort');
    const collator = new Intl.Collator('de-DE', {sensitivity: 'base'});
    const nameOf = row => drafts.get(Number(row.id)) ?? row.name;
    function sortedRows() {
        return [...rows].sort((a, b) => {
            const byName = collator.compare(nameOf(a), nameOf(b));
            if (sortKey === 'name') return sortDirection * byName || Number(a.id) - Number(b.id);
            if (a[sortKey] == null && b[sortKey] != null) return 1;
            if (b[sortKey] == null && a[sortKey] != null) return -1;
            return sortDirection * (Number(a[sortKey]) - Number(b[sortKey])) || byName || Number(a.id) - Number(b.id);
        });
    }
    function updateSortHeaders() {
        sortButtons.forEach(button => {
            const active = button.dataset.sort === sortKey;
            const th = button.closest('th');
            if (active) th.setAttribute('aria-sort', sortDirection === 1 ? 'ascending' : 'descending');
            else th.removeAttribute('aria-sort');
            button.querySelector('span').textContent = active ? (sortDirection === 1 ? '↑' : '↓') : '↕';
        });
    }
    sortButtons.forEach(button => button.addEventListener('click', () => {
        if (busy) return;
        const key = button.dataset.sort;
        sortDirection = key === sortKey ? -sortDirection : 1;
        sortKey = key;
        render();
    }));
    const euro = new Intl.NumberFormat('de-DE', {style: 'currency', currency: 'EUR'});
    const number = new Intl.NumberFormat('de-DE');
    const url = id => '/biz/data?monat=&jahr=&kat=' + encodeURIComponent(id);
    function cell(tr, cls, text) {
        const td = tr.insertCell();
        td.className = cls;
        if (text !== undefined) td.textContent = text;
        return td;
    }
    function sync() {
        save.disabled = busy || !drafts.size;
        save.textContent = drafts.size ? `Änderungen speichern (${drafts.size})` : 'Änderungen speichern';
        create.querySelectorAll('input, button').forEach(el => el.disabled = busy);
        tbody.querySelectorAll('input').forEach(el => el.disabled = busy);
        tbody.querySelectorAll('button').forEach(el => el.disabled = busy || el.dataset.used === '1');
    }
    function render() {
        updateSortHeaders();
        tbody.replaceChildren();
        if (!rows.length) {
            const td = tbody.insertRow().insertCell();
            td.colSpan = 5; td.textContent = 'Noch keine Kategorien vorhanden.';
        }
        for (const row of sortedRows()) {
            const id = Number(row.id);
            const tr = tbody.insertRow();
            tr.className = 'biz-kategorien-row';
            const nameCell = cell(tr, 'biz-kategorien-name-cell');
            const input = document.createElement('input');
            input.type = 'text'; input.maxLength = 255; input.required = true;
            input.value = drafts.get(id) ?? row.name;
            input.className = 'biz-kategorien-name-input';
            input.setAttribute('aria-label', 'Kategoriename: ' + row.name);
            input.classList.toggle('is-dirty', drafts.has(id));
            nameCell.append(input);
            input.addEventListener('input', () => {
                if (input.value === row.name) drafts.delete(id); else drafts.set(id, input.value);
                input.classList.toggle('is-dirty', drafts.has(id));
                sync();
            });
            input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); if (!save.disabled) save.click(); } });
            const countCell = cell(tr, 'biz-kategorien-count', number.format(Number(row.transfer_count)));
            const link = document.createElement('a');
            link.href = url(id); link.className = 'biz-kategorien-row-link';
            link.setAttribute('aria-label', 'Transfers anzeigen: ' + row.name);
            countCell.append(link);
            cell(tr, 'biz-kategorien-money', euro.format(Number(row.total)));
            cell(tr, 'biz-kategorien-money biz-kategorien-average', row.average === null ? '–' : euro.format(Number(row.average)));
            const actions = cell(tr, 'biz-kategorien-actions-cell');
            const del = document.createElement('button');
            del.type = 'button'; del.textContent = 'Löschen'; del.className = 'biz-kategorien-delete';
            del.dataset.used = Number(row.transfer_count) > 0 ? '1' : '0';
            del.title = del.dataset.used === '1' ? 'Kategorie enthält Transfers' : 'Kategorie löschen';
            del.addEventListener('click', () => {
                if (confirm('Kategorie „' + row.name + '“ wirklich löschen?')) mutate('delete', {id});
            });
            actions.append(del);
            tr.addEventListener('click', e => {
                if (e.target.closest('input, button, a, .biz-kategorien-actions-cell')) return;
                if (e.ctrlKey || e.metaKey) window.open(url(id), '_blank', 'noopener');
                else location.href = url(id);
            });
        }
        sync();
    }
    async function mutate(action, payload) {
        if (busy) return;
        busy = true; sync(); notice.hidden = true;
        try {
            const res = await fetch('/biz/kategorien', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf_token: csrf, action, ...payload})});
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.message || 'Änderung fehlgeschlagen.');
            if (action === 'save') drafts.clear();
            if (action === 'delete') drafts.delete(payload.id);
            if (action === 'create') create.reset();
            rows = data.rows;
            render();
            notice.textContent = data.message; notice.classList.remove('is-error');
        } catch (err) {
            notice.textContent = err.message || 'Änderung fehlgeschlagen.'; notice.classList.add('is-error');
        } finally { busy = false; sync(); notice.hidden = false; }
    }
    create.addEventListener('submit', e => { e.preventDefault(); mutate('create', {name: create.elements.name.value}); });
    save.addEventListener('click', () => {
        for (const input of tbody.querySelectorAll('input')) if (!input.reportValidity()) return;
        mutate('save', {changes: Array.from(drafts, ([id, name]) => ({id, name}))});
    });
    window.addEventListener('beforeunload', e => { if (drafts.size) { e.preventDefault(); e.returnValue = ''; } });
    render();
})();
</script>
</body>
</html>
