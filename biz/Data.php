<?php
require_once 'auth.php';
require_once 'template.php';

$bizconn->set_charset('utf8mb4');

// Monat und Jahr aus GET oder Default = aktueller Monat
$monat = $_GET['monat'] ?? date('m');
$jahr  = $_GET['jahr']  ?? date('Y');

// Alle Zeiträume (Monat+Jahr), in denen es Buchungen gibt
$zeitStmt = $bizconn->query("
    SELECT DISTINCT MONTH(valutadatum) AS monat, YEAR(valutadatum) AS jahr
    FROM transfers
    ORDER BY jahr DESC, monat DESC
");

$verfuegbareZeitraeume = [];
while ($row = $zeitStmt->fetch_assoc()) {
    $verfuegbareZeitraeume[] = $row;
}

// Monatsnamen definieren
$monatNamen = [
    1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
    5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
];

// Jahre extrahieren und eindeutige Liste erzeugen
$jahre = array_unique(array_column($verfuegbareZeitraeume, 'jahr'));
rsort($jahre);

// Kategorien laden
$kats = [];
$res = $bizconn->query("SELECT id, name FROM kategorien ORDER BY name");
while ($r = $res->fetch_assoc()) {
    $kats[$r['id']] = $r['name'];
}

// Einträge für den gewählten Zeitraum laden
$stmt = $bizconn->prepare("
    SELECT t.id, valutadatum, verwendungszweck, zahlungspartner, betrag, kategorie_id
    FROM transfers t
    WHERE MONTH(valutadatum) = ? AND YEAR(valutadatum) = ?
    ORDER BY valutadatum ASC
");
$stmt->bind_param('ii', $monat, $jahr);
$stmt->execute();
$entries = $stmt->get_result();
?>

<body>
    <main class="container" style="width: 1200px;">
        <h1 class="ueberschrift">Transaktionen <?= "$monat.$jahr" ?></h1>

        <form method="get" class="zeitbereich-form" style="margin-bottom: 2rem;">
            <div class="input-row">
                <div class="input-group">
                    <label for="monat">Monat</label>
                        <select name="monat" id="monat" onchange="this.form.submit()">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= ($m == $monat) ? 'selected' : '' ?>>
                                    <?= str_pad($m, 2, '0', STR_PAD_LEFT) ?> - <?= $monatNamen[$m] ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                </div>

                <div class="input-group">
                    <label for="jahr">Jahr</label>
                    <select name="jahr" id="jahr" onchange="this.form.submit()">
                        <?php foreach ($jahre as $j): ?>
                            <?php if ($j <= 2023) continue; ?>
                            <option value="<?= $j ?>" <?= ($j == $jahr) ? 'selected' : '' ?>>
                                <?= $j ?>
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
                    <tr data-id="<?= $row['id'] ?>">
                        <td><?= date('d.m.Y', strtotime($row['valutadatum'])) ?></td>
                        <td style="max-width: 250px;">
                            <?= htmlspecialchars($row['zahlungspartner']) ?>
                        </td>

                        <td style="white-space: nowrap;"><?= number_format($row['betrag'], 2, ',', '.') ?> €</td>
                        <td style="width: 220px;">
                            <select class="kategorie-select" style="width: 100%;">
                                <option value="">–</option>
                                <?php foreach ($kats as $id => $name): ?>
                                    <option value="<?= $id ?>" <?= ($row['kategorie_id'] == $id) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </main>

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
                            <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>


            

            <div class="input-group">
                <label>Verwendungszweck</label>
                <textarea id="modal-verwendungszweck" rows="3" readonly></textarea>
            </div>

            <button type="submit">Speichern</button>
        </form>
    </div>
</div>



    <script>
        document.querySelectorAll('.kategorie-select').forEach(select => {
            select.addEventListener('change', async function () {
                const row = this.closest('tr');
                const transferId = row.dataset.id;
                const kategorieId = this.value;

                const res = await fetch('update_kategorie.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id=${transferId}&kategorie_id=${kategorieId}`
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
    </script>

    <script>
        // Modal öffnen
        document.querySelectorAll('tbody tr').forEach(row => {
            row.addEventListener('click', async (e) => {
                if (e.target.closest('.kategorie-select')) return;

                const id = row.dataset.id;
                const res = await fetch(`get_transfer.php?id=${id}`);
                const data = await res.json();

                if (!data || !data.id) return;

                document.getElementById('modal-id').value = data.id;
                document.getElementById('modal-buchungstag').value = data.buchungstag ?? "";
                document.getElementById('modal-valutadatum').value = data.valutadatum ?? "";
                document.getElementById('modal-auftragskonto').value = data.auftragskonto ?? "";
                document.getElementById('modal-zahlungspartner').value = data.zahlungspartner ?? "";
                document.getElementById('modal-iban').value = data.iban ?? "";
                document.getElementById('modal-bic').value = data.bic ?? "";
                document.getElementById('modal-buchungstext').value = data.buchungstext ?? "";
                document.getElementById('modal-verwendungszweck').value = data.verwendungszweck ?? "";
                document.getElementById('modal-info').value = data.info ?? "";
                document.getElementById('modal-betrag').value = parseFloat(data.betrag).toFixed(2) + " €";
                document.getElementById('modal-waehrung').value = data.waehrung ?? "";
                document.getElementById('modal-kategorie').value = data.kategorie_id ?? "";

                document.getElementById('modal').style.display = 'flex';
            });
        });



        // Modal schließen
        function closeModal() {
            document.getElementById('modal').style.display = 'none';
        }

        // Modal schließen durch Klick auf Hintergrund
        document.getElementById('modal').addEventListener('click', function (e) {
            if (e.target === this) closeModal();
        });

        // Speichern
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
