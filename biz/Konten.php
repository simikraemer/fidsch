<?php
// biz/Konten.php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$bizconn->set_charset('utf8mb4');

$message = '';
$errors = [];

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function parseGermanAmount(?string $value): ?float
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $value = str_replace(["\xC2\xA0", ' '], '', $value);
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);

    if (!is_numeric($value)) {
        return null;
    }

    return (float)$value;
}

function euro($value): string
{
    return number_format((float)$value, 2, ',', '.') . ' €';
}

function formatDateTime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    try {
        return (new DateTime($value))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $value;
    }
}

/* =========================================================
 * POST: neuen Kontostand speichern
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $konto     = trim((string)($_POST['konto'] ?? ''));
    $betragRaw = trim((string)($_POST['betrag'] ?? ''));
    $info      = trim((string)($_POST['info'] ?? ''));

    if ($konto === '') {
        $errors[] = 'Bitte ein Konto angeben.';
    } elseif (mb_strlen($konto, 'UTF-8') > 100) {
        $errors[] = 'Der Kontoname ist zu lang. Maximal 100 Zeichen.';
    }

    $betrag = parseGermanAmount($betragRaw);
    if ($betrag === null) {
        $errors[] = 'Bitte einen gültigen Betrag angeben, z. B. 1.254,51 oder 1254.51.';
    }

    if ($errors === []) {
        $stmt = $bizconn->prepare("
            INSERT INTO konto_staende (
                konto,
                betrag,
                info
            ) VALUES (
                ?, ?, ?
            )
        ");

        if ($stmt === false) {
            $errors[] = 'SQL-Statement konnte nicht vorbereitet werden.';
            $errors[] = $bizconn->error;
        } else {
            try {
                $stmt->bind_param(
                    'sds',
                    $konto,
                    $betrag,
                    $info
                );

                $stmt->execute();
                $stmt->close();

                header('Location: /biz/konten?saved=1', true, 303);
                exit;
            } catch (mysqli_sql_exception $e) {
                $errors[] = 'SQL-Fehler: ' . $e->getMessage();
            } catch (Throwable $e) {
                $errors[] = 'Fehler: ' . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = 'Kontostand wurde gespeichert.';
}

/* =========================================================
 * Daten laden
 * ========================================================= */
$kontenVorschlaege = [];
$latestKontostaende = [];

$kontenRes = $bizconn->query("
    SELECT DISTINCT konto
    FROM konto_staende
    ORDER BY konto ASC
");

if ($kontenRes) {
    while ($row = $kontenRes->fetch_assoc()) {
        $kontenVorschlaege[] = (string)$row['konto'];
    }
}

$latestSql = "
    SELECT ks.id, ks.konto, ks.betrag, ks.eingetragen_am, ks.info
    FROM konto_staende ks
    WHERE NOT EXISTS (
        SELECT 1
        FROM konto_staende newer
        WHERE newer.konto = ks.konto
          AND (
              newer.eingetragen_am > ks.eingetragen_am
              OR (
                  newer.eingetragen_am = ks.eingetragen_am
                  AND newer.id > ks.id
              )
          )
    )
    ORDER BY ks.konto ASC
";

$latestRes = $bizconn->query($latestSql);
if ($latestRes) {
    while ($row = $latestRes->fetch_assoc()) {
        $latestKontostaende[] = $row;
    }
}

$page_title = 'Konten aktualisieren';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div class="container">
    <h1 class="ueberschrift">Konten aktualisieren</h1>

    <?php if ($message !== ''): ?>
        <p class="kalorien-output"><?= esc($message) ?></p>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <details open>
            <summary>Fehlerdetails anzeigen (<?= count($errors) ?>)</summary>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= esc($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>

        <div class="form-separator"></div>
    <?php endif; ?>

    <form class="form-block" method="post">
        <div class="input-group">
            <label for="konto">Konto:</label>
            <input
                type="text"
                id="konto"
                name="konto"
                list="konto-vorschlaege"
                maxlength="100"
                placeholder="z. B. Sparbuch, PayPal"
                required
            >

            <datalist id="konto-vorschlaege">
                <?php foreach ($kontenVorschlaege as $konto): ?>
                    <option value="<?= esc($konto) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="input-group">
            <label for="betrag">Kontostand:</label>
            <input
                type="text"
                id="betrag"
                name="betrag"
                inputmode="decimal"
                placeholder="z. B. 1.254,51"
                required
            >
        </div>

        <div class="input-group">
            <label for="info">Notiz:</label>
            <textarea
                id="info"
                name="info"
                rows="3"
                placeholder="Optional, z. B. Stand aus App geprüft"
            ></textarea>
        </div>

        <button type="submit">Kontostand speichern</button>
    </form>
</div>

<div class="container">
    <table class="food-table">
        <thead>
            <tr>
                <th>Konto</th>
                <th>Stand</th>
                <th>Eingetragen</th>
                <th>Notiz</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($latestKontostaende === []): ?>
                <tr>
                    <td colspan="5">Noch keine externen Kontostände gespeichert.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($latestKontostaende as $row): ?>
                    <tr>
                        <td><?= esc((string)$row['konto']) ?></td>
                        <td><?= esc(euro((float)$row['betrag'])) ?></td>
                        <td><?= esc(formatDateTime((string)$row['eingetragen_am'])) ?></td>
                        <td>
                            <?= $row['info'] !== null && trim((string)$row['info']) !== ''
                                ? esc((string)$row['info'])
                                : '—'
                            ?>
                        </td>
                        <td>
                            <button
                                type="button"
                                class="prefill-konto"
                                data-konto="<?= esc((string)$row['konto']) ?>"
                                data-betrag="<?= esc(number_format((float)$row['betrag'], 2, ',', '.')) ?>"
                                data-info="<?= esc((string)($row['info'] ?? '')) ?>"
                            >
                                Aktualisieren
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const kontoInput = document.getElementById('konto');
    const betragInput = document.getElementById('betrag');
    const infoInput = document.getElementById('info');

    document.querySelectorAll('.prefill-konto').forEach((button) => {
        button.addEventListener('click', () => {
            if (kontoInput) kontoInput.value = button.dataset.konto || '';
            if (betragInput) betragInput.value = button.dataset.betrag || '';
            if (infoInput) infoInput.value = button.dataset.info || '';

            if (betragInput) {
                betragInput.focus();
                betragInput.select();
            }

            const form = document.querySelector('form.form-block');
            if (form) {
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    });
});
</script>

</body>
</html>