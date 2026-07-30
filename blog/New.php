<?php
// blog/New.php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const BLOG_UPLOAD_MAX_BYTES = 12 * 1024 * 1024;

$blogUploadDirectory = '/work/blog/img';
$blogPublicImageBase = 'https://fidsch.de/img';

$blogDbReady = isset($blogconn) && $blogconn instanceof mysqli;
$blogDomReady = class_exists(DOMDocument::class) && class_exists(DOMXPath::class);

if ($blogDbReady) {
    $blogconn->set_charset('utf8mb4');
}

function blogEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function blogStringLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function blogStringCut(string $value, int $length): string
{
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $length, 'UTF-8')
        : substr($value, 0, $length);
}

function blogJsonResponse(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function blogSlugify(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($converted !== false) {
        $value = $converted;
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

    return trim($value, '-');
}

function blogCreateUniqueSlug(mysqli $connection, string $title): string
{
    $baseSlug = blogSlugify($title);
    if ($baseSlug === '') {
        $baseSlug = 'beitrag';
    }

    $baseSlug = blogStringCut($baseSlug, 170);
    $candidate = $baseSlug;
    $suffix = 2;

    $stmt = $connection->prepare('SELECT id FROM posts WHERE slug = ? LIMIT 1');

    while (true) {
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->fetch_assoc() !== null;
        $result->free();

        if (!$exists) {
            break;
        }

        $suffixText = '-' . $suffix;
        $candidate = blogStringCut($baseSlug, 191 - strlen($suffixText)) . $suffixText;
        $suffix++;
    }

    $stmt->close();
    return $candidate;
}

function blogSanitizeLinkUrl(string $url): ?string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if ($url === '') {
        return null;
    }

    if (
        str_starts_with($url, '/') ||
        str_starts_with($url, '#') ||
        preg_match('#^https?://#i', $url) === 1 ||
        preg_match('#^mailto:#i', $url) === 1
    ) {
        return $url;
    }

    return null;
}

function blogSanitizeImageUrl(string $url): ?string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    $allowedPrefixes = [
        'https://fidsch.de/img/',
        'https://www.fidsch.de/img/',
        'https://blog.fidsch.de/img/',
    ];

    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($url, $prefix)) {
            return $url;
        }
    }

    return null;
}

function blogSanitizeHtml(string $html): string
{
    if (!class_exists(DOMDocument::class) || !class_exists(DOMXPath::class)) {
        throw new RuntimeException(
            'Die PHP-DOM-Erweiterung fehlt. Installiere das Paket php-xml und lade Apache neu.'
        );
    }

    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $allowedElements = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'h2', 'h3', 'blockquote', 'ul', 'ol', 'li',
        'a', 'img', 'code', 'pre', 'hr'
    ];

    $removeCompletely = [
        'script', 'style', 'iframe', 'object', 'embed', 'form',
        'input', 'button', 'textarea', 'select', 'option',
        'video', 'audio', 'source', 'link', 'meta', 'svg', 'math'
    ];

    $document = new DOMDocument('1.0', 'UTF-8');
    $previousLibxmlState = libxml_use_internal_errors(true);

    $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="blog-content-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);

    if (!$loaded) {
        return '';
    }

    $xpath = new DOMXPath($document);
    $rootNodes = $xpath->query('//*[@id="blog-content-root"]');
    $root = $rootNodes !== false ? $rootNodes->item(0) : null;

    if (!$root instanceof DOMElement) {
        return '';
    }

    $nodeList = $xpath->query('//*[@id="blog-content-root"]//*');
    $nodes = [];

    if ($nodeList !== false) {
        foreach ($nodeList as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }
    }

    foreach (array_reverse($nodes) as $element) {
        $tagName = strtolower($element->tagName);

        if (in_array($tagName, $removeCompletely, true)) {
            $element->parentNode?->removeChild($element);
            continue;
        }

        if (!in_array($tagName, $allowedElements, true)) {
            $parent = $element->parentNode;
            if ($parent !== null) {
                while ($element->firstChild !== null) {
                    $parent->insertBefore($element->firstChild, $element);
                }
                $parent->removeChild($element);
            }
            continue;
        }

        $allowedAttributes = match ($tagName) {
            'a' => ['href', 'title'],
            'img' => ['src', 'alt', 'title'],
            default => [],
        };

        $attributesToRemove = [];
        foreach ($element->attributes as $attribute) {
            if (!in_array(strtolower($attribute->name), $allowedAttributes, true)) {
                $attributesToRemove[] = $attribute->name;
            }
        }

        foreach ($attributesToRemove as $attributeName) {
            $element->removeAttribute($attributeName);
        }

        if ($tagName === 'a') {
            $href = blogSanitizeLinkUrl($element->getAttribute('href'));
            if ($href === null) {
                $element->removeAttribute('href');
            } else {
                $element->setAttribute('href', $href);
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }

        if ($tagName === 'img') {
            $src = blogSanitizeImageUrl($element->getAttribute('src'));
            if ($src === null) {
                $element->parentNode?->removeChild($element);
                continue;
            }

            $element->setAttribute('src', $src);
            $element->setAttribute('loading', 'lazy');
            $element->setAttribute('decoding', 'async');
        }
    }

    // Vom Editor nach einem Bild erzeugte Leerabsätze entfernen.
    // Andere bewusst angelegte Absätze bleiben unverändert.
    $imageParagraphList = $xpath->query(
        './/p[.//img and not(normalize-space(string(.)))]',
        $root
    );

    $imageParagraphs = [];

    if ($imageParagraphList !== false) {
        foreach ($imageParagraphList as $imageParagraph) {
            if ($imageParagraph instanceof DOMElement) {
                $imageParagraphs[] = $imageParagraph;
            }
        }
    }

    foreach ($imageParagraphs as $imageParagraph) {
        while (true) {
            $nextNode = $imageParagraph->nextSibling;

            while (
                $nextNode instanceof DOMText &&
                trim((string)$nextNode->nodeValue) === ''
            ) {
                $nextNode = $nextNode->nextSibling;
            }

            if (
                !$nextNode instanceof DOMElement ||
                strtolower($nextNode->tagName) !== 'p'
            ) {
                break;
            }

            $paragraphText = trim(
                str_replace(
                    "\xC2\xA0",
                    ' ',
                    (string)$nextNode->textContent
                )
            );

            if (
                $paragraphText !== '' ||
                $nextNode->getElementsByTagName('img')->length > 0
            ) {
                break;
            }

            $nextNode->parentNode?->removeChild($nextNode);
        }
    }

    $result = '';
    foreach ($root->childNodes as $childNode) {
        $result .= $document->saveHTML($childNode);
    }

    return trim($result);
}

function blogPlainTextFromHtml(string $html): string
{
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function blogUploadErrorMessage(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist größer als das erlaubte Upload-Limit.',
        UPLOAD_ERR_PARTIAL => 'Die Datei wurde nur teilweise hochgeladen.',
        UPLOAD_ERR_NO_FILE => 'Es wurde keine Bilddatei ausgewählt.',
        UPLOAD_ERR_NO_TMP_DIR => 'Das temporäre Upload-Verzeichnis fehlt.',
        UPLOAD_ERR_CANT_WRITE => 'Die Datei konnte nicht auf den Server geschrieben werden.',
        UPLOAD_ERR_EXTENSION => 'Der Upload wurde durch eine PHP-Erweiterung gestoppt.',
        default => 'Beim Hochladen ist ein unbekannter Fehler aufgetreten.',
    };
}


/**
 * Erzeugt einen temporären Ausgabepfad mit derselben Dateiendung.
 * Dadurch erkennt ImageMagick das gewünschte Zielformat zuverlässig.
 */
function blogCreateTemporaryImagePath(string $destinationPath): string
{
    $directory = dirname($destinationPath);
    $filename = pathinfo($destinationPath, PATHINFO_FILENAME);
    $extension = pathinfo($destinationPath, PATHINFO_EXTENSION);

    return $directory
        . '/.'
        . $filename
        . '.part-'
        . bin2hex(random_bytes(8))
        . '.'
        . $extension;
}

function blogVerifyReencodedImage(
    string $path,
    string $expectedMimeType
): void {
    if (
        !is_file($path) ||
        filesize($path) === 0 ||
        @getimagesize($path) === false
    ) {
        throw new RuntimeException('Das bereinigte Bild ist ungültig.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $actualMimeType = (string)$finfo->file($path);

    if ($actualMimeType !== $expectedMimeType) {
        throw new RuntimeException(
            'Das bereinigte Bild besitzt ein unerwartetes Dateiformat.'
        );
    }
}

function blogFindImageMagickExecutable(): ?string
{
    $candidates = [
        '/usr/bin/magick',
        '/usr/local/bin/magick',
        '/opt/imagemagick/bin/magick',
        '/usr/bin/convert',
        '/usr/local/bin/convert',
        '/opt/imagemagick/bin/convert',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Bereinigt das Bild mit ImageMagick. -auto-orient wird vor -strip
 * ausgeführt, damit die EXIF-Ausrichtung sichtbar erhalten bleibt.
 */
function blogWriteMetadataFreeImageWithImageMagick(
    string $sourcePath,
    string $destinationPath,
    string $mimeType
): bool {
    if (!function_exists('proc_open')) {
        return false;
    }

    $executable = blogFindImageMagickExecutable();

    if ($executable === null) {
        return false;
    }

    $temporaryOutputPath = blogCreateTemporaryImagePath(
        $destinationPath
    );

    $command = [
        $executable,
        $sourcePath,
        '-auto-orient',
        '-strip',
    ];

    if ($mimeType === 'image/png') {
        $command[] = '-define';
        $command[] = 'png:exclude-chunk=date,time';
        $command[] = '-define';
        $command[] = 'png:compression-level=6';
    } else {
        $command[] = '-quality';
        $command[] = '92';
    }

    $command[] = $temporaryOutputPath;

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $pipes = [];
    $process = @proc_open(
        $command,
        $descriptorSpec,
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        return false;
    }

    try {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $process = null;

        if ($exitCode !== 0) {
            $details = trim((string)$stderr);

            throw new RuntimeException(
                'ImageMagick konnte das Bild nicht bereinigen.'
                . ($details !== '' ? ' ' . $details : '')
            );
        }

        blogVerifyReencodedImage(
            $temporaryOutputPath,
            $mimeType
        );

        if (!rename($temporaryOutputPath, $destinationPath)) {
            throw new RuntimeException(
                'Das bereinigte Bild konnte nicht übernommen werden.'
            );
        }

        return true;
    } finally {
        if (is_resource($process)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_terminate($process);
            proc_close($process);
        }

        if (is_file($temporaryOutputPath)) {
            @unlink($temporaryOutputPath);
        }
    }
}

/**
 * Liest die JPEG-Ausrichtung, bevor GD das Bild pixelbasiert neu schreibt.
 */
function blogReadJpegOrientation(string $path): int
{
    if (!function_exists('exif_read_data')) {
        return 1;
    }

    $exif = @exif_read_data($path, 'IFD0', true, false);

    if (!is_array($exif)) {
        return 1;
    }

    $orientation = (int)(
        $exif['IFD0']['Orientation']
        ?? $exif['Orientation']
        ?? 1
    );

    return $orientation >= 1 && $orientation <= 8
        ? $orientation
        : 1;
}

function blogRotateGdImage(mixed $image, float $angle): mixed
{
    $rotatedImage = imagerotate($image, $angle, 0);

    if ($rotatedImage === false) {
        throw new RuntimeException(
            'Die Bildausrichtung konnte nicht korrigiert werden.'
        );
    }

    imagedestroy($image);

    return $rotatedImage;
}

function blogApplyJpegOrientation(mixed $image, int $orientation): mixed
{
    switch ($orientation) {
        case 2:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            break;

        case 3:
            $image = blogRotateGdImage($image, 180);
            break;

        case 4:
            imageflip($image, IMG_FLIP_VERTICAL);
            break;

        case 5:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            $image = blogRotateGdImage($image, 90);
            break;

        case 6:
            $image = blogRotateGdImage($image, -90);
            break;

        case 7:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            $image = blogRotateGdImage($image, -90);
            break;

        case 8:
            $image = blogRotateGdImage($image, 90);
            break;
    }

    return $image;
}

function blogWriteMetadataFreeImageWithGd(
    string $sourcePath,
    string $destinationPath,
    string $mimeType
): bool {
    if (!extension_loaded('gd')) {
        return false;
    }

    $loaderFunction = match ($mimeType) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        default => null,
    };

    if (
        $loaderFunction === null ||
        !function_exists($loaderFunction)
    ) {
        return false;
    }

    $orientation = $mimeType === 'image/jpeg'
        ? blogReadJpegOrientation($sourcePath)
        : 1;

    $image = @$loaderFunction($sourcePath);

    if ($image === false) {
        throw new RuntimeException(
            'Das Bild konnte nicht sicher neu codiert werden.'
        );
    }

    $temporaryOutputPath = blogCreateTemporaryImagePath(
        $destinationPath
    );

    try {
        if ($mimeType === 'image/jpeg') {
            $image = blogApplyJpegOrientation(
                $image,
                $orientation
            );
            imageinterlace($image, true);
            $written = imagejpeg($image, $temporaryOutputPath, 92);
        } elseif ($mimeType === 'image/png') {
            if (function_exists('imagepalettetotruecolor')) {
                @imagepalettetotruecolor($image);
            }

            imagealphablending($image, false);
            imagesavealpha($image, true);
            $written = imagepng(
                $image,
                $temporaryOutputPath,
                6,
                PNG_ALL_FILTERS
            );
        } else {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $written = imagewebp(
                $image,
                $temporaryOutputPath,
                92
            );
        }

        if (!$written) {
            throw new RuntimeException(
                'Das bereinigte Bild konnte nicht gespeichert werden.'
            );
        }

        blogVerifyReencodedImage(
            $temporaryOutputPath,
            $mimeType
        );

        if (!rename($temporaryOutputPath, $destinationPath)) {
            throw new RuntimeException(
                'Das bereinigte Bild konnte nicht übernommen werden.'
            );
        }

        return true;
    } finally {
        imagedestroy($image);

        if (is_file($temporaryOutputPath)) {
            @unlink($temporaryOutputPath);
        }
    }
}

/**
 * Schreibt aus dem Upload eine vollständig neu codierte Bilddatei.
 * EXIF, GPS, IPTC, XMP, ICC-Profile, Kommentare und sonstige eingebettete
 * Metadaten werden nicht in die Ausgabedatei übernommen.
 */
function blogWriteMetadataFreeImage(
    string $sourcePath,
    string $destinationPath,
    string $mimeType
): void {
    $errors = [];

    try {
        if (
            blogWriteMetadataFreeImageWithImageMagick(
                $sourcePath,
                $destinationPath,
                $mimeType
            )
        ) {
            return;
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }

    try {
        if (
            blogWriteMetadataFreeImageWithGd(
                $sourcePath,
                $destinationPath,
                $mimeType
            )
        ) {
            return;
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }

    $details = $errors !== []
        ? ' ' . implode(' ', array_unique($errors))
        : '';

    throw new RuntimeException(
        'Das Bild konnte nicht ohne Metadaten gespeichert werden. '
        . 'Benötigt wird ImageMagick oder PHP-GD.'
        . $details
    );
}

function blogCreateImageFilename(string $directory, string $title, string $extension): string
{
    $baseName = blogSlugify($title);

    if ($baseName === '') {
        $baseName = 'beitrag';
    }

    $baseName = blogStringCut($baseName, 140);
    $allowedExtensions = ['jpg', 'png', 'webp'];
    $number = 1;

    while (true) {
        $stem = $baseName . '-' . $number;
        $exists = false;

        foreach ($allowedExtensions as $candidateExtension) {
            if (is_file($directory . '/' . $stem . '.' . $candidateExtension)) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            return $stem . '.' . $extension;
        }

        $number++;
    }
}

if (empty($_SESSION['blog_csrf_token']) || !is_string($_SESSION['blog_csrf_token'])) {
    $_SESSION['blog_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['blog_csrf_token'];

// AJAX: Bild hochladen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_image') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        blogJsonResponse(['success' => false, 'message' => 'Ungültiges CSRF-Token.'], 403);
    }

    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        blogJsonResponse(['success' => false, 'message' => 'Es wurde keine Bilddatei übermittelt.'], 400);
    }

    $uploadedFile = $_FILES['image'];
    $uploadError = (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK) {
        blogJsonResponse([
            'success' => false,
            'message' => blogUploadErrorMessage($uploadError),
        ], 400);
    }

    $temporaryPath = (string)($uploadedFile['tmp_name'] ?? '');
    $fileSize = (int)($uploadedFile['size'] ?? 0);

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        blogJsonResponse(['success' => false, 'message' => 'Die Upload-Datei ist ungültig.'], 400);
    }

    if ($fileSize <= 0 || $fileSize > BLOG_UPLOAD_MAX_BYTES) {
        blogJsonResponse(['success' => false, 'message' => 'Das Bild darf maximal 12 MB groß sein.'], 400);
    }

    if (!class_exists(finfo::class)) {
        blogJsonResponse([
            'success' => false,
            'message' => 'Die PHP-Fileinfo-Erweiterung fehlt.',
        ], 500);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($temporaryPath);

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!array_key_exists($mimeType, $allowedMimeTypes)) {
        blogJsonResponse([
            'success' => false,
            'message' => 'Erlaubt sind ausschließlich JPG, PNG und WebP.',
        ], 400);
    }

    if (@getimagesize($temporaryPath) === false) {
        blogJsonResponse(['success' => false, 'message' => 'Die Datei enthält kein gültiges Bild.'], 400);
    }

    $uploadTitle = trim((string)($_POST['title'] ?? ''));

    if ($uploadTitle === '') {
        blogJsonResponse([
            'success' => false,
            'message' => 'Bitte zuerst einen Beitragstitel eingeben.',
        ], 400);
    }

    if (blogStringLength($uploadTitle) > 255) {
        blogJsonResponse([
            'success' => false,
            'message' => 'Der Beitragstitel ist zu lang.',
        ], 400);
    }

    $extension = $allowedMimeTypes[$mimeType];

    if (!is_dir($blogUploadDirectory) && !mkdir($blogUploadDirectory, 0755, true) && !is_dir($blogUploadDirectory)) {
        blogJsonResponse(['success' => false, 'message' => 'Das Bildverzeichnis konnte nicht erstellt werden.'], 500);
    }

    if (!is_writable($blogUploadDirectory)) {
        blogJsonResponse(['success' => false, 'message' => 'Das Bildverzeichnis ist für PHP nicht beschreibbar.'], 500);
    }

    $finalFilename = blogCreateImageFilename(
        $blogUploadDirectory,
        $uploadTitle,
        $extension
    );

    $finalPath = $blogUploadDirectory . '/' . $finalFilename;

    try {
        blogWriteMetadataFreeImage(
            $temporaryPath,
            $finalPath,
            $mimeType
        );
    } catch (Throwable $exception) {
        error_log(
            '[blog-new] Bildbereinigung fehlgeschlagen: '
            . $exception->getMessage()
        );

        @unlink($finalPath);

        blogJsonResponse(
            ['success' => false, 'message' => $exception->getMessage()],
            500
        );
    }

    @chmod($finalPath, 0644);

    blogJsonResponse([
        'success' => true,
        'message' => 'Bild wurde hochgeladen.',
        'filename' => $finalFilename,
        'url' => $blogPublicImageBase . '/' . rawurlencode($finalFilename),
    ]);
}

$errors = [];
$submittedTitle = '';
$submittedContent = '';

// Beitrag speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_post') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.';
    }

    if (!$blogDbReady) {
        $errors[] = '$blogconn ist in db.php noch nicht definiert.';
    }

    if (!$blogDomReady) {
        $errors[] = 'PHP-DOM fehlt. Installiere php-xml und lade Apache neu.';
    }

    $submittedTitle = trim((string)($_POST['title'] ?? ''));
    $rawContent = (string)($_POST['content'] ?? '');

    try {
        $submittedContent = blogSanitizeHtml($rawContent);
    } catch (Throwable $exception) {
        $submittedContent = $rawContent;
        $errors[] = $exception->getMessage();
    }

    $plainTextContent = blogPlainTextFromHtml($submittedContent);

    if ($submittedTitle === '') {
        $errors[] = 'Bitte einen Titel eingeben.';
    } elseif (blogStringLength($submittedTitle) > 255) {
        $errors[] = 'Der Titel darf maximal 255 Zeichen lang sein.';
    }

    if ($plainTextContent === '') {
        $errors[] = 'Bitte einen Beitragstext eingeben.';
    }

    if (!$errors && $blogDbReady) {
        $slug = blogCreateUniqueSlug($blogconn, $submittedTitle);
        $status = 'published';

        $stmt = $blogconn->prepare(
            'INSERT INTO posts (slug, title, content, status, published_at) VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->bind_param('ssss', $slug, $submittedTitle, $submittedContent, $status);
        $stmt->execute();
        $stmt->close();

        $_SESSION['blog_csrf_token'] = bin2hex(random_bytes(32));

        header('Location: /blog/new?created=1&slug=' . rawurlencode($slug), true, 303);
        exit;
    }
}

$createdSuccessfully = isset($_GET['created']) && $_GET['created'] === '1';
$createdSlug = trim((string)($_GET['slug'] ?? ''));

$page_title = 'Blogbeitrag erstellen';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<style>
.blog-editor {
    min-height: 420px;
    padding: 16px;
    border: 1px solid #ccc;
    border-radius: 0 0 var(--border-radius) var(--border-radius);
    background: #fff;
    line-height: 1.65;
    overflow-wrap: anywhere;
    outline: none;
}

.blog-editor:focus {
    border-color: var(--primary);
}

.blog-editor img {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 14px auto;
    border-radius: var(--border-radius);
}

.blog-editor-toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 5px;
    padding: 10px;
    border: 1px solid #ccc;
    border-bottom: none;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    background: var(--bg-light);
}

.blog-editor-toolbar button,
.blog-editor-toolbar select {
    width: auto;
    margin: 0;
}

.blog-notice {
    margin-bottom: 18px;
    padding: 12px 14px;
    border-radius: var(--border-radius);
    font-weight: 700;
}

.blog-notice-success {
    border-left: 6px solid #2e9b55;
    background: #edf8f1;
}

.blog-notice-error {
    border-left: 6px solid #c0392b;
    background: #fdf0ee;
}
</style>

<main class="content-wrap">
    <div class="container" style="max-width: 1000px; margin-top: 18px;">
        <?php if (!$blogDbReady || !$blogDomReady): ?>
            <div class="blog-notice blog-notice-error">
                <strong>Konfiguration noch nicht vollständig:</strong>
                <ul style="margin-bottom: 0;">
                    <?php if (!$blogDbReady): ?>
                        <li><code>$blogconn</code> fehlt noch in <code>db.php</code>.</li>
                    <?php endif; ?>
                    <?php if (!$blogDomReady): ?>
                        <li>PHP-DOM fehlt. Benötigt wird das Debian-Paket <code>php-xml</code>.</li>
                    <?php endif; ?>
                </ul>
                Die Seite bleibt sichtbar; Speichern ist erst nach Behebung möglich.
            </div>
        <?php endif; ?>

        <?php if ($createdSuccessfully): ?>
            <div class="blog-notice blog-notice-success">
                Beitrag wurde veröffentlicht.
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="blog-notice blog-notice-error">
                <strong>Der Beitrag konnte nicht gespeichert werden.</strong>
                <ul style="margin-bottom: 0;">
                    <?php foreach ($errors as $error): ?>
                        <li><?= blogEsc($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form id="blog-post-form" method="post" action="/blog/new" class="form-block">
            <input type="hidden" name="action" value="create_post">
            <input type="hidden" name="csrf_token" value="<?= blogEsc($csrfToken) ?>">
            <input type="hidden" id="blog-content-input" name="content" value="">

            <div class="input-group">
                <label for="blog-title"><strong>Titel</strong></label>
                <input
                    type="text"
                    id="blog-title"
                    name="title"
                    maxlength="255"
                    value="<?= blogEsc($submittedTitle) ?>"
                    autocomplete="off"
                    required
                    autofocus
                >
            </div>

            <div class="form-separator"></div>

            <div class="input-group">
                <label><strong>Text</strong></label>

                <div id="blog-editor-toolbar" class="blog-editor-toolbar">
                    <select id="blog-format-select" aria-label="Absatzformat">
                        <option value="p">Absatz</option>
                        <option value="h2">Überschrift 2</option>
                        <option value="h3">Überschrift 3</option>
                        <option value="blockquote">Zitat</option>
                        <option value="pre">Codeblock</option>
                    </select>

                    <button type="button" data-command="bold" title="Fett"><strong>F</strong></button>
                    <button type="button" data-command="italic" title="Kursiv"><em>K</em></button>
                    <button type="button" data-command="underline" title="Unterstrichen"><u>U</u></button>
                    <button type="button" data-command="strikeThrough" title="Durchgestrichen"><s>D</s></button>
                    <button type="button" data-command="insertUnorderedList">• Liste</button>
                    <button type="button" data-command="insertOrderedList">1. Liste</button>
                    <button type="button" id="blog-link-button">Link</button>
                    <button type="button" id="blog-image-button" title="Bild hochladen und an der Cursorposition einfügen">Bild</button>
                    <input
                        type="file"
                        id="blog-image-file"
                        accept="image/jpeg,image/png,image/webp"
                        hidden
                    >
                    <button type="button" data-command="unlink">Link lösen</button>
                    <button type="button" data-command="removeFormat">Format löschen</button>
                </div>

                <div
                    id="blog-editor"
                    class="blog-editor"
                    contenteditable="true"
                    role="textbox"
                    aria-multiline="true"
                    spellcheck="true"
                ><?= $submittedContent ?></div>
            </div>


            <div
                id="blog-image-upload-message"
                class="hidden"
                style="padding: 10px 12px; border-radius: var(--border-radius); font-weight: 700;"
            ></div>

            <div class="form-separator"></div>

            <button
                type="submit"
                id="blog-submit-button"
                style="width: 100%;"
                <?= (!$blogDbReady || !$blogDomReady) ? 'disabled' : '' ?>
            >
                Beitrag veröffentlichen
            </button>
        </form>
    </div>
</main>

<script>
(() => {
    'use strict';

    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const editor = document.getElementById('blog-editor');
    const toolbar = document.getElementById('blog-editor-toolbar');
    const formatSelect = document.getElementById('blog-format-select');
    const linkButton = document.getElementById('blog-link-button');

    const postForm = document.getElementById('blog-post-form');
    const titleInput = document.getElementById('blog-title');
    const contentInput = document.getElementById('blog-content-input');
    const submitButton = document.getElementById('blog-submit-button');

    const imageButton = document.getElementById('blog-image-button');
    const imageFileInput = document.getElementById('blog-image-file');
    const imageUploadMessage = document.getElementById('blog-image-upload-message');

    let savedRange = null;

    function selectionIsInsideEditor() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return false;

        const range = selection.getRangeAt(0);
        const container = range.commonAncestorContainer;
        const element = container.nodeType === Node.ELEMENT_NODE
            ? container
            : container.parentElement;

        return element instanceof Element && editor.contains(element);
    }

    function saveEditorSelection() {
        if (!selectionIsInsideEditor()) return;
        const selection = window.getSelection();
        if (selection && selection.rangeCount > 0) {
            savedRange = selection.getRangeAt(0).cloneRange();
        }
    }

    function restoreEditorSelection() {
        editor.focus();
        const selection = window.getSelection();
        if (!selection) return;

        selection.removeAllRanges();

        if (savedRange && editor.contains(savedRange.commonAncestorContainer)) {
            selection.addRange(savedRange);
            return;
        }

        const range = document.createRange();
        range.selectNodeContents(editor);
        range.collapse(false);
        selection.addRange(range);
        savedRange = range.cloneRange();
    }

    function executeEditorCommand(command, value = null) {
        restoreEditorSelection();
        document.execCommand(command, false, value);
        saveEditorSelection();
        editor.focus();
    }

    function insertUploadedImage(url, altText) {
        restoreEditorSelection();

        const selection = window.getSelection();

        if (!selection || selection.rangeCount === 0) {
            return;
        }

        const range = selection.getRangeAt(0);
        const startNode = range.startContainer;
        const startElement = startNode.nodeType === Node.ELEMENT_NODE
            ? startNode
            : startNode.parentElement;
        const currentBlock = startElement instanceof Element
            ? startElement.closest('p, div, h2, h3, blockquote, li, pre')
            : null;
        const replaceEmptyBlock =
            currentBlock instanceof Element &&
            editor.contains(currentBlock) &&
            currentBlock.textContent.trim() === '' &&
            !currentBlock.querySelector('img');

        const image = document.createElement('img');
        image.src = String(url);
        image.alt = String(altText);
        image.loading = 'lazy';
        image.decoding = 'async';

        range.deleteContents();

        if (replaceEmptyBlock) {
            currentBlock.replaceChildren(image);
        } else {
            range.insertNode(image);
        }

        const caretRange = document.createRange();
        caretRange.setStartAfter(image);
        caretRange.collapse(true);

        selection.removeAllRanges();
        selection.addRange(caretRange);
        savedRange = caretRange.cloneRange();
        editor.focus();
    }

    function showUploadMessage(message, isError = false) {
        imageUploadMessage.textContent = message;
        imageUploadMessage.classList.remove('hidden');
        imageUploadMessage.style.background = isError ? '#fdf0ee' : '#edf8f1';
        imageUploadMessage.style.borderLeft = isError ? '6px solid #c0392b' : '6px solid #2e9b55';
        imageUploadMessage.style.color = isError ? '#8e2d22' : '#1f6f3c';
    }

    toolbar.addEventListener('mousedown', (event) => {
        if (event.target.closest('button[data-command]')) {
            event.preventDefault();
        }
    });

    toolbar.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-command]');
        if (!button) return;

        const command = button.dataset.command;
        if (command) executeEditorCommand(command);
    });

    formatSelect.addEventListener('change', () => {
        executeEditorCommand('formatBlock', '<' + (formatSelect.value || 'p') + '>');
        formatSelect.value = 'p';
    });

    linkButton.addEventListener('mousedown', (event) => event.preventDefault());
    linkButton.addEventListener('click', () => {
        restoreEditorSelection();
        const url = window.prompt('Link eingeben:', 'https://');
        if (url) executeEditorCommand('createLink', url);
    });

    ['keyup', 'mouseup', 'input', 'focus'].forEach((eventName) => {
        editor.addEventListener(eventName, saveEditorSelection);
    });

    document.addEventListener('selectionchange', () => {
        if (selectionIsInsideEditor()) saveEditorSelection();
    });

    imageButton.addEventListener('mousedown', (event) => {
        event.preventDefault();
    });

    imageButton.addEventListener('click', () => {
        const title = titleInput.value.trim();

        if (title === '') {
            titleInput.focus();
            window.alert('Bitte zuerst einen Titel eingeben. Daraus wird der Bilddateiname erzeugt.');
            return;
        }

        saveEditorSelection();
        imageFileInput.click();
    });

    imageFileInput.addEventListener('change', async () => {
        const file = imageFileInput.files?.[0] ?? null;

        if (!file) {
            return;
        }

        const title = titleInput.value.trim();

        if (title === '') {
            imageFileInput.value = '';
            titleInput.focus();
            window.alert('Bitte zuerst einen Titel eingeben.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'upload_image');
        formData.append('csrf_token', csrfToken);
        formData.append('title', title);
        formData.append('image', file);

        imageButton.disabled = true;
        imageButton.textContent = 'Bild lädt …';

        try {
            const response = await fetch('/blog/new', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'},
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Das Bild konnte nicht hochgeladen werden.');
            }

            insertUploadedImage(data.url, title);

            showUploadMessage(
                'Bild ' + data.filename + ' wurde ohne Metadaten hochgeladen und eingefügt.'
            );
        } catch (error) {
            showUploadMessage(
                error instanceof Error ? error.message : 'Beim Upload ist ein Fehler aufgetreten.',
                true
            );
        } finally {
            imageFileInput.value = '';
            imageButton.disabled = false;
            imageButton.textContent = 'Bild';
        }
    });

    postForm.addEventListener('submit', (event) => {
        if (titleInput.value.trim() === '') {
            event.preventDefault();
            titleInput.focus();
            window.alert('Bitte einen Titel eingeben.');
            return;
        }

        if (editor.innerText.trim() === '') {
            event.preventDefault();
            editor.focus();
            window.alert('Bitte einen Beitragstext eingeben.');
            return;
        }

        contentInput.value = editor.innerHTML.trim();
        submitButton.disabled = true;
        submitButton.textContent = 'Beitrag wird veröffentlicht …';
    });

    if (editor.innerHTML.trim() === '') {
        editor.innerHTML = '<p><br></p>';
    }
})();
</script>

</body>
</html>