<?php
// tools/LoginLogs.php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$loginconn->set_charset('utf8mb4');

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

function clamp_int($v, int $min, int $max, int $default): int {
    $n = filter_var($v, FILTER_VALIDATE_INT);
    if ($n === false) return $default;
    return max($min, min($max, (int)$n));
}

function build_logs_query(mysqli $conn, string $view, int $limit, string $q): array {
    $view = strtolower(trim($view));
    $q = trim($q);

    $where = [];
    $types = '';
    $params = [];

    switch ($view) {
        case 'success':
            $where[] = "success = 1";
            break;
        case 'fail':
            $where[] = "success = 0";
            break;
        case 'ip':
            $where[] = "auth_mode = 'ip'";
            break;
        case 'pw':
            $where[] = "auth_mode = 'pw'";
            break;
        case 'fail_pw':
            $where[] = "success = 0";
            $where[] = "auth_mode = 'pw'";
            break;
        case 'last24h':
            $where[] = "event_time >= (NOW(6) - INTERVAL 1 DAY)";
            break;
        case 'last7d':
            $where[] = "event_time >= (NOW(6) - INTERVAL 7 DAY)";
            break;
        case 'all':
        default:
            $view = 'all';
            break;
    }

    if ($q !== '') {
        // Suche über typische Felder; user_agent kann groß sein, daher LIMIT nutzen (und Debounce im JS).
        $where[] = "(username LIKE ? OR client_ip_text LIKE ? OR request_uri LIKE ? OR user_agent LIKE ?)";
        $pat = '%' . $q . '%';
        $types .= 'ssss';
        array_push($params, $pat, $pat, $pat, $pat);
    }

    $sql = "
        SELECT
            id,
            event_time,
            username,
            auth_mode,
            success,
            http_status,
            client_ip_text,
            x_forwarded_for,
            session_id,
            host,
            request_uri,
            referer,
            user_agent
        FROM login_events
    ";

    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY id DESC LIMIT ?";

    $types .= 'i';
    $params[] = $limit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [null, "prepare_failed"];
    }

    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $stmt->close();
        return [null, "execute_failed"];
    }

    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    $stmt->close();

    return [$rows, null];
}

/* ---------------------- POST (AJAX) ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'get_header') {
        // Nur: Fail-Anzahl innerhalb des letzten Monats
        $res = $loginconn->query("
            SELECT
                SUM(event_time >= (NOW(6) - INTERVAL 1 MONTH) AND success = 0) AS fail_month
            FROM login_events
        ");
        $failMonth = 0;
        if ($res && ($r = $res->fetch_assoc())) {
            $failMonth = (int)($r['fail_month'] ?? 0);
        }
        json_out(['ok' => true, 'fail_month' => $failMonth]);
    }

    if ($action === 'get_logs') {
        $view  = (string)($_POST['view'] ?? 'all');
        $limit = clamp_int($_POST['limit'] ?? null, 20, 2000, 200);
        $q     = (string)($_POST['q'] ?? '');

        [$rows, $err] = build_logs_query($loginconn, $view, $limit, $q);
        if ($err !== null) {
            json_out(['ok' => false, 'error' => 'DB-Fehler beim Laden.'], 500);
        }

        json_out(['ok' => true, 'view' => $view, 'limit' => $limit, 'rows' => $rows]);
    }

    json_out(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
}

/* ---------------------- Page ---------------------- */
$page_title = 'Login-Logs';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div id="llPage" class="lt-page dashboard-page">
    <div class="lt-topbar">
        <h1 class="ueberschrift dashboard-title">
            <span class="dashboard-title-main">Login-Logs</span>
            <span class="dashboard-title-soft">
                | <span id="llHdrFailMonth">0</span> Fehlanmeldungen im letzten Monat
            </span>
        </h1>

        <form method="get" id="llForm" class="dashboard-filterform" onsubmit="return false;">
            <div class="lt-yearwrap">
                <label for="llView" class="lt-label">Ansicht</label>
                <select id="llView" class="kategorie-select">
                    <option value="all">Alle</option>
                    <option value="success">Nur erfolgreich</option>
                    <option value="fail">Nur abgelehnt</option>
                    <option value="fail_pw">Abgelehnt (Passwort)</option>
                    <option value="ip">Nur IP-Whitelist</option>
                    <option value="pw">Nur Passwort</option>
                    <option value="last24h">Letzte 24h</option>
                    <option value="last7d">Letzte 7 Tage</option>
                </select>
            </div>

            <div class="lt-yearwrap">
                <label for="llLimit" class="lt-label">Limit</label>
                <select id="llLimit" class="kategorie-select">
                    <option value="100">100</option>
                    <option value="200" selected>200</option>
                    <option value="500">500</option>
                    <option value="1000">1000</option>
                    <option value="2000">2000</option>
                </select>
            </div>

            <div class="lt-yearwrap">
                <label for="llQ" class="lt-label">Suche</label>
                <input id="llQ" class="kategorie-select" type="text">
            </div>
        </form>
    </div>

    <table class="food-table">
        <thead>
            <tr>
                <th>Zeit</th>
                <th>User</th>
                <th>IP</th>
                <th>User-Agent</th>
            </tr>
        </thead>
        <tbody id="llTbody"></tbody>
    </table>
</div>

<script>
(() => {
    const elFailMonth = document.getElementById('llHdrFailMonth');

    const elView = document.getElementById('llView');
    const elLimit = document.getElementById('llLimit');
    const elQ = document.getElementById('llQ');
    const elTbody = document.getElementById('llTbody');

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

    function parseMysqlDateLocal(v) {
        if (!v) return null;
        const s = String(v).trim();
        const m = s.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.\d+)?$/);
        if (!m) return null;
        return new Date(`${m[1]}T${m[2]}`);
    }

    function fmtDe(v) {
        const d = parseMysqlDateLocal(v);
        if (!d) return String(v || '');
        return d.toLocaleString('de-DE', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
    }

    function setBusy(b) {
        if (elView) elView.disabled = !!b;
        if (elLimit) elLimit.disabled = !!b;
    }

    function savePrefs() {
        try {
            localStorage.setItem('loginlogs_view', String(elView.value || 'all'));
            localStorage.setItem('loginlogs_limit', String(elLimit.value || '200'));
            localStorage.setItem('loginlogs_q', String(elQ.value || ''));
        } catch (_) {}
    }

    function loadPrefs() {
        try {
            const v = localStorage.getItem('loginlogs_view');
            const l = localStorage.getItem('loginlogs_limit');
            const q = localStorage.getItem('loginlogs_q');

            if (v) elView.value = v;
            if (l) elLimit.value = l;
            if (q) elQ.value = q;
        } catch (_) {}
    }

    async function loadHeader() {
        try {
            const r = await post('get_header', {});
            if (!r || !r.ok) return;
            elFailMonth.textContent = String(r.fail_month ?? 0);
        } catch (_) {}
    }

    function renderRows(rows) {
        elTbody.innerHTML = '';

        if (!rows || !rows.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 4;
            td.textContent = 'Keine Einträge.';
            tr.appendChild(td);
            elTbody.appendChild(tr);
            return;
        }

        for (const r of rows) {
            const tr = document.createElement('tr');

            // Fail optisch leicht markieren (ohne neue CSS-Klassen)
            if (String(r.success) === '0') {
                tr.style.background = 'rgba(255, 0, 0, 0.06)';
            }

            const tdTime = document.createElement('td');
            tdTime.textContent = fmtDe(r.event_time);

            const tdUser = document.createElement('td');
            tdUser.textContent = String(r.username ?? '');

            const tdIp = document.createElement('td');
            tdIp.textContent = String(r.client_ip_text ?? '');

            const tdUa = document.createElement('td');

            // User-Agent mit Zeilenumbruch
            const uaDiv = document.createElement('div');
            uaDiv.textContent = String(r.user_agent ?? '');
            uaDiv.style.whiteSpace = 'pre-wrap';
            uaDiv.style.overflowWrap = 'anywhere';
            uaDiv.style.wordBreak = 'break-word';

            // kleine Meta-Zeile darunter (Status / Mode) – weiterhin innerhalb der relevanten Spalte
            const metaDiv = document.createElement('div');
            metaDiv.style.opacity = '0.75';
            metaDiv.style.fontSize = '0.92em';

            const mode = String(r.auth_mode ?? '');
            const st = (r.http_status != null) ? String(r.http_status) : '';
            const ok = String(r.success) === '1' ? 'OK' : 'FAIL';
            metaDiv.textContent = `${ok}${st ? ' / ' + st : ''}${mode ? ' / ' + mode : ''}`;

            tdUa.appendChild(uaDiv);
            tdUa.appendChild(metaDiv);

            tr.appendChild(tdTime);
            tr.appendChild(tdUser);
            tr.appendChild(tdIp);
            tr.appendChild(tdUa);

            // Details-Zeile per Klick togglen (URI/Referer/Host/XFF/Session)
            tr.style.cursor = 'pointer';
            tr.addEventListener('click', () => {
                const existing = elTbody.querySelector(`tr[data-details-for="${r.id}"]`);
                if (existing) {
                    existing.remove();
                    return;
                }

                const dtr = document.createElement('tr');
                dtr.dataset.detailsFor = String(r.id);

                const dtd = document.createElement('td');
                dtd.colSpan = 4;
                dtd.style.paddingTop = '6px';
                dtd.style.paddingBottom = '10px';

                const details = document.createElement('div');
                details.style.whiteSpace = 'pre-wrap';
                details.style.overflowWrap = 'anywhere';
                details.style.wordBreak = 'break-word';
                details.style.opacity = '0.9';

                const lines = [];
                if (r.request_uri) lines.push(`URI: ${r.request_uri}`);
                if (r.referer) lines.push(`Referer: ${r.referer}`);
                if (r.host) lines.push(`Host: ${r.host}`);
                if (r.x_forwarded_for) lines.push(`X-Forwarded-For: ${r.x_forwarded_for}`);
                if (r.session_id) lines.push(`Session: ${r.session_id}`);

                details.textContent = lines.length ? lines.join('\n') : 'Keine weiteren Details.';
                dtd.appendChild(details);
                dtr.appendChild(dtd);

                tr.insertAdjacentElement('afterend', dtr);
            });

            elTbody.appendChild(tr);
        }
    }

    async function loadLogs() {
        savePrefs();
        setBusy(true);
        try {
            const view = String(elView.value || 'all');
            const limit = String(elLimit.value || '200');
            const q = String(elQ.value || '');

            const r = await post('get_logs', { view, limit, q });
            if (!r || !r.ok) {
                renderRows([]);
                return;
            }
            renderRows(r.rows || []);
        } catch (_) {
            renderRows([]);
        } finally {
            setBusy(false);
        }
    }

    // Debounce für Suche (damit Tippen nicht 20 Requests erzeugt)
    let qTimer = 0;
    function scheduleLoadFromSearch() {
        if (qTimer) clearTimeout(qTimer);
        qTimer = setTimeout(() => {
            qTimer = 0;
            loadLogs();
        }, 250);
    }

    // UI Hooks: jede Änderung lädt direkt neu
    elView.addEventListener('change', () => loadLogs());
    elLimit.addEventListener('change', () => loadLogs());
    elQ.addEventListener('input', () => scheduleLoadFromSearch());
    elQ.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (qTimer) clearTimeout(qTimer);
            qTimer = 0;
            loadLogs();
        }
    });

    // init
    loadPrefs();
    loadHeader();
    loadLogs();
})();
</script>

</body>
</html>
