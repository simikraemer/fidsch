<?php
// biz/Start.php (Transaktionen-Übersicht)

// 1) Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// 2) DB
require_once __DIR__ . '/../db.php';
$bizconn->set_charset('utf8mb4');

// 3) Filter aus GET (kein Output davor!)
$monat = $_GET['monat'] ?? date('m');
$jahr  = $_GET['jahr']  ?? date('Y');
if ($monat === '') $monat = null;
if ($jahr === '')  $jahr  = null;

$katFilter = $_GET['kat'] ?? '';

// 4) verfügbare Zeiträume ermitteln
$zeitStmt = $bizconn->query("
    SELECT DISTINCT MONTH(valutadatum) AS monat, YEAR(valutadatum) AS jahr
    FROM transfers
    ORDER BY jahr DESC, monat DESC
");
$verfuegbareZeitraeume = [];
while ($row = $zeitStmt->fetch_assoc()) {
    $verfuegbareZeitraeume[] = $row;
}

// Monatsnamen
$monatNamen = [
    1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
    5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
];

// Jahre-Liste
$jahre = array_unique(array_column($verfuegbareZeitraeume, 'jahr'));
rsort($jahre);

// Kategorien laden
$kats = [];
$res = $bizconn->query("SELECT id, name FROM kategorien ORDER BY name");
while ($r = $res->fetch_assoc()) {
    $kats[$r['id']] = $r['name'];
}

// 5) Einträge für gewählten Zeitraum laden (vor Rendering)
$query  = "SELECT t.id, valutadatum, verwendungszweck, zahlungspartner, betrag, kategorie_id FROM transfers t WHERE 1=1";
$params = [];
$types  = "";

if (!is_null($monat)) {
    $query   .= " AND MONTH(valutadatum) = ?";
    $params[] = (int)$monat;
    $types   .= "i";
}
if (!is_null($jahr)) {
    $query   .= " AND YEAR(valutadatum) = ?";
    $params[] = (int)$jahr;
    $types   .= "i";
}
if ($katFilter !== '') {
    $query   .= " AND kategorie_id = ?";
    $params[] = (int)$katFilter;
    $types   .= "i";
}
$query .= " ORDER BY valutadatum DESC";

$stmt = $bizconn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$entries = $stmt->get_result();

// 6) Rendering starten
$page_title = 'Transfers';
require_once __DIR__ . '/../head.php';    // <!DOCTYPE html> … <body>
require_once __DIR__ . '/../navbar.php';  // Navbar
?>
<main class="container" style="max-width: 1000px;">
    <h1 class="ueberschrift">Transaktionen</h1>

    <form method="get" class="zeitbereich-form" style="margin-bottom: 2rem;">
        <div class="input-row" style="flex-wrap: wrap; gap: 1rem;">
            <div class="input-group-dropdown">
                <label for="monat">Monat</label>
                <select name="monat" id="monat" onchange="this.form.submit()">
                    <option value="">Alle Monate</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= ($m == (int)$monat) ? 'selected' : '' ?>>
                            <?= str_pad((string)$m, 2, '0', STR_PAD_LEFT) ?> - <?= $monatNamen[$m] ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="input-group-dropdown">
                <label for="jahr">Jahr</label>
                <select name="jahr" id="jahr" onchange="this.form.submit()">
                    <option value="">Alle Jahre</option>
                    <?php foreach ($jahre as $j): ?>
                        <?php if ((int)$j <= 2021) continue; ?>
                        <option value="<?= (int)$j ?>" <?= ((int)$j == (int)$jahr) ? 'selected' : '' ?>>
                            <?= (int)$j ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group-dropdown">
                <label for="kat">Kategorie</label>
                <select name="kat" id="kat" onchange="this.form.submit()">
                    <option value="">Alle Kategorien</option>
                    <?php foreach ($kats as $id => $name): ?>
                        <option value="<?= (int)$id ?>" <?= (($katFilter !== '' && (int)$katFilter === (int)$id) ? 'selected' : '') ?>>
                            <?= htmlspecialchars($name, ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <table class="food-table">
        <thead>
            <tr>
                <th>Datum</th>
                <th>Zahlungspartner</th>
                <th>Betrag</th>
                <th>Kategorie</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $entries->fetch_assoc()): ?>
                <tr data-id="<?= (int)$row['id'] ?>">
                    <td><?= htmlspecialchars(date('d.m.Y', strtotime($row['valutadatum'])), ENT_QUOTES) ?></td>
                    <td style="max-width: 250px;">
                        <?= htmlspecialchars($row['zahlungspartner'] ?? '', ENT_QUOTES) ?>
                    </td>
                    <td style="white-space: nowrap;"><?= number_format((float)$row['betrag'], 2, ',', '.') ?> €</td>
                    <td style="width: 220px;">
                        <select class="kategorie-select" style="width: 100%;">
                            <option value="">-</option>
                            <?php foreach ($kats as $id => $name): ?>
                                <option value="<?= (int)$id ?>" <?= ((int)$row['kategorie_id'] === (int)$id) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($name, ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</main>

<!-- Modal -->
<div id="modal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close-button" onclick="closeModal()">&times;</span>
        <h2>Eintrag bearbeiten</h2>
        <form id="modal-form">
            <input type="hidden" name="id" id="modal-id">

            <div class="input-row">
                <div class="input-group">
                    <label>Buchungstag</label>
                    <input type="text" id="modal-buchungstag" readonly>
                </div>
                <div class="input-group">
                    <label>Valutadatum</label>
                    <input type="text" id="modal-valutadatum" readonly>
                </div>
            </div>

            <div class="input-row">
                <div class="input-group">
                    <label>Auftragskonto</label>
                    <input type="text" id="modal-auftragskonto" readonly>
                </div>
                <div class="input-group">
                    <label>Zahlungspartner</label>
                    <input type="text" id="modal-zahlungspartner" readonly>
                </div>
            </div>

            <div class="input-row">
                <div class="input-group">
                    <label>IBAN</label>
                    <input type="text" id="modal-iban" readonly>
                </div>
                <div class="input-group">
                    <label>BIC</label>
                    <input type="text" id="modal-bic" readonly>
                </div>
            </div>

            <div class="input-row">
                <div class="input-group">
                    <label>Buchungstext</label>
                    <textarea id="modal-buchungstext" rows="2" readonly></textarea>
                </div>
                <div class="input-group">
                    <label>Info</label>
                    <textarea id="modal-info" rows="2" readonly></textarea>
                </div>
            </div>

            <div class="input-row">
                <div class="input-group">
                    <label>Betrag</label>
                    <input type="text" id="modal-betrag" readonly>
                </div>
                <div class="input-group">
                    <label>Währung</label>
                    <input type="text" id="modal-waehrung" readonly>
                </div>
                <div class="input-group">
                    <label>Kategorie</label>
                    <select id="modal-kategorie" name="kategorie_id">
                        <option value="">-</option>
                        <?php foreach ($kats as $id => $name): ?>
                            <option value="<?= (int)$id ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="input-group">
                <label>Verwendungszweck</label>
                <textarea id="modal-verwendungszweck" rows="3" readonly></textarea>
            </div>

            <div class="input-group">
                <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                    <input type="file" id="modal-document" accept="application/pdf,image/*" style="display: none;">
                    <button type="button" id="modal-document-button">Dokument hochladen</button>
                    <button type="button" id="modal-document-view" style="display: none;">Zur Rechnung</button>
                    <button type="submit">Speichern</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Kategorie-Änderung inline speichern
document.querySelectorAll('.kategorie-select').forEach(select => {
    select.addEventListener('change', async function () {
        const row = this.closest('tr');
        const transferId = row.dataset.id;
        const kategorieId = this.value;

        const res = await fetch('update_kategorie.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${encodeURIComponent(transferId)}&kategorie_id=${encodeURIComponent(kategorieId)}`
        });

        if (res.ok) {
            row.style.backgroundColor = 'var(--success)';
            setTimeout(() => row.style.backgroundColor = '', 500);
        } else {
            row.style.backgroundColor = 'var(--error)';
            setTimeout(() => row.style.backgroundColor = '', 1000);
        }
    });
});

const documentInput = document.getElementById('modal-document');
const documentUploadButton = document.getElementById('modal-document-button');
const documentViewButton = document.getElementById('modal-document-view');

// Button "Zur Rechnung" klickbar machen
documentViewButton.addEventListener('click', () => {
    const url = documentViewButton.dataset.url;
    if (url) {
        window.open(url, '_blank');
    }
});

function setDocumentButton(url) {
    if (url) {
        documentViewButton.dataset.url = url;
        documentViewButton.style.display = 'inline-flex';
    } else {
        documentViewButton.style.display = 'none';
        delete documentViewButton.dataset.url;
    }
}

documentUploadButton.addEventListener('click', () => {
    if (!document.getElementById('modal-id').value) return;
    documentInput.click();
});

documentInput.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const transferId = document.getElementById('modal-id').value;
    if (!transferId) {
        alert('Kein Transfer ausgewählt.');
        return;
    }

    const formData = new FormData();
    formData.append('id', transferId);
    formData.append('document', file);

    documentUploadButton.disabled = true;
    documentUploadButton.textContent = 'Lade hoch...';

    try {
        const res = await fetch('upload_transfer_document.php', {
            method: 'POST',
            body: formData
        });
        const json = await res.json().catch(() => null);
        if (!res.ok || !json?.success) {
            throw new Error(json?.error || 'Upload fehlgeschlagen.');
        }
        const url = json.url || `transfer_document.php?id=${encodeURIComponent(transferId)}`;
        setDocumentButton(url);
    } catch (err) {
        alert(err.message || 'Upload fehlgeschlagen.');
    } finally {
        documentUploadButton.disabled = false;
        documentUploadButton.textContent = 'Dokument hochladen';
        documentInput.value = '';
    }
});

// Modal öffnen mit Row-Klick (Kategorie-Dropdown ausgenommen)
document.querySelectorAll('tbody tr').forEach(row => {
    row.addEventListener('click', async (e) => {
        if (e.target.closest('.kategorie-select')) return;

        const id = row.dataset.id;
        const res = await fetch(`get_transfer.php?id=${encodeURIComponent(id)}`);
        const data = await res.json();

        if (!data || !data.id) return;

        document.getElementById('modal-id').value                = data.id;
        document.getElementById('modal-buchungstag').value       = data.buchungstag ?? "";
        document.getElementById('modal-valutadatum').value       = data.valutadatum ?? "";
        document.getElementById('modal-auftragskonto').value     = data.auftragskonto ?? "";
        document.getElementById('modal-zahlungspartner').value   = data.zahlungspartner ?? "";
        document.getElementById('modal-iban').value              = data.iban ?? "";
        document.getElementById('modal-bic').value               = data.bic ?? "";
        document.getElementById('modal-buchungstext').value      = data.buchungstext ?? "";
        document.getElementById('modal-verwendungszweck').value  = data.verwendungszweck ?? "";
        document.getElementById('modal-info').value              = data.info ?? "";
        document.getElementById('modal-betrag').value            = (parseFloat(data.betrag || 0).toFixed(2) + " €");
        document.getElementById('modal-waehrung').value          = data.waehrung ?? "";
        document.getElementById('modal-kategorie').value         = data.kategorie_id ?? "";
        setDocumentButton(data.document_url ?? null);

        document.getElementById('modal').style.display = 'flex';
    });
});

// Modal schließen
function closeModal() {
    document.getElementById('modal').style.display = 'none';
    setDocumentButton(null);
    documentUploadButton.disabled = false;
    documentUploadButton.textContent = 'Dokument hochladen';
    documentInput.value = '';
}
document.getElementById('modal').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
});

// Modal speichern
document.getElementById('modal-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const res = await fetch('update_transfer.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    });

    if (res.ok) {
        closeModal();
        location.reload();
    } else {
        alert('Fehler beim Speichern.');
    }
});
</script>

</body>
</html>
