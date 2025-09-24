<?php
require_once 'template.php';
?>

<div class="container">
    <h2 class="ueberschrift">IPv4 Netz-Konverter</h2>

    <table style="width:100%; border-collapse:collapse;">
        <tbody>
            <tr>
                <td style="width:40%; vertical-align:middle;">
                    <label for="cidr"><strong>CIDR (IP/Präfix)</strong></label>
                    <span id="warn_cidr" style="display:none; color:var(--warning); font-weight:bold; margin-left:8px;">⚠️</span>
                </td>
                <td><input type="text" id="cidr" placeholder="137.226.141.245/23"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="ip"><strong>IP-Adresse</strong></label>
                    <span id="warn_ip" style="display:none; color:var(--warning); font-weight:bold; margin-left:8px;">⚠️</span>
                </td>
                <td><input type="text" id="ip" placeholder="137.226.141.245"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="prefix"><strong>Präfix (/0–/32)</strong></label>
                    <span id="warn_prefix" style="display:none; color:var(--warning); font-weight:bold; margin-left:8px;">⚠️</span>
                </td>
                <td><input type="number" id="prefix" min="0" max="32" placeholder="23"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label><strong>DNS-Name (Reverse Lookup)</strong></label>
                </td>
                <td><input type="text" id="ptr_name" readonly placeholder="host.example.org"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="mask"><strong>Subnetzmaske</strong></label>
                    <span id="warn_mask" style="display:none; color:var(--warning); font-weight:bold; margin-left:8px;">⚠️</span>
                </td>
                <td><input type="text" id="mask" placeholder="255.255.254.0"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="wildcard"><strong>Wildcard-Maske</strong></label>
                    <span id="warn_wildcard" style="display:none; color:var(--warning); font-weight:bold; margin-left:8px;">⚠️</span>
                </td>
                <td><input type="text" id="wildcard" placeholder="0.0.1.255"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="network"><strong>Netzwerkadresse</strong></label>
                    <span id="warn_network" style="display:none; color:var(--warning); font-weight:bold; margin-left:8px;">⚠️</span>
                </td>
                <td><input type="text" id="network" placeholder="137.226.140.0"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="broadcast"><strong>Broadcastadresse</strong></label>
                    <span id="warn_broadcast" style="display:none; color:var(--warning); font-weight:bold; margin-left:8px;">⚠️</span>
                </td>
                <td><input type="text" id="broadcast" placeholder="137.226.141.255"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="first_host"><strong>Erste Host-IP</strong></label>
                    <span id="warn_first_host" style="display:none; color:var(--warning); font-weight:bold; margin-left:8px;">⚠️</span>
                </td>
                <td><input type="text" id="first_host" placeholder="137.226.140.1"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="last_host"><strong>Letzte Host-IP</strong></label>
                    <span id="warn_last_host" style="display:none; color:var(--warning); font-weight:bold; margin-left:8px;">⚠️</span>
                </td>
                <td><input type="text" id="last_host" placeholder="137.226.141.254"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="range_start"><strong>Range Start</strong></label>
                    <span id="warn_range_start" style="display:none; color:var(--warning); font-weight:bold; margin-left:8px;">⚠️</span>
                </td>
                <td><input type="text" id="range_start" placeholder="137.226.140.0"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label for="range_end"><strong>Range Ende</strong></label>
                    <span id="warn_range_end" style="display:none; color:var(--warning); font-weight:bold; margin-left:8px;">⚠️</span>
                </td>
                <td><input type="text" id="range_end" placeholder="137.226.141.255"></td>
            </tr>

            <!-- Readonly / Infos -->
            <tr>
                <td style="vertical-align:middle;">
                    <label><strong>Gesamt IPs</strong></label>
                </td>
                <td><input type="text" id="total_ips" readonly placeholder="512"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label><strong>Nutzbare Hosts</strong></label>
                </td>
                <td><input type="text" id="usable_hosts" readonly placeholder="510"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label><strong>Reverse DNS (PTR-Zone)</strong></label>
                </td>
                <td><input type="text" id="ptr_zone" readonly placeholder="141.226.137.in-addr.arpa."></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label><strong>Klasse / Bereich</strong></label>
                </td>
                <td><input type="text" id="ip_class" readonly placeholder="Class B, öffentlich"></td>
            </tr>

            <tr>
                <td style="vertical-align:middle;">
                    <label><strong>Binär (IP)</strong></label>
                </td>
                <td><input type="text" id="ip_binary" readonly placeholder="10001001.11100010.10001101.11110101"></td>
            </tr>
        </tbody>
    </table>
</div>


<script>
// ---------- Utils ----------
const warn = id => document.getElementById('warn_' + id);

function showWarn(id, on){ const w = warn(id); if (w) w.style.display = on ? 'inline-block' : 'none'; }

function ipToInt(ip){
    const m = ip.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
    if (!m) return null;
    const oct = m.slice(1).map(Number);
    if (oct.some(x => x < 0 || x > 255)) return null;
    return ((oct[0]<<24)>>>0) + (oct[1]<<16) + (oct[2]<<8) + oct[3];
}
function intToIp(n){
    return [(n>>>24)&255, (n>>>16)&255, (n>>>8)&255, n&255].join('.');
}
function maskFromPrefix(p){
    if (p<0 || p>32) return null;
    const m = p === 0 ? 0 : (0xFFFFFFFF << (32 - p)) >>> 0;
    return m >>> 0;
}
function prefixFromMask(maskStr){
    const v = ipToInt(maskStr);
    if (v === null) return null;
    const inv = (~v) >>> 0;
    if (((inv + 1) & inv) !== 0) return null; // not contiguous zeros
    let p = 0; for (let i=31;i>=0;i--) { if ((v>>>i)&1) p++; else break; }
    if (v !== maskFromPrefix(p)) return null;
    return p;
}
function wildcardFromMask(maskInt){ return (~maskInt) >>> 0; }

function networkOf(ipInt, prefix){
    const mask = maskFromPrefix(prefix);
    return mask === null ? null : (ipInt & mask) >>> 0;
}
function broadcastOf(ipInt, prefix){
    const mask = maskFromPrefix(prefix);
    if (mask === null) return null;
    return (ipInt | (~mask)) >>> 0;
}
function isPowerOfTwo(x){ return x>0 && (x & (x-1)) === 0; }
function log2(x){ return Math.log(x)/Math.log(2); }

function classify(ipInt){
    const first = ipInt>>>24;
    let cls = (first<=127?'A':first<=191?'B':first<=223?'C':first<=239?'D':'E');
    const priv =
      (first===10) ||
      (first===172 && ((ipInt>>>16)&0xF0)===16) || // 172.16.0.0/12
      (first===192 && ((ipInt>>>16)&255)===168);
    return `Class ${cls}, ${priv?'privat':'öffentlich'}`;
}
function ipBinary(ipInt){
    const parts = [(ipInt>>>24)&255,(ipInt>>>16)&255,(ipInt>>>8)&255,ipInt&255]
        .map(n => n.toString(2).padStart(8,'0'));
    return parts.join('.');
}
function ptrZone24(ipInt){
    // PTR-Zone für /24 Delegation: c.b.a.in-addr.arpa.
    const a = (ipInt>>>24)&255, b=(ipInt>>>16)&255, c=(ipInt>>>8)&255;
    return `${c}.${b}.${a}.in-addr.arpa.`;
}
function ptrNameFull(ipInt){
    // Vollständiger PTR-Name: d.c.b.a.in-addr.arpa.
    const a = (ipInt>>>24)&255, b=(ipInt>>>16)&255, c=(ipInt>>>8)&255, d=(ipInt)&255;
    return `${d}.${c}.${b}.${a}.in-addr.arpa.`;
}

function setVal(id, val, fromId){
    if (id===fromId) return;
    const el = document.getElementById(id);
    if (el && el.value !== val) el.value = val;
}

// ---------- DNS over HTTPS (Reverse PTR) ----------
let lastPtrQuery = 0;
async function resolvePtrForIpInt(ipInt){
    const ptr = ptrNameFull(ipInt);
    const url = 'https://dns.google/resolve?name=' + encodeURIComponent(ptr) + '&type=PTR';
    const myQueryId = ++lastPtrQuery;

    try {
        const res = await fetch(url, {mode: 'cors'});
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        // discard stale result if a newer query was sent
        if (myQueryId !== lastPtrQuery) return;

        const ans = Array.isArray(data.Answer) ? data.Answer : [];
        const firstPtr = ans.find(a => a.type === 12) || ans[0];
        const name = firstPtr ? (firstPtr.data || '').replace(/\.$/, '') : '';
        setVal('ptr_name', name || '(kein PTR gefunden)', null);
    } catch (e) {
        if (myQueryId !== lastPtrQuery) return;
        setVal('ptr_name', '(Lookup fehlgeschlagen)', null);
    }
}

// ---------- Core recompute ----------
function recomputeFromIpPrefix(ipInt, prefix, fromId){
    if (ipInt===null || prefix===null) return;

    const maskInt = maskFromPrefix(prefix);
    const wildInt = wildcardFromMask(maskInt);
    const net = networkOf(ipInt, prefix);
    const bc  = broadcastOf(ipInt, prefix);

    const total = 2 ** (32 - prefix);
    let usable;
    if (prefix === 32) usable = 1;
    else if (prefix === 31) usable = 2; // RFC 3021
    else usable = Math.max(total - 2, 0);

    const first = (prefix>=31) ? net : (net + 1) >>> 0;
    const last  = (prefix>=31) ? bc  : (bc  - 1) >>> 0;

    setVal('cidr', `${intToIp(ipInt)}/${prefix}`, fromId);
    setVal('ip', intToIp(ipInt), fromId);
    setVal('prefix', String(prefix), fromId);
    setVal('mask', intToIp(maskInt), fromId);
    setVal('wildcard', intToIp(wildInt), fromId);
    setVal('network', intToIp(net), fromId);
    setVal('broadcast', intToIp(bc), fromId);
    setVal('first_host', intToIp(first), fromId);
    setVal('last_host', intToIp(last), fromId);
    setVal('range_start', intToIp(net), fromId);
    setVal('range_end', intToIp(bc), fromId);

    setVal('total_ips', String(total), null);
    setVal('usable_hosts', String(usable), null);
    setVal('ip_class', classify(ipInt), null);
    setVal('ip_binary', ipBinary(ipInt), null);
    setVal('ptr_zone', ptrZone24(ipInt), null);

    // kick off async PTR lookup for the specific IP
    setVal('ptr_name', '… auflösen …', null);
    resolvePtrForIpInt(ipInt);

    ['cidr','ip','prefix','mask','wildcard','network','broadcast','first_host','last_host','range_start','range_end']
        .forEach(id => showWarn(id, false));
}

// ---------- Builders from inputs ----------
function fromCIDR(fromId){
    const v = document.getElementById('cidr').value.trim();
    const m = v.match(/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/);
    if (!m) { showWarn('cidr', v.length>0); return; }
    const ipInt = ipToInt(m[1]); const p = Number(m[2]);
    if (ipInt===null || p<0 || p>32){ showWarn('cidr', true); return; }
    recomputeFromIpPrefix(ipInt, p, fromId);
}
function fromIPPrefixOrMask(fromId){
    const ipStr = document.getElementById('ip').value.trim();
    const pStr  = document.getElementById('prefix').value.trim();
    const maskStr = document.getElementById('mask').value.trim();

    const ipInt = ipToInt(ipStr);
    if (ipStr && ipInt===null){ showWarn('ip', true); return; }
    else showWarn('ip', false);

    let p = null;
    if (pStr !== ''){
        const n = Number(pStr);
        if (!(n>=0 && n<=32)){ showWarn('prefix', true); return; }
        p = n; showWarn('prefix', false);
    } else if (maskStr !== ''){
        const pf = prefixFromMask(maskStr);
        if (pf===null){ showWarn('mask', true); return; }
        p = pf; showWarn('mask', false);
    }

    if (ipInt!==null && p!==null) recomputeFromIpPrefix(ipInt, p, fromId);
}

function fromMask(fromId){ fromIPPrefixOrMask(fromId); }

function fromWildcard(fromId){
    const wStr = document.getElementById('wildcard').value.trim();
    const wInt = ipToInt(wStr);
    if (wStr && wInt===null){ showWarn('wildcard', true); return; }
    if (wInt===null) return;
    const maskInt = (~wInt) >>> 0;
    const maskStr = intToIp(maskInt);
    setVal('mask', maskStr, 'wildcard');
    const p = prefixFromMask(maskStr);
    if (p===null){ showWarn('wildcard', true); return; }
    showWarn('wildcard', false);
    fromIPPrefixOrMask(fromId);
}

function fromNetworkBroadcast(fromId){
    const nStr = document.getElementById('network').value.trim();
    const bStr = document.getElementById('broadcast').value.trim();
    const nInt = ipToInt(nStr), bInt = ipToInt(bStr);
    if (nStr && nInt===null){ showWarn('network', true); return; }
    if (bStr && bInt===null){ showWarn('broadcast', true); return; }
    if (nInt===null || bInt===null || bInt < nInt){ return; }

    const size = (bInt - nInt + 1) >>> 0;
    if (!isPowerOfTwo(size)){ showWarn('network', true); showWarn('broadcast', true); return; }
    const p = 32 - Math.round(log2(size));
    const mask = maskFromPrefix(p);
    if ((nInt & mask) !== nInt){ showWarn('network', true); return; }
    const ipInt = nInt;
    recomputeFromIpPrefix(ipInt, p, fromId);
}

function fromRange(fromId){
    const sStr = document.getElementById('range_start').value.trim();
    const eStr = document.getElementById('range_end').value.trim();
    const sInt = ipToInt(sStr), eInt = ipToInt(eStr);
    if (sStr && sInt===null){ showWarn('range_start', true); return; }
    if (eStr && eInt===null){ showWarn('range_end', true); return; }
    if (sInt===null || eInt===null || eInt < sInt){ return; }

    const size = (eInt - sInt + 1) >>> 0;
    if (!isPowerOfTwo(size)){ showWarn('range_start', true); showWarn('range_end', true); return; }
    const p = 32 - Math.round(log2(size));
    const mask = maskFromPrefix(p);
    if ((sInt & mask) !== sInt){ showWarn('range_start', true); return; }

    recomputeFromIpPrefix(sInt, p, fromId);
}

function fromFirstLastHost(fromId){
    const fStr = document.getElementById('first_host').value.trim();
    const lStr = document.getElementById('last_host').value.trim();
    const fInt = ipToInt(fStr), lInt = ipToInt(lStr);
    if (fStr && fInt===null){ showWarn('first_host', true); return; }
    if (lStr && lInt===null){ showWarn('last_host', true); return; }
    if (fInt===null || lInt===null || lInt < fInt) return;

    const sizeHosts = (lInt - fInt + 1) >>> 0;
    const sizeTotal = sizeHosts + 2;
    if (!isPowerOfTwo(sizeTotal)){ showWarn('first_host', true); showWarn('last_host', true); return; }
    const p = 32 - Math.round(log2(sizeTotal));
    const net = (fInt - 1) >>> 0;
    const mask = maskFromPrefix(p);
    if ((net & mask) !== net){ showWarn('first_host', true); return; }

    recomputeFromIpPrefix(net, p, fromId);
}

// ---------- Wire up ----------
document.addEventListener('DOMContentLoaded', () => {
    const on = (id, fn) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', () => fn(id));
    };

    on('cidr', fromCIDR);
    on('ip', () => fromIPPrefixOrMask('ip'));
    on('prefix', () => fromIPPrefixOrMask('prefix'));
    on('mask', () => fromMask('mask'));
    on('wildcard', () => fromWildcard('wildcard'));
    on('network', () => fromNetworkBroadcast('network'));
    on('broadcast', () => fromNetworkBroadcast('broadcast'));
    on('range_start', () => fromRange('range_start'));
    on('range_end', () => fromRange('range_end'));
    on('first_host', () => fromFirstLastHost('first_host'));
    on('last_host', () => fromFirstLastHost('last_host'));
});
</script>
