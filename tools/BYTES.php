<?php
$page_title = 'Bit-Konverter';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>
<div class="container">
    <table style="width:100%; border-collapse:collapse;">
        <thead>
        <tr>
            <th style="text-align:left; padding:8px 0;"></th>
            <th style="text-align:left; padding:8px 0;"><strong>SI-Byte</strong></th>
            <th style="text-align:left; padding:8px 0;"><strong>Binär-Byte</strong></th>
            <th style="text-align:left; padding:8px 0;"><strong>Bit</strong></th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td style="vertical-align:middle;"><strong>Tera</strong></td>
            <td><input type="number" step="any" id="tera_TB"  placeholder="z.B. 1.5 (TB)"></td>
            <td><input type="number" step="any" id="tera_TiB" placeholder="z.B. 1.36 (TiB)"></td>
            <td><input type="number" step="any" id="tera_Tb"  placeholder="z.B. 12 (Tb)"></td>
        </tr>
        <tr>
            <td style="vertical-align:middle;"><strong>Giga</strong></td>
            <td><input type="number" step="any" id="giga_GB"  placeholder="z.B. 1 (GB)"></td>
            <td><input type="number" step="any" id="giga_GiB" placeholder="z.B. 0.93 (GiB)"></td>
            <td><input type="number" step="any" id="giga_Gb"  placeholder="z.B. 8 (Gb)"></td>
        </tr>
        <tr>
            <td style="vertical-align:middle;"><strong>Mega</strong></td>
            <td><input type="number" step="any" id="mega_MB"  placeholder="z.B. 500 (MB)"></td>
            <td><input type="number" step="any" id="mega_MiB" placeholder="z.B. 476.84 (MiB)"></td>
            <td><input type="number" step="any" id="mega_Mb"  placeholder="z.B. 4000 (Mb)"></td>
        </tr>
        <tr>
            <td style="vertical-align:middle;"><strong>Kilo</strong></td>
            <td><input type="number" step="any" id="kilo_KB"  placeholder="z.B. 1000 (KB)"></td>
            <td><input type="number" step="any" id="kilo_KiB" placeholder="z.B. 976.5625 (KiB)"></td>
            <td><input type="number" step="any" id="kilo_Kb"  placeholder="z.B. 8000 (Kb)"></td>
        </tr>
        <tr>
            <td style="vertical-align:middle;"><strong>Basis</strong></td>
            <td><input type="number" step="any" id="base_B"   placeholder="z.B. 1 (B)"></td>
            <td><input type="number" step="any" id="base_Bi"  placeholder="= 1 (B)"></td>
            <td><input type="number" step="any" id="base_b"   placeholder="z.B. 8 (b)"></td>
        </tr>
        </tbody>
    </table>
</div>

<script>
// Faktoren
const SI_BYTE  = { tera: 1e12,  giga: 1e9,  mega: 1e6,  kilo: 1e3,  base: 1 };
const IEC_BYTE = { tera: 2**40, giga: 2**30, mega: 2**20, kilo: 2**10, base: 1 };
const SI_BIT   = { tera: 1e12,  giga: 1e9,  mega: 1e6,  kilo: 1e3,  base: 1 };

// IDs pro Zeile/Spalte
const ROWS = {
  tera: { siB: 'tera_TB',  iecB: 'tera_TiB', siBit: 'tera_Tb' },
  giga: { siB: 'giga_GB',  iecB: 'giga_GiB', siBit: 'giga_Gb' },
  mega: { siB: 'mega_MB',  iecB: 'mega_MiB', siBit: 'mega_Mb' },
  kilo: { siB: 'kilo_KB',  iecB: 'kilo_KiB', siBit: 'kilo_Kb' },
  base: { siB: 'base_B',   iecB: 'base_Bi',  siBit: 'base_b'  }
};

// Reverse-Lookup: id -> {scale, kind}
const ID_INFO = {};
Object.entries(ROWS).forEach(([scale, cols]) => {
  ID_INFO[cols.siB]  = { scale, kind: 'siB'   };
  ID_INFO[cols.iecB] = { scale, kind: 'iecB'  };
  ID_INFO[cols.siBit]= { scale, kind: 'siBit' };
});

// Helfer
function toNumber(v){ const n = parseFloat(v); return Number.isFinite(n) ? n : null; }
function fmt(n){ if(!Number.isFinite(n)) return ''; return n.toFixed(6).replace(/\.?0+$/,''); }

let updating = false;

// Recalc GLOBAL: von einem Feld -> alle Felder
function recalcAll(fromId, rawVal){
  const info = ID_INFO[fromId];
  if(!info) return;

  const val = toNumber(rawVal);
  if(val === null) return;

  // 1) Auf kanonische BYTES normalisieren
  let canonicalBytes;
  if(info.kind === 'siB'){
    canonicalBytes = val * SI_BYTE[info.scale];
  } else if(info.kind === 'iecB'){
    canonicalBytes = val * IEC_BYTE[info.scale];
  } else { // siBit
    const bits = val * SI_BIT[info.scale];
    canonicalBytes = bits / 8;
  }

  // 2) Alle Felder neu setzen
  updating = true;
  Object.entries(ROWS).forEach(([scale, cols]) => {
    // SI-Byte
    document.getElementById(cols.siB).value   = fmt(canonicalBytes / SI_BYTE[scale]);
    // IEC-Byte
    document.getElementById(cols.iecB).value  = fmt(canonicalBytes / IEC_BYTE[scale]);
    // SI-Bit
    const bits = canonicalBytes * 8;
    document.getElementById(cols.siBit).value = fmt(bits / SI_BIT[scale]);
  });
  // Aktives Feld mit Originalwert überschreiben, damit Rundung den Tipp nicht „springt“
  const active = document.getElementById(fromId);
  if(active) active.value = rawVal;
  updating = false;
}

// Events
Object.keys(ID_INFO).forEach(id => {
  const el = document.getElementById(id);
  if(!el) return;
  el.addEventListener('input', function(){
    if(updating) return;
    recalcAll(id, this.value);
  });
});
</script>
