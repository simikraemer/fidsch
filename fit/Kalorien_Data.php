<?php
// fit/Kalorien_Data.php

// 1) Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// 2) DB
require_once __DIR__ . '/../db.php';
$fitconn->set_charset('utf8mb4');

// --- CSRF ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

// ---------------------- POST-VERARBEITUNG (AJAX) ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';
    $csrf   = $_POST['csrf']   ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'CSRF ungültig.']);
        exit;
    }

    if ($action === 'list') {
        // Gruppierte Liste laden
        $sql = "
            SELECT 
                COALESCE(beschreibung, '') AS beschreibung,
                kalorien,
                COUNT(*) AS anzahl,
                ROUND(AVG(`eiweiß`), 2) AS eiweiss,
                ROUND(AVG(`fett`), 2) AS fett,
                ROUND(AVG(`kohlenhydrate`), 2) AS kohlenhydrate,
                ROUND(AVG(`alkohol`), 2) AS alkohol,
                MAX(tstamp) AS last_used
            FROM kalorien
            WHERE beschreibung IS NOT NULL
            AND TRIM(beschreibung) <> ''
            GROUP BY beschreibung, kalorien
            ORDER BY beschreibung ASC, kalorien ASC
        ";
        $res = $fitconn->query($sql);
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'beschreibung' => $row['beschreibung'],
                'kalorien'     => (int)$row['kalorien'],
                'anzahl'       => (int)$row['anzahl'],
                'eiweiss'      => (float)$row['eiweiss'],
                'fett'         => (float)$row['fett'],
                'kohlenhydrate'=> (float)$row['kohlenhydrate'],
                'alkohol'      => (float)$row['alkohol'],
                'last_used'    => $row['last_used'],
            ];
        }
        echo json_encode(['ok' => true, 'items' => $items]);
        exit;
    }

    if ($action === 'update') {
        // Validierung + Normalisierung
        $orig_beschreibung = $_POST['orig_beschreibung'] ?? '';
        $orig_kalorien     = isset($_POST['orig_kalorien']) ? (int)$_POST['orig_kalorien'] : 0;

        $beschreibung = trim((string)($_POST['beschreibung'] ?? ''));
        $kalorien     = isset($_POST['kalorien']) ? (int)$_POST['kalorien'] : 0;

        $norm = function($v) {
            $v = str_replace(',', '.', (string)$v);
            return round((float)$v, 2);
        };
        $eiweiss       = $norm($_POST['eiweiss']       ?? 0);
        $fett          = $norm($_POST['fett']          ?? 0);
        $kohlenhydrate = $norm($_POST['kohlenhydrate'] ?? 0);
        $alkohol       = $norm($_POST['alkohol']       ?? 0);

        if ($kalorien < 0 || $kalorien > 5000) {
            echo json_encode(['ok' => false, 'msg' => 'Kalorien unplausibel.']);
            exit;
        }
        if (mb_strlen($beschreibung) > 255) {
            echo json_encode(['ok' => false, 'msg' => 'Beschreibung zu lang (max. 255).']);
            exit;
        }

        // Update ALLER passenden Einträge
        $sql = "UPDATE kalorien
                SET beschreibung = ?, kalorien = ?, `eiweiß` = ?, `fett` = ?, `kohlenhydrate` = ?, `alkohol` = ?
                WHERE COALESCE(beschreibung, '') = ? AND kalorien = ?";

        $stmt = $fitconn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['ok' => false, 'msg' => 'DB-Fehler (prepare): ' . $fitconn->error]);
            exit;
        }

        // Typen: s (string), i (int), d (double), d, d, d, s, i  => "siddddsi"
        $ok = $stmt->bind_param(
            'siddddsi',
            $beschreibung,      // s
            $kalorien,          // i
            $eiweiss,           // d
            $fett,              // d
            $kohlenhydrate,     // d
            $alkohol,           // d
            $orig_beschreibung, // s
            $orig_kalorien      // i
        );

        if (!$ok || !$stmt->execute()) {
            echo json_encode(['ok' => false, 'msg' => 'DB-Fehler beim Aktualisieren: ' . $stmt->error]);
            exit;
        }

        echo json_encode(['ok' => true, 'affected' => $stmt->affected_rows]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unbekannte Aktion.']);
    exit;
}
// ---------------------- RENDERING START ----------------------
$page_title = 'Kalorien Daten';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>
<div class="content-wrap">
  <div class="toolbar">
    <div class="toolbar-left">
      <input id="searchInput" type="text" class="search-input" placeholder="🔎 Suche nach Beschreibung …">
    </div>
    <div class="toolbar-right">
      <span id="statsBadge" class="badge">0 / 0 Gruppen</span>
    </div>
  </div>

  <div id="listMount" class="food-grid" aria-live="polite"></div>

  <div class="loadmore-wrap">
    <button id="loadMoreBtn" class="loadmore-btn" style="display:none;">Mehr laden</button>
  </div>

  <div id="statusMsg" class="status-msg" style="display:none;"></div>
</div>

<!-- Modal -->
<div id="editModal" class="modal" style="display:none;">
  <div class="modal-content">
    <span id="modalClose" class="close-button" aria-label="Modal schließen">&times;</span>
    <h2 style="margin-top:0;">Gruppe bearbeiten</h2>

    <div class="input-row">
        <div class="input-group">
            <label>Beschreibung</label>
            <input type="text" id="m_beschreibung" maxlength="255" />
        </div>
        <div class="input-group">
            <label>Kalorien</label>
            <input type="number" id="m_kalorien" min="0" max="5000" step="1" />
            <div id="m_kcal-pruefsumme" style="margin-top:6px; font-size:0.9em; opacity:0.8;">
            Prüfsumme: <span id="m_kcal-check">0</span> kcal
            </div>
        </div>
    </div>

    <div class="input-row">
      <div class="input-group">
        <label>Eiweiß (g)</label>
        <input type="number" id="m_eiweiss" min="0" max="500" step="0.01" />
      </div>
      <div class="input-group">
        <label>Fett (g)</label>
        <input type="number" id="m_fett" min="0" max="500" step="0.01" />
      </div>
    </div>

    <div class="input-row">
      <div class="input-group">
        <label>Kohlenhydrate (g)</label>
        <input type="number" id="m_kohlenhydrate" min="0" max="1000" step="0.01" />
      </div>
      <div class="input-group">
        <label>Alkohol (g)</label>
        <input type="number" id="m_alkohol" min="0" max="500" step="0.01" />
      </div>
    </div>

    <div class="modal-actions">
      <button id="saveBtn">Alle Einträge dieser Gruppe speichern</button>
      <button type="button" id="cancelBtn" class="btn-secondary">Abbrechen</button>
    </div>

    <input type="hidden" id="m_orig_beschreibung">
    <input type="hidden" id="m_orig_kalorien">
  </div>
</div>

<script>
(() => {
  const csrf = <?= json_encode($CSRF) ?>;

  const listMount   = document.getElementById('listMount');
  const statsBadge  = document.getElementById('statsBadge');
  const loadMoreBtn = document.getElementById('loadMoreBtn');
  const statusMsg   = document.getElementById('statusMsg');
  const searchInput = document.getElementById('searchInput');

  const modal       = document.getElementById('editModal');
  const modalClose  = document.getElementById('modalClose');
  const cancelBtn   = document.getElementById('cancelBtn');
  const saveBtn     = document.getElementById('saveBtn');

  const m_beschreibung  = document.getElementById('m_beschreibung');
  const m_kalorien      = document.getElementById('m_kalorien');
  const m_eiweiss       = document.getElementById('m_eiweiss');
  const m_fett          = document.getElementById('m_fett');
  const m_kohlenhydrate = document.getElementById('m_kohlenhydrate');
  const m_alkohol       = document.getElementById('m_alkohol');
  const m_orig_beschreibung = document.getElementById('m_orig_beschreibung');
  const m_orig_kalorien     = document.getElementById('m_orig_kalorien');

  const m_kcalCheckSpan = document.getElementById('m_kcal-check');

  let ALL = [];
  let FILTERED = [];
  let rendered = 0;
  const PAGE = 150;

  function showStatus(text, type='ok', timeout=3000) {
    statusMsg.textContent = text;
    statusMsg.className = 'status-msg ' + (type === 'ok' ? 'is-ok' : 'is-err');
    statusMsg.style.display = 'block';
    if (timeout) {
      setTimeout(() => { statusMsg.style.display = 'none'; }, timeout);
    }
  }

  function fmtDec(x) {
    return (Math.round((x ?? 0) * 100) / 100).toFixed(2).replace('.', ',');
  }

  function dateDE(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
    return m ? `${m[3]}.${m[2]}.${m[1]}` : '';
  }

  function cardTpl(item) {
    const desc = item.beschreibung || '— ohne Beschreibung —';
    return `
      <button class="food-card" data-b="${encodeURIComponent(item.beschreibung)}" data-k="${item.kalorien}">
        <div class="card-title">
          <span class="title-text">${escapeHtml(desc)}</span>
        </div>
        <div class="meta-row">
          <span class="badge">${item.anzahl}×</span>
          <span class="pill">${item.kalorien} kcal</span>
        </div>
        <div class="meta-row">
          <span class="nutri">EW ${fmtDec(item.eiweiss)}g</span>
          <span class="nutri">F ${fmtDec(item.fett)}g</span>
          <span class="nutri">KH ${fmtDec(item.kohlenhydrate)}g</span>
          ${item.alkohol > 0 ? `<span class="nutri">ALK ${fmtDec(item.alkohol)}g</span>` : ''}
        </div>
        <div class="subtle">Zuletzt: ${dateDE(item.last_used)}</div>
      </button>
    `;
  }

  function escapeHtml(s) {
    return (s ?? '').replace(/[&<>"']/g, m => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  function renderReset() {
    listMount.innerHTML = '';
    rendered = 0;
    loadMoreBtn.style.display = 'none';
  }

  function renderNext() {
    const end = Math.min(rendered + PAGE, FILTERED.length);
    const frag = document.createDocumentFragment();
    for (let i = rendered; i < end; i++) {
      const wrap = document.createElement('div');
      wrap.innerHTML = cardTpl(FILTERED[i]);
      const btn = wrap.firstElementChild;
      btn.addEventListener('click', () => openModal(FILTERED[i]));
      frag.appendChild(btn);
    }
    listMount.appendChild(frag);
    rendered = end;
    loadMoreBtn.style.display = rendered < FILTERED.length ? 'inline-block' : 'none';
    statsBadge.textContent = `${FILTERED.length} / ${ALL.length} Gruppen`;
  }

  function applyFilter() {
    const q = searchInput.value.trim().toLowerCase();
    if (!q) {
      FILTERED = ALL.slice();
    } else {
      FILTERED = ALL.filter(x => (x.beschreibung || '').toLowerCase().includes(q));
    }
    renderReset();
    renderNext();
  }

  async function fetchList() {
    const body = new URLSearchParams({ action: 'list', csrf });
    const res = await fetch(location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    const data = await res.json();
    if (!data.ok) throw new Error(data.msg || 'Unbekannter Fehler');
    ALL = data.items || [];
    applyFilter();
  }

  function numOrZeroModal(v) {
    v = (v ?? '').toString().replace(',', '.').trim();
    if (v === '') return 0;
    const n = Number(v);
    return Number.isFinite(n) ? n : 0;
  }

  function recomputeModalChecksum() {
    const eiw = numOrZeroModal(m_eiweiss.value);
    const fett = numOrZeroModal(m_fett.value);
    const kh   = numOrZeroModal(m_kohlenhydrate.value);
    const alk  = numOrZeroModal(m_alkohol.value);
    const kcal = (eiw * 4) + (kh * 4) + (fett * 9) + (alk * 7);
    if (m_kcalCheckSpan) {
      m_kcalCheckSpan.textContent = String(Math.round(kcal));
    }
  }

  function openModal(item) {
    m_orig_beschreibung.value = item.beschreibung || '';
    m_orig_kalorien.value     = String(item.kalorien);

    m_beschreibung.value  = item.beschreibung || '';
    m_kalorien.value      = item.kalorien;
    m_eiweiss.value       = (item.eiweiss ?? 0).toString();
    m_fett.value          = (item.fett ?? 0).toString();
    m_kohlenhydrate.value = (item.kohlenhydrate ?? 0).toString();
    m_alkohol.value       = (item.alkohol ?? 0).toString();

    modal.style.display = 'flex';

    // Prüfsumme initial berechnen
    recomputeModalChecksum();
  }

  function closeModal() {
    modal.style.display = 'none';
  }

  async function saveAll() {
    const toNum = v => {
      v = (v ?? '').toString().replace(',', '.').trim();
      if (v === '') return 0;
      const n = Number(v);
      return isFinite(n) ? Math.round(n * 100) / 100 : 0;
    };

    const payload = new URLSearchParams({
      action: 'update',
      csrf,
      orig_beschreibung: m_orig_beschreibung.value,
      orig_kalorien: m_orig_kalorien.value,
      beschreibung: m_beschreibung.value.trim(),
      kalorien: String(Math.max(0, parseInt(m_kalorien.value || '0', 10))),
      eiweiss: String(toNum(m_eiweiss.value)),
      fett: String(toNum(m_fett.value)),
      kohlenhydrate: String(toNum(m_kohlenhydrate.value)),
      alkohol: String(toNum(m_alkohol.value)),
    });

    saveBtn.disabled = true;
    try {
      const res = await fetch(location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.msg || 'Update fehlgeschlagen.');
      showStatus(`Gespeichert. ${data.affected ?? 0} Einträge aktualisiert.`, 'ok');
      closeModal();
      await fetchList(); // neu laden, da Gruppe/n sich geändert haben könnten
    } catch (e) {
      showStatus(String(e.message || e), 'err', 5000);
    } finally {
      saveBtn.disabled = false;
    }
  }

  // Events
  loadMoreBtn.addEventListener('click', renderNext);
  searchInput.addEventListener('input', applyFilter);
  modalClose.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
  });
  saveBtn.addEventListener('click', saveAll);

  // Live-Update der Prüfsumme im Modal
  [m_eiweiss, m_fett, m_kohlenhydrate, m_alkohol].forEach(el => {
    el.addEventListener('input', recomputeModalChecksum);
  });

  // Init
  fetchList().catch(err => showStatus('Fehler beim Laden: ' + err.message, 'err', 8000));
})();
</script>
