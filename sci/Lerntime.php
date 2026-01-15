<?php
// sci/Lerntime.php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
$sciconn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$SUBJECTS = []; // name => ['id'=>int,'color'=>string,'legend_hidden_default'=>int,'sort_order'=>int]
$resF = $sciconn->query("
    SELECT id, name, color, legend_hidden_default, sort_order
    FROM lerntime_faecher
    WHERE is_active = 1
    ORDER BY sort_order ASC, id ASC
");
if (!$resF) {
    http_response_code(500);
    die('DB-Fehler: lerntime_faecher konnte nicht geladen werden.');
}
while ($r = $resF->fetch_assoc()) {
    $name = (string)$r['name'];
    $SUBJECTS[$name] = [
        'id' => (int)$r['id'],
        'color' => (string)$r['color'],
        'legend_hidden_default' => (int)$r['legend_hidden_default'],
        'sort_order' => (int)$r['sort_order'],
    ];
}
if (!$SUBJECTS) {
    http_response_code(500);
    die('Keine Fächer vorhanden. Bitte lerntime_faecher befüllen.');
}

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

$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');
if ($jahr < 2000 || $jahr > 2100) $jahr = (int)date('Y');

$preferredDefault = 'Regelungstechnik';
$defaultFach = isset($SUBJECTS[$preferredDefault]) ? $preferredDefault : (string)array_key_first($SUBJECTS);

$sessionFach = (string)($_SESSION['lerntime_fach'] ?? $defaultFach);
if (!isset($SUBJECTS[$sessionFach])) $sessionFach = $defaultFach;
$_SESSION['lerntime_fach'] = $sessionFach;

/* ---------------------- POST (AJAX) ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'set_fach') {
        $fach = (string)($_POST['fach'] ?? '');
        if (!isset($SUBJECTS[$fach])) {
            json_out(['ok' => false, 'error' => 'Ungültiges Fach.'], 400);
        }
        $_SESSION['lerntime_fach'] = $fach;
        json_out(['ok' => true]);
    }

    if ($action === 'toggle_done') {
        $id = (int)($_POST['id'] ?? 0);
        $checked = (string)($_POST['checked'] ?? '0') === '1';

        if ($id <= 0) json_out(['ok' => false, 'error' => 'Ungültige ID.'], 400);

        if ($checked) {
            $stmt = $sciconn->prepare("UPDATE lerntime SET erledigt_am = NOW() WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $sciconn->prepare("UPDATE lerntime SET erledigt_am = NULL WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $sciconn->prepare("SELECT id, fach, einheit, titel, notiz, dauer_sekunden, erledigt_am, sort_key FROM lerntime WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) json_out(['ok' => false, 'error' => 'Eintrag nicht gefunden.'], 404);

        json_out([
            'ok' => true,
            'task' => $row,
        ]);
    }

    if ($action === 'add_task') {
        $fach = (string)($_POST['fach'] ?? '');
        $einheit = trim((string)($_POST['einheit'] ?? ''));
        $titel = trim((string)($_POST['titel'] ?? ''));
        $notiz = (string)($_POST['notiz'] ?? '');
        $dauer_sekunden = isset($_POST['dauer_sekunden']) && $_POST['dauer_sekunden'] !== ''
            ? max(0, (int)$_POST['dauer_sekunden'])
            : null;

        if (!isset($SUBJECTS[$fach])) json_out(['ok' => false, 'error' => 'Ungültiges Fach.'], 400);
        if ($einheit === '' || $titel === '') json_out(['ok' => false, 'error' => 'Einheit und Titel sind Pflichtfelder.'], 400);

        $_SESSION['lerntime_fach'] = $fach;

        $sciconn->begin_transaction();
        try {
            $stmt = $sciconn->prepare("SELECT MAX(sort_key) AS m FROM lerntime WHERE fach = ?");
            $stmt->bind_param('s', $fach);
            $stmt->execute();
            $res = $stmt->get_result();
            $max = 0.0;
            if ($res && ($r = $res->fetch_assoc()) && $r['m'] !== null) {
                $max = (float)$r['m'];
            }
            $stmt->close();

            $newSort = ($max > 0 ? $max + 1000.0 : 1000.0);

            if ($dauer_sekunden === null) {
                $stmt = $sciconn->prepare("INSERT INTO lerntime (fach, einheit, titel, notiz, dauer_sekunden, erledigt_am, sort_key) VALUES (?, ?, ?, ?, NULL, NULL, ?)");
                $stmt->bind_param('ssssd', $fach, $einheit, $titel, $notiz, $newSort);
            } else {
                $stmt = $sciconn->prepare("INSERT INTO lerntime (fach, einheit, titel, notiz, dauer_sekunden, erledigt_am, sort_key) VALUES (?, ?, ?, ?, ?, NULL, ?)");
                $stmt->bind_param('ssssid', $fach, $einheit, $titel, $notiz, $dauer_sekunden, $newSort);
            }
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            $sciconn->commit();

            $stmt = $sciconn->prepare("SELECT id, fach, einheit, titel, notiz, dauer_sekunden, erledigt_am, sort_key FROM lerntime WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $newId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            json_out(['ok' => true, 'task' => $row]);
        } catch (Throwable $e) {
            $sciconn->rollback();
            json_out(['ok' => false, 'error' => 'DB-Fehler beim Einfügen.'], 500);
        }
    }

    if ($action === 'update_sort') {
        $id = (int)($_POST['id'] ?? 0);
        $sort = (string)($_POST['sort_key'] ?? '');

        if ($id <= 0 || $sort === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $sort)) {
            json_out(['ok' => false, 'error' => 'Ungültige Sortierung.'], 400);
        }

        $stmt = $sciconn->prepare("UPDATE lerntime SET sort_key = ? WHERE id = ?");
        $stmt->bind_param('si', $sort, $id);
        $stmt->execute();
        $stmt->close();

        json_out(['ok' => true, 'id' => $id, 'sort_key' => $sort]);
    }

    if ($action === 'update_task') {
        $id = (int)($_POST['id'] ?? 0);

        $fach = (string)($_POST['fach'] ?? '');
        $einheit = trim((string)($_POST['einheit'] ?? ''));
        $titel = trim((string)($_POST['titel'] ?? ''));
        $notiz = (string)($_POST['notiz'] ?? '');
        $dauer_sekunden = isset($_POST['dauer_sekunden']) && $_POST['dauer_sekunden'] !== ''
            ? max(0, (int)$_POST['dauer_sekunden'])
            : null;

        if ($id <= 0) json_out(['ok' => false, 'error' => 'Ungültige ID.'], 400);
        if (!isset($SUBJECTS[$fach])) json_out(['ok' => false, 'error' => 'Ungültiges Fach.'], 400);
        if ($einheit === '' || $titel === '') json_out(['ok' => false, 'error' => 'Einheit und Titel sind Pflichtfelder.'], 400);

        $sciconn->begin_transaction();
        try {
            $stmt = $sciconn->prepare("SELECT fach FROM lerntime WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $old = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$old) {
                $sciconn->rollback();
                json_out(['ok' => false, 'error' => 'Eintrag nicht gefunden.'], 404);
            }

            $oldFach = (string)$old['fach'];

            $newSort = null;
            if ($fach !== $oldFach) {
                $stmt = $sciconn->prepare("SELECT MAX(sort_key) AS m FROM lerntime WHERE fach = ?");
                $stmt->bind_param('s', $fach);
                $stmt->execute();
                $res = $stmt->get_result();
                $max = 0.0;
                if ($res && ($r = $res->fetch_assoc()) && $r['m'] !== null) {
                    $max = (float)$r['m'];
                }
                $stmt->close();
                $newSort = ($max > 0 ? $max + 1000.0 : 1000.0);
            }

            if ($dauer_sekunden === null && $newSort === null) {
                $stmt = $sciconn->prepare("UPDATE lerntime SET fach=?, einheit=?, titel=?, notiz=?, dauer_sekunden=NULL WHERE id=?");
                $stmt->bind_param('ssssi', $fach, $einheit, $titel, $notiz, $id);
            } elseif ($dauer_sekunden === null && $newSort !== null) {
                $stmt = $sciconn->prepare("UPDATE lerntime SET fach=?, einheit=?, titel=?, notiz=?, dauer_sekunden=NULL, sort_key=? WHERE id=?");
                $stmt->bind_param('ssssdi', $fach, $einheit, $titel, $notiz, $newSort, $id);
            } elseif ($dauer_sekunden !== null && $newSort === null) {
                $stmt = $sciconn->prepare("UPDATE lerntime SET fach=?, einheit=?, titel=?, notiz=?, dauer_sekunden=? WHERE id=?");
                $stmt->bind_param('ssssii', $fach, $einheit, $titel, $notiz, $dauer_sekunden, $id);
            } else {
                $stmt = $sciconn->prepare("UPDATE lerntime SET fach=?, einheit=?, titel=?, notiz=?, dauer_sekunden=?, sort_key=? WHERE id=?");
                $stmt->bind_param('ssssidi', $fach, $einheit, $titel, $notiz, $dauer_sekunden, $newSort, $id);
            }

            $stmt->execute();
            $stmt->close();

            $sciconn->commit();

            $_SESSION['lerntime_fach'] = $fach;

            $stmt = $sciconn->prepare("SELECT id, fach, einheit, titel, notiz, dauer_sekunden, erledigt_am, sort_key FROM lerntime WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            json_out(['ok' => true, 'task' => $row]);
        } catch (Throwable $e) {
            $sciconn->rollback();
            json_out(['ok' => false, 'error' => 'DB-Fehler beim Speichern.'], 500);
        }
    }

    json_out(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
}

/* ---------------------- Semester for Dropdown ---------------------- */
function semester_key_for_date(DateTimeInterface $dt): string {
    $y = (int)$dt->format('Y');
    $m = (int)$dt->format('n'); // 1..12
    if ($m >= 10) return "wise_{$y}";
    if ($m <= 3)  return "wise_" . ($y - 1);
    return "sose_{$y}";
}
function semester_label(string $key): string {
    if (!preg_match('/^(wise|sose)_(\d{4})$/', $key, $m)) return $key;
    $typ = $m[1];
    $y   = (int)$m[2];
    if ($typ === 'wise') return 'WiSe ' . $y . '/' . substr((string)($y + 1), -2);
    return 'SoSe ' . $y;
}
function semester_start_ts(string $key): int {
    if (!preg_match('/^(wise|sose)_(\d{4})$/', $key, $m)) return 0;
    $typ = $m[1];
    $y   = (int)$m[2];
    if ($typ === 'wise') return (new DateTimeImmutable(sprintf('%04d-10-01 00:00:00', $y)))->getTimestamp();
    return (new DateTimeImmutable(sprintf('%04d-04-01 00:00:00', $y)))->getTimestamp();
}
function semester_sql_range(string $key): array {
    if (!preg_match('/^(wise|sose)_(\d{4})$/', $key, $m)) {
        // Fallback: aktuelles Jahr
        $y = (int)date('Y');
        $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $y));
        $end   = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $y + 1));
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    $typ = $m[1];
    $y   = (int)$m[2];

    if ($typ === 'wise') {
        // WiSe: 01.10.Y bis 01.04.(Y+1)
        $start = new DateTimeImmutable(sprintf('%04d-10-01 00:00:00', $y));
        $end   = new DateTimeImmutable(sprintf('%04d-04-01 00:00:00', $y + 1));
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    // SoSe: 01.04.Y bis 01.10.Y
    $start = new DateTimeImmutable(sprintf('%04d-04-01 00:00:00', $y));
    $end   = new DateTimeImmutable(sprintf('%04d-10-01 00:00:00', $y));
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}


$semesterSet = [];
$curKey = semester_key_for_date(new DateTimeImmutable('now'));
$semesterSet[$curKey] = true;

// Semester nur aus vorhandenen erledigt_am-Daten ableiten (plus aktuelles Semester)
$resS = $sciconn->query("
    SELECT DISTINCT YEAR(erledigt_am) AS y, MONTH(erledigt_am) AS m
    FROM lerntime
    WHERE erledigt_am IS NOT NULL
");
if ($resS) {
    while ($r = $resS->fetch_assoc()) {
        $y = (int)$r['y'];
        $m = (int)$r['m'];
        if ($m >= 10) $key = "wise_{$y}";
        elseif ($m <= 3) $key = "wise_" . ($y - 1);
        else $key = "sose_{$y}";
        $semesterSet[$key] = true;
    }
}

$semesterKeys = array_keys($semesterSet);
usort($semesterKeys, fn($a, $b) => semester_start_ts($b) <=> semester_start_ts($a));

$semester = isset($_GET['semester']) ? (string)$_GET['semester'] : $curKey;
if (!isset($semesterSet[$semester])) $semester = $semesterKeys[0] ?? $curKey;

[$semStartSql, $semEndSql] = semester_sql_range($semester);

$stmt = $sciconn->prepare("
    SELECT COALESCE(SUM(COALESCE(dauer_sekunden, 0)), 0) AS sum_sec
    FROM lerntime
    WHERE erledigt_am IS NOT NULL
      AND erledigt_am >= ?
      AND erledigt_am < ?
");
$stmt->bind_param('ss', $semStartSql, $semEndSql);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

$semesterLernzeitSekunden = (int)($row['sum_sec'] ?? 0);

/* Anzeige-Wert (du kannst hier wählen)
   - als ganze Stunden:
*/
$semesterLernzeitStunden = (int)round($semesterLernzeitSekunden / 3600);

/* ---------------------- Klausurtermine for JS ---------------------- */
$klausuren = [];
$resK = $sciconn->query("
    SELECT id, fach, datum
    FROM klausurtermine
    ORDER BY datum ASC, id ASC
");
if ($resK) {
    while ($r = $resK->fetch_assoc()) {
        $klausuren[] = $r;
    }
}


/* ---------------------- All Tasks for JS ---------------------- */
$tasks = [];
$resT = $sciconn->query("SELECT id, fach, einheit, titel, notiz, dauer_sekunden, erledigt_am, sort_key FROM lerntime ORDER BY fach ASC, sort_key ASC, id ASC");
if ($resT) {
    while ($r = $resT->fetch_assoc()) {
        $tasks[] = $r;
    }
}

$page_title = 'Lerntime';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div id="ltPage" class="lt-page dashboard-page">
    <div class="lt-topbar">

        <h1 class="ueberschrift dashboard-title">
        <span class="dashboard-title-main">B.Sc. Maschinenbau <?= htmlspecialchars((string)semester_label($semester), ENT_QUOTES, 'UTF-8') ?></span>
        <span class="dashboard-title-soft">| <?= htmlspecialchars((string)$semesterLernzeitStunden, ENT_QUOTES, 'UTF-8') ?> Stunden</span>
        </h1>

        <div class="lt-yearwrap">
            <label for="ltSemester" class="lt-label">Semester</label>
            <select id="ltSemester" class="kategorie-select">
                <?php foreach ($semesterKeys as $key): ?>
                    <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= ($key === $semester) ? 'selected' : '' ?>>
                        <?= htmlspecialchars(semester_label($key), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="lt-chart-wrap lernzeit-chart-wrap">
        <canvas id="ltRemainingChart"></canvas>
    </div>

    <!-- <hr class="lt-hr"> -->

    <div class="lt-subject-row">
        <div id="ltTabs" class="lt-tabs" role="tablist" aria-label="Fächer"></div>

        <button id="ltAddBtn" class="lt-add-btn" type="button" title="Eintrag hinzufügen" aria-label="Eintrag hinzufügen">
            <span class="lt-add-plus">+</span>
        </button>    
    </div>

    <div class="lt-table-wrap">
        <table class="lt-table">
            <thead>
                <tr>
                    <th class="lt-col-drag"></th>
                    <th>Einheit / Thema</th>
                    <th class="lt-col-check">Erledigt</th>
                </tr>
            </thead>
            <tbody id="ltTbody"></tbody>
        </table>
    </div>
</div>

<!-- Modal: Neuer Eintrag -->
<div id="ltModal" class="modal hidden" aria-hidden="true">
    <div class="modal-content lt-modal-content" role="dialog" aria-modal="true" aria-labelledby="ltModalTitle">
        <span class="close-button" id="ltModalClose" title="Schließen">&times;</span>
        <h2 id="ltModalTitle" class="lt-modal-title">Neuer Eintrag</h2>

        <div class="form-block">
            <input type="hidden" id="ltEditId" value="">
            <div class="input-group-dropdown">
                <label for="ltNewFach">Fach</label>
                    <select id="ltNewFach">
                        <?php foreach ($SUBJECTS as $name => $meta): ?>
                            <option value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
            </div>

            <div class="input-group-dropdown">
                <label for="ltNewEinheit">Einheit</label>
                <input id="ltNewEinheit" type="text" placeholder="z.B. V01 / Ü01 / Altklausur SoSe25">
            </div>

            <div class="input-group-dropdown">
                <label for="ltNewTitel">Titel</label>
                <input id="ltNewTitel" type="text" placeholder="z.B. Zugversuch">
            </div>

            <div class="input-group-dropdown">
                <label for="ltNewNotiz">Notiz</label>
                <textarea id="ltNewNotiz" rows="3" placeholder="optional"></textarea>
            </div>

            <div class="lt-duration-grid">
                <div class="input-group-dropdown">
                    <label for="ltDurH">Stunden</label>
                    <input id="ltDurH" type="number" min="0" step="1" value="0">
                </div>
                <div class="input-group-dropdown">
                    <label for="ltDurM">Minuten</label>
                    <input id="ltDurM" type="number" min="0" step="1" value="0">
                </div>
                <div class="input-group-dropdown">
                    <label for="ltDurS">Sekunden</label>
                    <input id="ltDurS" type="number" min="0" step="1" value="0">
                </div>
            </div>

            <button id="ltSaveNew" type="button">Speichern</button>
        </div>
    </div>
</div>

<!-- Chart libs -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/luxon@3.4.3/build/global/luxon.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.3.1"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.2.1"></script>

<script>
(() => {
    const DateTime = luxon.DateTime;

    const SUBJECTS = <?= json_encode($SUBJECTS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const TASKS = <?= json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const EXAMS = <?= json_encode($klausuren, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const phpDefaultFach = <?= json_encode($sessionFach, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialSemester = <?= json_encode($semester, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const elPage = document.getElementById('ltPage');
    const elTabs = document.getElementById('ltTabs');
    const elTbody = document.getElementById('ltTbody');
    const elSemester = document.getElementById('ltSemester');

    const elModal = document.getElementById('ltModal');
    const elModalClose = document.getElementById('ltModalClose');
    const elAddBtn = document.getElementById('ltAddBtn');

    const elNewFach = document.getElementById('ltNewFach');
    const elNewEinheit = document.getElementById('ltNewEinheit');
    const elNewTitel = document.getElementById('ltNewTitel');
    const elNewNotiz = document.getElementById('ltNewNotiz');
    const elDurH = document.getElementById('ltDurH');
    const elDurM = document.getElementById('ltDurM');
    const elDurS = document.getElementById('ltDurS');
    const elSaveNew = document.getElementById('ltSaveNew');

    const validSubjects = new Set(Object.keys(SUBJECTS));

    function subjectColor(fach) {
        return SUBJECTS?.[String(fach ?? '')]?.color || '#999';
    }

    // Hidden-State bleibt über rebuildChart() erhalten
    const hiddenSubjects = Object.create(null);
    for (const [name, meta] of Object.entries(SUBJECTS)) {
        if (meta && Number(meta.legend_hidden_default) === 1) hiddenSubjects[name] = true;
    }

    function isSubjectHidden(fach) {
        return !!hiddenSubjects[String(fach ?? '')];
    }

    const validSemesters = new Set([...elSemester.options].map(o => o.value));

    let selectedSemester = localStorage.getItem('lerntime_semester') || initialSemester;
    if (!validSemesters.has(selectedSemester)) selectedSemester = initialSemester;
    elSemester.value = selectedSemester;

    function getSemesterRange(key) {
        const m = String(key).match(/^(wise|sose)_(\d{4})$/);
        if (!m) {
            const y = DateTime.local().year;
            return {
                startDay: DateTime.local(y, 1, 1).startOf('day'),
                endDay: DateTime.local(y, 12, 31).startOf('day'),
                minMs: DateTime.local(y, 1, 1).startOf('day').toMillis(),
                maxMs: DateTime.local(y, 12, 31).endOf('day').toMillis()
            };
        }
        const typ = m[1];
        const y = Number(m[2]);

        if (typ === 'wise') {
            const startDay = DateTime.local(y, 10, 1).startOf('day');
            const endDay   = DateTime.local(y + 1, 3, 31).startOf('day');
            return {
                startDay,
                endDay,
                minMs: startDay.toMillis(),
                maxMs: DateTime.local(y + 1, 3, 31).endOf('day').toMillis()
            };
        }

        const startDay = DateTime.local(y, 4, 1).startOf('day');
        const endDay   = DateTime.local(y, 9, 30).startOf('day');
        return {
            startDay,
            endDay,
            minMs: startDay.toMillis(),
            maxMs: DateTime.local(y, 9, 30).endOf('day').toMillis()
        };
    }


    let dragAllowed = false;
    let draggingRow = null;
    let draggingId = null;
    let dragStartIndex = null;

    // START AUTOSCROLL 

    const elTableWrap = document.querySelector('.lt-table-wrap');
    const elNavbar = document.querySelector('.navbar');
    let dragClientX = 0;
    let dragClientY = 0;
    let autoScrollRaf = 0;
    let autoScrollVel = 0;

    const AUTO_SCROLL_MARGIN = 90; // px Edge-Zone
    const AUTO_SCROLL_MAX    = 26; // px pro Frame

    function clamp(n, lo, hi) { return Math.max(lo, Math.min(hi, n)); }

    function getScrollTarget() {
        if (elTableWrap && elTableWrap.scrollHeight > elTableWrap.clientHeight + 1) return elTableWrap;
        return document.scrollingElement || document.documentElement;
    }

    function setDragPointer(e) {
        dragClientX = e.clientX;
        dragClientY = e.clientY;
    }

    function hoverReorder(targetRow, clientY) {
        if (!draggingRow || !targetRow || targetRow === draggingRow) return;

        clearDropMarkers();

        const rect = targetRow.getBoundingClientRect();
        const before = clientY < rect.top + rect.height / 2;

        /* targetRow.classList.toggle('lt-drop-before', before);
        targetRow.classList.toggle('lt-drop-after', !before); */

        if (before) elTbody.insertBefore(draggingRow, targetRow);
        else elTbody.insertBefore(draggingRow, targetRow.nextSibling);
    }

    function navbarBottomPx() {
        // fixed Navbar nimmt oberen Bereich ein -> Scroll-Edge darunter
        return elNavbar ? Math.max(0, elNavbar.getBoundingClientRect().bottom) : 0;
    }

    function updateAutoScrollFromPointer() {
        if (!draggingRow) return stopAutoScroll();

        const target = getScrollTarget();
        const docScroll = (document.scrollingElement || document.documentElement);

        const nb = navbarBottomPx();

        let topEdge = nb;                 // <- IMPORTANT
        let bottomEdge = window.innerHeight;

        if (target !== docScroll) {
            const r = target.getBoundingClientRect();
            topEdge = Math.max(r.top, nb); // <- IMPORTANT
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

        if (autoScrollVel !== 0 && !autoScrollRaf) {
            autoScrollRaf = requestAnimationFrame(autoScrollTick);
        } else if (autoScrollVel === 0) {
            stopAutoScroll();
        }
    }

    function autoScrollTick() {
        autoScrollRaf = 0;
        if (!draggingRow || autoScrollVel === 0) return;

        const target = getScrollTarget();
        const docScroll = (document.scrollingElement || document.documentElement);

        if (target === docScroll) {
            window.scrollBy(0, autoScrollVel);
        } else {
            const max = Math.max(0, target.scrollHeight - target.clientHeight);
            target.scrollTop = clamp(target.scrollTop + autoScrollVel, 0, max);
        }

        // Reorder auch während Auto-Scroll (falls native dragover nicht sauber triggert)
        const el = document.elementFromPoint(dragClientX, dragClientY);
        const row = el && el.closest ? el.closest('tr.lt-row') : null;
        if (row && elTbody.contains(row)) hoverReorder(row, dragClientY);

        autoScrollRaf = requestAnimationFrame(autoScrollTick);
    }

    function stopAutoScroll() {
        if (autoScrollRaf) cancelAnimationFrame(autoScrollRaf);
        autoScrollRaf = 0;
        autoScrollVel = 0;
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

    async function commitSortIfChanged() {
        if (!draggingId) return;

        const rows = [...elTbody.querySelectorAll('tr.lt-row')];
        const endIndex = rows.findIndex(r => r.dataset.id === String(draggingId));

        if (dragStartIndex === null || endIndex < 0 || endIndex === dragStartIndex) return;

        const prevId = endIndex > 0 ? rows[endIndex - 1].dataset.id : null;
        const nextId = endIndex < rows.length - 1 ? rows[endIndex + 1].dataset.id : null;

        const prevTask = prevId ? TASKS.find(t => String(t.id) === String(prevId)) : null;
        const nextTask = nextId ? TASKS.find(t => String(t.id) === String(nextId)) : null;

        const prevSort = prevTask ? Number(prevTask.sort_key ?? 0) : null;
        const nextSort = nextTask ? Number(nextTask.sort_key ?? 0) : null;

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
        } catch (e) {
            renderTable(); // revert
        }
    }




    const elEditId = document.getElementById('ltEditId');

    function openNewModal() {
        if (elEditId) elEditId.value = '';
        document.getElementById('ltModalTitle').textContent = 'Neuer Eintrag';

        elNewFach.value = selectedFach;
        elNewEinheit.value = '';
        elNewTitel.value = '';
        elNewNotiz.value = '';
        elDurH.value = 0; elDurM.value = 0; elDurS.value = 0;

        openModal(elNewEinheit);
    }

    function openEditModal(task) {
        if (elEditId) elEditId.value = String(task.id);
        document.getElementById('ltModalTitle').textContent = 'Eintrag bearbeiten';

        elNewFach.value = task.fach;
        elNewEinheit.value = task.einheit ?? '';
        elNewTitel.value = task.titel ?? '';
        elNewNotiz.value = task.notiz ?? '';

        const total = Math.max(0, Number(task.dauer_sekunden ?? 0));
        elDurH.value = Math.floor(total / 3600);
        elDurM.value = Math.floor((total % 3600) / 60);
        elDurS.value = total % 60;

        openModal(elNewTitel);
    }




    function toInt(v, def=0) {
        const n = Number(v);
        return Number.isFinite(n) ? Math.trunc(n) : def;
    }

    function pad2(n) { return String(n).padStart(2, '0'); }

    function fmtHMS(totalSeconds) {
        if (!Number.isFinite(totalSeconds) || totalSeconds <= 0) return '';
        const s = Math.max(0, Math.trunc(totalSeconds));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        if (h > 0) return `${h}:${pad2(m)}:${pad2(sec)}`;
        return `${m}:${pad2(sec)}`;
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

    let selectedFach = localStorage.getItem('lerntime_fach') || phpDefaultFach;
    if (!validSubjects.has(selectedFach)) selectedFach = phpDefaultFach;

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
                localStorage.setItem('lerntime_fach', selectedFach);
                setAccentForSubject(selectedFach);
                renderTabs();
                renderTable();
                elNewFach.value = selectedFach;

                try { await post('set_fach', { fach: selectedFach }); } catch (e) {}
            });

            elTabs.appendChild(btn);
        });
    }

    function sortTasks(a, b) {
        const da = !!(a.erledigt_am && a.erledigt_am !== 'pending');
        const db = !!(b.erledigt_am && b.erledigt_am !== 'pending');
        if (da !== db) return da ? 1 : -1; // unerledigt zuerst

        const sa = Number(a.sort_key ?? 0);
        const sb = Number(b.sort_key ?? 0);
        if (sa !== sb) return sa - sb;

        return Number(a.id) - Number(b.id);
    }

    function makeCheckbox(task) {
        const wrap = document.createElement('div');
        wrap.className = 'lt-check';

        const id = `ltDone_${task.id}`;

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.id = id;
        input.checked = !!task.erledigt_am;

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

            // Optimistisch UI
            task.erledigt_am = newChecked ? 'pending' : null;
            updateRowState(task.id);

            try {
                const res = await post('toggle_done', { id: String(task.id), checked: newChecked ? '1' : '0' });
                if (!res || !res.ok || !res.task) throw new Error('toggle_failed');

                // task update (server truth)
                const t = res.task;
                applyTaskUpdate(t);

                input.checked = !!t.erledigt_am;
                input.disabled = false;
                updateRowState(task.id);
                rebuildChart();
            } catch (e) {
                // Revert
                input.checked = !newChecked;
                task.erledigt_am = input.checked ? 'pending' : null;
                input.disabled = false;
                updateRowState(task.id);
            }
        });

        wrap.appendChild(input);
        wrap.appendChild(label);
        return wrap;
    }

    function applyTaskUpdate(updatedTask) {
        const id = Number(updatedTask.id);
        const idx = TASKS.findIndex(t => Number(t.id) === id);
        if (idx >= 0) {
            TASKS[idx] = { ...TASKS[idx], ...updatedTask };
        }
    }

    function updateRowState(taskId) {
        const row = document.querySelector(`tr[data-id="${taskId}"]`);
        if (!row) return;
        const task = TASKS.find(t => Number(t.id) === Number(taskId));
        const isDone = !!(task && task.erledigt_am && task.erledigt_am !== 'pending');
        const isPending = !!(task && task.erledigt_am === 'pending');
        row.classList.toggle('done', isDone);
        row.classList.toggle('pending', isPending);
    }

    /* FIX 2: JS – renderTable(): Anpassungen NUR in diesem Block (ersetzen/patchen) */

    function renderTable() {
        elTbody.innerHTML = '';

        const list = TASKS
            .filter(t => t.fach === selectedFach)
            .slice()
            .sort(sortTasks);

        if (list.length === 0) {
            const tr = document.createElement('tr');
            tr.className = 'lt-empty';
            const td = document.createElement('td');
            td.colSpan = 3; // <-- war 2
            td.textContent = 'Noch keine Einträge für dieses Fach.';
            tr.appendChild(td);
            elTbody.appendChild(tr);
            return;
        }

        list.forEach(task => {
            const tr = document.createElement('tr');
            tr.dataset.id = String(task.id);
            tr.className = 'lt-row' + (task.erledigt_am ? ' done' : '');
            tr.draggable = true;

            // Drag-Handle (erste Spalte)
            const tdDrag = document.createElement('td');
            tdDrag.className = 'lt-dragcell';

            const grip = document.createElement('div');
            /* grip.className = 'lt-grip'; */
            grip.innerHTML = '&#8942;&#8942;'; // ⋮⋮
            tdDrag.appendChild(grip);

            // Ganze Zelle ist der "Handle"
            tdDrag.addEventListener('pointerdown', () => { dragAllowed = true; });
            const disableDragAllowed = () => { dragAllowed = false; };
            tdDrag.addEventListener('pointerup', disableDragAllowed);
            tdDrag.addEventListener('pointercancel', disableDragAllowed);
            tdDrag.addEventListener('pointerleave', disableDragAllowed);

            // Klick auf Drag-Spalte soll NICHT Edit-Modal öffnen
            tdDrag.addEventListener('click', (e) => e.stopPropagation());

            tr.addEventListener('dragstart', (e) => {
                if (!dragAllowed) {
                    e.preventDefault();
                    return;
                }
                dragAllowed = false;

                draggingRow = tr;
                draggingId = tr.dataset.id;
                dragStartIndex = [...elTbody.querySelectorAll('tr.lt-row')]
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

            tr.addEventListener('dragleave', () => {
                tr.classList.remove('lt-drop-before', 'lt-drop-after');
            });

            tr.addEventListener('drop', (e) => {
                e.preventDefault();
                clearDropMarkers();
            });

            tr.addEventListener('dragend', async () => {
                tr.classList.remove('lt-dragging');
                clearDropMarkers();
                stopAutoScroll();

                await commitSortIfChanged();

                draggingRow = null;
                draggingId = null;
                dragStartIndex = null;
            });

            tr.addEventListener('click', (e) => {
            if (e.target.closest('.lt-check')) return;
            if (e.target.closest('.lt-dragcell')) return; // statt .lt-grip
            openEditModal(task);
            });

            // Inhalt (zweite Spalte)
            const tdLeft = document.createElement('td');
            tdLeft.className = 'lt-left';

            const main = document.createElement('div');
            main.className = 'lt-task';

            const top = document.createElement('div');
            top.className = 'lt-task-top';

            const unit = document.createElement('span');
            unit.className = 'lt-unit';
            unit.textContent = task.einheit ?? '';

            const title = document.createElement('span');
            title.className = 'lt-title';
            title.textContent = task.titel ?? '';

            top.appendChild(unit);
            top.appendChild(title);

            const meta = document.createElement('div');
            meta.className = 'lt-meta';

            const note = (task.notiz ?? '').trim();
            if (note) {
                const noteEl = document.createElement('div');
                noteEl.className = 'lt-note';
                noteEl.textContent = note;
                meta.appendChild(noteEl);
            }

            const dur = Number(task.dauer_sekunden ?? 0);
            if (dur > 0) {
                const durEl = document.createElement('div');
                durEl.className = 'lt-dur';
                durEl.textContent = `Dauer: ${fmtHMS(dur)}`;
                meta.appendChild(durEl);
            }

            main.appendChild(top);
            if (meta.childNodes.length > 0) main.appendChild(meta);

            tdLeft.appendChild(main);

            // Checkbox (dritte Spalte)
            const tdRight = document.createElement('td');
            tdRight.className = 'lt-right';
            tdRight.appendChild(makeCheckbox(task));

            tr.appendChild(tdDrag);
            tr.appendChild(tdLeft);
            tr.appendChild(tdRight);

            elTbody.appendChild(tr);
        });

        setAccentForSubject(selectedFach);
    }


    function openModal(focusEl = null) {
        elModal.classList.remove('hidden');
        elModal.setAttribute('aria-hidden', 'false');
        setTimeout(() => {
            const el = focusEl || elNewEinheit;
            if (el && typeof el.focus === 'function') el.focus();
        }, 50);
    }

    function closeModal() {
        elModal.classList.add('hidden');
        elModal.setAttribute('aria-hidden', 'true');
    }

    elAddBtn.addEventListener('click', openNewModal);
    elModalClose.addEventListener('click', closeModal);
    elModal.addEventListener('click', (e) => {
        if (e.target === elModal) closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !elModal.classList.contains('hidden')) closeModal();
    });

    elSaveNew.addEventListener('click', async () => {
        const editId = (elEditId.value || '').trim();

        const fach = elNewFach.value;
        const einheit = elNewEinheit.value.trim();
        const titel = elNewTitel.value.trim();
        const notiz = elNewNotiz.value ?? '';

        const h = Math.max(0, toInt(elDurH.value, 0));
        const m = Math.max(0, toInt(elDurM.value, 0));
        const s = Math.max(0, toInt(elDurS.value, 0));
        const dauer = (h * 3600) + (m * 60) + s;

        if (!validSubjects.has(fach)) return;
        if (!einheit || !titel) return;

        elSaveNew.disabled = true;

        try {
            if (!editId) {
                const res = await post('add_task', {
                    fach, einheit, titel, notiz, dauer_sekunden: String(dauer)
                });
                if (!res || !res.ok || !res.task) throw new Error('add_failed');

                TASKS.push(res.task);
                selectedFach = fach;
            } else {
                const res = await post('update_task', {
                    id: editId,
                    fach, einheit, titel, notiz, dauer_sekunden: String(dauer)
                });
                if (!res || !res.ok || !res.task) throw new Error('update_failed');

                applyTaskUpdate(res.task);
                selectedFach = res.task.fach;
            }

            localStorage.setItem('lerntime_fach', selectedFach);

            setAccentForSubject(selectedFach);
            renderTabs();
            renderTable();
            rebuildChart();
            closeModal();
        } catch (e) {
            // silent fail
        } finally {
            elSaveNew.disabled = false;
        }
    });

    // Monats-Labels in Monatsmitte (Labels), Gridlines bleiben Monatsanfang (Ticks)
    function monthMidMsLocal(year, monthIndex0) {
        const start = DateTime.local(year, monthIndex0 + 1, 1).startOf('day');
        const dim = start.daysInMonth;
        const mid = start.plus({ days: Math.floor(dim / 2), hours: 12 }); // mittig + 12:00
        return mid.toMillis();
    }
    function fmtMonthDE(ms) {
        return DateTime.fromMillis(ms).setLocale('de').toFormat('MMM').replace('.', '');
    }

    const midMonthLabelsPlugin = {
        id: 'midMonthLabelsPlugin',
        afterDraw(chart) {
            const scale = chart?.scales?.x;
            if (!scale || scale.type !== 'time') return;

            const xOpts = scale.options || {};
            if (!xOpts.midMonthLabels) return;

            const startMs = (typeof xOpts.midMonthLabelStartMs === 'number') ? xOpts.midMonthLabelStartMs : scale.min;
            const endMs   = (typeof xOpts.midMonthLabelEndMs === 'number') ? xOpts.midMonthLabelEndMs : scale.max;

            const compactW = xOpts.midMonthLabelCompactWidth ?? 420;
            const ctx = chart.ctx;

            // Tick-Font/Farbe übernehmen
            let fontStr = '12px sans-serif';
            try {
                if (Chart?.helpers?.toFont) fontStr = Chart.helpers.toFont(xOpts.ticks?.font).string;
            } catch (_) {}
            const color = xOpts.ticks?.color ?? Chart.defaults.color ?? '#666';

            const startMonth = DateTime.fromMillis(startMs).startOf('month');
            const endMonth   = DateTime.fromMillis(endMs).startOf('month');

            const months = [];
            let cur = startMonth;
            while (cur <= endMonth) {
                months.push(cur);
                cur = cur.plus({ months: 1 });
            }

            const step = (typeof scale.width === 'number' && scale.width < compactW) ? 2 : 1;

            ctx.save();
            ctx.font = fontStr;
            ctx.fillStyle = color;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';

            const y = scale.bottom - 2;

            months.forEach((mStart, i) => {
                if (step === 2 && (i % 2 === 1)) return;
                const mid = mStart
                    .startOf('day')
                    .plus({ days: Math.floor(mStart.daysInMonth / 2), hours: 12 });
                const x = scale.getPixelForValue(mid.toMillis());
                ctx.fillText(mStart.setLocale('de').toFormat('MMM').replace('.', ''), x, y);
            });

            ctx.restore();
        }
    };
    try { Chart.register(midMonthLabelsPlugin); } catch (e) {}

    try {
        const ann = window.ChartAnnotation || window['chartjs-plugin-annotation'];
        if (ann) Chart.register(ann);
    } catch (e) {}

    /* ---------------------- Chart ---------------------- */
    let chart = null;

    function secToHours(sec) {
        const s = Number(sec);
        if (!Number.isFinite(s)) return 0;
        return s / 3600;
    }

    function buildDailyRemainingSeriesForSubject(fach, startDay, endDay) {
        const startMs = startDay.toMillis();
        const endMs   = endDay.endOf('day').toMillis();

        const list = TASKS.filter(t => t.fach === fach);

        let total = 0;
        let doneBefore = 0;
        const doneByDate = Object.create(null); // YYYY-MM-DD -> sec

        for (const t of list) {
            const dur = Math.max(0, Number(t.dauer_sekunden ?? 0));
            total += dur;

            if (!t.erledigt_am) continue;

            const dt = DateTime.fromSQL(String(t.erledigt_am), { zone: 'local' });
            if (!dt.isValid) continue;

            const ms = dt.toMillis();

            if (ms < startMs) { doneBefore += dur; continue; }
            if (ms > endMs) continue;

            const key = dt.toISODate();
            doneByDate[key] = (doneByDate[key] || 0) + dur;
        }

        let remaining = Math.max(0, total - doneBefore);
        const points = [];

        let cursor = startDay;
        while (cursor <= endDay) {
            const key = cursor.toISODate();
            if (doneByDate[key]) remaining = Math.max(0, remaining - doneByDate[key]);

            points.push({ x: cursor.toMillis(), y: secToHours(remaining) }); // 00:00
            cursor = cursor.plus({ days: 1 });
        }

        return points;
        }


    function buildRemainingStepSeriesForSubject(fach, startDay, endDay) {
        const startMs = startDay.toMillis();
        const endMs   = endDay.endOf('day').toMillis();

        const list = TASKS.filter(t => t.fach === fach);

        // Total Workload (sek) aus allen Tasks dieses Fachs
        let total = 0;
        for (const t of list) {
            total += Math.max(0, Number(t.dauer_sekunden ?? 0));
        }

        // Events: { ms, dur } für erledigte Tasks innerhalb des Ranges
        let doneBefore = 0;
        const events = [];

        for (const t of list) {
            const dur = Math.max(0, Number(t.dauer_sekunden ?? 0));
            if (!dur) continue;
            if (!t.erledigt_am) continue;

            const dt = DateTime.fromSQL(String(t.erledigt_am), { zone: 'local' });
            if (!dt.isValid) continue;

            const ms = dt.toMillis();

            if (ms < startMs) {
                doneBefore += dur;
                continue;
            }
            if (ms > endMs) continue;

            events.push({ ms, dur });
        }

        // Startwert: Rest = total - alles was vor Range schon erledigt war
        let remaining = Math.max(0, total - doneBefore);

        // Events nach Zeit sortieren
        events.sort((a, b) => a.ms - b.ms);

        // Falls mehrere Events exakt gleiche ms haben: zusammenfassen
        const merged = [];
        for (const ev of events) {
            const last = merged[merged.length - 1];
            if (last && last.ms === ev.ms) last.dur += ev.dur;
            else merged.push({ ...ev });
        }

        const points = [];
        // Initialpunkt (damit Linie im Range beginnt)
        points.push({ x: startMs, y: secToHours(remaining) });

        // Bei jedem Event: Drop exakt zur Timestamp
        for (const ev of merged) {
            remaining = Math.max(0, remaining - ev.dur);
            points.push({ x: ev.ms, y: secToHours(remaining) });
        }

        // Endpunkt (damit Linie bis Range-Ende weiterläuft)
        points.push({ x: endMs, y: secToHours(remaining) });

        return points;
    }


    function rebuildChart() {
        const range = getSemesterRange(selectedSemester);

        const annotations = {};

        (() => {
            const now = DateTime.local();
            const nowMs = now.toMillis();
            if (nowMs >= range.minMs && nowMs <= range.maxMs) {
                annotations['today_line'] = {
                    type: 'line',
                    xMin: nowMs,
                    xMax: nowMs,
                    borderColor: '#000',
                    borderWidth: 1,
                    drawTime: 'afterDatasetsDraw'
                };
            }
        })();

        for (const ex of (Array.isArray(EXAMS) ? EXAMS : [])) {
            const fach = String(ex.fach ?? '');
            if (!SUBJECTS[fach]) continue;

            const dt = DateTime.fromSQL(String(ex.datum ?? ''), { zone: 'local' });
            if (!dt.isValid) continue;

            const ms = dt.startOf('day').plus({ hours: 12 }).toMillis();
            if (ms < range.minMs || ms > range.maxMs) continue;

            annotations[`exam_${ex.id}`] = {
                type: 'line',
                xMin: ms,
                xMax: ms,
                borderColor: subjectColor(fach),
                borderWidth: 4,
                borderDash: [6, 6],
                drawTime: 'afterDatasetsDraw'
            };
        }

        const datasets = Object.keys(SUBJECTS).flatMap((fach) => {
            const color = subjectColor(fach);
            const hidden = isSubjectHidden(fach);

            return [
                {
                    label: fach,
                    data: buildRemainingStepSeriesForSubject(fach, range.startDay, range.endDay),
                    parsing: false,
                    borderColor: color,
                    backgroundColor: color,
                    borderWidth: 5,
                    pointRadius: 0,
                    hitRadius: 0,
                    hoverRadius: 0,
                    tension: 0.25,
                    stepped: 'after',
                    hidden
                },
                {
                    label: fach,
                    data: buildDailyRemainingSeriesForSubject(fach, range.startDay, range.endDay),
                    parsing: false,
                    showLine: false,
                    borderWidth: 0,
                    pointRadius: 0,
                    hitRadius: 14,
                    hoverRadius: 0,
                    borderColor: color,
                    backgroundColor: color,
                    ltHover: true,
                    hidden
                }
            ];
        });

        const cfg = {
            type: 'line',
            data: { datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                adapters: {
                    date: { zone: 'local' }
                },
                interaction: { mode: 'nearest', axis: 'x', intersect: false },
                plugins: {
                    legend: {
                      position: 'top',
                      labels: {
                        boxWidth: 14,
                        boxHeight: 14,
                        filter: (legendItem, data) => {
                          const ds = data?.datasets?.[legendItem.datasetIndex];
                          return !ds?.ltHover; // Hover-Serie aus Legend raus
                        }
                      },
                      onClick: (e, legendItem, legend) => {
                        const chart = legend.chart;

                        // fach aus Legend-Text (entspricht label)
                        const fach = String(legendItem.text ?? '');

                        // aktueller Sichtbarkeits-Status (Step-Dataset ist das Legend-Item)
                        const idx0 = legendItem.datasetIndex;
                        const nextVisible = !chart.isDatasetVisible(idx0);

                        // State merken (damit rebuildChart() das beibehält)
                        hiddenSubjects[fach] = !nextVisible;

                        // Beide Datasets (Step + Hover) für dieses Fach synchron toggeln
                        chart.data.datasets.forEach((ds, i) => {
                          if (ds && ds.label === fach) chart.setDatasetVisibility(i, nextVisible);
                        });

                        chart.update();
                      }
                    },
                    tooltip: {
                    displayColors: true,
                    boxWidth: 12,
                    boxHeight: 12,
                    callbacks: {
                        label: (ctx) => {
                        const v = Number(ctx.parsed.y ?? 0);
                        const hours = Math.round(v * 10) / 10;
                        return `${ctx.dataset.label}: ${hours} h`;
                        },

                        // Farbbox links im Tooltip passend zum Fach
                        labelColor: (ctx) => {
                        const ds = ctx.dataset || {};
                        let c = ds.borderColor || ds.backgroundColor || '#999';
                        if (Array.isArray(c)) c = c[0]; // falls mal Array-Farben drin sind
                        return {
                            borderColor: c,
                            backgroundColor: c
                        };
                        },
                    }
                    },
                    annotation: {
                        annotations
                    }
                },
                scales: {
                    x: {
                        type: 'time',
                        time: { unit: 'month', tooltipFormat: 'dd.LL.yyyy' },

                        midMonthLabels: true,
                        midMonthLabelStartMs: range.minMs,
                        midMonthLabelEndMs: range.maxMs,
                        midMonthLabelCompactWidth: 420,

                        ticks: {
                            autoSkip: false,
                            maxRotation: 0,
                            minRotation: 0,
                            callback: () => ' '
                        },
                        grid: { display: true },

                        min: range.minMs,
                        max: range.maxMs
                    },
                    y: {
                        title: { display: true, text: 'Restaufwand (h)' },
                        ticks: {
                            callback: (v) => {
                                const n = Number(v);
                                if (!Number.isFinite(n)) return v;
                                return (Math.round(n * 10) / 10).toString();
                            }
                        },
                        beginAtZero: true
                    }
                }
            }
        };

        const ctx = document.getElementById('ltRemainingChart');
        if (chart) {
            chart.data = cfg.data;
            chart.options = cfg.options;
            chart.update();
        } else {
            chart = new Chart(ctx, cfg);
        }
    }

    elSemester.addEventListener('change', () => {
        selectedSemester = elSemester.value;
        localStorage.setItem('lerntime_semester', selectedSemester);

        // optional: URL-Param persistieren
        const u = new URL(location.href);
        u.searchParams.set('semester', selectedSemester);
        history.replaceState(null, '', u.toString());

        rebuildChart();
    });

    /* ---------------------- Init ---------------------- */
    function init() {
        setAccentForSubject(selectedFach);
        renderTabs();
        renderTable();
        rebuildChart();
    }

    init();
})();
</script>

</body>
</html>
