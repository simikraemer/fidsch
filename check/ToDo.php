<?php
// check/ToDo.php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

/**
 * Erwartung: db.php stellt $checkconn bereit (mysqli) und verbindet auf DB `check`.
 * Falls du das noch nicht hast: in db.php analog zu $sciconn hinzufügen.
 */
$checkconn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ---------------------- Helpers ---------------------- */
function json_out(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function parse_int($v, int $default = 0): int {
    if (!isset($v)) return $default;
    if (is_numeric($v)) return (int)$v;
    return $default;
}

/* ---------------------- Kategorien laden ---------------------- */
$CATS = []; // id => ['id'=>int,'name'=>string,'color'=>string,'sort_order'=>int]
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
    $id = (int)$r['id'];
    $CATS[$id] = [
        'id' => $id,
        'name' => (string)$r['name'],
        'color' => (string)$r['color'],
        'sort_order' => (int)$r['sort_order'],
    ];
}
if (!$CATS) {
    http_response_code(500);
    die('Keine Kategorien vorhanden. Bitte todo_kategorien befüllen.');
}

$defaultCatId = (int)array_key_first($CATS);
$sessionCatId = (int)($_SESSION['todo_cat_id'] ?? $defaultCatId);
if (!isset($CATS[$sessionCatId])) $sessionCatId = $defaultCatId;
$_SESSION['todo_cat_id'] = $sessionCatId;

/* ---------------------- POST (AJAX) ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'set_category') {
        $catId = (int)($_POST['category_id'] ?? 0);
        if ($catId <= 0 || !isset($CATS[$catId])) {
            json_out(['ok' => false, 'error' => 'Ungültige Kategorie.'], 400);
        }
        $_SESSION['todo_cat_id'] = $catId;
        json_out(['ok' => true]);
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

        $stmt = $checkconn->prepare("SELECT id, category_id, parent_id, title, note, done_at, sort_key FROM todo WHERE id = ? LIMIT 1");
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
        $catId = (int)($_POST['category_id'] ?? 0);
        $parentIdRaw = trim((string)($_POST['parent_id'] ?? ''));
        $parentId = ($parentIdRaw === '' || $parentIdRaw === '0') ? null : (int)$parentIdRaw;

        $title = trim((string)($_POST['title'] ?? ''));
        $note  = (string)($_POST['note'] ?? '');

        if ($catId <= 0 || !isset($CATS[$catId])) json_out(['ok' => false, 'error' => 'Ungültige Kategorie.'], 400);
        if ($title === '') json_out(['ok' => false, 'error' => 'Titel ist Pflicht.'], 400);

        // parent validieren (optional)
        if ($parentId !== null) {
            if ($parentId <= 0) json_out(['ok' => false, 'error' => 'Ungültiger Parent.'], 400);

            $stmt = $checkconn->prepare("SELECT id, category_id, parent_id FROM todo WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $parentId);
            $stmt->execute();
            $res = $stmt->get_result();
            $p = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$p) json_out(['ok' => false, 'error' => 'Parent nicht gefunden.'], 404);
            if ((int)$p['category_id'] !== $catId) json_out(['ok' => false, 'error' => 'Parent gehört zu anderer Kategorie.'], 400);
        }

        $_SESSION['todo_cat_id'] = $catId;

        $checkconn->begin_transaction();
        try {
            // max sort innerhalb der SIBLINGS (category_id + parent_id)
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
                $stmt = $checkconn->prepare("INSERT INTO todo (category_id, parent_id, title, note, done_at, sort_key) VALUES (?, NULL, ?, ?, NULL, ?)");
                $stmt->bind_param('issd', $catId, $title, $note, $newSort);
            } else {
                $stmt = $checkconn->prepare("INSERT INTO todo (category_id, parent_id, title, note, done_at, sort_key) VALUES (?, ?, ?, ?, NULL, ?)");
                $stmt->bind_param('iissd', $catId, $parentId, $title, $note, $newSort);
            }
            $stmt->execute();
            $newId = (int)$stmt->insert_id;
            $stmt->close();

            $checkconn->commit();

            $stmt = $checkconn->prepare("SELECT id, category_id, parent_id, title, note, done_at, sort_key FROM todo WHERE id = ? LIMIT 1");
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
        $catId = (int)($_POST['category_id'] ?? 0);
        $parentIdRaw = trim((string)($_POST['parent_id'] ?? ''));
        $parentId = ($parentIdRaw === '' || $parentIdRaw === '0') ? null : (int)$parentIdRaw;

        $title = trim((string)($_POST['title'] ?? ''));
        $note  = (string)($_POST['note'] ?? '');

        if ($id <= 0) json_out(['ok' => false, 'error' => 'Ungültige ID.'], 400);
        if ($catId <= 0 || !isset($CATS[$catId])) json_out(['ok' => false, 'error' => 'Ungültige Kategorie.'], 400);
        if ($title === '') json_out(['ok' => false, 'error' => 'Titel ist Pflicht.'], 400);

        // current row (für Wechsel-Erkennung)
        $stmt = $checkconn->prepare("SELECT id, category_id, parent_id FROM todo WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $old = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$old) json_out(['ok' => false, 'error' => 'Eintrag nicht gefunden.'], 404);

        $oldCatId = (int)$old['category_id'];
        $oldParentId = $old['parent_id'] === null ? null : (int)$old['parent_id'];

        // parent validieren (optional)
        if ($parentId !== null) {
            if ($parentId <= 0) json_out(['ok' => false, 'error' => 'Ungültiger Parent.'], 400);
            if ($parentId === $id) json_out(['ok' => false, 'error' => 'Parent darf nicht selbst sein.'], 400);

            $stmt = $checkconn->prepare("SELECT id, category_id, parent_id FROM todo WHERE id = ? LIMIT 1");
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
            $newSort = null;

            $moved = ($catId !== $oldCatId) || (($parentId === null) !== ($oldParentId === null)) || ($parentId !== null && $oldParentId !== null && $parentId !== $oldParentId);

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
                $stmt = $checkconn->prepare("UPDATE todo SET category_id=?, parent_id=NULL, title=?, note=? WHERE id=?");
                $stmt->bind_param('issi', $catId, $title, $note, $id);
            } elseif ($parentId === null && $newSort !== null) {
                $stmt = $checkconn->prepare("UPDATE todo SET category_id=?, parent_id=NULL, title=?, note=?, sort_key=? WHERE id=?");
                $stmt->bind_param('issdi', $catId, $title, $note, $newSort, $id);
            } elseif ($parentId !== null && $newSort === null) {
                $stmt = $checkconn->prepare("UPDATE todo SET category_id=?, parent_id=?, title=?, note=? WHERE id=?");
                $stmt->bind_param('iissi', $catId, $parentId, $title, $note, $id);
            } else {
                $stmt = $checkconn->prepare("UPDATE todo SET category_id=?, parent_id=?, title=?, note=?, sort_key=? WHERE id=?");
                $stmt->bind_param('iissdi', $catId, $parentId, $title, $note, $newSort, $id);
            }

            $stmt->execute();
            $stmt->close();

            $checkconn->commit();

            $_SESSION['todo_cat_id'] = $catId;

            $stmt = $checkconn->prepare("SELECT id, category_id, parent_id, title, note, done_at, sort_key FROM todo WHERE id = ? LIMIT 1");
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

/* ---------------------- Done-Count for Header ---------------------- */
$doneCount = 0;
$resD = $checkconn->query("SELECT COUNT(*) AS c FROM todo WHERE done_at IS NOT NULL");
if ($resD && ($r = $resD->fetch_assoc())) $doneCount = (int)$r['c'];

/* ---------------------- Todos for JS ---------------------- */
$todos = [];
$resT = $checkconn->query("
    SELECT id, category_id, parent_id, title, note, done_at, sort_key
    FROM todo
    ORDER BY category_id ASC, (parent_id IS NULL) DESC, parent_id ASC, sort_key ASC, id ASC
");
if ($resT) {
    while ($r = $resT->fetch_assoc()) $todos[] = $r;
}

$page_title = 'ToDo';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div id="tdPage" class="td-page dashboard-page">
    <div class="td-topbar">
        <h1 class="ueberschrift dashboard-title">
            <span class="dashboard-title-main">ToDo</span>
            <span class="dashboard-title-soft">| <span id="tdDoneCount"><?= htmlspecialchars((string)$doneCount, ENT_QUOTES, 'UTF-8') ?></span> erledigt</span>
        </h1>
    </div>

    <div class="td-subject-row">
        <div id="tdTabs" class="td-tabs" role="tablist" aria-label="Kategorien"></div>

        <button id="tdAddBtn" class="td-add-btn" type="button" title="Todo hinzufügen" aria-label="Todo hinzufügen">
            <span class="td-add-plus">+</span>
        </button>
    </div>

    <div class="td-table-wrap">
        <table class="td-table">
            <thead>
                <tr>
                    <th class="td-col-drag"></th>
                    <th>ToDo</th>
                    <th class="td-col-check">Erledigt</th>
                </tr>
            </thead>
            <tbody id="tdTbody"></tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div id="tdModal" class="modal hidden" aria-hidden="true">
    <div class="modal-content td-modal-content" role="dialog" aria-modal="true" aria-labelledby="tdModalTitle">
        <span class="close-button" id="tdModalClose" title="Schließen">&times;</span>
        <h2 id="tdModalTitle" class="td-modal-title">Neues Todo</h2>

        <div class="form-block">
            <input type="hidden" id="tdEditId" value="">

            <div class="td-modal-grid">
                <div class="input-group-dropdown">
                    <label for="tdNewCat">Kategorie</label>
                    <select id="tdNewCat"></select>
                </div>

                <div class="input-group-dropdown">
                    <label for="tdNewParent">Unterpunkt von</label>
                    <select id="tdNewParent"></select>
                </div>
            </div>

            <div class="input-group-dropdown">
                <label for="tdNewTitle">Titel</label>
                <input id="tdNewTitle" type="text" placeholder="z.B. Einkaufsliste schreiben">
            </div>

            <div class="input-group-dropdown">
                <label for="tdNewNote">Notiz</label>
                <textarea id="tdNewNote" rows="4" placeholder="optional"></textarea>
            </div>

            <button id="tdSave" type="button">Speichern</button>
        </div>
    </div>
</div>

<script>
(() => {
    const CATS = <?= json_encode($CATS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>; // id => {id,name,color,...}
    const TODOS = <?= json_encode($todos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const phpDefaultCatId = <?= json_encode((int)$sessionCatId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const elPage = document.getElementById('tdPage');
    const elTabs = document.getElementById('tdTabs');
    const elTbody = document.getElementById('tdTbody');
    const elDoneCount = document.getElementById('tdDoneCount');

    const elModal = document.getElementById('tdModal');
    const elModalClose = document.getElementById('tdModalClose');
    const elAddBtn = document.getElementById('tdAddBtn');

    const elEditId = document.getElementById('tdEditId');
    const elNewCat = document.getElementById('tdNewCat');
    const elNewParent = document.getElementById('tdNewParent');
    const elNewTitle = document.getElementById('tdNewTitle');
    const elNewNote = document.getElementById('tdNewNote');
    const elSave = document.getElementById('tdSave');

    const catIds = Object.keys(CATS).map(Number);
    const validCats = new Set(catIds);

    // Expand-State (parent ids)
    const expanded = new Set();
    try {
        const raw = localStorage.getItem('todo_expanded');
        if (raw) JSON.parse(raw).forEach(x => expanded.add(String(x)));
    } catch (_) {}

    function saveExpanded() {
        try { localStorage.setItem('todo_expanded', JSON.stringify([...expanded])); } catch (_) {}
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

    function catColor(catId) {
        const c = CATS?.[String(catId)]?.color;
        return c || '#999';
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

    let selectedCatId = Number(localStorage.getItem('todo_cat_id') || phpDefaultCatId);
    if (!validCats.has(selectedCatId)) selectedCatId = phpDefaultCatId;

    function setAccent(catId) {
        elPage.style.setProperty('--td-accent', catColor(catId));
    }

    function updateDoneCount() {
        let c = 0;
        for (const t of TODOS) if (t.done_at) c++;
        elDoneCount.textContent = String(c);
    }

    function buildTreeForCat(catId) {
        const list = TODOS.filter(t => Number(t.category_id) === Number(catId));

        const byId = new Map();
        const kids = new Map(); // parentIdStr -> array
        const roots = [];

        for (const t of list) {
            const id = String(t.id);
            byId.set(id, t);

            const p = (t.parent_id == null) ? null : String(t.parent_id);
            if (p === null) roots.push(t);
            else {
                if (!kids.has(p)) kids.set(p, []);
                kids.get(p).push(t);
            }
        }

        const sortSiblings = (a, b) => {
            const da = !!a.done_at;
            const db = !!b.done_at;
            if (da !== db) return da ? 1 : -1;

            const sa = Number(a.sort_key ?? 0);
            const sb = Number(b.sort_key ?? 0);
            if (sa !== sb) return sa - sb;

            return Number(a.id) - Number(b.id);
        };

        roots.sort(sortSiblings);
        for (const [p, arr] of kids.entries()) arr.sort(sortSiblings);

        return { byId, kids, roots };
    }

    function renderTabs() {
        elTabs.innerHTML = '';

        // sort by sort_order then id (already in PHP order, but keep stable)
        const list = Object.values(CATS).slice().sort((a, b) => {
            const sa = Number(a.sort_order ?? 0), sb = Number(b.sort_order ?? 0);
            if (sa !== sb) return sa - sb;
            return Number(a.id) - Number(b.id);
        });

        for (const cat of list) {
            const btn = document.createElement('button');
            const c = cat.color || '#999';
            btn.style.setProperty('--tab-accent', c);
            btn.style.setProperty('--tab-accent-text', pickTextColor(c));

            btn.type = 'button';
            btn.className = 'td-tab' + (Number(cat.id) === Number(selectedCatId) ? ' active' : '');
            btn.textContent = cat.name;
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-selected', Number(cat.id) === Number(selectedCatId) ? 'true' : 'false');

            btn.addEventListener('click', async () => {
                const next = Number(cat.id);
                if (next === selectedCatId) return;
                selectedCatId = next;
                localStorage.setItem('todo_cat_id', String(selectedCatId));
                setAccent(selectedCatId);
                renderTabs();
                renderTable();
                rebuildModalSelects();

                try { await post('set_category', { category_id: String(selectedCatId) }); } catch (_) {}
            });

            elTabs.appendChild(btn);
        }
    }

    function rebuildModalSelects(parentPref = null) {
        // Kategorie-Select
        elNewCat.innerHTML = '';
        Object.values(CATS).slice().sort((a,b) => (a.sort_order-b.sort_order) || (a.id-b.id)).forEach(cat => {
            const opt = document.createElement('option');
            opt.value = String(cat.id);
            opt.textContent = cat.name;
            elNewCat.appendChild(opt);
        });
        elNewCat.value = String(selectedCatId);

        // Parent-Select: nur Top-Level in aktueller Kategorie (plus "Kein Parent")
        elNewParent.innerHTML = '';
        const optNone = document.createElement('option');
        optNone.value = '0';
        optNone.textContent = '— (Top-Level)';
        elNewParent.appendChild(optNone);

        const tree = buildTreeForCat(selectedCatId);
        tree.roots.forEach(t => {
            const opt = document.createElement('option');
            opt.value = String(t.id);
            opt.textContent = t.title || `(Todo #${t.id})`;
            elNewParent.appendChild(opt);
        });

        if (parentPref != null) elNewParent.value = String(parentPref);
        else elNewParent.value = '0';
    }

    function openModal({ edit = null, parentPref = null } = {}) {
        elModal.classList.remove('hidden');
        elModal.setAttribute('aria-hidden', 'false');

        rebuildModalSelects(parentPref);

        if (!edit) {
            elEditId.value = '';
            document.getElementById('tdModalTitle').textContent = 'Neues Todo';
            elNewTitle.value = '';
            elNewNote.value = '';
            setTimeout(() => elNewTitle.focus(), 50);
            return;
        }

        elEditId.value = String(edit.id);
        document.getElementById('tdModalTitle').textContent = 'Todo bearbeiten';

        elNewCat.value = String(edit.category_id);
        selectedCatId = Number(edit.category_id);
        localStorage.setItem('todo_cat_id', String(selectedCatId));
        setAccent(selectedCatId);
        renderTabs();

        rebuildModalSelects(edit.parent_id == null ? 0 : edit.parent_id);

        elNewTitle.value = edit.title ?? '';
        elNewNote.value = edit.note ?? '';
        setTimeout(() => elNewTitle.focus(), 50);
    }

    function closeModal() {
        elModal.classList.add('hidden');
        elModal.setAttribute('aria-hidden', 'true');
    }

    elAddBtn.addEventListener('click', () => openModal({ edit: null, parentPref: 0 }));
    elModalClose.addEventListener('click', closeModal);
    elModal.addEventListener('click', (e) => { if (e.target === elModal) closeModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !elModal.classList.contains('hidden')) closeModal();
    });

    // Beim Kategorie-Wechsel im Modal: Parent-Liste neu aufbauen
    elNewCat.addEventListener('change', () => {
        const cid = Number(elNewCat.value || 0);
        if (!validCats.has(cid)) return;
        selectedCatId = cid;
        localStorage.setItem('todo_cat_id', String(selectedCatId));
        setAccent(selectedCatId);
        renderTabs();
        renderTable();
        rebuildModalSelects(0);
    });

    function applyTodoUpdate(updated) {
        const id = Number(updated.id);
        const idx = TODOS.findIndex(t => Number(t.id) === id);
        if (idx >= 0) TODOS[idx] = { ...TODOS[idx], ...updated };
        else TODOS.push(updated);
    }

    // Drag & Drop (nur innerhalb gleicher parent_id)
    let dragAllowed = false;
    let draggingRow = null;
    let draggingId = null;
    let dragStartIndex = null;

    const elTableWrap = document.querySelector('.td-table-wrap');
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
        const row = el && el.closest ? el.closest('tr.td-row') : null;
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
        elTbody.querySelectorAll('tr.td-row').forEach(r => r.classList.remove('td-drop-before', 'td-drop-after'));
    }

    function hoverReorder(targetRow, clientY) {
        if (!draggingRow || !targetRow || targetRow === draggingRow) return;

        // nur gleiche parent-gruppe
        if (String(targetRow.dataset.parent || '0') !== String(draggingRow.dataset.parent || '0')) return;

        clearDropMarkers();

        const rect = targetRow.getBoundingClientRect();
        const before = clientY < rect.top + rect.height / 2;

        if (before) elTbody.insertBefore(draggingRow, targetRow);
        else elTbody.insertBefore(draggingRow, targetRow.nextSibling);
    }

    async function commitSortIfChanged() {
        if (!draggingId) return;

        const parentKey = String(draggingRow?.dataset?.parent || '0');
        const rows = [...elTbody.querySelectorAll(`tr.td-row[data-parent="${CSS.escape(parentKey)}"]`)];
        const endIndex = rows.findIndex(r => r.dataset.id === String(draggingId));

        if (dragStartIndex === null || endIndex < 0 || endIndex === dragStartIndex) return;

        const prevId = endIndex > 0 ? rows[endIndex - 1].dataset.id : null;
        const nextId = endIndex < rows.length - 1 ? rows[endIndex + 1].dataset.id : null;

        const prevT = prevId ? TODOS.find(t => String(t.id) === String(prevId)) : null;
        const nextT = nextId ? TODOS.find(t => String(t.id) === String(nextId)) : null;

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

            const idx = TODOS.findIndex(t => String(t.id) === String(draggingId));
            if (idx >= 0) TODOS[idx].sort_key = sortStr;

            renderTable();
        } catch (_) {
            renderTable();
        }
    }

    function makeCheckbox(todo) {
        const wrap = document.createElement('div');
        wrap.className = 'td-check';

        const id = `tdDone_${todo.id}`;

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.id = id;
        input.checked = !!todo.done_at;

        const label = document.createElement('label');
        label.htmlFor = id;
        label.title = input.checked ? 'Erledigt (klicken zum Zurücksetzen)' : 'Als erledigt markieren';

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.classList.add('td-checkmark');

        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', 'M20 6L9 17l-5-5');
        svg.appendChild(path);
        label.appendChild(svg);

        input.addEventListener('change', async () => {
            input.disabled = true;
            const newChecked = input.checked;

            // optimistic
            todo.done_at = newChecked ? 'pending' : null;
            updateRowState(todo.id);

            try {
                const res = await post('toggle_done', { id: String(todo.id), checked: newChecked ? '1' : '0' });
                if (!res || !res.ok || !res.todo) throw new Error('toggle_failed');

                applyTodoUpdate(res.todo);
                input.checked = !!res.todo.done_at;
                input.disabled = false;

                updateRowState(todo.id);
                updateDoneCount();
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
        const t = TODOS.find(x => Number(x.id) === Number(todoId));
        const isDone = !!(t && t.done_at && t.done_at !== 'pending');
        const isPending = !!(t && t.done_at === 'pending');
        row.classList.toggle('done', isDone);
        row.classList.toggle('pending', isPending);
    }

    function renderTable() {
        elTbody.innerHTML = '';

        const tree = buildTreeForCat(selectedCatId);
        const kids = tree.kids;

        if (!tree.roots.length) {
            const tr = document.createElement('tr');
            tr.className = 'td-empty';
            const td = document.createElement('td');
            td.colSpan = 3;
            td.textContent = 'Noch keine Todos in dieser Kategorie.';
            tr.appendChild(td);
            elTbody.appendChild(tr);
            return;
        }

        const renderOne = (todo, depth) => {
            const idStr = String(todo.id);
            const parentKey = (todo.parent_id == null) ? '0' : String(todo.parent_id);

            const tr = document.createElement('tr');
            tr.dataset.id = idStr;
            tr.dataset.parent = (todo.parent_id == null) ? '0' : String(todo.parent_id);
            tr.className = 'td-row' + (todo.done_at ? ' done' : '') + (depth > 0 ? ' td-child' : '');

            tr.draggable = true;

            // Drag cell
            const tdDrag = document.createElement('td');
            tdDrag.className = 'td-dragcell';

            const grip = document.createElement('div');
            grip.className = 'td-grip';
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

                const parentKey = String(tr.dataset.parent || '0');
                dragStartIndex = [...elTbody.querySelectorAll(`tr.td-row[data-parent="${CSS.escape(parentKey)}"]`)]
                    .findIndex(r => r.dataset.id === String(draggingId));

                tr.classList.add('td-dragging');
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
                tr.classList.remove('td-dragging');
                clearDropMarkers();
                stopAutoScroll();

                await commitSortIfChanged();

                draggingRow = null;
                draggingId = null;
                dragStartIndex = null;
            });

            // Content cell
            const tdLeft = document.createElement('td');
            tdLeft.className = 'td-left';

            const wrap = document.createElement('div');
            wrap.className = 'td-item';

            const top = document.createElement('div');
            top.className = 'td-item-top';

            // Expand toggle (nur für Parent-Items)
            const children = kids.get(idStr) || [];
            const hasKids = children.length > 0;

            const indent = document.createElement('span');
            indent.className = 'td-indent';
            indent.style.setProperty('--td-depth', String(depth));
            top.appendChild(indent);

            const expBtn = document.createElement('button');
            expBtn.type = 'button';
            expBtn.className = 'td-expand' + (hasKids ? '' : ' is-hidden');
            expBtn.title = hasKids ? 'Auf-/Zuklappen' : '';
            expBtn.setAttribute('aria-label', 'Aufklappen');
            expBtn.innerHTML = '&#9656;'; // ▶

            const isOpen = expanded.has(idStr);
            expBtn.classList.toggle('open', isOpen);

            expBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (!hasKids) return;
                if (expanded.has(idStr)) expanded.delete(idStr);
                else expanded.add(idStr);
                saveExpanded();
                renderTable();
            });

            const title = document.createElement('span');
            title.className = 'td-title';
            title.textContent = todo.title ?? '';

            // quick add sub
            const subBtn = document.createElement('button');
            subBtn.type = 'button';
            subBtn.className = 'td-subadd' + (depth > 0 ? ' is-hidden' : '');
            subBtn.title = 'Unterpunkt hinzufügen';
            subBtn.setAttribute('aria-label', 'Unterpunkt hinzufügen');
            subBtn.textContent = '+';
            subBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                // parent öffnen und modal direkt auf sub
                expanded.add(idStr);
                saveExpanded();
                openModal({ edit: null, parentPref: idStr });
            });

            const meta = document.createElement('div');
            meta.className = 'td-meta';

            const note = String(todo.note ?? '').trim();
            if (note) {
                const noteEl = document.createElement('div');
                noteEl.className = 'td-note';
                noteEl.textContent = note;
                meta.appendChild(noteEl);
            }

            // children count badge
            if (hasKids && depth === 0) {
                const badge = document.createElement('span');
                badge.className = 'td-badge';
                badge.textContent = String(children.length);
                title.appendChild(badge);
            }

            top.appendChild(expBtn);
            top.appendChild(title);
            top.appendChild(subBtn);

            wrap.appendChild(top);
            if (meta.childNodes.length > 0) wrap.appendChild(meta);

            tdLeft.appendChild(wrap);

            // Checkbox cell
            const tdRight = document.createElement('td');
            tdRight.className = 'td-right';
            tdRight.appendChild(makeCheckbox(todo));

            tr.appendChild(tdDrag);
            tr.appendChild(tdLeft);
            tr.appendChild(tdRight);

            // Row click -> edit (nicht bei checkbox / drag / expand / subadd)
            tr.addEventListener('click', (e) => {
                if (e.target.closest('.td-check')) return;
                if (e.target.closest('.td-dragcell')) return;
                if (e.target.closest('.td-expand')) return;
                if (e.target.closest('.td-subadd')) return;
                openModal({ edit: todo });
            });

            elTbody.appendChild(tr);

            // render children if expanded
            if (hasKids && expanded.has(idStr)) {
                children.forEach(ch => renderOne(ch, depth + 1));
            }
        };

        tree.roots.forEach(t => renderOne(t, 0));

        setAccent(selectedCatId);
    }

    elSave.addEventListener('click', async () => {
        const editId = (elEditId.value || '').trim();

        const category_id = String(elNewCat.value || '');
        const parent_id = String(elNewParent.value || '0');
        const title = elNewTitle.value.trim();
        const note = elNewNote.value ?? '';

        const catIdNum = Number(category_id || 0);
        if (!validCats.has(catIdNum)) return;
        if (!title) return;

        elSave.disabled = true;

        try {
            if (!editId) {
                const res = await post('add_todo', { category_id, parent_id, title, note });
                if (!res || !res.ok || !res.todo) throw new Error('add_failed');

                applyTodoUpdate(res.todo);

                selectedCatId = Number(res.todo.category_id);
                localStorage.setItem('todo_cat_id', String(selectedCatId));

                // parent auto-expand wenn subtask
                if (res.todo.parent_id != null) {
                    expanded.add(String(res.todo.parent_id));
                    saveExpanded();
                }

            } else {
                const res = await post('update_todo', { id: editId, category_id, parent_id, title, note });
                if (!res || !res.ok || !res.todo) throw new Error('update_failed');

                applyTodoUpdate(res.todo);

                selectedCatId = Number(res.todo.category_id);
                localStorage.setItem('todo_cat_id', String(selectedCatId));

                if (res.todo.parent_id != null) {
                    expanded.add(String(res.todo.parent_id));
                    saveExpanded();
                }
            }

            updateDoneCount();
            setAccent(selectedCatId);
            renderTabs();
            renderTable();
            rebuildModalSelects(0);
            closeModal();
        } catch (_) {
            // silent
        } finally {
            elSave.disabled = false;
        }
    });

    function init() {
        setAccent(selectedCatId);
        renderTabs();
        rebuildModalSelects(0);
        updateDoneCount();
        renderTable();
    }

    init();
})();
</script>

</body>
</html>
