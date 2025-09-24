<?php
require_once 'template.php';

$mac_dots = $_POST['mac_dots'] ?? '';
$mac_colons = $_POST['mac_colons'] ?? '';
$mac_minus = $_POST['mac_minus'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($mac_dots)) {
        $mac_clean = strtolower(str_replace('.', '', $mac_dots));
    } elseif (!empty($mac_colons)) {
        $mac_clean = strtolower(str_replace(':', '', $mac_colons));
    } elseif (!empty($mac_minus)) {
        $mac_clean = strtolower(str_replace('-', '', $mac_minus));
    }

    if (!empty($mac_clean) && strlen($mac_clean) === 12) {
        $mac_colons = strtolower(rtrim(chunk_split($mac_clean, 2, ':'), ':'));
        $mac_colons_upper = strtoupper($mac_colons);
        $mac_colons_lower = strtolower($mac_colons);
        $mac_minus = strtolower(rtrim(chunk_split($mac_clean, 2, '-'), '-'));
        $mac_dots = strtolower(rtrim(chunk_split($mac_clean, 4, '.'), '.'));
        $mac_raw = strtolower($mac_clean);
    }
}
?>

<div class="container">
    <h2 class="ueberschrift">MAC-Adressen-Konverter</h2>
    <form method="post" action="">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="vertical-align: middle; width: 50%;">
                    <label for="mac_colons">
                        <strong>Standardformat</strong> (z.B. 2c:52:2d:d2:d5:3f)
                        <span id="warn_colons" style="display:none; color: var(--warning); font-weight: bold;">⚠️ Ungültiges Format</span>
                    </label>
                </td>
                <td>
                    <input type="text" id="mac_colons" name="mac_colons" value="<?php echo htmlspecialchars($mac_colons); ?>">
                </td>
            </tr>
            <tr>
                <td><label><strong>Mit Großbuchstaben</strong></label></td>
                <td><input type="text" id="mac_colons_upper" readonly value="<?php echo htmlspecialchars($mac_colons_upper ?? ''); ?>"></td>
            </tr>
            <tr>
                <td><label><strong>Mit Kleinbuchstaben</strong></label></td>
                <td><input type="text" id="mac_colons_lower" readonly value="<?php echo htmlspecialchars($mac_colons_lower ?? ''); ?>"></td>
            </tr>
            <tr>
                <td><label><strong>Ohne Trennzeichen</strong></label></td>
                <td><input type="text" id="mac_raw" readonly value="<?php echo htmlspecialchars($mac_raw ?? ''); ?>"></td>
            </tr>
            <tr>
                <td style="vertical-align: middle;">
                    <label for="mac_dots">
                        <strong>Cisco-Format</strong> (z.B. 2c52.2dd2.d53f)
                        <span id="warn_dots" style="display:none; color: var(--warning); font-weight: bold;">⚠️ Ungültiges Format</span>
                    </label>
                </td>
                <td>
                    <input type="text" id="mac_dots" name="mac_dots" value="<?php echo htmlspecialchars($mac_dots); ?>">
                </td>
            </tr>
            <tr>
                <td style="vertical-align: middle;">
                    <label for="mac_minus">
                        <strong>Minus-Format</strong> (z.B. 2c-52-2d-d2-d5-3f)
                        <span id="warn_minus" style="display:none; color: var(--warning); font-weight: bold;">⚠️ Ungültiges Format</span>
                    </label>
                </td>
                <td>
                    <input type="text" id="mac_minus" name="mac_minus" value="<?php echo htmlspecialchars($mac_minus); ?>">
                </td>
            </tr>
        </table>
    </form>
</div>

<script>
    const regexes = {
        mac_dots: /^[a-f0-9]{4}\.[a-f0-9]{4}\.[a-f0-9]{4}$/i,
        mac_colons: /^([a-f0-9]{2}:){5}[a-f0-9]{2}$/i,
        mac_minus: /^([a-f0-9]{2}-){5}[a-f0-9]{2}$/i
    };

    function formatMAC(mac) {
        return mac.toLowerCase().replace(/[^a-f0-9]/gi, '');
    }

    function validate(id) {
        const input = document.getElementById(id);
        const warn = document.getElementById("warn_" + id.split('_')[1]);
        const regex = regexes[id];
        const val = input.value.trim();
        warn.style.display = (val && !regex.test(val)) ? "inline-block" : "none";
    }

    function fillFields(from, value) {
        const clean = formatMAC(value);
        if (clean.length !== 12) return;

        if (from !== 'mac_dots') {
            document.getElementById('mac_dots').value = clean.match(/.{1,4}/g).join('.');
            validate('mac_dots');
        }
        if (from !== 'mac_colons') {
            const colons = clean.match(/.{1,2}/g).join(':');
            document.getElementById('mac_colons').value = colons;
            validate('mac_colons');

            document.getElementById('mac_colons_upper').value = colons.toUpperCase();
            document.getElementById('mac_colons_lower').value = colons.toLowerCase();
        }
        if (from !== 'mac_minus') {
            document.getElementById('mac_minus').value = clean.match(/.{1,2}/g).join('-');
            validate('mac_minus');
        }

        document.getElementById('mac_raw').value = clean;
    }

    ['mac_dots', 'mac_colons', 'mac_minus'].forEach(id => {
        const input = document.getElementById(id);
        input.addEventListener('input', function () {
            fillFields(id, this.value);
            validate(id);
        });
        validate(id);
    });
</script>
