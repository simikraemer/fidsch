<?php
// ---- POST-Verarbeitung (kein Output davor!) ----
$mac_colons = $_POST['mac_colons'] ?? '';
$mac_dots   = $_POST['mac_dots']   ?? '';
$mac_minus  = $_POST['mac_minus']  ?? '';
$mac_upper  = $_POST['mac_upper']  ?? '';
$mac_lower  = $_POST['mac_lower']  ?? '';
$mac_raw    = $_POST['mac_raw']    ?? '';

function mac_clean_12($s) {
    return preg_replace('/[^a-f0-9]/i', '', $s ?? '');
}

$base = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ([$mac_colons, $mac_dots, $mac_minus, $mac_upper, $mac_lower, $mac_raw] as $candidate) {
        $c = strtolower(mac_clean_12($candidate));
        if (strlen($c) === 12) { $base = $c; break; }
    }
    if ($base) {
        $pairs       = implode(':', str_split($base, 2));
        $mac_colons  = $pairs;                          // Standard (case-insensitiv)
        $mac_dots    = implode('.', str_split($base, 4)); // Cisco
        $mac_minus   = implode('-', str_split($base, 2)); // Minus
        $mac_upper   = strtoupper($pairs);              // Colons UPPER
        $mac_lower   = strtolower($pairs);              // Colons lower
        $mac_raw     = $base;                           // Raw
    }
}

// ---- Rendering starten ----
$page_title = 'MAC-Konverter';
require_once __DIR__ . '/../head.php';     // <!DOCTYPE html> … <body>
require_once __DIR__ . '/../navbar.php';   // nur die Navbar
?>
<div class="container">
    <h2 class="ueberschrift">MAC-Adressen-Konverter</h2>
    <form method="post" action="">
        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <td style="vertical-align: middle; width:50%;">
                    <label for="mac_colons"><strong>Standardformat</strong></label>
                    <span id="warn_colons" style="display:none; color: var(--warning); font-weight: bold;">⚠️ Ungültig</span>
                </td>
                <td>
                    <input type="text" id="mac_colons" name="mac_colons"
                           placeholder="a1:b2:c3:d4:e5:f6"
                           value="<?php echo htmlspecialchars($mac_colons); ?>">
                </td>
            </tr>
            <tr>
                <td style="vertical-align: middle;">
                    <label for="mac_dots"><strong>Cisco-Format</strong></label>
                    <span id="warn_dots" style="display:none; color: var(--warning); font-weight: bold;">⚠️ Ungültig</span>
                </td>
                <td>
                    <input type="text" id="mac_dots" name="mac_dots"
                           placeholder="a1b2.c3d4.e5f6"
                           value="<?php echo htmlspecialchars($mac_dots); ?>">
                </td>
            </tr>
            <tr>
                <td style="vertical-align: middle;">
                    <label for="mac_minus"><strong>Minus-Format</strong></label>
                    <span id="warn_minus" style="display:none; color: var(--warning); font-weight: bold;">⚠️ Ungültig</span>
                </td>
                <td>
                    <input type="text" id="mac_minus" name="mac_minus"
                           placeholder="a1-b2-c3-d4-e5-f6"
                           value="<?php echo htmlspecialchars($mac_minus); ?>">
                </td>
            </tr>
            <tr>
                <td style="vertical-align: middle;">
                    <label for="mac_upper"><strong>Großbuchstaben</strong></label>
                    <span id="warn_upper" style="display:none; color: var(--warning); font-weight: bold;">⚠️ Ungültig</span>
                </td>
                <td>
                    <input type="text" id="mac_upper" name="mac_upper"
                           placeholder="A1:B2:C3:D4:E5:F6"
                           value="<?php echo htmlspecialchars($mac_upper); ?>">
                </td>
            </tr>
            <tr>
                <td style="vertical-align: middle;">
                    <label for="mac_lower"><strong>Kleinbuchstaben</strong></label>
                    <span id="warn_lower" style="display:none; color: var(--warning); font-weight: bold;">⚠️ Ungültig</span>
                </td>
                <td>
                    <input type="text" id="mac_lower" name="mac_lower"
                           placeholder="a1:b2:c3:d4:e5:f6"
                           value="<?php echo htmlspecialchars($mac_lower); ?>">
                </td>
            </tr>
            <tr>
                <td style="vertical-align: middle;">
                    <label for="mac_raw"><strong>Ohne Trennzeichen</strong></label>
                    <span id="warn_raw" style="display:none; color: var(--warning); font-weight: bold;">⚠️ Ungültig</span>
                </td>
                <td>
                    <input type="text" id="mac_raw" name="mac_raw"
                           placeholder="a1b2c3d4e5f6"
                           value="<?php echo htmlspecialchars($mac_raw); ?>">
                </td>
            </tr>
        </table>
    </form>
</div>


<script>
/* Regex pro Feld */
const regexes = {
    mac_colons: /^([A-Fa-f0-9]{2}:){5}[A-Fa-f0-9]{2}$/,              // Standard, case-insensitiv
    mac_dots:   /^[A-Fa-f0-9]{4}\.[A-Fa-f0-9]{4}\.[A-Fa-f0-9]{4}$/,  // Cisco
    mac_minus:  /^([A-Fa-f0-9]{2}-){5}[A-Fa-f0-9]{2}$/,              // Minus
    mac_upper:  /^([0-9A-F]{2}:){5}[0-9A-F]{2}$/,                    // Colons UPPER only
    mac_lower:  /^([0-9a-f]{2}:){5}[0-9a-f]{2}$/,                    // Colons lower only
    mac_raw:    /^[A-Fa-f0-9]{12}$/                                  // 12 hex, keine Trennzeichen
};

function onlyHex12(value) {
    return value.toLowerCase().replace(/[^a-f0-9]/g, '').slice(0, 12);
}

function setVal(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val;
}

function validate(id) {
    const el = document.getElementById(id);
    const warn = document.getElementById('warn_' + id.split('_')[1]);
    const ok = regexes[id].test((el.value || '').trim());
    warn.style.display = (el.value && !ok) ? 'inline-block' : 'none';
}

/* Füllt ALLE Felder anhand einer 12-hex Basis */
function fillAllFromBase(base12) {
    const pairs = base12.match(/.{1,2}/g);
    setVal('mac_colons', pairs.join(':'));
    setVal('mac_dots',   base12.match(/.{1,4}/g).join('.'));
    setVal('mac_minus',  pairs.join('-'));
    setVal('mac_upper',  pairs.join(':').toUpperCase());
    setVal('mac_lower',  pairs.join(':').toLowerCase());
    setVal('mac_raw',    base12.toLowerCase());

    ['mac_colons','mac_dots','mac_minus','mac_upper','mac_lower','mac_raw'].forEach(validate);
}

/* Liest Eingabe aus irgendeinem Feld, normalisiert zu 12-hex und füllt die anderen */
function handleInput(fromId, val) {
    let base = onlyHex12(val);
    if (base.length !== 12) {
        // Nur Validierungsanzeige für dieses Feld
        validate(fromId);
        return;
    }
    fillAllFromBase(base);
}

/* Event-Listener für alle Eingabefelder */
['mac_colons','mac_dots','mac_minus','mac_upper','mac_lower','mac_raw'].forEach(id => {
    const el = document.getElementById(id);
    el.addEventListener('input', function() {
        handleInput(id, this.value);
    });
    // Initiale Validierung auf vorhandenen Serverwerten
    validate(id);
});
</script>
