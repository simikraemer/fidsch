<?php
require_once 'template.php';

$win_standard   = $_POST['win_standard']   ?? '';
$win_escaped    = $_POST['win_escaped']    ?? '';
$win_quoted     = $_POST['win_quoted']     ?? '';
$linux_standard = $_POST['linux_standard'] ?? '';
$linux_quoted   = $_POST['linux_quoted']   ?? '';
$linux_escaped  = $_POST['linux_escaped']  ?? '';
$bash_single    = $_POST['bash_single']    ?? '';
$url_encoded    = $_POST['url_encoded']    ?? '';
?>
<div class="container">
    <h2 class="ueberschrift">Path-Konverter</h2>
    <form method="post" action="">
        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <td style="vertical-align:middle; width:50%;">
                    <label for="win_standard"><strong>Windows-Standard</strong></label>
                    <span id="warn_win_standard" style="display:none; color: var(--warning); font-weight: bold; margin-left:8px;">⚠️</span>
                </td>
                <td>
                    <input type="text" id="win_standard" name="win_standard"
                           placeholder="C:\Users\Tupac\file.sh"
                           value="<?php echo htmlspecialchars($win_standard); ?>">
                </td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="win_escaped"><strong>Windows (doppelte Backslashes)</strong></label>
                    <span id="warn_win_escaped" style="display:none; color: var(--warning); font-weight: bold; margin-left:8px;">⚠️</span>
                </td>
                <td>
                    <input type="text" id="win_escaped" name="win_escaped"
                           placeholder="C:\\Users\\Tupac\\file.sh"
                           value="<?php echo htmlspecialchars($win_escaped); ?>">
                </td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="win_quoted"><strong>Windows mit Anführungszeichen</strong></label>
                    <span id="warn_win_quoted" style="display:none; color: var(--warning); font-weight: bold; margin-left:8px;">⚠️</span>
                </td>
                <td>
                    <input type="text" id="win_quoted" name="win_quoted"
                           placeholder="&quot;C:\Users\Tupac\file.sh&quot;"
                           value="<?php echo htmlspecialchars($win_quoted); ?>">
                </td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="linux_standard"><strong>Linux-Standard</strong></label>
                    <span id="warn_linux_standard" style="display:none; color: var(--warning); font-weight: bold; margin-left:8px;">⚠️</span>
                </td>
                <td>
                    <input type="text" id="linux_standard" name="linux_standard"
                           placeholder="/Users/Tupac/file.sh"
                           value="<?php echo htmlspecialchars($linux_standard); ?>">
                </td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="linux_quoted"><strong>Linux mit Anführungszeichen</strong></label>
                    <span id="warn_linux_quoted" style="display:none; color: var(--warning); font-weight: bold; margin-left:8px;">⚠️</span>
                </td>
                <td>
                    <input type="text" id="linux_quoted" name="linux_quoted"
                           placeholder="&quot;/Users/Tupac/file.sh&quot;"
                           value="<?php echo htmlspecialchars($linux_quoted); ?>">
                </td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="linux_escaped"><strong>Linux mit escaped Spaces</strong></label>
                    <span id="warn_linux_escaped" style="display:none; color: var(--warning); font-weight: bold; margin-left:8px;">⚠️</span>
                </td>
                <td>
                    <input type="text" id="linux_escaped" name="linux_escaped"
                           placeholder="/Users/Tupac/file.sh"
                           value="<?php echo htmlspecialchars($linux_escaped); ?>">
                </td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="bash_single"><strong>Bash (einfach quotiert)</strong></label>
                    <span id="warn_bash_single" style="display:none; color: var(--warning); font-weight: bold; margin-left:8px;">⚠️</span>
                </td>
                <td>
                    <input type="text" id="bash_single" name="bash_single"
                           placeholder="'/Users/Tupac/file.sh'"
                           value="<?php echo htmlspecialchars($bash_single); ?>">
                </td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="url_encoded"><strong>URL-encoded</strong></label>
                    <span id="warn_url_encoded" style="display:none; color: var(--warning); font-weight: bold; margin-left:8px;">⚠️</span>
                </td>
                <td>
                    <input type="text" id="url_encoded" name="url_encoded"
                           placeholder="/Users/Tupac/file.sh"
                           value="<?php echo htmlspecialchars($url_encoded); ?>">
                </td>
            </tr>
        </table>
    </form>
</div>


<script>
// ---------- Helpers ----------
function trimQuotes(s) {
    if (s.length >= 2 && ((s.startsWith('"') && s.endsWith('"')) || (s.startsWith("'") && s.endsWith("'")))) return s.slice(1, -1);
    return s;
}
function winUnescapeBackslashes(s){ return s.replace(/\\\\/g,"\\"); }
function toUnixSlashes(s){ return s.replace(/\\/g,"/"); }

function splitLinuxPath(p){
    const abs = p.startsWith("/");
    const parts = p.split("/").filter((x,i)=>!(i===0&&x==="")&&x!=="");
    return { absolute: abs, parts };
}

// Parse user input into a neutral object
function parseInputPath(id, value){
    let v = (value||"").trim();
    if (!v) return null;

    switch(id){
        case 'win_escaped':  v = winUnescapeBackslashes(v); break;
        case 'win_quoted':   v = trimQuotes(v); break;
        case 'linux_quoted': v = trimQuotes(v); break;
        case 'bash_single':
            if (v.startsWith("'") && v.endsWith("'")) v = v.slice(1,-1).replace(/'\\''/g,"'");
            break;
        case 'url_encoded':
            v = v.split('/').map(seg=>{ try{return decodeURIComponent(seg);}catch(e){return seg;} }).join('/');
            break;
    }

    // Windows (drive)
    const m = v.match(/^([A-Za-z]):[\\/]/);
    if (m){
        const drive = m[1].toLowerCase();
        const rest  = v.slice(2).replace(/^[\\/]/,'');
        const parts = rest.split(/[\\/]+/).filter(Boolean);
        return { style:'win', drive, parts };
    }

    // Linux-like
    const lin = splitLinuxPath(toUnixSlashes(v));
    return { style:'linux', absolute: lin.absolute, parts: lin.parts };
}

// ---------- Formatters ----------
function fmtWinStandard(obj){
    const drive = (obj.style==='win' ? obj.drive.toUpperCase() : 'C'); // inject C for linux-origin
    return drive + ':\\' + obj.parts.join('\\');
}
function fmtWinEscaped(winStd){ return winStd.replace(/\\/g,'\\\\'); }
function fmtWinQuoted(winStd){ return `"${winStd}"`; }

// For Linux outputs: ALWAYS drop drive (if any)
function fmtLinuxStandard(obj){ return '/' + obj.parts.join('/'); }
function fmtLinuxQuoted(linStd){
    const esc = linStd.replace(/(["\\$`])/g,'\\$1');
    return `"${esc}"`;
}
function fmtLinuxEscaped(linStd){ return linStd.replace(/ /g,'\\ '); }
function fmtBashSingle(linStd){ return `'${linStd.replace(/'/g, `'\\''`)}'`; }
function fmtUrlEncoded(linStd){
    return linStd.split('/').map((seg,i)=> i===0 && seg==='' ? '' : encodeURIComponent(seg)).join('/');
}

// ---------- Validation (tolerant) ----------
const validators = {
    win_standard:  v => /^[A-Za-z]:\\/.test(v),
    win_escaped:   v => /^[A-Za-z]:(\\\\|\\)/.test(v),
    win_quoted:    v => /^"[A-Za-z]:\\/.test(v) && v.endsWith('"'),
    linux_standard:v => /^\/.*/.test(v),
    linux_quoted:  v => /^".*"$/.test(v),
    linux_escaped: v => /^\/.*/.test(v) && !/(^|[^\\])\s/.test(v),
    bash_single:   v => /^'.*'$/.test(v),
    url_encoded:   v => {
        if (!/^\/.*/.test(v)) return false;
        try { v.split('/').forEach(seg=>{ if(seg) decodeURIComponent(seg); }); return true; } catch(e){ return false; }
    }
};

function validateField(id){
    const el = document.getElementById(id);
    const warn = document.getElementById('warn_'+id);
    const val = (el.value||'').trim();
    const ok = val ? (validators[id] ? validators[id](val) : true) : true;
    if (warn) warn.style.display = (val && !ok) ? 'inline-block' : 'none';
}

// Set value but DO NOT touch the currently edited field
function setVal(id, val, fromId){
    if (id === fromId) return;
    const el = document.getElementById(id);
    if (el && el.value !== val) el.value = val;
}

function fillAll(fromId, value){
    const parsed = parseInputPath(fromId, value);
    if (!parsed){ validateField(fromId); return; }

    // Canonical linux first (drive removed)
    const linStd = fmtLinuxStandard(parsed);

    // Windows (inject C if linux-origin)
    const winStd = fmtWinStandard(parsed);

    const winEsc = fmtWinEscaped(winStd);
    const winQ   = fmtWinQuoted(winStd);

    const linQ   = fmtLinuxQuoted(linStd);
    const linEsc = fmtLinuxEscaped(linStd);
    const bashQ  = fmtBashSingle(linStd);
    const urlEnc = fmtUrlEncoded(linStd);

    setVal('win_standard',   winStd, fromId);
    setVal('win_escaped',    winEsc, fromId);
    setVal('win_quoted',     winQ,   fromId);
    setVal('linux_standard', linStd, fromId);
    setVal('linux_quoted',   linQ,   fromId);
    setVal('linux_escaped',  linEsc, fromId);
    setVal('bash_single',    bashQ,  fromId);
    setVal('url_encoded',    urlEnc, fromId);

    ['win_standard','win_escaped','win_quoted','linux_standard','linux_quoted','linux_escaped','bash_single','url_encoded']
        .forEach(validateField);
}

// ---------- Wire up ----------
document.addEventListener('DOMContentLoaded', function(){
    const ids = ['win_standard','win_escaped','win_quoted','linux_standard','linux_quoted','linux_escaped','bash_single','url_encoded'];
    ids.forEach(id=>{
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function(){ fillAll(id, this.value); });
        validateField(id);
    });
});
</script>
