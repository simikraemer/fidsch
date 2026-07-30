<?php
// blog/Manage.php

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

function blogManageEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function blogManageStringLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function blogManageStringCut(string $value, int $length): string
{
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $length, 'UTF-8')
        : substr($value, 0, $length);
}

function blogManageJsonResponse(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function blogManageSlugify(string $value): string
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

function blogManageSanitizeLinkUrl(string $url): ?string
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

function blogManageSanitizeImageUrl(string $url): ?string
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

function blogManageSanitizeHtml(string $html): string
{
    if (!class_exists(DOMDocument::class) || !class_exists(DOMXPath::class)) {
        throw new RuntimeException(
            'Die PHP-DOM-Erweiterung fehlt. Installiere php-xml und lade Apache neu.'
        );
    }

    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $allowedElements = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'h2', 'h3', 'blockquote', 'ul', 'ol', 'li',
        'a', 'img', 'code', 'pre', 'hr',
    ];

    $removeCompletely = [
        'script', 'style', 'iframe', 'object', 'embed', 'form',
        'input', 'button', 'textarea', 'select', 'option',
        'video', 'audio', 'source', 'link', 'meta', 'svg', 'math',
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
            $href = blogManageSanitizeLinkUrl($element->getAttribute('href'));

            if ($href === null) {
                $element->removeAttribute('href');
            } else {
                $element->setAttribute('href', $href);
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }

        if ($tagName === 'img') {
            $src = blogManageSanitizeImageUrl($element->getAttribute('src'));

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

function blogManagePlainTextFromHtml(string $html): string
{
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function blogManageUploadErrorMessage(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE => 'Die Datei ist größer als das erlaubte Upload-Limit.',
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
function blogManageCreateTemporaryImagePath(string $destinationPath): string
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

function blogManageVerifyReencodedImage(
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

function blogManageFindImageMagickExecutable(): ?string
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
function blogManageWriteMetadataFreeImageWithImageMagick(
    string $sourcePath,
    string $destinationPath,
    string $mimeType
): bool {
    if (!function_exists('proc_open')) {
        return false;
    }

    $executable = blogManageFindImageMagickExecutable();

    if ($executable === null) {
        return false;
    }

    $temporaryOutputPath = blogManageCreateTemporaryImagePath(
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

        blogManageVerifyReencodedImage(
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
function blogManageReadJpegOrientation(string $path): int
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

function blogManageRotateGdImage(mixed $image, float $angle): mixed
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

function blogManageApplyJpegOrientation(mixed $image, int $orientation): mixed
{
    switch ($orientation) {
        case 2:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            break;

        case 3:
            $image = blogManageRotateGdImage($image, 180);
            break;

        case 4:
            imageflip($image, IMG_FLIP_VERTICAL);
            break;

        case 5:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            $image = blogManageRotateGdImage($image, 90);
            break;

        case 6:
            $image = blogManageRotateGdImage($image, -90);
            break;

        case 7:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            $image = blogManageRotateGdImage($image, -90);
            break;

        case 8:
            $image = blogManageRotateGdImage($image, 90);
            break;
    }

    return $image;
}

function blogManageWriteMetadataFreeImageWithGd(
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
        ? blogManageReadJpegOrientation($sourcePath)
        : 1;

    $image = @$loaderFunction($sourcePath);

    if ($image === false) {
        throw new RuntimeException(
            'Das Bild konnte nicht sicher neu codiert werden.'
        );
    }

    $temporaryOutputPath = blogManageCreateTemporaryImagePath(
        $destinationPath
    );

    try {
        if ($mimeType === 'image/jpeg') {
            $image = blogManageApplyJpegOrientation(
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

        blogManageVerifyReencodedImage(
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
function blogManageWriteMetadataFreeImage(
    string $sourcePath,
    string $destinationPath,
    string $mimeType
): void {
    $errors = [];

    try {
        if (
            blogManageWriteMetadataFreeImageWithImageMagick(
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
            blogManageWriteMetadataFreeImageWithGd(
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

function blogManageCreateImageFilename(
    string $directory,
    string $title,
    string $extension
): string {
    $baseName = blogManageSlugify($title);

    if ($baseName === '') {
        $baseName = 'beitrag';
    }

    $baseName = blogManageStringCut($baseName, 140);
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

if (
    empty($_SESSION['blog_csrf_token']) ||
    !is_string($_SESSION['blog_csrf_token'])
) {
    $_SESSION['blog_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['blog_csrf_token'];

// AJAX: Bild hochladen
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'upload_image'
) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Ungültiges CSRF-Token.'],
            403
        );
    }

    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Es wurde keine Bilddatei übermittelt.'],
            400
        );
    }

    $uploadedFile = $_FILES['image'];
    $uploadError = (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK) {
        blogManageJsonResponse([
            'success' => false,
            'message' => blogManageUploadErrorMessage($uploadError),
        ], 400);
    }

    $temporaryPath = (string)($uploadedFile['tmp_name'] ?? '');
    $fileSize = (int)($uploadedFile['size'] ?? 0);

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Die Upload-Datei ist ungültig.'],
            400
        );
    }

    if ($fileSize <= 0 || $fileSize > BLOG_UPLOAD_MAX_BYTES) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Das Bild darf maximal 12 MB groß sein.'],
            400
        );
    }

    if (!class_exists(finfo::class)) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Die PHP-Fileinfo-Erweiterung fehlt.'],
            500
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($temporaryPath);

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!array_key_exists($mimeType, $allowedMimeTypes)) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Erlaubt sind ausschließlich JPG, PNG und WebP.'],
            400
        );
    }

    if (@getimagesize($temporaryPath) === false) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Die Datei enthält kein gültiges Bild.'],
            400
        );
    }

    $uploadTitle = trim((string)($_POST['title'] ?? ''));

    if ($uploadTitle === '') {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Bitte zuerst einen Beitragstitel eingeben.'],
            400
        );
    }

    if (blogManageStringLength($uploadTitle) > 255) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Der Beitragstitel ist zu lang.'],
            400
        );
    }

    if (
        !is_dir($blogUploadDirectory) &&
        !mkdir($blogUploadDirectory, 0755, true) &&
        !is_dir($blogUploadDirectory)
    ) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Das Bildverzeichnis konnte nicht erstellt werden.'],
            500
        );
    }

    if (!is_writable($blogUploadDirectory)) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Das Bildverzeichnis ist für PHP nicht beschreibbar.'],
            500
        );
    }

    $extension = $allowedMimeTypes[$mimeType];
    $finalFilename = blogManageCreateImageFilename(
        $blogUploadDirectory,
        $uploadTitle,
        $extension
    );
    $finalPath = $blogUploadDirectory . '/' . $finalFilename;

    try {
        blogManageWriteMetadataFreeImage(
            $temporaryPath,
            $finalPath,
            $mimeType
        );
    } catch (Throwable $exception) {
        error_log(
            '[blog-manage] Bildbereinigung fehlgeschlagen: '
            . $exception->getMessage()
        );

        @unlink($finalPath);

        blogManageJsonResponse(
            ['success' => false, 'message' => $exception->getMessage()],
            500
        );
    }

    @chmod($finalPath, 0644);

    blogManageJsonResponse([
        'success' => true,
        'message' => 'Bild wurde hochgeladen.',
        'filename' => $finalFilename,
        'url' => $blogPublicImageBase . '/' . rawurlencode($finalFilename),
    ]);
}

// AJAX: Beitrag aktualisieren
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update_post'
) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'],
            403
        );
    }

    if (!$blogDbReady) {
        blogManageJsonResponse(
            ['success' => false, 'message' => '$blogconn ist in db.php nicht verfügbar.'],
            500
        );
    }

    if (!$blogDomReady) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'PHP-DOM fehlt. Installiere php-xml und lade Apache neu.'],
            500
        );
    }

    $postId = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $rawContent = (string)($_POST['content'] ?? '');

    if ($postId <= 0) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Ungültige Beitrags-ID.'],
            400
        );
    }

    if ($title === '') {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Bitte einen Titel eingeben.'],
            400
        );
    }

    if (blogManageStringLength($title) > 255) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Der Titel darf maximal 255 Zeichen lang sein.'],
            400
        );
    }

    try {
        $content = blogManageSanitizeHtml($rawContent);
    } catch (Throwable $exception) {
        blogManageJsonResponse(
            ['success' => false, 'message' => $exception->getMessage()],
            500
        );
    }

    if (blogManagePlainTextFromHtml($content) === '') {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Bitte einen Beitragstext eingeben.'],
            400
        );
    }

    $stmt = $blogconn->prepare(
        'UPDATE posts SET title = ?, content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    $stmt->bind_param('ssi', $title, $content, $postId);
    $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    if ($affectedRows < 0) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Der Beitrag konnte nicht gespeichert werden.'],
            500
        );
    }

    blogManageJsonResponse([
        'success' => true,
        'message' => 'Beitrag wurde gespeichert.',
    ]);
}

// AJAX: Beitrag löschen
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete_post'
) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'],
            403
        );
    }

    if (!$blogDbReady) {
        blogManageJsonResponse(
            ['success' => false, 'message' => '$blogconn ist in db.php nicht verfügbar.'],
            500
        );
    }

    $postId = (int)($_POST['id'] ?? 0);

    if ($postId <= 0) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Ungültige Beitrags-ID.'],
            400
        );
    }

    $stmt = $blogconn->prepare('DELETE FROM posts WHERE id = ?');
    $stmt->bind_param('i', $postId);
    $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    if ($affectedRows !== 1) {
        blogManageJsonResponse(
            ['success' => false, 'message' => 'Der Beitrag wurde nicht gefunden oder bereits gelöscht.'],
            404
        );
    }

    blogManageJsonResponse([
        'success' => true,
        'message' => 'Beitrag wurde gelöscht.',
    ]);
}

$posts = [];
$loadError = null;

if ($blogDbReady) {
    try {
        $result = $blogconn->query(
            'SELECT id, slug, title, content, status, published_at, created_at, updated_at
             FROM posts
             ORDER BY COALESCE(published_at, created_at) DESC, id DESC'
        );

        if ($result instanceof mysqli_result) {
            $posts = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
        }
    } catch (Throwable $exception) {
        $loadError = $exception->getMessage();
    }
}

$page_title = 'Blogbeiträge verwalten';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<style>
.blog-manage-shell {
    width: min(1200px, calc(100% - 32px));
    margin: 24px auto 60px;
}

.blog-manage-panel {
    max-width: none;
    margin: 0;
}

.blog-manage-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 14px;
}

.blog-manage-count {
    font-weight: 800;
    color: var(--text);
}

.blog-manage-new-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 16px;
    border-radius: var(--border-radius);
    background: var(--primary);
    color: var(--text-light);
    text-decoration: none;
    font-weight: 800;
    box-shadow: var(--shadow);
}

.blog-manage-new-link:hover {
    background: var(--primary-dark);
}

.blog-manage-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    white-space: nowrap;
}

.blog-manage-delete-button {
    background: #fff;
    color: #c0392b;
    border: 2px solid rgba(192, 57, 43, 0.55);
    box-shadow: none;
}

.blog-manage-delete-button:hover {
    background: #c0392b;
    color: #fff;
}

#blog-edit-modal,
#blog-delete-modal {
    z-index: 10000;
}

#blog-edit-modal .modal-content,
#blog-delete-modal .modal-content {
    z-index: 10001;
}

.blog-manage-modal-content {
    width: min(1000px, calc(100vw - 32px));
    max-width: min(1000px, calc(100vw - 32px));
    max-height: calc(100vh - 32px);
    overflow-y: auto;
    box-sizing: border-box;
}

.blog-manage-editor-toolbar {
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

.blog-manage-editor-toolbar button,
.blog-manage-editor-toolbar select {
    width: auto;
    margin: 0;
}

.blog-manage-editor {
    min-height: 360px;
    padding: 16px;
    border: 1px solid #ccc;
    border-radius: 0 0 var(--border-radius) var(--border-radius);
    background: #fff;
    line-height: 1.65;
    overflow-wrap: anywhere;
    outline: none;
}

.blog-manage-editor:focus {
    border-color: var(--primary);
}

.blog-manage-editor img {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 14px auto;
    border-radius: var(--border-radius);
}

.blog-manage-modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 18px;
}

.blog-manage-notice {
    margin-bottom: 16px;
    padding: 12px 14px;
    border-radius: var(--border-radius);
    font-weight: 700;
}

.blog-manage-notice-error {
    border-left: 6px solid #c0392b;
    background: #fdf0ee;
}

.blog-manage-toast {
    position: fixed;
    left: 50%;
    bottom: 24px;
    z-index: 5000;
    transform: translateX(-50%);
    min-width: min(360px, calc(100vw - 32px));
    max-width: calc(100vw - 32px);
    padding: 12px 16px;
    border-radius: var(--border-radius);
    background: #fff;
    box-shadow: var(--shadow);
    border-left: 6px solid var(--primary);
    font-weight: 800;
    text-align: center;
}

.blog-manage-toast.is-error {
    border-left-color: #c0392b;
}

@media (max-width: 760px) {
    .blog-manage-shell {
        width: calc(100% - 20px);
    }

    .blog-manage-head {
        align-items: stretch;
        flex-direction: column;
    }

    .blog-manage-actions {
        flex-direction: column;
    }

    .blog-manage-actions button {
        width: 100%;
    }
}
</style>

<main class="blog-manage-shell">
    <div class="container blog-manage-panel">
        <div class="blog-manage-head">
            <div class="blog-manage-count">
                <?= count($posts) ?> Beitrag<?= count($posts) === 1 ? '' : 'e' ?>
            </div>

            <a href="/blog/new" class="blog-manage-new-link">
                Neuer Beitrag
            </a>
        </div>

        <?php if (!$blogDbReady || !$blogDomReady || $loadError !== null): ?>
            <div class="blog-manage-notice blog-manage-notice-error">
                <strong>Die Blogverwaltung ist noch nicht vollständig verfügbar.</strong>
                <ul style="margin-bottom: 0;">
                    <?php if (!$blogDbReady): ?>
                        <li><code>$blogconn</code> fehlt in <code>db.php</code>.</li>
                    <?php endif; ?>
                    <?php if (!$blogDomReady): ?>
                        <li>PHP-DOM fehlt. Benötigt wird <code>php-xml</code>.</li>
                    <?php endif; ?>
                    <?php if ($loadError !== null): ?>
                        <li><?= blogManageEsc($loadError) ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div style="overflow-x: auto;">
            <table class="food-table" style="margin-top: 0;">
                <thead>
                    <tr>
                        <th>Titel</th>
                        <th style="width: 190px;">Datum</th>
                        <th style="width: 220px; text-align: right;">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$posts): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; opacity: 0.7;">
                                Noch keine Beiträge vorhanden.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($posts as $post): ?>
                            <?php
                            $dateValue = (string)(
                                $post['published_at']
                                ?? $post['created_at']
                                ?? ''
                            );

                            $formattedDate = $dateValue !== ''
                                ? date('d.m.Y H:i', strtotime($dateValue))
                                : '—';
                            ?>
                            <tr id="blog-post-row-<?= (int)$post['id'] ?>">
                                <td>
                                    <strong><?= blogManageEsc((string)$post['title']) ?></strong>
                                </td>
                                <td><?= blogManageEsc($formattedDate) ?></td>
                                <td>
                                    <div class="blog-manage-actions">
                                        <button
                                            type="button"
                                            class="blog-edit-button"
                                            data-post-id="<?= (int)$post['id'] ?>"
                                            <?= (!$blogDbReady || !$blogDomReady) ? 'disabled' : '' ?>
                                        >
                                            Bearbeiten
                                        </button>

                                        <button
                                            type="button"
                                            class="blog-manage-delete-button blog-delete-button"
                                            data-post-id="<?= (int)$post['id'] ?>"
                                            <?= !$blogDbReady ? 'disabled' : '' ?>
                                        >
                                            Löschen
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div id="blog-edit-modal" class="modal hidden">
    <div class="modal-content blog-manage-modal-content">
        <span id="blog-edit-close-x" class="close-button">&times;</span>

        <form id="blog-edit-form" class="form-block">
            <input type="hidden" id="blog-edit-id">

            <div class="input-group">
                <label for="blog-edit-title"><strong>Titel</strong></label>
                <input
                    type="text"
                    id="blog-edit-title"
                    maxlength="255"
                    autocomplete="off"
                    required
                >
            </div>

            <div class="form-separator"></div>

            <div class="input-group">
                <label><strong>Text</strong></label>

                <div id="blog-edit-toolbar" class="blog-manage-editor-toolbar">
                    <select id="blog-edit-format" aria-label="Absatzformat">
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
                    <button type="button" id="blog-edit-link-button">Link</button>
                    <button type="button" id="blog-edit-image-button">Bild</button>
                    <input
                        type="file"
                        id="blog-edit-image-file"
                        accept="image/jpeg,image/png,image/webp"
                        hidden
                    >
                    <button type="button" data-command="unlink">Link lösen</button>
                    <button type="button" data-command="removeFormat">Format löschen</button>
                </div>

                <div
                    id="blog-edit-editor"
                    class="blog-manage-editor"
                    contenteditable="true"
                    role="textbox"
                    aria-multiline="true"
                    spellcheck="true"
                ></div>
            </div>

            <div
                id="blog-edit-upload-message"
                class="hidden"
                style="padding: 10px 12px; border-radius: var(--border-radius); font-weight: 700;"
            ></div>

            <div class="blog-manage-modal-actions">
                <button type="button" id="blog-edit-cancel" class="btn-secondary">
                    Abbrechen
                </button>
                <button type="submit" id="blog-edit-save">
                    Speichern
                </button>
            </div>
        </form>
    </div>
</div>

<div id="blog-delete-modal" class="modal hidden">
    <div class="modal-content">
        <span id="blog-delete-close-x" class="close-button">&times;</span>

        <h2 style="margin-top: 0;">Beitrag löschen</h2>

        <p>
            Soll <strong id="blog-delete-title"></strong> wirklich gelöscht werden?
        </p>

        <p class="subtle">
            Der Datenbankeintrag wird entfernt. Bereits hochgeladene Bilddateien bleiben bestehen.
        </p>

        <div class="modal-actions">
            <button type="button" id="blog-delete-cancel" class="btn-secondary">
                Abbrechen
            </button>
            <button
                type="button"
                id="blog-delete-confirm"
                class="blog-manage-delete-button"
            >
                Löschen
            </button>
        </div>
    </div>
</div>

<div id="blog-manage-toast" class="blog-manage-toast hidden"></div>

<script>
(() => {
    'use strict';

    const csrfToken = <?= json_encode(
        $csrfToken,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    const posts = <?= json_encode(
        $posts,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    const postsById = new Map(
        posts.map((post) => [String(post.id), post])
    );

    const editModal = document.getElementById('blog-edit-modal');
    const editCloseX = document.getElementById('blog-edit-close-x');
    const editCancel = document.getElementById('blog-edit-cancel');
    const editForm = document.getElementById('blog-edit-form');
    const editId = document.getElementById('blog-edit-id');
    const editTitle = document.getElementById('blog-edit-title');
    const editToolbar = document.getElementById('blog-edit-toolbar');
    const editFormat = document.getElementById('blog-edit-format');
    const editLinkButton = document.getElementById('blog-edit-link-button');
    const editImageButton = document.getElementById('blog-edit-image-button');
    const editImageFile = document.getElementById('blog-edit-image-file');
    const editEditor = document.getElementById('blog-edit-editor');
    const editUploadMessage = document.getElementById('blog-edit-upload-message');
    const editSave = document.getElementById('blog-edit-save');

    const deleteModal = document.getElementById('blog-delete-modal');
    const deleteCloseX = document.getElementById('blog-delete-close-x');
    const deleteCancel = document.getElementById('blog-delete-cancel');
    const deleteConfirm = document.getElementById('blog-delete-confirm');
    const deleteTitle = document.getElementById('blog-delete-title');

    const toast = document.getElementById('blog-manage-toast');

    let activeDeleteId = null;
    let savedRange = null;
    let toastTimer = null;

    function showToast(message, isError = false) {
        window.clearTimeout(toastTimer);
        toast.textContent = message;
        toast.classList.remove('hidden', 'is-error');

        if (isError) {
            toast.classList.add('is-error');
        }

        toastTimer = window.setTimeout(() => {
            toast.classList.add('hidden');
        }, 3200);
    }

    async function requestJson(formData) {
        const response = await fetch('/blog/manage', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const responseText = await response.text();
        let data = null;

        try {
            data = JSON.parse(responseText);
        } catch (error) {
            throw new Error('Der Server hat keine gültige JSON-Antwort geliefert.');
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Die Aktion ist fehlgeschlagen.');
        }

        return data;
    }

    function openModal(modal) {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.classList.add('hidden');

        if (
            editModal.classList.contains('hidden') &&
            deleteModal.classList.contains('hidden')
        ) {
            document.body.style.overflow = '';
        }
    }

    function selectionIsInsideEditor() {
        const selection = window.getSelection();

        if (!selection || selection.rangeCount === 0) {
            return false;
        }

        const range = selection.getRangeAt(0);
        const container = range.commonAncestorContainer;
        const element = container.nodeType === Node.ELEMENT_NODE
            ? container
            : container.parentElement;

        return element instanceof Element && editEditor.contains(element);
    }

    function saveEditorSelection() {
        if (!selectionIsInsideEditor()) {
            return;
        }

        const selection = window.getSelection();

        if (selection && selection.rangeCount > 0) {
            savedRange = selection.getRangeAt(0).cloneRange();
        }
    }

    function restoreEditorSelection() {
        editEditor.focus();

        const selection = window.getSelection();
        if (!selection) {
            return;
        }

        selection.removeAllRanges();

        if (
            savedRange &&
            editEditor.contains(savedRange.commonAncestorContainer)
        ) {
            selection.addRange(savedRange);
            return;
        }

        const range = document.createRange();
        range.selectNodeContents(editEditor);
        range.collapse(false);
        selection.addRange(range);
        savedRange = range.cloneRange();
    }

    function executeEditorCommand(command, value = null) {
        restoreEditorSelection();
        document.execCommand(command, false, value);
        saveEditorSelection();
        editEditor.focus();
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
            editEditor.contains(currentBlock) &&
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
        editEditor.focus();
    }

    function showUploadMessage(message, isError = false) {
        editUploadMessage.textContent = message;
        editUploadMessage.classList.remove('hidden');
        editUploadMessage.style.background = isError ? '#fdf0ee' : '#edf8f1';
        editUploadMessage.style.borderLeft = isError
            ? '6px solid #c0392b'
            : '6px solid #2e9b55';
        editUploadMessage.style.color = isError ? '#8e2d22' : '#1f6f3c';
    }

    function openEditModal(postId) {
        const post = postsById.get(String(postId));

        if (!post) {
            showToast('Der Beitrag wurde nicht gefunden.', true);
            return;
        }

        editId.value = String(post.id);
        editTitle.value = post.title || '';
        editEditor.innerHTML = post.content || '<p><br></p>';
        editUploadMessage.classList.add('hidden');
        savedRange = null;

        openModal(editModal);
        editTitle.focus();
        editTitle.select();
    }

    function openDeleteModal(postId) {
        const post = postsById.get(String(postId));

        if (!post) {
            showToast('Der Beitrag wurde nicht gefunden.', true);
            return;
        }

        activeDeleteId = String(post.id);
        deleteTitle.textContent = post.title || 'Diesen Beitrag';
        openModal(deleteModal);
        deleteConfirm.focus();
    }

    document.querySelectorAll('.blog-edit-button').forEach((button) => {
        button.addEventListener('click', () => {
            openEditModal(button.dataset.postId || '');
        });
    });

    document.querySelectorAll('.blog-delete-button').forEach((button) => {
        button.addEventListener('click', () => {
            openDeleteModal(button.dataset.postId || '');
        });
    });

    [editCloseX, editCancel].forEach((element) => {
        element.addEventListener('click', () => closeModal(editModal));
    });

    [deleteCloseX, deleteCancel].forEach((element) => {
        element.addEventListener('click', () => closeModal(deleteModal));
    });

    editModal.addEventListener('click', (event) => {
        if (event.target === editModal) {
            closeModal(editModal);
        }
    });

    deleteModal.addEventListener('click', (event) => {
        if (event.target === deleteModal) {
            closeModal(deleteModal);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        if (!deleteModal.classList.contains('hidden')) {
            closeModal(deleteModal);
        } else if (!editModal.classList.contains('hidden')) {
            closeModal(editModal);
        }
    });

    editToolbar.addEventListener('mousedown', (event) => {
        if (event.target.closest('button[data-command]')) {
            event.preventDefault();
        }
    });

    editToolbar.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-command]');

        if (!button) {
            return;
        }

        const command = button.dataset.command;
        if (command) {
            executeEditorCommand(command);
        }
    });

    editFormat.addEventListener('change', () => {
        executeEditorCommand(
            'formatBlock',
            '<' + (editFormat.value || 'p') + '>'
        );

        editFormat.value = 'p';
    });

    editLinkButton.addEventListener('mousedown', (event) => {
        event.preventDefault();
    });

    editLinkButton.addEventListener('click', () => {
        restoreEditorSelection();
        const url = window.prompt('Link eingeben:', 'https://');

        if (url) {
            executeEditorCommand('createLink', url);
        }
    });

    ['keyup', 'mouseup', 'input', 'focus'].forEach((eventName) => {
        editEditor.addEventListener(eventName, saveEditorSelection);
    });

    document.addEventListener('selectionchange', () => {
        if (selectionIsInsideEditor()) {
            saveEditorSelection();
        }
    });

    editImageButton.addEventListener('mousedown', (event) => {
        event.preventDefault();
    });

    editImageButton.addEventListener('click', () => {
        const title = editTitle.value.trim();

        if (title === '') {
            editTitle.focus();
            window.alert('Bitte zuerst einen Titel eingeben.');
            return;
        }

        saveEditorSelection();
        editImageFile.click();
    });

    editImageFile.addEventListener('change', async () => {
        const file = editImageFile.files?.[0] ?? null;

        if (!file) {
            return;
        }

        const title = editTitle.value.trim();

        if (title === '') {
            editImageFile.value = '';
            editTitle.focus();
            window.alert('Bitte zuerst einen Titel eingeben.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'upload_image');
        formData.append('csrf_token', csrfToken);
        formData.append('title', title);
        formData.append('image', file);

        editImageButton.disabled = true;
        editImageButton.textContent = 'Bild lädt …';

        try {
            const data = await requestJson(formData);
            insertUploadedImage(data.url, title);

            showUploadMessage(
                'Bild ' + data.filename + ' wurde ohne Metadaten hochgeladen und eingefügt.'
            );
        } catch (error) {
            showUploadMessage(
                error instanceof Error
                    ? error.message
                    : 'Beim Upload ist ein Fehler aufgetreten.',
                true
            );
        } finally {
            editImageFile.value = '';
            editImageButton.disabled = false;
            editImageButton.textContent = 'Bild';
        }
    });

    editForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const postId = editId.value;
        const title = editTitle.value.trim();
        const content = editEditor.innerHTML.trim();

        if (title === '') {
            editTitle.focus();
            window.alert('Bitte einen Titel eingeben.');
            return;
        }

        if (editEditor.innerText.trim() === '') {
            editEditor.focus();
            window.alert('Bitte einen Beitragstext eingeben.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_post');
        formData.append('csrf_token', csrfToken);
        formData.append('id', postId);
        formData.append('title', title);
        formData.append('content', content);

        editSave.disabled = true;
        editSave.textContent = 'Speichert …';

        try {
            const data = await requestJson(formData);
            closeModal(editModal);
            showToast(data.message || 'Beitrag wurde gespeichert.');

            window.setTimeout(() => {
                window.location.reload();
            }, 450);
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : 'Der Beitrag konnte nicht gespeichert werden.',
                true
            );
        } finally {
            editSave.disabled = false;
            editSave.textContent = 'Speichern';
        }
    });

    deleteConfirm.addEventListener('click', async () => {
        if (!activeDeleteId) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'delete_post');
        formData.append('csrf_token', csrfToken);
        formData.append('id', activeDeleteId);

        deleteConfirm.disabled = true;
        deleteConfirm.textContent = 'Löscht …';

        try {
            const data = await requestJson(formData);
            const row = document.getElementById('blog-post-row-' + activeDeleteId);

            if (row) {
                row.remove();
            }

            postsById.delete(activeDeleteId);
            activeDeleteId = null;
            closeModal(deleteModal);
            showToast(data.message || 'Beitrag wurde gelöscht.');

            window.setTimeout(() => {
                window.location.reload();
            }, 450);
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : 'Der Beitrag konnte nicht gelöscht werden.',
                true
            );
        } finally {
            deleteConfirm.disabled = false;
            deleteConfirm.textContent = 'Löschen';
        }
    });
})();
</script>

</body>
</html>