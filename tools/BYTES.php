<?php
$page_title = 'Bit-Konverter';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>
<div class="container">
    <h2 class="ueberschrift">Bytes/Bits-Konverter</h2>

    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr>
                <th style="text-align:left; padding:8px 0;">Einheit</th>
                <th style="text-align:left; padding:8px 0;">Byte</th>
                <th style="text-align:left; padding:8px 0;">Bit</th>
            </tr>
        </thead>
        <tbody>
            <!-- Tera -->
            <tr>
                <td style="vertical-align:middle;"><strong>Tera</strong> (TB / Tb)</td>
                <td><input type="number" step="any" id="tb_byte" placeholder="1.5 (TB)"></td>
                <td><input type="number" step="any" id="tb_bit"  placeholder="12 (Tb)"></td>
            </tr>
            <!-- Giga -->
            <tr>
                <td style="vertical-align:middle;"><strong>Giga</strong> (GB / Gb)</td>
                <td><input type="number" step="any" id="gb_byte" placeholder="1 (GB)"></td>
                <td><input type="number" step="any" id="gb_bit"  placeholder="8 (Gb)"></td>
            </tr>
            <!-- Mega -->
            <tr>
                <td style="vertical-align:middle;"><strong>Mega</strong> (MB / Mb)</td>
                <td><input type="number" step="any" id="mb_byte" placeholder="1 (MB)"></td>
                <td><input type="number" step="any" id="mb_bit"  placeholder="8 (Mb)"></td>
            </tr>
            <!-- Kilo -->
            <tr>
                <td style="vertical-align:middle;"><strong>Kilo</strong> (KB / Kb)</td>
                <td><input type="number" step="any" id="kb_byte" placeholder="1 (KB)"></td>
                <td><input type="number" step="any" id="kb_bit"  placeholder="8 (Kb)"></td>
            </tr>
            <!-- Basis -->
            <tr>
                <td style="vertical-align:middle;"><strong>Basis</strong> (B / b)</td>
                <td><input type="number" step="any" id="b_byte"  placeholder="1 (B)"></td>
                <td><input type="number" step="any" id="b_bit"   placeholder="8 (b)"></td>
            </tr>
        </tbody>
    </table>
</div>

<script>
// SI-Basis (Dezimal): 1 KB = 1000 B (nicht KiB)
// Faktoren in BYTES für TB/GB/MB/KB/B
const BYTE_FACTORS = {
    tb: 1e12,
    gb: 1e9,
    mb: 1e6,
    kb: 1e3,
    b : 1
};
// Faktoren in BITS für Tb/Gb/Mb/Kb/b
const BIT_FACTORS = {
    tb: 1e12,
    gb: 1e9,
    mb: 1e6,
    kb: 1e3,
    b : 1
};

const fields = {
    tb_byte: 'tb', gb_byte: 'gb', mb_byte: 'mb', kb_byte: 'kb', b_byte: 'b',
    tb_bit : 'tb', gb_bit : 'gb', mb_bit : 'mb', kb_bit : 'kb', b_bit : 'b'
};

function toNumber(v) {
    const n = parseFloat(v);
    return Number.isFinite(n) ? n : null;
}

function fmt(n) {
    if (!Number.isFinite(n)) return '';
    // bis 6 Nachkommastellen, ohne unnötige Nullen
    const s = n.toFixed(6).replace(/\.?0+$/,'');
    return s;
}

// Rechne von einer Eingabe auf alle Felder
function recalc(fromId, rawVal) {
    const unit = fields[fromId];
    if (!unit) return;

    const val = toNumber(rawVal);
    if (val === null) return; // ignorieren bei leer/ungültig

    let bytesCanonical;

    if (fromId.endsWith('_byte')) {
        // Eingabe in Byte-Einheit -> auf Bytes umrechnen
        bytesCanonical = val * BYTE_FACTORS[unit];
    } else {
        // Eingabe in Bit-Einheit -> erst auf Bits, dann Bytes
        const bits = val * BIT_FACTORS[unit];
        bytesCanonical = bits / 8;
    }

    // Jetzt alle Byte-Felder setzen
    for (const id of Object.keys(fields)) {
        const u = fields[id];
        if (id.endsWith('_byte')) {
            const v = bytesCanonical / BYTE_FACTORS[u];
            setIfChanged(id, fmt(v), fromId);
        } else {
            const bits = bytesCanonical * 8;
            const v = bits / BIT_FACTORS[u];
            setIfChanged(id, fmt(v), fromId);
        }
    }
}

let updating = false;
function setIfChanged(id, val, fromId) {
    if (id === fromId) return;          // aktuell editierte Zelle nicht überschreiben
    const el = document.getElementById(id);
    if (!el) return;
    if (el.value !== val) {
        updating = true;
        el.value = val;
        updating = false;
    }
}

function wire(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function() {
        if (updating) return;
        recalc(id, this.value);
    });
}

// Alle Felder verbinden
[
 'tb_byte','gb_byte','mb_byte','kb_byte','b_byte',
 'tb_bit','gb_bit','mb_bit','kb_bit','b_bit'
].forEach(wire);
</script>
