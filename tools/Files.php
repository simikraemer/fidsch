<?php
// /work/www/tools/Files_v2.php
// Privater Dateiaustausch zwischen eigenen Geräten.

require_once __DIR__ . '/../auth.php';

const FILE_SHARE_DIR = '/work/www/uploads/file_transfers';
const FILE_SHARE_URL = '/tools/files';

function file_share_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function file_share_format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unit = 0;

    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    if ($unit === 0) {
        return (string)$bytes . ' B';
    }

    return number_format($value, $value >= 10 ? 1 : 2, ',', '.') . ' ' . $units[$unit];
}

function file_share_safe_filename(string $original): string
{
    $name = basename(str_replace('\\', '/', $original));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
    $name = trim($name, " .\t\n\r\0\x0B");

    if ($name === '') {
        return 'Datei';
    }

    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $base = pathinfo($name, PATHINFO_FILENAME);

    $base = trim($base, " .\t\n\r\0\x0B");
    if ($base === '') {
        $base = 'Datei';
    }

    if (function_exists('mb_strcut')) {
        $base = mb_strcut($base, 0, 180, 'UTF-8');
    } else {
        $base = substr($base, 0, 180);
    }

    // Erweiterung bewusst konservativ halten; Inhalt selbst darf beliebig sein.
    $extension = preg_replace('/[^A-Za-z0-9_+-]/', '', $extension) ?? '';
    if (strlen($extension) > 24) {
        $extension = substr($extension, 0, 24);
    }

    return $extension !== '' ? $base . '.' . $extension : $base;
}

function file_share_unique_destination(string $dir, string $filename): array
{
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $candidate = $filename;
    $counter = 2;

    while (file_exists($dir . '/' . $candidate)) {
        $suffix = ' (' . $counter . ')';
        $candidate = $extension !== ''
            ? $base . $suffix . '.' . $extension
            : $base . $suffix;
        $counter++;
    }

    return [$candidate, $dir . '/' . $candidate];
}

function file_share_resolve_file(string $dir, string $name): ?string
{
    if ($name === '' || basename($name) !== $name) {
        return null;
    }

    $dirReal = realpath($dir);
    $pathReal = realpath($dir . '/' . $name);

    if ($dirReal === false || $pathReal === false) {
        return null;
    }

    if (dirname($pathReal) !== $dirReal || !is_file($pathReal) || is_link($pathReal)) {
        return null;
    }

    return $pathReal;
}

function file_share_mime(string $path): string
{
    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    } catch (Throwable $e) {
        // Fallback unten.
    }

    return 'application/octet-stream';
}

function file_share_can_inline(string $mime): bool
{
    if (in_array($mime, [
        'application/pdf',
        'application/json',
        'text/plain',
        'text/csv',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/avif',
    ], true)) {
        return true;
    }

    return str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/');
}

function file_share_serve_file(string $path, bool $inlineRequested): never
{
    $size = filesize($path);
    if ($size === false) {
        http_response_code(500);
        exit('Dateigröße konnte nicht ermittelt werden.');
    }

    $name = basename($path);
    $mime = file_share_mime($path);
    $inline = $inlineRequested && file_share_can_inline($mime);

    $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?: 'datei';
    $utf8Name = rawurlencode($name);
    $disposition = $inline ? 'inline' : 'attachment';

    $start = 0;
    $end = max(0, $size - 1);
    $isPartial = false;

    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if ($size > 0 && $range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
        $first = $m[1];
        $last = $m[2];

        if ($first === '' && $last !== '') {
            $suffixLength = (int)$last;
            if ($suffixLength > 0) {
                $start = max(0, $size - $suffixLength);
                $end = $size - 1;
                $isPartial = true;
            }
        } elseif ($first !== '') {
            $start = (int)$first;
            $end = $last !== '' ? min((int)$last, $size - 1) : $size - 1;

            if ($start <= $end && $start < $size) {
                $isPartial = true;
            } else {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                exit;
            }
        }
    }

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Accept-Ranges: bytes');
    header(
        'Content-Disposition: ' . $disposition .
        '; filename="' . addcslashes($asciiName, '\\"') . '"' .
        "; filename*=UTF-8''" . $utf8Name
    );

    if ($isPartial) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $length = $size === 0 ? 0 : ($end - $start + 1);
    header('Content-Length: ' . $length);

    if ($_SERVER['REQUEST_METHOD'] === 'HEAD' || $length === 0) {
        exit;
    }

    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        http_response_code(500);
        exit('Datei konnte nicht geöffnet werden.');
    }

    if ($start > 0) {
        fseek($fh, $start);
    }

    $remaining = $length;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = fread($fh, min(1024 * 1024, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }

        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }

    fclose($fh);
    exit;
}

function file_share_upload_error_text(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE   => 'Datei ist größer als upload_max_filesize.',
        UPLOAD_ERR_FORM_SIZE  => 'Datei ist größer als das erlaubte Formularlimit.',
        UPLOAD_ERR_PARTIAL    => 'Upload wurde nur teilweise übertragen.',
        UPLOAD_ERR_NO_FILE    => 'Keine Datei ausgewählt.',
        UPLOAD_ERR_NO_TMP_DIR => 'Temporärer Upload-Ordner fehlt.',
        UPLOAD_ERR_CANT_WRITE => 'Datei konnte nicht auf den Server geschrieben werden.',
        UPLOAD_ERR_EXTENSION  => 'Eine PHP-Erweiterung hat den Upload gestoppt.',
        default               => 'Unbekannter Upload-Fehler.',
    };
}

function file_share_flash(string $type, string $text): void
{
    $_SESSION['file_share_flash'] = [
        'type' => $type,
        'text' => $text,
    ];
}

function file_share_redirect(): never
{
    header('Location: ' . FILE_SHARE_URL, true, 303);
    exit;
}

if (!is_dir(FILE_SHARE_DIR)) {
    if (!mkdir(FILE_SHARE_DIR, 0770, true) && !is_dir(FILE_SHARE_DIR)) {
        http_response_code(500);
        exit('Transfer-Ordner konnte nicht angelegt werden.');
    }
}

if (!is_readable(FILE_SHARE_DIR) || !is_writable(FILE_SHARE_DIR)) {
    http_response_code(500);
    exit('Transfer-Ordner ist für den Webserver nicht les-/schreibbar.');
}

if (empty($_SESSION['file_share_csrf'])) {
    $_SESSION['file_share_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['file_share_csrf'];

// Dateien kontrolliert inline oder als Download ausliefern.
if (isset($_GET['open']) || isset($_GET['download'])) {
    $requested = isset($_GET['open']) ? (string)$_GET['open'] : (string)$_GET['download'];
    $path = file_share_resolve_file(FILE_SHARE_DIR, $requested);

    if ($path === null) {
        http_response_code(404);
        exit('Datei nicht gefunden.');
    }

    file_share_serve_file($path, isset($_GET['open']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        file_share_flash(
            'error',
            'Upload ist größer als das PHP-Limit (post_max_size: ' . ini_get('post_max_size') .
            ', upload_max_filesize: ' . ini_get('upload_max_filesize') . ').'
        );
        file_share_redirect();
    }

    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
        http_response_code(400);
        exit('Ungültige Anfrage.');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $name = (string)($_POST['file'] ?? '');
        $path = file_share_resolve_file(FILE_SHARE_DIR, $name);

        if ($path === null) {
            file_share_flash('error', 'Datei wurde nicht gefunden.');
        } elseif (@unlink($path)) {
            file_share_flash('success', '„' . $name . '“ wurde gelöscht.');
        } else {
            file_share_flash('error', '„' . $name . '“ konnte nicht gelöscht werden.');
        }

        file_share_redirect();
    }

    if ($action === 'upload') {
        $names = $_FILES['files']['name'] ?? [];
        $tmpNames = $_FILES['files']['tmp_name'] ?? [];
        $errors = $_FILES['files']['error'] ?? [];

        if (!is_array($names)) {
            $names = [$names];
            $tmpNames = [$tmpNames];
            $errors = [$errors];
        }

        $uploaded = 0;
        $failed = [];

        foreach ($names as $i => $originalName) {
            $originalName = (string)$originalName;
            $tmpPath = (string)($tmpNames[$i] ?? '');
            $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);

            if ($error !== UPLOAD_ERR_OK) {
                if ($error !== UPLOAD_ERR_NO_FILE || count($names) === 1) {
                    $failed[] = ($originalName !== '' ? $originalName . ': ' : '') . file_share_upload_error_text($error);
                }
                continue;
            }

            if (!is_uploaded_file($tmpPath)) {
                $failed[] = ($originalName !== '' ? $originalName . ': ' : '') . 'Ungültiger Upload.';
                continue;
            }

            $safeName = file_share_safe_filename($originalName);
            [$storedName, $destination] = file_share_unique_destination(FILE_SHARE_DIR, $safeName);

            if (!move_uploaded_file($tmpPath, $destination)) {
                $failed[] = $originalName . ': Speichern fehlgeschlagen.';
                continue;
            }

            @chmod($destination, 0660);
            @touch($destination);
            $uploaded++;
        }

        if ($uploaded > 0 && !$failed) {
            file_share_flash('success', $uploaded === 1 ? 'Datei hochgeladen.' : $uploaded . ' Dateien hochgeladen.');
        } elseif ($uploaded > 0) {
            file_share_flash('error', $uploaded . ' hochgeladen. Fehler: ' . implode(' | ', $failed));
        } else {
            file_share_flash('error', implode(' | ', $failed) ?: 'Keine Datei ausgewählt.');
        }

        file_share_redirect();
    }

    http_response_code(400);
    exit('Unbekannte Aktion.');
}

$files = [];
$iterator = new FilesystemIterator(FILE_SHARE_DIR, FilesystemIterator::SKIP_DOTS);

foreach ($iterator as $item) {
    if (!$item->isFile() || $item->isLink()) {
        continue;
    }

    $files[] = [
        'name' => $item->getFilename(),
        'size' => $item->getSize(),
        'mtime' => $item->getMTime(),
    ];
}

usort($files, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

$flash = $_SESSION['file_share_flash'] ?? null;
unset($_SESSION['file_share_flash']);

$page_title = 'Files';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<main class="phan-page">
    <div class="phan-head">
        <h1 class="phan-title">Files</h1>
    </div>

    <?php if (is_array($flash) && !empty($flash['text'])): ?>
        <div class="phan-msg <?= ($flash['type'] ?? '') === 'error' ? 'phan-error' : '' ?>">
            <?= file_share_h((string)$flash['text']) ?>
        </div>
    <?php endif; ?>

    <div class="form-block">
        <section class="phan-card">
            <h2 class="phan-card-title">Dateien hochladen</h2>

            <form method="post" enctype="multipart/form-data" class="form-block">
                <input type="hidden" name="csrf" value="<?= file_share_h($csrf) ?>">
                <input type="hidden" name="action" value="upload">

                <label>
                    <strong>Datei auswählen</strong>
                    <input
                        type="file"
                        name="files[]"
                        multiple
                        required
                    >
                </label>

                <div class="phan-actions phan-actions--top">
                    <button type="submit">Hochladen</button>
                </div>
            </form>
        </section>

        <section class="phan-table-wrap">
            <table class="phan-table">
                <thead>
                    <tr>
                        <th>Datei</th>
                        <th>Größe</th>
                        <th>Hochgeladen</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$files): ?>
                        <tr>
                            <td colspan="4" class="phan-empty-table">Noch keine Dateien vorhanden.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($files as $file): ?>
                            <tr>
                                <td><strong><?= file_share_h($file['name']) ?></strong></td>
                                <td><?= file_share_h(file_share_format_bytes((int)$file['size'])) ?></td>
                                <td><?= date('d.m.Y H:i', (int)$file['mtime']) ?></td>
                                <td>
                                    <div class="phan-actions phan-actions--top">
                                        <form method="get" action="<?= FILE_SHARE_URL ?>" target="_blank">
                                            <input type="hidden" name="open" value="<?= file_share_h($file['name']) ?>">
                                            <button type="submit" class="btn-secondary">Öffnen</button>
                                        </form>

                                        <form method="get" action="<?= FILE_SHARE_URL ?>">
                                            <input type="hidden" name="download" value="<?= file_share_h($file['name']) ?>">
                                            <button type="submit">Download</button>
                                        </form>

                                        <form
                                            method="post"
                                            action="<?= FILE_SHARE_URL ?>"
                                            data-confirm="<?= file_share_h('„' . $file['name'] . '“ wirklich löschen?') ?>"
                                            onsubmit="return confirm(this.dataset.confirm);"
                                        >
                                            <input type="hidden" name="csrf" value="<?= file_share_h($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="file" value="<?= file_share_h($file['name']) ?>">
                                            <button type="submit" class="phan-danger">Löschen</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>
</main>

</body>
</html>
