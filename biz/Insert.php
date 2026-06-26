<?php
// biz/Insert.php
// Klassische Upload-Seite. Die CSV-Verarbeitung liegt zentral in upload_csv.php.

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/upload_csv.php';

$bizconn->set_charset('utf8mb4');

$importMessage = '';
$importErrors = [];
$importOk = null;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $importResult = csvImportTransfersFromUpload($bizconn, $_FILES['csv']);

    $importOk = (bool)($importResult['ok'] ?? false);
    $importMessage = (string)($importResult['message'] ?? 'Import abgeschlossen.');
    $importErrors = $importResult['errors'] ?? [];
}

$page_title = 'Transfers importieren';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>
<main class="container">
    <h1 class="ueberschrift">Transfers importieren</h1>

    <?php if ($importMessage !== ''): ?>
        <p style="text-align: center; font-weight: bold; color: <?= ($importOk === false) ? 'var(--error)' : 'var(--success)' ?>;">
            <?= esc($importMessage) ?>
        </p>
    <?php endif; ?>

    <?php if ($importErrors !== []): ?>
        <details open style="max-width: 1000px; margin: 1rem auto; padding: 1rem; border: 1px solid #ccc; border-radius: 0.5rem; background: #fff8f8;">
            <summary style="font-weight: bold; cursor: pointer;">Fehlerdetails anzeigen (<?= count($importErrors) ?>)</summary>
            <ul style="margin-top: 1rem; padding-left: 1.2rem;">
                <?php foreach ($importErrors as $error): ?>
                    <li style="margin-bottom: 0.4rem; color: var(--error);"><?= esc($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <form class="form-block" method="post" enctype="multipart/form-data">
        <label for="csv-upload"><strong>CSV-Datei hochladen</strong></label>
        <input type="file" id="csv-upload" name="csv" accept=".csv" required>
        <p style="font-size: 0.9rem; color: #666;">
            Hinweis: Erwartet wird die Sparkasse-CSV.
        </p>
        <button type="submit">Hochladen</button>
    </form>
</main>

</body>
</html>