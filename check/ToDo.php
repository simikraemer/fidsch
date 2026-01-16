<?php
// check/ToDo.php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$checkconn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ---------------------- Status-Filter (open|done) ---------------------- */
$status = strtolower(trim((string)($_GET['status'] ?? 'open')));
if (!in_array($status, ['open', 'done'], true)) $status = 'open';

/* ---------------------- Helpers ---------------------- */
function json_out(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------------------- Kategorien laden (als SUBJECTS wie Lerntime) ---------------------- */
$SUBJECTS = []; // name => ['id'=>int,'color'=>string,'sort_order'=>int]
$resC = $checkconn->query("
    SELECT id, name, color, sort_order
    FROM todo_kategorien
    WHERE is_active = 1
    ORDER BY sort_order ASC, id ASC
");
if (!$resC) {
    http_response_code(500);
    die('DB-Fehler: todo_kategorien konnte nicht geladen werden.');
}
while ($r = $resC->fetch_assoc()) {
    $name = (string)$r['name'];
    $SUBJECTS[$name] = [
        'id' => (int)$r['id'],
        'color' => (string)$r['color'],
        'sort_order' => (int)$r['sort_order'],
    ];
}
if (!$SUBJECTS) {
    http_response_code(500);
    die('Keine Kategorien vorhanden. Bitte todo_kategorien befüllen.');
}

$defaultFach = (string)array_key_first($SUBJECTS);
$sessionFach = (string)($_SESSION['todo_fach'] ?? $defaultFach);
if (!isset($SUBJECTS[$sessionFach])) $sessionFach = $defaultFach;
$_SESSION['todo_fach'] = $sessionFach;

/* ---------------------- POST (AJAX) ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'set_fach') {
        $fach = (string)($_POST['fach'] ?? '');
        if (!isset($SUBJECTS[$fach])) json_out(['ok' => false, 'error' => 'Ungültige Kategorie.'], 400);
        $_SESSION['todo_fach'] = $fach;
        json_out(['ok' => true]);
    }
    
    if ($action === 'get_counts') {
        $res = $checkconn->query("
            SELECT
                SUM(done_at IS NULL) AS open_c,
                SUM(done_at IS NOT NULL) AS done_c
            FROM todo
        ");
        $open = 0;
        $done = 0;
        if ($res && ($r = $res->fetch_assoc())) {
            $open = (int)$r['open_c'];
            $done = (int)$r['done_c'];
        }
        json_out(['ok' => true, 'open' => $open, 'done' => $done]);
    }

    if ($action === 'toggle_done') {
        $id = (int)($_POST['id'] ?? 0);
        $checked = (string)($_POST['checked'] ?? '0') === '1';
        if ($id <= 0) json_out(['ok' => false, 'error' => 'Ungültige ID.'], 400);

        if ($checked) {
            $stmt = $checkconn->prepare("UPDATE todo SET done_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $checkconn->prepare("UPDATE todo SET done_at = NULL WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $checkconn->prepare("
            SELECT t.id, k.name AS fach, t.category_id, t.parent_id, t.title, t.note, t.detail_note, t.done_at, t.sort_key
            FROM todo t
            JOIN todo_kategorien k ON k.id = t.category_id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) json_out(['ok' => false, 'error' => 'Eintrag nicht gefunden.'], 404);
        json_out(['ok' => true, 'todo' => $row]);
    }

    if ($action === 'update_sort') {
        $id = (int)($_POST['id'] ?? 0);
        $sort = (string)($_POST['sort_key'] ?? '');

        if ($id <= 0 || $sort === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $sort)) {
            json_out(['ok' => false, 'error' => 'Ungültige Sortierung.'], 400);
        }

        $sortF = (float)$sort;
        $stmt = $checkconn->prepare("UPDATE todo SET sort_key = ? WHERE id = ?");
        $stmt->bind_param('di', $sortF, $id);
        $stmt->execute();
        $stmt->close();

        json_out(['ok' => true, 'id' => $id, 'sort_key' => $sort]);
    }

    if ($action === 'add_todo') {
        $fach = (string)($_POST['fach'] ?? '');
        $parentIdRaw = trim((string)($_POST['parent_id'] ?? '0'));
        $parentId = ($parentIdRaw === '' || $parentIdRaw === '0') ? null : (int)$parentIdRaw;

        $title = trim((string)($_POST['title'] ?? ''));
        $note  = (string)($_POST['note'] ?? '');
        $detailNote = (string)($_POST['detail_note'] ?? '');

        if (!isset($SUBJECTS[$fach])) json_out(['ok' => false, 'error' => 'Ungültige Kategorie.'], 400);
        if ($title === '') json_out(['ok' => false, 'error' => 'Titel ist Pflicht.'], 400);

        $catId = (int)$SUBJECTS[$fach]['id'];

        if ($parentId !== null) {
            if ($parentId <= 0) json_out(['ok' => false, 'error' => 'Ungültiger Parent.'], 400);

            $stmt = $checkconn->prepare("SELECT id, category_id FROM todo WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $parentId);
            $stmt->execute();
            $res = $stmt->get_result();
            $p = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$p) json_out(['ok' => false, 'error' => 'Parent nicht gefunden.'], 404);
            if ((int)$p['category_id'] !== $catId) json_out(['ok' => false, 'error' => 'Parent gehört zu anderer Kategorie.'], 400);
        }

        $_SESSION['todo_fach'] = $fach;

        $checkconn->begin_transaction();
        try {
            $max = 0.0;
            if ($parentId === null) {
                $stmt = $checkconn->prepare("SELECT MAX(sort_key) AS m FROM todo WHERE category_id = ? AND parent_id IS NULL");
                $stmt->bind_param('i', $catId);
            } else {
                $stmt = $checkconn->prepare("SELECT MAX(sort_key) AS m FROM todo WHERE category_id = ? AND parent_id = ?");
                $stmt->bind_param('ii', $catId, $parentId);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($r = $res->fetch_assoc()) && $r['m'] !== null) $max = (float)$r['m'];
            $stmt->close();

            $newSort = ($max > 0 ? $max + 1000.0 : 1000.0);

            if ($parentId === null) {
                $stmt = $checkconn->prepare("INSERT INTO todo (category_id, parent_id, title, note, detail_note, done_at, sort_key) VALUES (?, NULL, ?, ?, ?, NULL, ?)");
                $stmt->bind_param('isssd', $catId, $title, $note, $detailNote, $newSort);
            } else {
                $stmt = $checkconn->prepare("INSERT INTO todo (category_id, parent_id, title, note, detail_note, done_at, sort_key) VALUES (?, ?, ?, ?, ?, NULL, ?)");
                $stmt->bind_param('iisssd', $catId, $parentId, $title, $note, $detailNote, $newSort);
            }
            $stmt->execute();
            $newId = (int)$stmt->insert_id;
            $stmt->close();

            $checkconn->commit();

            $stmt = $checkconn->prepare("
                SELECT t.id, k.name AS fach, t.category_id, t.parent_id, t.title, t.note, t.detail_note, t.done_at, t.sort_key
                FROM todo t
                JOIN todo_kategorien k ON k.id = t.category_id
                WHERE t.id = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $newId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            json_out(['ok' => true, 'todo' => $row]);
        } catch (Throwable $e) {
            $checkconn->rollback();
            json_out(['ok' => false, 'error' => 'DB-Fehler beim Einfügen.'], 500);
        }
    }

    if ($action === 'update_todo') {
        $id = (int)($_POST['id'] ?? 0);
        $fach = (string)($_POST['fach'] ?? '');
        $parentIdRaw = trim((string)($_POST['parent_id'] ?? '0'));
        $parentId = ($parentIdRaw === '' || $parentIdRaw === '0') ? null : (int)$parentIdRaw;

        $title = trim((string)($_POST['title'] ?? ''));
        $note  = (string)($_POST['note'] ?? '');
        $detailNote = (string)($_POST['detail_note'] ?? '');

        if ($id <= 0) json_out(['ok' => false, 'error' => 'Ungültige ID.'], 400);
        if (!isset($SUBJECTS[$fach])) json_out(['ok' => false, 'error' => 'Ungültige Kategorie.'], 400);
        if ($title === '') json_out(['ok' => false, 'error' => 'Titel ist Pflicht.'], 400);

        $catId = (int)$SUBJECTS[$fach]['id'];

        $stmt = $checkconn->prepare("SELECT id, category_id, parent_id FROM todo WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $old = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$old) json_out(['ok' => false, 'error' => 'Eintrag nicht gefunden.'], 404);

        $oldCatId = (int)$old['category_id'];
        $oldParentId = ($old['parent_id'] === null) ? null : (int)$old['parent_id'];

        if ($parentId !== null) {
            if ($parentId <= 0) json_out(['ok' => false, 'error' => 'Ungültiger Parent.'], 400);
            if ($parentId === $id) json_out(['ok' => false, 'error' => 'Parent darf nicht selbst sein.'], 400);

            $stmt = $checkconn->prepare("SELECT id, category_id FROM todo WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $parentId);
            $stmt->execute();
            $res = $stmt->get_result();
            $p = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$p) json_out(['ok' => false, 'error' => 'Parent nicht gefunden.'], 404);
            if ((int)$p['category_id'] !== $catId) json_out(['ok' => false, 'error' => 'Parent gehört zu anderer Kategorie.'], 400);
        }

        $checkconn->begin_transaction();
        try {
            $moved = ($catId !== $oldCatId)
                || (($parentId === null) !== ($oldParentId === null))
                || ($parentId !== null && $oldParentId !== null && $parentId !== $oldParentId);

            $newSort = null;
            if ($moved) {
                $max = 0.0;
                if ($parentId === null) {
                    $stmt = $checkconn->prepare("SELECT MAX(sort_key) AS m FROM todo WHERE category_id = ? AND parent_id IS NULL");
                    $stmt->bind_param('i', $catId);
                } else {
                    $stmt = $checkconn->prepare("SELECT MAX(sort_key) AS m FROM todo WHERE category_id = ? AND parent_id = ?");
                    $stmt->bind_param('ii', $catId, $parentId);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($r = $res->fetch_assoc()) && $r['m'] !== null) $max = (float)$r['m'];
                $stmt->close();

                $newSort = ($max > 0 ? $max + 1000.0 : 1000.0);
            }

            if ($parentId === null && $newSort === null) {
                $stmt = $checkconn->prepare("UPDATE todo SET category_id=?, parent_id=NULL, title=?, note=?, detail_note=? WHERE id=?");
                $stmt->bind_param('isssi', $catId, $title, $note, $detailNote, $id);
            } elseif ($parentId === null && $newSort !== null) {
                $stmt = $checkconn->prepare("UPDATE todo SET category_id=?, parent_id=NULL, title=?, note=?, detail_note=?, sort_key=? WHERE id=?");
                $stmt->bind_param('isssdi', $catId, $title, $note, $detailNote, $newSort, $id);
            } elseif ($parentId !== null && $newSort === null) {
                $stmt = $checkconn->prepare("UPDATE todo SET category_id=?, parent_id=?, title=?, note=?, detail_note=? WHERE id=?");
                $stmt->bind_param('iisssi', $catId, $parentId, $title, $note, $detailNote, $id);
            } else {
                $stmt = $checkconn->prepare("UPDATE todo SET category_id=?, parent_id=?, title=?, note=?, detail_note=?, sort_key=? WHERE id=?");
                $stmt->bind_param('iisssdi', $catId, $parentId, $title, $note, $detailNote, $newSort, $id);
            }

            $stmt->execute();
            $stmt->close();

            $checkconn->commit();

            $_SESSION['todo_fach'] = $fach;

            $stmt = $checkconn->prepare("
                SELECT t.id, k.name AS fach, t.category_id, t.parent_id, t.title, t.note, t.detail_note, t.done_at, t.sort_key
                FROM todo t
                JOIN todo_kategorien k ON k.id = t.category_id
                WHERE t.id = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            json_out(['ok' => true, 'todo' => $row]);
        } catch (Throwable $e) {
            $checkconn->rollback();
            json_out(['ok' => false, 'error' => 'DB-Fehler beim Speichern.'], 500);
        }
    }

    json_out(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
}

/* ---------------------- Counts for Header ---------------------- */
$openCount = 0;
$doneCount = 0;

$resCnt = $checkconn->query("
    SELECT
        SUM(done_at IS NULL)     AS open_c,
        SUM(done_at IS NOT NULL) AS done_c
    FROM todo
");
if ($resCnt && ($r = $resCnt->fetch_assoc())) {
    $openCount = (int)$r['open_c'];
    $doneCount = (int)$r['done_c'];
}

$headerCount = ($status === 'done') ? $doneCount : $openCount;
$headerLabel = ($status === 'done') ? 'erledigt' : 'offen';


/* ---------------------- Todos for JS ---------------------- */
$todos = [];
if ($status === 'done') {
    // "Erledigt"-Ansicht: alle erledigten Todos + Elternzeilen als Kontext, falls ein Unterpunkt erledigt ist
    $resT = $checkconn->query("
        SELECT
            t.id,
            k.name AS fach,
            t.category_id,
            t.parent_id,
            t.title,
            t.note,
            t.detail_note,
            t.done_at,
            t.sort_key
        FROM todo t
        JOIN todo_kategorien k ON k.id = t.category_id
        LEFT JOIN todo p ON p.id = t.parent_id
        WHERE k.is_active = 1
          AND (
                (t.parent_id IS NULL AND (
                    t.done_at IS NOT NULL
                    OR EXISTS (
                        SELECT 1
                        FROM todo c
                        WHERE c.parent_id = t.id
                          AND c.done_at IS NOT NULL
                    )
                ))
             OR (t.parent_id IS NOT NULL AND p.id IS NOT NULL AND t.done_at IS NOT NULL)
          )
        ORDER BY
            k.sort_order ASC,
            k.id ASC,
            (t.parent_id IS NULL) DESC,
            t.parent_id ASC,
            t.done_at DESC,
            t.sort_key ASC,
            t.id ASC
    ");
} else {
    // "Offen"-Ansicht: wie bisher (erledigte Top-Level bleiben 7 Tage, plus Eltern wenn Unterpunkt offen ist)
    $resT = $checkconn->query("
        SELECT
            t.id,
            k.name AS fach,
            t.category_id,
            t.parent_id,
            t.title,
            t.note,
            t.detail_note,
            t.done_at,
            t.sort_key
        FROM todo t
        JOIN todo_kategorien k ON k.id = t.category_id
        LEFT JOIN todo p ON p.id = t.parent_id
        WHERE k.is_active = 1
          AND (
                (t.parent_id IS NULL AND (
                    t.done_at IS NULL
                    OR t.done_at >= (NOW() - INTERVAL 7 DAY)
                    OR EXISTS (
                        SELECT 1
                        FROM todo c
                        WHERE c.parent_id = t.id
                          AND c.done_at IS NULL
                    )
                ))
             OR (t.parent_id IS NOT NULL AND p.id IS NOT NULL AND (
                    p.done_at IS NULL
                 OR t.done_at IS NULL
                ))
          )
        ORDER BY
            k.sort_order ASC,
            k.id ASC,
            (t.parent_id IS NULL) DESC,
            t.parent_id ASC,
            t.sort_key ASC,
            t.id ASC
    ");
}


if ($resT) {
    while ($r = $resT->fetch_assoc()) $todos[] = $r;
}

/* ---------------------- Child-Counts (für Badge done/total) ---------------------- */
$childCounts = []; // parent_id => ['total'=>int,'done'=>int]

$resCC = $checkconn->query("
    SELECT
        parent_id,
        COUNT(*) AS total_c,
        SUM(done_at IS NOT NULL) AS done_c
    FROM todo
    WHERE parent_id IS NOT NULL
    GROUP BY parent_id
");
if ($resCC) {
    while ($r = $resCC->fetch_assoc()) {
        $pid = (string)$r['parent_id'];
        $childCounts[$pid] = [
            'total' => (int)$r['total_c'],
            'done'  => (int)$r['done_c'],
        ];
    }
}


$page_title = 'ToDo-Liste';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div id="ltPage" class="lt-page dashboard-page">
    <div class="lt-topbar">
        <h1 class="ueberschrift dashboard-title">
            <span class="dashboard-title-main">ToDo-Liste</span>
            <span class="dashboard-title-soft">
                | <span id="ltStatusCount"><?= htmlspecialchars((string)$headerCount, ENT_QUOTES, 'UTF-8') ?></span>
                <span id="ltStatusLabel"><?= htmlspecialchars((string)$headerLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </span>
        </h1>
        <form method="get" id="statusForm" class="dashboard-filterform">
            <div class="lt-yearwrap">
                <label for="ltStatus" class="lt-label">Status</label>
                <select id="ltStatus" name="status" class="kategorie-select" onchange="this.form.submit()">
                <option value="open" <?= ($status === 'open' ? 'selected' : '') ?>>Offen</option>
                <option value="done" <?= ($status === 'done' ? 'selected' : '') ?>>Erledigt</option>
                </select>
            </div>
        </form>
    </div>

    <div class="lt-subject-row">
        <div id="ltTabs" class="lt-tabs" role="tablist" aria-label="Kategorien"></div>

        <button id="ltAddBtn" class="lt-add-btn" type="button" title="Todo hinzufügen" aria-label="Todo hinzufügen">
            <span class="lt-add-plus">+</span>
        </button>
    </div>

    <div class="lt-table-wrap">
        <table class="lt-table">
            <thead>
                <tr>
                    <th class="lt-col-drag"></th>
                    <th class="lt-col-exp"></th>
                    <th>ToDo</th>
                    <th class="lt-col-sub"></th>
                    <th class="lt-col-check">Erledigt</th>
                </tr>
            </thead>
            <tbody id="ltTbody"></tbody>
        </table>
    </div>
</div>

<div id="ltModal" class="modal hidden" aria-hidden="true">
    <div class="modal-content lt-modal-content" role="dialog" aria-modal="true" aria-labelledby="ltModalTitle">
        <span class="close-button" id="ltModalClose" title="Schließen">&times;</span>
        <h2 id="ltModalTitle" class="lt-modal-title">Neues Todo</h2>

        <div class="form-block">
            <input type="hidden" id="ltEditId" value="">

            <div class="input-group-dropdown">
                <label for="ltNewFach">Kategorie</label>
                <select id="ltNewFach">
                    <?php foreach ($SUBJECTS as $name => $meta): ?>
                        <option value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group-dropdown">
                <label for="ltNewParent">Unterpunkt von</label>
                <select id="ltNewParent"></select>
            </div>

            <div class="input-group-dropdown">
                <label for="ltNewTitel">Titel</label>
                <input id="ltNewTitel" type="text" placeholder="z.B. Einkaufsliste schreiben">
            </div>

            <div class="input-group-dropdown">
                <label for="ltNewNotiz">Notiz</label>
                <textarea id="ltNewNotiz" rows="2" placeholder="optional"></textarea>
            </div>
            
            <div class="input-group-dropdown">
                <label for="ltNewDetailNotiz">Details</label>
                <textarea id="ltNewDetailNotiz" rows="5" placeholder="optional"></textarea>
            </div>

            <button id="ltSaveNew" type="button">Speichern</button>
        </div>
    </div>
</div>

<style>
  #ltNewNotiz{
    resize: none;           
    overflow-y: auto;       
    overflow-wrap: anywhere;
    word-break: break-word;
  }
  #ltNewDetailNotiz{
    resize: none;           
    overflow-y: auto;
    overflow-wrap: anywhere;
    word-break: break-word;
  }
</style>

<script>
(() => {
    const SUBJECTS = <?= json_encode($SUBJECTS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const TASKS = <?= json_encode($todos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const CHILD_COUNTS = <?= json_encode($childCounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const phpDefaultFach = <?= json_encode($sessionFach, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const elPage = document.getElementById('ltPage');
    const elTabs = document.getElementById('ltTabs');
    const elTbody = document.getElementById('ltTbody');

    const elStatus = document.getElementById('ltStatus');
    const elStatusCount = document.getElementById('ltStatusCount');
    const elStatusLabel = document.getElementById('ltStatusLabel');

    let viewStatus = (elStatus && elStatus.value) ? String(elStatus.value) : 'open';
    if (viewStatus !== 'done') viewStatus = 'open';

    const elModal = document.getElementById('ltModal');
    const elModalClose = document.getElementById('ltModalClose');
    const elAddBtn = document.getElementById('ltAddBtn');

    const elEditId = document.getElementById('ltEditId');
    const elNewFach = document.getElementById('ltNewFach');
    const elNewParent = document.getElementById('ltNewParent');
    const elNewTitel = document.getElementById('ltNewTitel');
    const elNewNotiz = document.getElementById('ltNewNotiz');
    const elNewDetailNotiz = document.getElementById('ltNewDetailNotiz');
    const elSaveNew = document.getElementById('ltSaveNew');

    const validSubjects = new Set(Object.keys(SUBJECTS));

    function subjectColor(fach) {
        return SUBJECTS?.[String(fach ?? '')]?.color || '#999';
    }

    function setAccentForSubject(fach) {
        elPage.style.setProperty('--lt-accent', subjectColor(fach));
    }

    function pickTextColor(hex) {
        const m = String(hex).trim().match(/^#?([0-9a-f]{6})$/i);
        if (!m) return '#fff';
        const n = parseInt(m[1], 16);
        const r = (n >> 16) & 255;
        const g = (n >> 8) & 255;
        const b = n & 255;

        const srgb = [r, g, b].map(v => {
            const x = v / 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        });
        const L = 0.2126 * srgb[0] + 0.7152 * srgb[1] + 0.0722 * srgb[2];
        return L > 0.55 ? '#111' : '#fff';
    }

    function hexToRgba(hex, a = 0.12) {
        const m = String(hex).trim().match(/^#?([0-9a-f]{6})$/i);
        if (!m) return `rgba(0,0,0,${a})`;
        const n = parseInt(m[1], 16);
        const r = (n >> 16) & 255;
        const g = (n >> 8) & 255;
        const b = n & 255;
        return `rgba(${r},${g},${b},${a})`;
    }

    function post(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
        return fetch(location.pathname + location.search, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(r => r.json());
    }

    let selectedFach = localStorage.getItem('todo_fach') || phpDefaultFach;
    if (!validSubjects.has(selectedFach)) selectedFach = phpDefaultFach;

    const expanded = new Set();
    try {
        const raw = localStorage.getItem('todo_expanded');
        if (raw) JSON.parse(raw).forEach(x => expanded.add(String(x)));
    } catch (_) {}
    function saveExpanded() {
        try { localStorage.setItem('todo_expanded', JSON.stringify([...expanded])); } catch (_) {}
    }

    function applyTodoUpdate(updated) {
        const id = Number(updated.id);
        const idx = TASKS.findIndex(t => Number(t.id) === id);
        if (idx >= 0) TASKS[idx] = { ...TASKS[idx], ...updated };
        else TASKS.push(updated);
    }

    async function updateCounts() {
        try {
            const res = await post('get_counts', {});
            if (res && res.ok) {
                const open = (typeof res.open !== 'undefined') ? Number(res.open) : 0;
                const done = (typeof res.done !== 'undefined') ? Number(res.done) : 0;

                if (viewStatus === 'done') {
                    if (elStatusCount) elStatusCount.textContent = String(done);
                    if (elStatusLabel) elStatusLabel.textContent = 'erledigt';
                } else {
                    if (elStatusCount) elStatusCount.textContent = String(open);
                    if (elStatusLabel) elStatusLabel.textContent = 'offen';
                }
            }
        } catch (_) {}
    }


    function sortSiblings(a, b) {
        const da = !!(a.done_at && a.done_at !== 'pending');
        const db = !!(b.done_at && b.done_at !== 'pending');
        if (da !== db) return da ? 1 : -1;

        const sa = Number(a.sort_key ?? 0);
        const sb = Number(b.sort_key ?? 0);
        if (sa !== sb) return sa - sb;

        return Number(a.id) - Number(b.id);
    }

    function todoState(t) {
        const v = t?.done_at;
        if (!v) return 'open';
        if (v === 'pending') return 'pending';
        return 'done';
    }

    function parseMysqlDateLocal(v) {
        if (!v || v === 'pending') return null;
        const s = String(v).trim();
        const m = s.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.\d+)?$/);
        if (!m) return null;
        return new Date(`${m[1]}T${m[2]}`);
    }

    function filterTasksForView(list) {
        const byId = new Map(list.map(t => [String(t.id), t]));
        const kids = new Map();
        for (const t of list) {
            if (t.parent_id == null) continue;
            const p = String(t.parent_id);
            if (!kids.has(p)) kids.set(p, []);
            kids.get(p).push(t);
        }

        const included = new Set();

        if (viewStatus === 'done') {
            // Root: erledigt ODER hat erledigten Unterpunkt (Kontext)
            for (const t of list) {
                if (t.parent_id != null) continue;
                const id = String(t.id);
                const st = todoState(t);
                const ch = kids.get(id) || [];
                const hasDoneChild = ch.some(c => todoState(c) !== 'open');
                if (st !== 'open' || hasDoneChild) included.add(id);
            }
            // Child: nur erledigte Unterpunkte, wenn Parent vorhanden
            for (const t of list) {
                if (t.parent_id == null) continue;
                if (todoState(t) === 'open') continue;
                const pid = String(t.parent_id);
                if (!byId.has(pid)) continue;
                included.add(String(t.id));
                included.add(pid);
            }
        } else {
            // Offen-Ansicht: erledigte Root bleiben 7 Tage sichtbar (wie bisher)
            const now = Date.now();
            const weekMs = 7 * 24 * 60 * 60 * 1000;

            const doneWithinWeek = (t) => {
                const st = todoState(t);
                if (st === 'open') return false;
                if (st === 'pending') return true;
                const d = parseMysqlDateLocal(t.done_at);
                if (!d) return true;
                return (now - d.getTime()) <= weekMs;
            };

            for (const t of list) {
                if (t.parent_id != null) continue;
                const id = String(t.id);
                const st = todoState(t);

                if (st === 'open' || st === 'pending') { included.add(id); continue; }
                if (doneWithinWeek(t)) { included.add(id); continue; }

                const ch = kids.get(id) || [];
                if (ch.some(c => todoState(c) === 'open')) { included.add(id); continue; }
            }

            for (const t of list) {
                if (t.parent_id == null) continue;
                const pid = String(t.parent_id);
                const p = byId.get(pid);
                if (!p) continue;

                const pOpen = (todoState(p) === 'open');
                const tOpen = (todoState(t) === 'open');

                if (pOpen || tOpen) {
                    included.add(String(t.id));
                    included.add(pid);
                }
            }
        }

        return list.filter(t => included.has(String(t.id)));
    }

    function buildTreeForSubject(fach) {
        const raw = TASKS.filter(t => String(t.fach) === String(fach));

        // Max erledigt-ts pro Parent (nur für DONE-Ansicht + Parent-Kontext-Zeilen)
        const maxDoneByParent = new Map(); // parentIdStr -> ms
        for (const t of raw) {
            if (t.parent_id == null) continue;
            if (todoState(t) !== 'done') continue;
            const d = parseMysqlDateLocal(t.done_at);
            const ts = d ? d.getTime() : 0;
            const pid = String(t.parent_id);
            const cur = maxDoneByParent.get(pid) || 0;
            if (ts > cur) maxDoneByParent.set(pid, ts);
        }

        const effectiveDoneTs = (t) => {
            const st = todoState(t);
            if (st === 'done') {
                const d = parseMysqlDateLocal(t.done_at);
                return d ? d.getTime() : 0;
            }
            // Parent-Kontext in DONE-Ansicht: nutze "letzter Abschluss" aus Kindern
            if (viewStatus === 'done' && t.parent_id == null) {
                return maxDoneByParent.get(String(t.id)) || 0;
            }
            return 0;
        };

        const cmp = (a, b) => {
            if (viewStatus === 'done') {
                const ta = effectiveDoneTs(a);
                const tb = effectiveDoneTs(b);
                if (ta !== tb) return tb - ta; // neueste zuerst

                // Stabiler Fallback
                const sa = Number(a.sort_key ?? 0);
                const sb = Number(b.sort_key ?? 0);
                if (sa !== sb) return sa - sb;
                return Number(a.id) - Number(b.id);
            }

            // OPEN-Ansicht: bisheriges Verhalten
            const da = (todoState(a) === 'done');
            const db = (todoState(b) === 'done');
            if (da !== db) return da ? 1 : -1;

            const sa = Number(a.sort_key ?? 0);
            const sb = Number(b.sort_key ?? 0);
            if (sa !== sb) return sa - sb;

            return Number(a.id) - Number(b.id);
        };

        // list: gefilterte Todos für die aktuell gewählte View (open/done)
        const list = filterTasksForView(raw);

        const kids = new Map(); // parentIdStr -> array (gefilterte Kinder)
        const roots = [];

        for (const t of list) {
            const p = (t.parent_id == null) ? null : String(t.parent_id);
            if (p === null) roots.push(t);
            else {
                if (!kids.has(p)) kids.set(p, []);
                kids.get(p).push(t);
            }
        }

        roots.sort(cmp);
        for (const [p, arr] of kids.entries()) arr.sort(cmp);

        return { kids, roots, maxDoneByParent, effectiveDoneTs };
    }



    function rebuildParentOptions(fach, selectedParentId = '0', excludeId = null) {
        elNewParent.innerHTML = '';
        const optNone = document.createElement('option');
        optNone.value = '0';
        optNone.textContent = '— (Top-Level)';
        elNewParent.appendChild(optNone);

        const { roots } = buildTreeForSubject(fach);
        for (const t of roots) {
            if (excludeId != null && String(t.id) === String(excludeId)) continue;
            const opt = document.createElement('option');
            opt.value = String(t.id);
            opt.textContent = t.title || `(Todo #${t.id})`;
            elNewParent.appendChild(opt);
        }

        elNewParent.value = String(selectedParentId ?? '0');
        if (!elNewParent.value) elNewParent.value = '0';
    }

    function openModal(focusEl = null) {
        elModal.classList.remove('hidden');
        elModal.setAttribute('aria-hidden', 'false');
        setTimeout(() => {
            const el = focusEl || elNewTitel;
            if (el && typeof el.focus === 'function') el.focus();
        }, 50);
    }

    function closeModal() {
        elModal.classList.add('hidden');
        elModal.setAttribute('aria-hidden', 'true');
    }

    function openNewModal(parentPref = '0') {
        elEditId.value = '';
        document.getElementById('ltModalTitle').textContent = 'Neues Todo';

        elNewFach.value = selectedFach;
        rebuildParentOptions(selectedFach, parentPref, null);

        elNewTitel.value = '';
        elNewNotiz.value = '';
        elNewDetailNotiz.value = '';

        openModal(elNewTitel);
    }

    function openEditModal(todo) {
        elEditId.value = String(todo.id);
        document.getElementById('ltModalTitle').textContent = 'Todo bearbeiten';

        selectedFach = String(todo.fach);
        localStorage.setItem('todo_fach', selectedFach);

        elNewFach.value = selectedFach;
        rebuildParentOptions(selectedFach, (todo.parent_id == null ? '0' : String(todo.parent_id)), String(todo.id));

        elNewTitel.value = todo.title ?? '';
        elNewNotiz.value = todo.note ?? '';
        elNewDetailNotiz.value = todo.detail_note ?? '';

        setAccentForSubject(selectedFach);
        renderTabs();
        renderTable();

        openModal(elNewTitel);
    }

    elAddBtn.addEventListener('click', () => openNewModal('0'));
    elModalClose.addEventListener('click', closeModal);
    elModal.addEventListener('click', (e) => { if (e.target === elModal) closeModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !elModal.classList.contains('hidden')) closeModal();
    });

    elNewFach.addEventListener('change', () => {
        const fach = String(elNewFach.value || '');
        if (!validSubjects.has(fach)) return;
        selectedFach = fach;
        localStorage.setItem('todo_fach', selectedFach);
        setAccentForSubject(selectedFach);
        renderTabs();
        renderTable();
        rebuildParentOptions(selectedFach, '0', (elEditId.value || '').trim() || null);
        try { post('set_fach', { fach: selectedFach }); } catch (_) {}
    });

    function renderTabs() {
        elTabs.innerHTML = '';

        Object.keys(SUBJECTS).forEach((fach) => {
            const btn = document.createElement('button');
            const c = subjectColor(fach);
            btn.style.setProperty('--tab-accent', c);
            btn.style.setProperty('--tab-accent-text', pickTextColor(c));

            btn.type = 'button';
            btn.className = 'lt-tab' + (fach === selectedFach ? ' active' : '');
            btn.textContent = fach;
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-selected', fach === selectedFach ? 'true' : 'false');

            btn.addEventListener('click', async () => {
                if (fach === selectedFach) return;
                selectedFach = fach;
                localStorage.setItem('todo_fach', selectedFach);
                setAccentForSubject(selectedFach);
                renderTabs();
                renderTable();
                elNewFach.value = selectedFach;
                rebuildParentOptions(selectedFach, '0', (elEditId.value || '').trim() || null);

                try { await post('set_fach', { fach: selectedFach }); } catch (e) {}
            });

            elTabs.appendChild(btn);
        });
    }

    function makeCheckbox(todo) {
        const wrap = document.createElement('div');
        wrap.className = 'lt-check';

        const id = `ltDone_${todo.id}`;

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.id = id;
        input.checked = todoState(todo) !== 'open';

        const label = document.createElement('label');
        label.htmlFor = id;
        label.title = input.checked ? 'Erledigt (klicken zum Zurücksetzen)' : 'Als erledigt markieren';

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.classList.add('lt-checkmark');

        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', 'M20 6L9 17l-5-5');
        svg.appendChild(path);
        label.appendChild(svg);

        input.addEventListener('change', async () => {
            input.disabled = true;
            const newChecked = input.checked;
            const wasDone = (todoState(todo) === 'done');
            const pid = (todo.parent_id == null) ? null : String(todo.parent_id);


            todo.done_at = newChecked ? 'pending' : null;
            updateRowState(todo.id);

            try {
                const res = await post('toggle_done', { id: String(todo.id), checked: newChecked ? '1' : '0' });
                if (!res || !res.ok || !res.todo) throw new Error('toggle_failed');

                applyTodoUpdate(res.todo);

                const nowDone = (todoState(res.todo) === 'done');

                if (pid !== null) {
                    if (!CHILD_COUNTS[pid]) CHILD_COUNTS[pid] = { total: 0, done: 0 };
                    if (wasDone !== nowDone) {
                        CHILD_COUNTS[pid].done = Math.max(0, Number(CHILD_COUNTS[pid].done || 0) + (nowDone ? 1 : -1));
                    }
                }

                input.checked = (todoState(res.todo) !== 'open');
                input.disabled = false;

                updateRowState(todo.id);
                updateCounts();
                renderTable();
            } catch (_) {
                input.checked = !newChecked;
                todo.done_at = input.checked ? 'pending' : null;
                input.disabled = false;
                updateRowState(todo.id);
            }
        });

        wrap.appendChild(input);
        wrap.appendChild(label);
        return wrap;
    }

    function updateRowState(todoId) {
        const row = document.querySelector(`tr[data-id="${todoId}"]`);
        if (!row) return;
        const t = TASKS.find(x => Number(x.id) === Number(todoId));
        const isDone = !!(t && t.done_at && t.done_at !== 'pending');
        const isPending = !!(t && t.done_at === 'pending');
        row.classList.toggle('done', isDone);
        row.classList.toggle('pending', isPending);
    }

    let dragAllowed = false;
    let draggingRow = null;
    let draggingId = null;
    let dragStartIndex = null;

    const elTableWrap = document.querySelector('.lt-table-wrap');
    const elNavbar = document.querySelector('.navbar');
    let dragClientX = 0;
    let dragClientY = 0;
    let autoScrollRaf = 0;
    let autoScrollVel = 0;

    const AUTO_SCROLL_MARGIN = 90;
    const AUTO_SCROLL_MAX = 26;

    function clamp(n, lo, hi) { return Math.max(lo, Math.min(hi, n)); }

    function getScrollTarget() {
        if (elTableWrap && elTableWrap.scrollHeight > elTableWrap.clientHeight + 1) return elTableWrap;
        return document.scrollingElement || document.documentElement;
    }

    function navbarBottomPx() {
        return elNavbar ? Math.max(0, elNavbar.getBoundingClientRect().bottom) : 0;
    }

    function setDragPointer(e) {
        dragClientX = e.clientX;
        dragClientY = e.clientY;
    }

    function stopAutoScroll() {
        if (autoScrollRaf) cancelAnimationFrame(autoScrollRaf);
        autoScrollRaf = 0;
        autoScrollVel = 0;
    }

    function updateAutoScrollFromPointer() {
        if (!draggingRow) return stopAutoScroll();

        const target = getScrollTarget();
        const docScroll = (document.scrollingElement || document.documentElement);

        const nb = navbarBottomPx();
        let topEdge = nb;
        let bottomEdge = window.innerHeight;

        if (target !== docScroll) {
            const r = target.getBoundingClientRect();
            topEdge = Math.max(r.top, nb);
            bottomEdge = r.bottom;
        }

        let v = 0;
        if (dragClientY < topEdge + AUTO_SCROLL_MARGIN) {
            const t = (topEdge + AUTO_SCROLL_MARGIN - dragClientY) / AUTO_SCROLL_MARGIN;
            v = -AUTO_SCROLL_MAX * clamp(t, 0, 1);
        } else if (dragClientY > bottomEdge - AUTO_SCROLL_MARGIN) {
            const t = (dragClientY - (bottomEdge - AUTO_SCROLL_MARGIN)) / AUTO_SCROLL_MARGIN;
            v = AUTO_SCROLL_MAX * clamp(t, 0, 1);
        }

        autoScrollVel = Math.trunc(v);
        if (autoScrollVel !== 0 && !autoScrollRaf) autoScrollRaf = requestAnimationFrame(autoScrollTick);
        if (autoScrollVel === 0) stopAutoScroll();
    }

    function autoScrollTick() {
        autoScrollRaf = 0;
        if (!draggingRow || autoScrollVel === 0) return;

        const target = getScrollTarget();
        const docScroll = (document.scrollingElement || document.documentElement);

        if (target === docScroll) window.scrollBy(0, autoScrollVel);
        else {
            const max = Math.max(0, target.scrollHeight - target.clientHeight);
            target.scrollTop = clamp(target.scrollTop + autoScrollVel, 0, max);
        }

        const el = document.elementFromPoint(dragClientX, dragClientY);
        const row = el && el.closest ? el.closest('tr.lt-row') : null;
        if (row && elTbody.contains(row)) hoverReorder(row, dragClientY);

        autoScrollRaf = requestAnimationFrame(autoScrollTick);
    }

    document.addEventListener('dragover', (e) => {
        if (!draggingRow) return;
        setDragPointer(e);
        updateAutoScrollFromPointer();
        e.preventDefault();
    }, { capture: true, passive: false });

    document.addEventListener('drop', () => stopAutoScroll(), true);
    document.addEventListener('dragend', () => stopAutoScroll(), true);

    function clearDropMarkers() {
        elTbody.querySelectorAll('tr.lt-row').forEach(r => r.classList.remove('lt-drop-before', 'lt-drop-after'));
    }

    function hoverReorder(targetRow, clientY) {
        if (!draggingRow || !targetRow || targetRow === draggingRow) return;
        if (String(targetRow.dataset.parent || '0') !== String(draggingRow.dataset.parent || '0')) return;

        clearDropMarkers();

        const rect = targetRow.getBoundingClientRect();
        const before = clientY < rect.top + rect.height / 2;

        if (before) elTbody.insertBefore(draggingRow, targetRow);
        else elTbody.insertBefore(draggingRow, targetRow.nextSibling);
    }

    async function commitSortIfChanged() {
        if (!draggingId || !draggingRow) return;

        const parentKey = String(draggingRow.dataset.parent || '0');
        const rows = [...elTbody.querySelectorAll(`tr.lt-row[data-parent="${CSS.escape(parentKey)}"]`)];
        const endIndex = rows.findIndex(r => r.dataset.id === String(draggingId));

        if (dragStartIndex === null || endIndex < 0 || endIndex === dragStartIndex) return;

        const prevId = endIndex > 0 ? rows[endIndex - 1].dataset.id : null;
        const nextId = endIndex < rows.length - 1 ? rows[endIndex + 1].dataset.id : null;

        const prevT = prevId ? TASKS.find(t => String(t.id) === String(prevId)) : null;
        const nextT = nextId ? TASKS.find(t => String(t.id) === String(nextId)) : null;

        const prevSort = prevT ? Number(prevT.sort_key ?? 0) : null;
        const nextSort = nextT ? Number(nextT.sort_key ?? 0) : null;

        let newSort;
        if (prevSort === null && nextSort === null) newSort = 1000;
        else if (prevSort === null) newSort = nextSort - 1000;
        else if (nextSort === null) newSort = prevSort + 1000;
        else newSort = (prevSort + nextSort) / 2;

        const sortStr = Number(newSort).toFixed(10);

        try {
            const res = await post('update_sort', { id: String(draggingId), sort_key: sortStr });
            if (!res || !res.ok) throw new Error('update_sort_failed');

            const idx = TASKS.findIndex(t => String(t.id) === String(draggingId));
            if (idx >= 0) TASKS[idx].sort_key = sortStr;

            renderTable();
        } catch (_) {
            renderTable();
        }
    }

    function renderTable() {
        elTbody.innerHTML = '';

        const { kids, roots, maxDoneByParent, effectiveDoneTs } = buildTreeForSubject(selectedFach);

        if (!roots.length) {
            const tr = document.createElement('tr');
            tr.className = 'lt-empty';
            const td = document.createElement('td');
            td.colSpan = 5;
            td.textContent = 'Noch keine Todos in dieser Kategorie.';
            tr.appendChild(td);
            elTbody.appendChild(tr);
            setAccentForSubject(selectedFach);
            return;
        }

        const renderOne = (todo, depth) => {
            const idStr = String(todo.id);
            const parentKey = (todo.parent_id == null) ? '0' : String(todo.parent_id);

            const children = kids.get(idStr) || [];
            const hasKids = children.length > 0;
            const isOpen = expanded.has(idStr);

            const tr = document.createElement('tr');
            if (depth > 0) {
                tr.style.setProperty('--lt-child-bg', hexToRgba(subjectColor(selectedFach), 0.12));
            }
            tr.dataset.id = idStr;
            tr.dataset.parent = parentKey;
            const st = todoState(todo);
            tr.className =
                'lt-row' +
                (st === 'done' ? ' done' : '') +
                (st === 'pending' ? ' pending' : '') +
                (depth > 0 ? ' lt-child' : '');

            tr.draggable = true;

            const tdDrag = document.createElement('td');
            tdDrag.className = 'lt-dragcell';

            const grip = document.createElement('div');
            grip.innerHTML = '&#8942;&#8942;';
            tdDrag.appendChild(grip);

            tdDrag.addEventListener('pointerdown', () => { dragAllowed = true; });
            const disableDragAllowed = () => { dragAllowed = false; };
            tdDrag.addEventListener('pointerup', disableDragAllowed);
            tdDrag.addEventListener('pointercancel', disableDragAllowed);
            tdDrag.addEventListener('pointerleave', disableDragAllowed);
            tdDrag.addEventListener('click', (e) => e.stopPropagation());

            tr.addEventListener('dragstart', (e) => {
                if (!dragAllowed) { e.preventDefault(); return; }
                dragAllowed = false;

                draggingRow = tr;
                draggingId = tr.dataset.id;

                const pKey = String(tr.dataset.parent || '0');
                dragStartIndex = [...elTbody.querySelectorAll(`tr.lt-row[data-parent="${CSS.escape(pKey)}"]`)]
                    .findIndex(r => r.dataset.id === String(draggingId));

                tr.classList.add('lt-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', draggingId);

                setDragPointer(e);
                updateAutoScrollFromPointer();
            });

            tr.addEventListener('dragover', (e) => {
                if (!draggingRow || tr === draggingRow) return;
                e.preventDefault();
                setDragPointer(e);
                updateAutoScrollFromPointer();
                hoverReorder(tr, e.clientY);
            });

            tr.addEventListener('drop', (e) => { e.preventDefault(); clearDropMarkers(); });

            tr.addEventListener('dragend', async () => {
                tr.classList.remove('lt-dragging');
                clearDropMarkers();
                stopAutoScroll();

                await commitSortIfChanged();

                draggingRow = null;
                draggingId = null;
                dragStartIndex = null;
            });

            const toggleExpand = (e) => {
                e.stopPropagation();
                if (!hasKids) return;
                if (expanded.has(idStr)) expanded.delete(idStr);
                else expanded.add(idStr);
                saveExpanded();
                renderTable();
            };

            const addSub = (e) => {
                e.stopPropagation();
                expanded.add(idStr);
                saveExpanded();
                openNewModal(idStr);
            };

            const tdExp = document.createElement('td');
            tdExp.className = 'lt-expcell';

            const expBtn = document.createElement('span');
            expBtn.className = 'lt-todo-expbtn' + (hasKids ? '' : ' is-hidden') + (isOpen ? ' open' : '');
            expBtn.innerHTML = '&#9656;';
            expBtn.setAttribute('role', 'button');
            expBtn.setAttribute('aria-label', 'Auf-/Zuklappen');
            expBtn.tabIndex = hasKids ? 0 : -1;
            expBtn.addEventListener('click', toggleExpand);
            expBtn.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleExpand(e);
                }
            });

            tdExp.appendChild(expBtn);

            const tdLeft = document.createElement('td');
            tdLeft.className = 'lt-left';

            const main = document.createElement('div');
            main.className = 'lt-task';

            const row = document.createElement('div');
            row.className = 'lt-task-row';

            const left = document.createElement('div');
            left.className = 'lt-task-left';

            const top = document.createElement('div');
            top.className = 'lt-task-top';

            const title = document.createElement('span');
            title.className = 'lt-title';
            title.textContent = todo.title ?? '';

            if (depth === 0) {
                const cc = CHILD_COUNTS[idStr] || null;
                if (cc && Number(cc.total) > 0) {
                    const total = Number(cc.total) || 0;
                    const done  = Number(cc.done)  || 0;

                    const badge = document.createElement('span');
                    badge.className = 'lt-badge';
                    badge.textContent = `${done}/${total}`;
                    title.appendChild(badge);
                }
            }

            top.appendChild(title);

            const meta = document.createElement('div');
            meta.className = 'lt-meta';

            const note = String(todo.note ?? '').trim();
            if (note) {
                const noteEl = document.createElement('div');
                noteEl.className = 'lt-note';
                noteEl.textContent = note;
                meta.appendChild(noteEl);
            }

            left.appendChild(top);
            if (meta.childNodes.length > 0) left.appendChild(meta);

            row.appendChild(left);

            // Rechts: "Erledigt am" (DONE) bzw. "Letzter Abschluss" (Parent-Kontext ohne done_at)
            if (viewStatus === 'done') {
                const ts = effectiveDoneTs(todo);
                if (ts > 0) {
                    const right = document.createElement('div');
                    right.className = 'lt-task-right';

                    const label = document.createElement('span');
                    label.className = 'lt-doneat-label';
                    label.textContent = (todoState(todo) === 'done') ? 'Erledigt:' : 'Zuletzt:';

                    const val = document.createElement('span');
                    val.className = 'lt-doneat-value';
                    val.textContent = new Date(ts).toLocaleString('de-DE', {
                        day: '2-digit', month: '2-digit', year: 'numeric',
                        hour: '2-digit', minute: '2-digit'
                    });

                    right.appendChild(label);
                    right.appendChild(val);
                    row.appendChild(right);
                }
            }

            main.appendChild(row);
            tdLeft.appendChild(main);

            const tdSub = document.createElement('td');
            tdSub.className = 'lt-subcell';

            if (depth === 0) {
                const subBtn = document.createElement('span');
                subBtn.className = 'lt-todo-subbtn';
                subBtn.textContent = '+';
                subBtn.setAttribute('role', 'button');
                subBtn.setAttribute('aria-label', 'Unterpunkt hinzufügen');
                subBtn.tabIndex = 0;
                subBtn.addEventListener('click', addSub);
                subBtn.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        addSub(e);
                    }
                });
                tdSub.appendChild(subBtn);
            }

            const tdRight = document.createElement('td');
            tdRight.className = 'lt-right';
            tdRight.appendChild(makeCheckbox(todo));

            tr.appendChild(tdDrag);
            tr.appendChild(tdExp);
            tr.appendChild(tdLeft);
            tr.appendChild(tdSub);
            tr.appendChild(tdRight);

            tr.addEventListener('click', (e) => {
                if (e.target.closest('.lt-check')) return;
                if (e.target.closest('.lt-dragcell')) return;
                if (e.target.closest('.lt-todo-expbtn')) return;
                if (e.target.closest('.lt-todo-subbtn')) return;
                openEditModal(todo);
            });

            elTbody.appendChild(tr);

            if (hasKids && isOpen) {
                children.forEach(ch => renderOne(ch, depth + 1));
            }
        };


        roots.forEach(t => renderOne(t, 0));
        setAccentForSubject(selectedFach);
    }

    elSaveNew.addEventListener('click', async () => {
        const editId = (elEditId.value || '').trim();

        const fach = String(elNewFach.value || '');
        const parent_id = String(elNewParent.value || '0');
        const title = elNewTitel.value.trim();
        const note = elNewNotiz.value ?? '';
        const detail_note = elNewDetailNotiz.value ?? '';

        if (!validSubjects.has(fach)) return;
        if (!title) return;

        elSaveNew.disabled = true;

        const before = editId ? TASKS.find(t => String(t.id) === String(editId)) : null;
        const beforePid = (before && before.parent_id != null) ? String(before.parent_id) : null;
        const beforeDone = before ? (todoState(before) === 'done') : false;

        try {
            if (!editId) {
                const res = await post('add_todo', { fach, parent_id, title, note, detail_note });
                if (!res || !res.ok || !res.todo) throw new Error('add_failed');

                applyTodoUpdate(res.todo);

                const afterPid = (res.todo.parent_id != null) ? String(res.todo.parent_id) : null;
                if (afterPid !== null) {
                    if (!CHILD_COUNTS[afterPid]) CHILD_COUNTS[afterPid] = { total: 0, done: 0 };
                    CHILD_COUNTS[afterPid].total = Number(CHILD_COUNTS[afterPid].total || 0) + 1;
                }


                selectedFach = String(res.todo.fach);
                localStorage.setItem('todo_fach', selectedFach);

                if (res.todo.parent_id != null) {
                    expanded.add(String(res.todo.parent_id));
                    saveExpanded();
                }
            } else {
                const res = await post('update_todo', { id: editId, fach, parent_id, title, note, detail_note });
                if (!res || !res.ok || !res.todo) throw new Error('update_failed');

                applyTodoUpdate(res.todo);

                const afterPid = (res.todo.parent_id != null) ? String(res.todo.parent_id) : null;
                const afterDone = (todoState(res.todo) === 'done');

                if (beforePid !== afterPid) {
                    // aus altem Parent raus
                    if (beforePid !== null && CHILD_COUNTS[beforePid]) {
                        CHILD_COUNTS[beforePid].total = Math.max(0, Number(CHILD_COUNTS[beforePid].total || 0) - 1);
                        if (beforeDone) {
                            CHILD_COUNTS[beforePid].done = Math.max(0, Number(CHILD_COUNTS[beforePid].done || 0) - 1);
                        }
                    }
                    // in neuen Parent rein
                    if (afterPid !== null) {
                        if (!CHILD_COUNTS[afterPid]) CHILD_COUNTS[afterPid] = { total: 0, done: 0 };
                        CHILD_COUNTS[afterPid].total = Number(CHILD_COUNTS[afterPid].total || 0) + 1;
                        if (afterDone) {
                            CHILD_COUNTS[afterPid].done = Number(CHILD_COUNTS[afterPid].done || 0) + 1;
                        }
                    }
                } else if (afterPid !== null) {
                    // Parent gleich, nur done-status ggf. geändert
                    if (!CHILD_COUNTS[afterPid]) CHILD_COUNTS[afterPid] = { total: 0, done: 0 };
                    if (beforeDone !== afterDone) {
                        CHILD_COUNTS[afterPid].done = Math.max(0, Number(CHILD_COUNTS[afterPid].done || 0) + (afterDone ? 1 : -1));
                    }
                }

                selectedFach = String(res.todo.fach);
                localStorage.setItem('todo_fach', selectedFach);

                if (res.todo.parent_id != null) {
                    expanded.add(String(res.todo.parent_id));
                    saveExpanded();
                }
            }

            updateCounts();
            setAccentForSubject(selectedFach);
            renderTabs();
            rebuildParentOptions(selectedFach, '0', (elEditId.value || '').trim() || null);
            renderTable();
            closeModal();
        } catch (_) {
        } finally {
            elSaveNew.disabled = false;
        }
    });

    function init() {
        setAccentForSubject(selectedFach);
        renderTabs();
        rebuildParentOptions(selectedFach, '0', null);
        updateCounts();
        renderTable();
    }

    init();
})();
</script>

</body>
</html>
