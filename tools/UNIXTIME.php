<?php
$page_title = 'Unixtime-Konverter';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>
<div class="container">
    <h2 class="ueberschrift">Unixtime-Konverter</h2>

    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="vertical-align:middle; width:50%;">
                <label for="unix_ts"><strong>Unixzeit (Sekunden)</strong></label>
            </td>
            <td>
                <input type="number" step="1" id="unix_ts" placeholder="1727097600">
            </td>
        </tr>
        <tr>
            <td style="vertical-align:middle;">
                <label for="dt_local"><strong>Datum &amp; Zeit (HTML)</strong></label>
            </td>
            <td>
                <input type="datetime-local" id="dt_local">
            </td>
        </tr>
        <tr>
            <td style="vertical-align:middle;">
                <label for="dt_german"><strong>Datum &amp; Zeit (String)</strong></label>
            </td>
            <td>
                <input type="text" id="dt_german" readonly placeholder="31.12.2025 23:59">
            </td>
        </tr>
    </table>
</div>

<script>
// ---------- Helpers ----------
function pad(n){ return n.toString().padStart(2,'0'); }

function toDatetimeLocalValue(d){
    // Format: YYYY-MM-DDTHH:MM (datetime-local ohne Sekunden)
    const y = d.getFullYear();
    const m = pad(d.getMonth()+1);
    const da = pad(d.getDate());
    const h = pad(d.getHours());
    const mi = pad(d.getMinutes());
    return `${y}-${m}-${da}T${h}:${mi}`;
}

function fromDatetimeLocalValue(val){
    // Expect "YYYY-MM-DDTHH:MM" (browser-local time)
    if(!val) return null;
    const m = val.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
    if(!m) return null;
    const [_, Y, Mo, D, H, Mi] = m.map(Number);
    return new Date(Y, Mo-1, D, H, Mi, 0, 0); // local time, Sekunden=0
}

function formatGermanStringNoSeconds(d){
    // de-String ohne Sekunden: DD.MM.YYYY HH:MM
    const dd = pad(d.getDate());
    const mm = pad(d.getMonth()+1);
    const yyyy = d.getFullYear();
    const HH = pad(d.getHours());
    const MM = pad(d.getMinutes());
    return `${dd}.${mm}.${yyyy} ${HH}:${MM}`;
}

function setVal(id, val){
    const el = document.getElementById(id);
    if (el && el.value !== val) el.value = val;
}

// ---------- Converters ----------
function fromUnixInput(){
    const el = document.getElementById('unix_ts');
    const v = el.value.trim();
    if (v === '') { clearAll(); return; }
    const num = Number(v);
    if (!Number.isFinite(num)) return;

    const d = new Date(num * 1000);
    if (isNaN(d.getTime())) return;

    setVal('dt_local', toDatetimeLocalValue(d));
    setVal('dt_german', formatGermanStringNoSeconds(d));
}

function fromDatetimeLocalInput(){
    const el = document.getElementById('dt_local');
    const val = el.value;
    if (!val) { clearAll(); return; }
    const d = fromDatetimeLocalValue(val);
    if (!d) return;

    const unix = Math.floor(d.getTime() / 1000);
    setVal('unix_ts', String(unix));
    setVal('dt_german', formatGermanStringNoSeconds(d));
}

function clearAll(){
    setVal('dt_german', '');
}

// ---------- Wire up ----------
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('unix_ts').addEventListener('input', fromUnixInput);
    document.getElementById('dt_local').addEventListener('input', fromDatetimeLocalInput);

    if (document.getElementById('unix_ts').value) fromUnixInput();
    else if (document.getElementById('dt_local').value) fromDatetimeLocalInput();
});
</script>
