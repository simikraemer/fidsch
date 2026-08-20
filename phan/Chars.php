<?php
// phan/Chars.php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$phanconn->set_charset('utf8mb4');

const PHAN_CHAR_UPLOAD_DIR = __DIR__ . '/../uploads/phan/chars';
const PHAN_CHAR_PUBLIC_DIR = '/uploads/phan/chars';
const PHAN_CHAR_THUMB_DIR = __DIR__ . '/../uploads/phan/chars/thumbs';
const PHAN_MAX_IMAGE_BYTES = 12582912; // 12 MB
const PHAN_CHAR_THUMB_SIZE = 116;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['phan_csrf'])) {
    $_SESSION['phan_csrf'] = bin2hex(random_bytes(32));
}

$csrf = (string)$_SESSION['phan_csrf'];


/* =========================================================
 * Helfer
 * ========================================================= */

function phan_h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function phan_json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function phan_exec(mysqli $db, string $sql, array $params = []): mysqli_stmt
{
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    if ($params) {
        $types = '';
        $values = array_values($params);
        $refs = [];

        foreach ($values as $i => $value) {
            $types .= is_int($value)
                ? 'i'
                : (is_float($value) ? 'd' : 's');

            $refs[$i] = &$values[$i];
        }

        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();

    return $stmt;
}

function phan_one(
    mysqli $db,
    string $sql,
    array $params = []
): ?array {
    $stmt = phan_exec($db, $sql, $params);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

function phan_all(
    mysqli $db,
    string $sql,
    array $params = []
): array {
    $stmt = phan_exec($db, $sql, $params);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function phan_char_disk_path(?string $publicPath): ?string
{
    if (
        !$publicPath
        || !str_starts_with(
            $publicPath,
            PHAN_CHAR_PUBLIC_DIR . '/'
        )
    ) {
        return null;
    }

    $filename = basename($publicPath);

    if ($filename === '' || $filename === '.' || $filename === '..') {
        return null;
    }

    return PHAN_CHAR_UPLOAD_DIR . '/' . $filename;
}

function phan_delete_char_image(?string $publicPath): void
{
    $path = phan_char_disk_path($publicPath);

    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

function phan_upload_char_image(
    array $file,
    int $charId
): ?string {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'Bild-Upload fehlgeschlagen (PHP-Code '
            . $error
            . ').'
        );
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException(
            'Ungültige Upload-Datei.'
        );
    }

    if ($size <= 0 || $size > PHAN_MAX_IMAGE_BYTES) {
        throw new RuntimeException(
            'Das Bild darf maximal 12 MB groß sein.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (
        !isset($allowed[$mime])
        || @getimagesize($tmp) === false
    ) {
        throw new RuntimeException(
            'Erlaubt sind ausschließlich gültige '
            . 'JPG-, PNG- und WebP-Bilder.'
        );
    }

    if (
        !is_dir(PHAN_CHAR_UPLOAD_DIR)
        && !mkdir(PHAN_CHAR_UPLOAD_DIR, 0755, true)
        && !is_dir(PHAN_CHAR_UPLOAD_DIR)
    ) {
        throw new RuntimeException(
            'Upload-Verzeichnis konnte nicht erstellt werden.'
        );
    }

    if (!is_writable(PHAN_CHAR_UPLOAD_DIR)) {
        throw new RuntimeException(
            'Upload-Verzeichnis ist für PHP nicht beschreibbar.'
        );
    }

    $filename = sprintf(
        'char_%d_%s.%s',
        $charId,
        bin2hex(random_bytes(8)),
        $allowed[$mime]
    );

    $target = PHAN_CHAR_UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException(
            'Bild konnte nicht gespeichert werden.'
        );
    }

    @chmod($target, 0644);

    return PHAN_CHAR_PUBLIC_DIR . '/' . $filename;
}

function phan_format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    try {
        $dt = new DateTimeImmutable($value);

        return $dt->format('d.m.Y, H:i');
    } catch (Throwable) {
        return '—';
    }
}


function phan_crop_value(string $key): ?float
{
    $raw = trim((string)($_POST[$key] ?? ''));

    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }

    $value = (float)$raw;

    return ($value >= 0.0 && $value <= 1.0)
        ? $value
        : null;
}


function phan_cleanup_char_thumbs(int $charId): void
{
    if (!is_dir(PHAN_CHAR_THUMB_DIR)) {
        return;
    }

    foreach (
        glob(
            PHAN_CHAR_THUMB_DIR
            . '/char_'
            . $charId
            . '_img_*.jpg'
        ) ?: []
        as $path
    ) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function phan_cleanup_image_thumbs(
    int $charId,
    int $imageId
): void {
    if (!is_dir(PHAN_CHAR_THUMB_DIR)) {
        return;
    }

    foreach (
        glob(
            PHAN_CHAR_THUMB_DIR
            . '/char_'
            . $charId
            . '_img_'
            . $imageId
            . '_*.jpg'
        ) ?: []
        as $path
    ) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function phan_image_display_title(array $image): string
{
    $title = trim((string)($image['title'] ?? ''));

    if ($title !== '') {
        return $title;
    }

    $filename = trim(
        (string)($image['original_filename'] ?? '')
    );

    if ($filename === '') {
        $filename = basename(
            (string)($image['image_path'] ?? '')
        );
    }

    return $filename !== ''
        ? $filename
        : 'Bild';
}

function phan_original_filename(array $file): string
{
    $name = str_replace(
        '\\\\',
        '/',
        trim((string)($file['name'] ?? ''))
    );

    $name = basename($name);

    if ($name === '' || $name === '.' || $name === '..') {
        return 'bild';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 255, 'UTF-8');
    }

    return substr($name, 0, 255);
}

function phan_make_char_thumb(
    int $charId,
    int $imageId,
    string $sourcePath,
    ?float $faceX,
    ?float $faceY,
    ?float $faceW,
    ?float $faceH
): string {
    if (!extension_loaded('gd')) {
        throw new RuntimeException(
            'PHP-GD ist für Charakter-Thumbnails nicht installiert.'
        );
    }

    if (
        !is_dir(PHAN_CHAR_THUMB_DIR)
        && !mkdir(PHAN_CHAR_THUMB_DIR, 0755, true)
        && !is_dir(PHAN_CHAR_THUMB_DIR)
    ) {
        throw new RuntimeException(
            'Thumbnail-Verzeichnis konnte nicht erstellt werden.'
        );
    }

    $mtime = (int)@filemtime($sourcePath);

    $cropKey = implode(
        '_',
        [
            $faceX ?? 'null',
            $faceY ?? 'null',
            $faceW ?? 'null',
            $faceH ?? 'null',
        ]
    );

    $hash = substr(
        sha1(
            $mtime
            . '|'
            . $imageId
            . '|'
            . $cropKey
        ),
        0,
        12
    );

    $target =
        PHAN_CHAR_THUMB_DIR
        . '/char_'
        . $charId
        . '_img_'
        . $imageId
        . '_'
        . $hash
        . '.jpg';

    if (is_file($target)) {
        return $target;
    }

    $info = @getimagesize($sourcePath);

    if (!$info) {
        throw new RuntimeException(
            'Charakterbild konnte nicht gelesen werden.'
        );
    }

    [$srcW, $srcH] = $info;

    if ($srcW <= 0 || $srcH <= 0) {
        throw new RuntimeException(
            'Ungültige Bildgröße.'
        );
    }

    $mime = (string)($info['mime'] ?? '');

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($sourcePath),
        'image/png' => @imagecreatefrompng($sourcePath),
        'image/webp' => function_exists('imagecreatefromwebp')
            ? @imagecreatefromwebp($sourcePath)
            : false,
        default => false,
    };

    if (!$src) {
        throw new RuntimeException(
            'Charakterbild konnte nicht für Thumbnail geladen werden.'
        );
    }

    $hasCrop =
        $faceX !== null
        && $faceY !== null
        && $faceW !== null
        && $faceH !== null
        && $faceW > 0
        && $faceH > 0;

    if ($hasCrop) {
        $cropX = max(
            0,
            min(
                $srcW - 1,
                (int)round($faceX * $srcW)
            )
        );

        $cropY = max(
            0,
            min(
                $srcH - 1,
                (int)round($faceY * $srcH)
            )
        );

        $cropW = max(
            1,
            min(
                $srcW - $cropX,
                (int)round($faceW * $srcW)
            )
        );

        $cropH = max(
            1,
            min(
                $srcH - $cropY,
                (int)round($faceH * $srcH)
            )
        );

        $side = min($cropW, $cropH);
        $cropW = $side;
        $cropH = $side;

    } else {
        $side = min($srcW, $srcH);
        $cropW = $side;
        $cropH = $side;
        $cropX = (int)round(($srcW - $side) / 2);
        $cropY = (int)round(($srcH - $side) / 2);
    }

    $dst = imagecreatetruecolor(
        PHAN_CHAR_THUMB_SIZE,
        PHAN_CHAR_THUMB_SIZE
    );

    imagecopyresampled(
        $dst,
        $src,
        0,
        0,
        $cropX,
        $cropY,
        PHAN_CHAR_THUMB_SIZE,
        PHAN_CHAR_THUMB_SIZE,
        $cropW,
        $cropH
    );

    if (!imagejpeg($dst, $target, 82)) {
        imagedestroy($src);
        imagedestroy($dst);

        throw new RuntimeException(
            'Charakter-Thumbnail konnte nicht gespeichert werden.'
        );
    }

    imagedestroy($src);
    imagedestroy($dst);

    @chmod($target, 0644);

    foreach (
        glob(
            PHAN_CHAR_THUMB_DIR
            . '/char_'
            . $charId
            . '_img_'
            . $imageId
            . '_*.jpg'
        ) ?: []
        as $path
    ) {
        if (
            $path !== $target
            && is_file($path)
        ) {
            @unlink($path);
        }
    }

    return $target;
}


/* =========================================================
 * Geschütztes Charakterbild / Thumbnail
 *
 * Aktivbild eines Charakters:
 *   /phan/chars?image=<charId>
 *   /phan/chars?thumb=<charId>
 *
 * Bestimmtes Galeriebild:
 *   /phan/chars?image_id=<imageId>
 *   /phan/chars?thumb_image=<imageId>
 * ========================================================= */

if (
    isset($_GET['image'])
    || isset($_GET['thumb'])
    || isset($_GET['image_id'])
    || isset($_GET['thumb_image'])
) {
    $isThumb =
        isset($_GET['thumb'])
        || isset($_GET['thumb_image']);

    $byImageId =
        isset($_GET['image_id'])
        || isset($_GET['thumb_image']);

    $requestedId = max(
        0,
        (int)(
            $_GET['thumb_image']
            ?? $_GET['image_id']
            ?? $_GET['thumb']
            ?? $_GET['image']
            ?? 0
        )
    );

    if ($requestedId <= 0) {
        http_response_code(404);
        exit;
    }

    if ($byImageId) {
        $imageRow = phan_one(
            $phanconn,
            '
            SELECT
                ci.id AS image_id,
                ci.char_id,
                ci.image_path,
                ci.face_x,
                ci.face_y,
                ci.face_w,
                ci.face_h
            FROM char_images ci
            WHERE ci.id = ?
            ',
            [$requestedId]
        );

    } else {
        $imageRow = phan_one(
            $phanconn,
            '
            SELECT
                ci.id AS image_id,
                ci.char_id,
                ci.image_path,
                ci.face_x,
                ci.face_y,
                ci.face_w,
                ci.face_h
            FROM chars c
            INNER JOIN char_images ci
                ON ci.id = c.active_image_id
               AND ci.char_id = c.id
            WHERE c.id = ?
            ',
            [$requestedId]
        );
    }

    if (!$imageRow) {
        http_response_code(404);
        exit;
    }

    $sourcePath = phan_char_disk_path(
        $imageRow['image_path'] ?? null
    );

    if (
        $sourcePath === null
        || !is_file($sourcePath)
        || !is_readable($sourcePath)
    ) {
        http_response_code(404);
        exit;
    }

    try {
        if ($isThumb) {
            $diskPath = phan_make_char_thumb(
                (int)$imageRow['char_id'],
                (int)$imageRow['image_id'],
                $sourcePath,
                isset($imageRow['face_x'])
                    && $imageRow['face_x'] !== null
                    ? (float)$imageRow['face_x']
                    : null,
                isset($imageRow['face_y'])
                    && $imageRow['face_y'] !== null
                    ? (float)$imageRow['face_y']
                    : null,
                isset($imageRow['face_w'])
                    && $imageRow['face_w'] !== null
                    ? (float)$imageRow['face_w']
                    : null,
                isset($imageRow['face_h'])
                    && $imageRow['face_h'] !== null
                    ? (float)$imageRow['face_h']
                    : null
            );

            $mime = 'image/jpeg';

        } else {
            $diskPath = $sourcePath;

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($diskPath);
        }

    } catch (Throwable) {
        http_response_code(500);
        exit;
    }

    if (
        !in_array(
            $mime,
            [
                'image/jpeg',
                'image/png',
                'image/webp',
            ],
            true
        )
    ) {
        http_response_code(415);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($diskPath));

    header(
        'Cache-Control: private, '
        . ($isThumb
            ? 'max-age=86400'
            : 'no-store, max-age=0'
        )
    );

    header('X-Content-Type-Options: nosniff');

    readfile($diskPath);
    exit;
}


/* =========================================================
 * POST / AJAX
 * ========================================================= */

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (
        (string)($_POST['ajax'] ?? '') === '1'
        || strtolower(
            (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')
        ) === 'xmlhttprequest'
    );

    try {
        if (
            !hash_equals(
                $csrf,
                (string)($_POST['csrf'] ?? '')
            )
        ) {
            throw new RuntimeException(
                'Ungültiges Formular-Token.'
            );
        }

        $action = (string)($_POST['action'] ?? 'save');
        $id = max(0, (int)($_POST['id'] ?? 0));


        /* -------------------------------------------------
         * Charakter löschen
         * ------------------------------------------------- */

        if ($action === 'delete') {
            if ($id > 0) {
                $images = phan_all(
                    $phanconn,
                    'SELECT id, image_path
                     FROM char_images
                     WHERE char_id = ?',
                    [$id]
                );

                foreach ($images as $image) {
                    phan_delete_char_image(
                        $image['image_path'] ?? null
                    );
                }

                phan_cleanup_char_thumbs($id);

                phan_exec(
                    $phanconn,
                    'DELETE FROM chars
                     WHERE id = ?',
                    [$id]
                )->close();
            }

            if ($isAjax) {
                phan_json_out([
                    'ok' => true,
                    'deleted' => true,
                ]);
            }

            header(
                'Location: /phan/chars?deleted=1',
                true,
                303
            );
            exit;
        }


        /* -------------------------------------------------
         * Einzelnes Galeriebild aktiv setzen
         * ------------------------------------------------- */

        if ($action === 'set_active_image') {
            $imageId = max(
                0,
                (int)($_POST['image_id'] ?? 0)
            );

            if ($id <= 0 || $imageId <= 0) {
                throw new RuntimeException(
                    'Ungültige Bildauswahl.'
                );
            }

            $image = phan_one(
                $phanconn,
                'SELECT
                    id,
                    image_path,
                    face_x,
                    face_y,
                    face_w,
                    face_h
                 FROM char_images
                 WHERE id = ?
                   AND char_id = ?',
                [$imageId, $id]
            );

            if (!$image) {
                throw new RuntimeException(
                    'Bild gehört nicht zu diesem Charakter.'
                );
            }

            phan_exec(
                $phanconn,
                '
                UPDATE chars
                SET
                    active_image_id = ?,
                    image_path = ?,
                    face_x = ?,
                    face_y = ?,
                    face_w = ?,
                    face_h = ?
                WHERE id = ?
                ',
                [
                    $imageId,
                    $image['image_path'],
                    $image['face_x'],
                    $image['face_y'],
                    $image['face_w'],
                    $image['face_h'],
                    $id,
                ]
            )->close();

            phan_json_out([
                'ok' => true,
                'id' => $id,
                'active_image_id' => $imageId,
            ]);
        }


        /* -------------------------------------------------
         * Bildtitel und Crop eines Galeriebilds speichern
         * ------------------------------------------------- */

        if ($action === 'save_image') {
            $imageId = max(
                0,
                (int)($_POST['image_id'] ?? 0)
            );

            if ($id <= 0 || $imageId <= 0) {
                throw new RuntimeException(
                    'Ungültiges Galeriebild.'
                );
            }

            $image = phan_one(
                $phanconn,
                'SELECT id
                 FROM char_images
                 WHERE id = ?
                   AND char_id = ?',
                [$imageId, $id]
            );

            if (!$image) {
                throw new RuntimeException(
                    'Bild gehört nicht zu diesem Charakter.'
                );
            }

            $title = trim(
                (string)($_POST['image_title'] ?? '')
            );

            if (function_exists('mb_substr')) {
                $title = mb_substr(
                    $title,
                    0,
                    120,
                    'UTF-8'
                );
            } else {
                $title = substr($title, 0, 120);
            }

            $x = phan_crop_value('face_x');
            $y = phan_crop_value('face_y');
            $w = phan_crop_value('face_w');
            $h = phan_crop_value('face_h');

            $validCrop =
                $x !== null
                && $y !== null
                && $w !== null
                && $h !== null
                && $w > 0.01
                && $h > 0.01
                && ($x + $w) <= 1.0001
                && ($y + $h) <= 1.0001;

            phan_cleanup_image_thumbs(
                $id,
                $imageId
            );

            if ($validCrop) {
                phan_exec(
                    $phanconn,
                    '
                    UPDATE char_images
                    SET
                        title = NULLIF(?, \'\'),
                        face_x = ?,
                        face_y = ?,
                        face_w = ?,
                        face_h = ?
                    WHERE id = ?
                      AND char_id = ?
                    ',
                    [
                        $title,
                        $x,
                        $y,
                        $w,
                        $h,
                        $imageId,
                        $id,
                    ]
                )->close();

            } else {
                phan_exec(
                    $phanconn,
                    '
                    UPDATE char_images
                    SET
                        title = NULLIF(?, \'\'),
                        face_x = NULL,
                        face_y = NULL,
                        face_w = NULL,
                        face_h = NULL
                    WHERE id = ?
                      AND char_id = ?
                    ',
                    [
                        $title,
                        $imageId,
                        $id,
                    ]
                )->close();
            }

            $activeRow = phan_one(
                $phanconn,
                'SELECT active_image_id
                 FROM chars
                 WHERE id = ?',
                [$id]
            );

            if (
                (int)($activeRow['active_image_id'] ?? 0)
                === $imageId
            ) {
                phan_exec(
                    $phanconn,
                    '
                    UPDATE chars
                    SET
                        face_x = ?,
                        face_y = ?,
                        face_w = ?,
                        face_h = ?
                    WHERE id = ?
                    ',
                    $validCrop
                        ? [$x, $y, $w, $h, $id]
                        : [null, null, null, null, $id]
                )->close();
            }

            $savedRow = phan_one(
                $phanconn,
                'SELECT updated_at
                 FROM chars
                 WHERE id = ?',
                [$id]
            );

            phan_json_out([
                'ok' => true,
                'id' => $id,
                'image_id' => $imageId,
                'updated_at' => phan_format_datetime(
                    $savedRow['updated_at'] ?? null
                ),
            ]);
        }


        /* -------------------------------------------------
         * Einzelnes Galeriebild entfernen
         * ------------------------------------------------- */

        if ($action === 'remove_image') {
            $imageId = max(
                0,
                (int)($_POST['image_id'] ?? 0)
            );

            if ($id <= 0 || $imageId <= 0) {
                throw new RuntimeException(
                    'Ungültiges Galeriebild.'
                );
            }

            $image = phan_one(
                $phanconn,
                'SELECT id, image_path
                 FROM char_images
                 WHERE id = ?
                   AND char_id = ?',
                [$imageId, $id]
            );

            if (!$image) {
                throw new RuntimeException(
                    'Bild gehört nicht zu diesem Charakter.'
                );
            }

            $charRow = phan_one(
                $phanconn,
                'SELECT active_image_id
                 FROM chars
                 WHERE id = ?',
                [$id]
            );

            phan_delete_char_image(
                $image['image_path'] ?? null
            );

            phan_cleanup_image_thumbs(
                $id,
                $imageId
            );

            phan_exec(
                $phanconn,
                'DELETE FROM char_images
                 WHERE id = ?
                   AND char_id = ?',
                [$imageId, $id]
            )->close();

            $nextActiveId = null;

            if (
                (int)($charRow['active_image_id'] ?? 0)
                === $imageId
            ) {
                $next = phan_one(
                    $phanconn,
                    '
                    SELECT
                        id,
                        image_path,
                        face_x,
                        face_y,
                        face_w,
                        face_h
                    FROM char_images
                    WHERE char_id = ?
                    ORDER BY
                        sort_order DESC,
                        id DESC
                    LIMIT 1
                    ',
                    [$id]
                );

                $nextActiveId = $next
                    ? (int)$next['id']
                    : null;

                phan_exec(
                    $phanconn,
                    '
                    UPDATE chars
                    SET
                        active_image_id = ?,
                        image_path = ?,
                        face_x = ?,
                        face_y = ?,
                        face_w = ?,
                        face_h = ?
                    WHERE id = ?
                    ',
                    [
                        $nextActiveId,
                        $next['image_path'] ?? null,
                        $next['face_x'] ?? null,
                        $next['face_y'] ?? null,
                        $next['face_w'] ?? null,
                        $next['face_h'] ?? null,
                        $id,
                    ]
                )->close();
            }

            phan_json_out([
                'ok' => true,
                'id' => $id,
                'image_removed' => true,
                'active_image_id' => $nextActiveId,
            ]);
        }


        /* -------------------------------------------------
         * Charakterdaten speichern / neues Bild hochladen
         * ------------------------------------------------- */

        if ($action !== 'save') {
            throw new RuntimeException(
                'Unbekannte Aktion.'
            );
        }

        $fields = [
            trim((string)($_POST['call_name'] ?? '')),
            trim((string)($_POST['first_name'] ?? '')),
            trim((string)($_POST['last_name'] ?? '')),
            trim((string)($_POST['gender'] ?? '')),
            trim((string)($_POST['species'] ?? '')),
            trim((string)($_POST['accent'] ?? '')),
            trim((string)($_POST['occupation'] ?? '')),
            max(0, (int)($_POST['region_id'] ?? 0)),
            trim((string)($_POST['age_text'] ?? '')),
            trim((string)($_POST['faction'] ?? '')),
            trim((string)($_POST['height_cm'] ?? '')),
            trim((string)($_POST['weight_kg'] ?? '')),
            trim((string)($_POST['personality'] ?? '')),
            trim((string)($_POST['notes'] ?? '')),
            trim((string)($_POST['prompt'] ?? '')),
        ];

        $validGenders = [
            '',
            '♀',
            '⊕',
            '▬',
            '⚥',
            '♂',
        ];

        if (!in_array($fields[3], $validGenders, true)) {
            throw new RuntimeException(
                'Ungültiger Wert für Geschlecht.'
            );
        }

        if ($id > 0) {
            phan_exec(
                $phanconn,
                '
                UPDATE chars
                SET
                    call_name = ?,
                    first_name = ?,
                    last_name = ?,
                    gender = ?,
                    species = ?,
                    accent = ?,
                    occupation = ?,
                    region_id = NULLIF(?, 0),
                    age_text = ?,
                    faction = ?,
                    height_cm = NULLIF(?, \'\'),
                    weight_kg = NULLIF(?, \'\'),
                    personality = ?,
                    notes = ?,
                    prompt = ?
                WHERE id = ?
                ',
                [...$fields, $id]
            )->close();

        } else {
            $stmt = phan_exec(
                $phanconn,
                '
                INSERT INTO chars (
                    call_name,
                    first_name,
                    last_name,
                    gender,
                    species,
                    accent,
                    occupation,
                    region_id,
                    age_text,
                    faction,
                    height_cm,
                    weight_kg,
                    personality,
                    notes,
                    prompt
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    NULLIF(?, 0),
                    ?, ?,
                    NULLIF(?, \'\'),
                    NULLIF(?, \'\'),
                    ?, ?, ?
                )
                ',
                $fields
            );

            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        $imageChanged = false;
        $newImageId = null;

        $newImage = isset($_FILES['image'])
            ? phan_upload_char_image(
                $_FILES['image'],
                $id
            )
            : null;

        if ($newImage !== null) {
            $maxSort = phan_one(
                $phanconn,
                'SELECT COALESCE(MAX(sort_order), -1) AS max_sort
                 FROM char_images
                 WHERE char_id = ?',
                [$id]
            );

            $nextSort =
                (int)($maxSort['max_sort'] ?? -1)
                + 1;

            $stmt = phan_exec(
                $phanconn,
                '
                INSERT INTO char_images (
                    char_id,
                    image_path,
                    title,
                    original_filename,
                    face_x,
                    face_y,
                    face_w,
                    face_h,
                    sort_order
                )
                VALUES (
                    ?, ?, NULL, ?,
                    NULL, NULL, NULL, NULL,
                    ?
                )
                ',
                [
                    $id,
                    $newImage,
                    phan_original_filename(
                        $_FILES['image']
                    ),
                    $nextSort,
                ]
            );

            $newImageId = (int)$stmt->insert_id;
            $stmt->close();

            /*
             * Das zuletzt hochgeladene Bild ist automatisch
             * das aktive Bild des Charakters.
             */
            phan_exec(
                $phanconn,
                '
                UPDATE chars
                SET
                    active_image_id = ?,
                    image_path = ?,
                    face_x = NULL,
                    face_y = NULL,
                    face_w = NULL,
                    face_h = NULL
                WHERE id = ?
                ',
                [$newImageId, $newImage, $id]
            )->close();

            $imageChanged = true;
        }

        if ($isAjax) {
            $savedRow = phan_one(
                $phanconn,
                'SELECT updated_at
                 FROM chars
                 WHERE id = ?',
                [$id]
            );

            phan_json_out([
                'ok' => true,
                'id' => $id,
                'image_changed' => $imageChanged,
                'image_id' => $newImageId,
                'image_url' => $newImageId
                    ? '/phan/chars?image_id=' . $newImageId
                    : null,
                'updated_at' => phan_format_datetime(
                    $savedRow['updated_at'] ?? null
                ),
            ]);
        }

        header(
            'Location: /phan/chars?id='
            . $id
            . '&saved=1',
            true,
            303
        );
        exit;

    } catch (Throwable $e) {
        if ($isAjax) {
            phan_json_out(
                [
                    'ok' => false,
                    'message' => $e->getMessage(),
                ],
                400
            );
        }

        $error = $e->getMessage();
    }
}


/* =========================================================
 * Daten laden
 * ========================================================= */

$regions = phan_all(
    $phanconn,
    '
    SELECT id, title
    FROM regions
    ORDER BY sort_order, title
    '
);

$selectedRegion = max(
    0,
    (int)($_GET['region'] ?? 0)
);

$detailId = max(
    0,
    (int)($_GET['id'] ?? 0)
);

$isNew = isset($_GET['new']);

$listPage = max(
    1,
    (int)($_GET['page'] ?? 1)
);

$listPerPage = 20;

$allowedSorts = [
    'face' => 'ai.id',
    'call_name' => 'c.call_name',
    'name' => "CONCAT_WS(' ', c.last_name, c.first_name)",
    'species' => 'c.species',
    'occupation' => 'c.occupation',
    'region' => 'r.title',
    'height' => 'c.height_cm',
    'weight' => 'c.weight_kg',
];

$listSort = (string)($_GET['sort'] ?? 'call_name');

if (!isset($allowedSorts[$listSort])) {
    $listSort = 'call_name';
}

$listDir = strtolower(
    (string)($_GET['dir'] ?? 'asc')
);

if (!in_array($listDir, ['asc', 'desc'], true)) {
    $listDir = 'asc';
}

$charImages = [];
$activeImage = null;

if ($detailId > 0 || $isNew) {
    $char = $detailId > 0
        ? phan_one(
            $phanconn,
            'SELECT *
             FROM chars
             WHERE id = ?',
            [$detailId]
        )
        : null;

    if ($detailId > 0 && !$char) {
        http_response_code(404);
        exit('Charakter nicht gefunden.');
    }

    $char ??= [
        'id' => 0,
        'call_name' => '',
        'first_name' => '',
        'last_name' => '',
        'gender' => '',
        'species' => '',
        'accent' => '',
        'occupation' => '',
        'region_id' => $selectedRegion ?: null,
        'age_text' => '',
        'faction' => '',
        'height_cm' => '',
        'weight_kg' => '',
        'personality' => '',
        'notes' => '',
        'prompt' => '',
        'active_image_id' => null,
        'updated_at' => null,
    ];

    if ((int)$char['id'] > 0) {
        $charImages = phan_all(
            $phanconn,
            '
            SELECT
                id,
                char_id,
                image_path,
                title,
                original_filename,
                face_x,
                face_y,
                face_w,
                face_h,
                sort_order,
                created_at,
                updated_at
            FROM char_images
            WHERE char_id = ?
            ORDER BY
                sort_order ASC,
                id ASC
            ',
            [(int)$char['id']]
        );

        $activeImageId =
            (int)($char['active_image_id'] ?? 0);

        foreach ($charImages as $image) {
            if ((int)$image['id'] === $activeImageId) {
                $activeImage = $image;
                break;
            }
        }

        /*
         * Sicherheitsnetz für migrierte/alte Datensätze:
         * Falls Bilder existieren, aber kein Aktivbild gesetzt ist,
         * wird das zuletzt einsortierte Bild aktiv.
         */
        if (!$activeImage && $charImages) {
            $activeImage = $charImages[
                count($charImages) - 1
            ];

            $char['active_image_id'] =
                (int)$activeImage['id'];

            phan_exec(
                $phanconn,
                'UPDATE chars
                 SET active_image_id = ?
                 WHERE id = ?',
                [
                    (int)$activeImage['id'],
                    (int)$char['id'],
                ]
            )->close();
        }
    }

} else {
    $where = $selectedRegion > 0
        ? 'WHERE c.region_id = ?'
        : '';

    $countParams = $selectedRegion > 0
        ? [$selectedRegion]
        : [];

    $countRow = phan_one(
        $phanconn,
        "
        SELECT COUNT(*) AS total
        FROM chars c
        $where
        ",
        $countParams
    );

    $totalChars = (int)($countRow['total'] ?? 0);
    $totalPages = max(
        1,
        (int)ceil(
            $totalChars / $listPerPage
        )
    );

    if ($listPage > $totalPages) {
        $listPage = $totalPages;
    }

    $listOffset =
        ($listPage - 1)
        * $listPerPage;

    $sortSql =
        $allowedSorts[$listSort];

    $dirSql =
        strtoupper($listDir);

    $params = $selectedRegion > 0
        ? [
            $selectedRegion,
            $listPerPage,
            $listOffset,
        ]
        : [
            $listPerPage,
            $listOffset,
        ];

    $chars = phan_all(
        $phanconn,
        "
        SELECT
            c.*,
            ai.image_path AS active_image_path,
            r.title AS region_title,
            EXISTS (
                SELECT 1
                FROM char_images ci_check
                WHERE ci_check.char_id = c.id
            ) AS has_image
        FROM chars c
        LEFT JOIN regions r
            ON r.id = c.region_id
        LEFT JOIN char_images ai
            ON ai.id = c.active_image_id
           AND ai.char_id = c.id
        $where
        ORDER BY
            CASE
                WHEN $sortSql IS NULL
                     OR $sortSql = ''
                THEN 1
                ELSE 0
            END,
            $sortSql $dirSql,
            c.call_name ASC,
            c.last_name ASC,
            c.first_name ASC
        LIMIT ?
        OFFSET ?
        ",
        $params
    );
}

if (isset($_GET['saved'])) {
    $flash = 'Gespeichert.';
}

if (isset($_GET['deleted'])) {
    $flash = 'Charakter gelöscht.';
}


function phan_list_url(array $changes = []): string
{
    global $selectedRegion, $listPage, $listSort, $listDir;

    $query = [
        'region' => $selectedRegion > 0
            ? $selectedRegion
            : null,
        'page' => $listPage > 1
            ? $listPage
            : null,
        'sort' => $listSort !== 'call_name'
            ? $listSort
            : null,
        'dir' => $listDir !== 'asc'
            ? $listDir
            : null,
    ];

    foreach ($changes as $key => $value) {
        $query[$key] = $value;
    }

    $query = array_filter(
        $query,
        static fn($value) =>
            $value !== null
            && $value !== ''
            && $value !== 0
    );

    return '/phan/chars'
        . (
            $query
                ? '?'
                    . http_build_query(
                        $query,
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    )
                : ''
        );
}

function phan_sort_url(string $column): string
{
    global $listSort, $listDir;

    $nextDir =
        $listSort === $column
        && $listDir === 'asc'
            ? 'desc'
            : 'asc';

    return phan_list_url([
        'sort' => $column,
        'dir' => $nextDir,
        'page' => null,
    ]);
}

function phan_sort_symbol(string $column): string
{
    global $listSort, $listDir;

    if ($listSort !== $column) {
        return '';
    }

    return $listDir === 'asc'
        ? '▲'
        : '▼';
}


function phan_detail_url(int $charId): string
{
    global $selectedRegion, $listPage, $listSort, $listDir;

    $query = [
        'id' => $charId,
        'region' => $selectedRegion > 0 ? $selectedRegion : null,
        'page' => $listPage > 1 ? $listPage : null,
        'sort' => $listSort !== 'call_name' ? $listSort : null,
        'dir' => $listDir !== 'asc' ? $listDir : null,
    ];

    $query = array_filter(
        $query,
        static fn($value) =>
            $value !== null
            && $value !== ''
            && $value !== 0
    );

    return '/phan/chars?'
        . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}


function phan_new_url(): string
{
    global $selectedRegion, $listPage, $listSort, $listDir;

    $query = [
        'new' => 1,
        'region' => $selectedRegion > 0 ? $selectedRegion : null,
        'page' => $listPage > 1 ? $listPage : null,
        'sort' => $listSort !== 'call_name' ? $listSort : null,
        'dir' => $listDir !== 'asc' ? $listDir : null,
    ];

    $query = array_filter(
        $query,
        static fn($value) =>
            $value !== null
            && $value !== ''
            && $value !== 0
    );

    return '/phan/chars?'
        . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}


/* =========================================================
 * Rendering
 * ========================================================= */

$page_title = 'Charaktere';

require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div class="phan-page">

    <?php if (!$detailId && !$isNew): ?>

        <div class="phan-head">
            <h1 class="ueberschrift phan-title">
                Charaktere
            </h1>

            <div class="phan-actions phan-actions--top">
                <button
                    type="button"
                    onclick="location.href='<?= phan_h(
                        phan_new_url()
                    ) ?>'"
                >
                    + Charakter
                </button>
            </div>
        </div>

    <?php else: ?>

        <div class="phan-detail-head">

            <div class="phan-detail-head-left">
                <button
                    type="button"
                    onclick="location.href='<?= phan_h(
                        phan_list_url()
                    ) ?>'"
                >
                    ← Zurück zur Liste
                </button>
            </div>

            <div
                class="phan-autosave-status"
                id="autosaveStatus"
                aria-live="polite"
            ></div>

            <div class="phan-last-saved">
                Zuletzt gespeichert am
                <span id="lastSavedValue">
                    <?= phan_h(
                        phan_format_datetime(
                            $char['updated_at'] ?? null
                        )
                    ) ?>
                </span>
            </div>

        </div>

    <?php endif; ?>


    <?php if ($flash): ?>
        <div class="phan-msg">
            <?= phan_h($flash) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="phan-msg phan-error">
            <?= phan_h($error) ?>
        </div>
    <?php endif; ?>


    <?php if (!$detailId && !$isNew): ?>

        <div class="phan-tabs">

            <button
                class="phan-tab <?= $selectedRegion === 0 ? 'active' : '' ?>"
                onclick="location.href='<?= phan_h(
                    phan_list_url([
                        'region' => null,
                        'page' => null,
                    ])
                ) ?>'"
            >
                Alle
            </button>

            <?php foreach ($regions as $region): ?>

                <button
                    class="phan-tab <?= $selectedRegion === (int)$region['id'] ? 'active' : '' ?>"
                    onclick="location.href='<?= phan_h(
                        phan_list_url([
                            'region' => (int)$region['id'],
                            'page' => null,
                        ])
                    ) ?>'"
                >
                    <?= phan_h($region['title']) ?>
                </button>

            <?php endforeach; ?>

        </div>


        <div class="phan-table-wrap">
            <table class="phan-table">

                <thead>
                    <tr>

                        <th class="phan-sortable">
                            <a href="<?= phan_h(phan_sort_url('face')) ?>">
                                Gesicht
                                <span class="phan-sort-indicator">
                                    <?= phan_h(phan_sort_symbol('face')) ?>
                                </span>
                            </a>
                        </th>

                        <th class="phan-sortable">
                            <a href="<?= phan_h(phan_sort_url('call_name')) ?>">
                                Rufname
                                <span class="phan-sort-indicator">
                                    <?= phan_h(phan_sort_symbol('call_name')) ?>
                                </span>
                            </a>
                        </th>

                        <th class="phan-sortable">
                            <a href="<?= phan_h(phan_sort_url('name')) ?>">
                                Name
                                <span class="phan-sort-indicator">
                                    <?= phan_h(phan_sort_symbol('name')) ?>
                                </span>
                            </a>
                        </th>

                        <th class="phan-sortable">
                            <a href="<?= phan_h(phan_sort_url('species')) ?>">
                                Art
                                <span class="phan-sort-indicator">
                                    <?= phan_h(phan_sort_symbol('species')) ?>
                                </span>
                            </a>
                        </th>

                        <th class="phan-sortable">
                            <a href="<?= phan_h(phan_sort_url('occupation')) ?>">
                                Beruf
                                <span class="phan-sort-indicator">
                                    <?= phan_h(phan_sort_symbol('occupation')) ?>
                                </span>
                            </a>
                        </th>

                        <th class="phan-sortable">
                            <a href="<?= phan_h(phan_sort_url('region')) ?>">
                                Region
                                <span class="phan-sort-indicator">
                                    <?= phan_h(phan_sort_symbol('region')) ?>
                                </span>
                            </a>
                        </th>

                        <th class="phan-sortable">
                            <a href="<?= phan_h(phan_sort_url('height')) ?>">
                                Größe
                                <span class="phan-sort-indicator">
                                    <?= phan_h(phan_sort_symbol('height')) ?>
                                </span>
                            </a>
                        </th>

                        <th class="phan-sortable">
                            <a href="<?= phan_h(phan_sort_url('weight')) ?>">
                                Gewicht
                                <span class="phan-sort-indicator">
                                    <?= phan_h(phan_sort_symbol('weight')) ?>
                                </span>
                            </a>
                        </th>

                    </tr>
                </thead>

                <tbody>

                <?php if (!$chars): ?>

                    <tr>
                        <td
                            colspan="8"
                            class="phan-empty-table"
                        >
                            Noch keine Charaktere in dieser Auswahl.
                        </td>
                    </tr>

                <?php endif; ?>


                <?php foreach ($chars as $c): ?>

                    <tr
                        class="phan-row"
                        data-href="<?= phan_h(
                            phan_detail_url(
                                (int)$c['id']
                            )
                        ) ?>"
                    >

                        <td
                            data-sort-value="<?= !empty($c['active_image_path']) ? '1' : '0' ?>"
                        >

                            <?php if (!empty($c['active_image_path'])): ?>

                                <img
                                    class="phan-face phan-face-thumb"
                                    src="/phan/chars?thumb=<?= (int)$c['id'] ?>"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                >

                            <?php else: ?>

                                <span class="phan-face phan-noimg">
                                    kein Bild
                                </span>

                            <?php endif; ?>

                        </td>

                        <td
                            data-sort-value="<?= phan_h($c['call_name']) ?>"
                        >
                            <div class="phan-char-name-cell">
                                <strong>
                                    <?= phan_h($c['call_name']) ?>
                                </strong>

                                <?php if (
                                    !empty($c['has_image'])
                                    && trim(
                                        (string)($c['prompt'] ?? '')
                                    ) === ''
                                ): ?>
                                    <span
                                        class="phan-char-warning"
                                        title="Charakter hat ein Bild, aber keinen Prompt."
                                        aria-label="Bild vorhanden, Prompt fehlt"
                                    >
                                        !
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td
                            data-sort-value="<?= phan_h(
                                trim(
                                    ($c['first_name'] ?? '')
                                    . ' '
                                    . ($c['last_name'] ?? '')
                                )
                            ) ?>"
                        >
                            <?= phan_h(
                                trim(
                                    ($c['first_name'] ?? '')
                                    . ' '
                                    . ($c['last_name'] ?? '')
                                )
                            ) ?>
                        </td>

                        <td
                            data-sort-value="<?= phan_h($c['species'] ?? '') ?>"
                        >
                            <?= phan_h($c['species'] ?: '—') ?>
                        </td>

                        <td
                            data-sort-value="<?= phan_h($c['occupation'] ?? '') ?>"
                        >
                            <?= phan_h($c['occupation'] ?: '—') ?>
                        </td>

                        <td
                            data-sort-value="<?= phan_h($c['region_title'] ?? '') ?>"
                        >
                            <?= phan_h($c['region_title'] ?: '—') ?>
                        </td>


                        <td>
                            <?= $c['height_cm'] !== null
                                && $c['height_cm'] !== ''
                                    ? phan_h(
                                        rtrim(
                                            rtrim(
                                                number_format(
                                                    (float)$c['height_cm'],
                                                    1,
                                                    ',',
                                                    ''
                                                ),
                                                '0'
                                            ),
                                            ','
                                        )
                                        . ' cm'
                                    )
                                    : '—'
                            ?>
                        </td>

                        <td>
                            <?= $c['weight_kg'] !== null
                                && $c['weight_kg'] !== ''
                                    ? phan_h(
                                        rtrim(
                                            rtrim(
                                                number_format(
                                                    (float)$c['weight_kg'],
                                                    1,
                                                    ',',
                                                    ''
                                                ),
                                                '0'
                                            ),
                                            ','
                                        )
                                        . ' kg'
                                    )
                                    : '—'
                            ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>
        </div>


        <?php if ($totalPages > 1): ?>

            <nav
                class="phan-pagination"
                aria-label="Charakterseiten"
            >

                <a
                    class="
                        phan-page-button
                        <?= $listPage <= 1 ? 'disabled' : '' ?>
                    "
                    href="<?= $listPage > 1
                        ? phan_h(
                            phan_list_url([
                                'page' => $listPage - 1,
                            ])
                        )
                        : '#'
                    ?>"
                    <?= $listPage <= 1
                        ? 'aria-disabled="true" tabindex="-1"'
                        : ''
                    ?>
                >
                    ←
                </a>


                <?php
                $pageStart = max(
                    1,
                    $listPage - 2
                );

                $pageEnd = min(
                    $totalPages,
                    $listPage + 2
                );
                ?>


                <?php if ($pageStart > 1): ?>

                    <a
                        class="phan-page-button"
                        href="<?= phan_h(
                            phan_list_url([
                                'page' => 1,
                            ])
                        ) ?>"
                    >
                        1
                    </a>

                    <?php if ($pageStart > 2): ?>
                        <span class="phan-page-ellipsis">
                            …
                        </span>
                    <?php endif; ?>

                <?php endif; ?>


                <?php for (
                    $pageNumber = $pageStart;
                    $pageNumber <= $pageEnd;
                    $pageNumber++
                ): ?>

                    <a
                        class="
                            phan-page-button
                            <?= $pageNumber === $listPage
                                ? 'active'
                                : ''
                            ?>
                        "
                        href="<?= phan_h(
                            phan_list_url([
                                'page' => $pageNumber,
                            ])
                        ) ?>"
                        <?= $pageNumber === $listPage
                            ? 'aria-current="page"'
                            : ''
                        ?>
                    >
                        <?= $pageNumber ?>
                    </a>

                <?php endfor; ?>


                <?php if ($pageEnd < $totalPages): ?>

                    <?php if ($pageEnd < $totalPages - 1): ?>
                        <span class="phan-page-ellipsis">
                            …
                        </span>
                    <?php endif; ?>

                    <a
                        class="phan-page-button"
                        href="<?= phan_h(
                            phan_list_url([
                                'page' => $totalPages,
                            ])
                        ) ?>"
                    >
                        <?= $totalPages ?>
                    </a>

                <?php endif; ?>


                <a
                    class="
                        phan-page-button
                        <?= $listPage >= $totalPages ? 'disabled' : '' ?>
                    "
                    href="<?= $listPage < $totalPages
                        ? phan_h(
                            phan_list_url([
                                'page' => $listPage + 1,
                            ])
                        )
                        : '#'
                    ?>"
                    <?= $listPage >= $totalPages
                        ? 'aria-disabled="true" tabindex="-1"'
                        : ''
                    ?>
                >
                    →
                </a>


                <span class="phan-pagination-info">
                    <?= $totalChars ?>
                    Charakter<?= $totalChars === 1 ? '' : 'e' ?>
                    · Seite <?= $listPage ?> / <?= $totalPages ?>
                </span>

            </nav>

        <?php endif; ?>


    <?php else: ?>

        <form
            method="post"
            enctype="multipart/form-data"
            class="phan-detail"
            id="charForm"
            autocomplete="off"
        >

            <input
                type="hidden"
                name="csrf"
                value="<?= phan_h($csrf) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="save"
            >

            <input
                type="hidden"
                name="id"
                id="charId"
                value="<?= (int)$char['id'] ?>"
            >

            <input
                type="hidden"
                name="image_id"
                id="activeImageId"
                value="<?= (int)($activeImage['id'] ?? 0) ?>"
            >

            <input
                type="hidden"
                name="face_x"
                id="faceX"
                value="<?= phan_h($activeImage['face_x'] ?? '') ?>"
            >

            <input
                type="hidden"
                name="face_y"
                id="faceY"
                value="<?= phan_h($activeImage['face_y'] ?? '') ?>"
            >

            <input
                type="hidden"
                name="face_w"
                id="faceW"
                value="<?= phan_h($activeImage['face_w'] ?? '') ?>"
            >

            <input
                type="hidden"
                name="face_h"
                id="faceH"
                value="<?= phan_h($activeImage['face_h'] ?? '') ?>"
            >

            <input
                type="file"
                name="image"
                id="charImageInput"
                class="phan-image-upload-input"
                accept="image/jpeg,image/png,image/webp"
                multiple
            >


            <div class="phan-card phan-image-card">

                <div class="phan-image-card-head">

                    <div>
                        <strong>Bilder</strong>

                        <span class="phan-image-count">
                            <?= count($charImages) ?>
                        </span>
                    </div>

                    <button
                        type="button"
                        id="addImageButton"
                    >
                        + Bild
                    </button>

                </div>


                <?php if (count($charImages) > 1): ?>

                    <div
                        class="phan-image-gallery"
                        aria-label="Charakterbilder"
                    >

                        <?php foreach ($charImages as $image): ?>

                            <?php
                            $imageId = (int)$image['id'];
                            $isActiveImage =
                                $activeImage
                                && $imageId
                                    === (int)$activeImage['id'];
                            ?>

                            <button
                                type="button"
                                class="phan-image-gallery-item <?= $isActiveImage ? 'active' : '' ?>"
                                data-image-id="<?= $imageId ?>"
                                title="<?= phan_h(
                                    phan_image_display_title($image)
                                ) ?>"
                            >
                                <img
                                    src="/phan/chars?thumb_image=<?= $imageId ?>"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                >

                                <span>
                                    <?= phan_h(
                                        phan_image_display_title($image)
                                    ) ?>
                                </span>
                            </button>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>


                <?php if ($activeImage): ?>

                    <div
                        class="phan-cropbox phan-image-dropzone"
                        id="cropBox"
                    >

                        <img
                            src="/phan/chars?image_id=<?= (int)$activeImage['id'] ?>"
                            alt="<?= phan_h($char['call_name']) ?>"
                            id="cropImage"
                            draggable="false"
                        >

                        <div
                            class="phan-crop-overlay"
                            id="cropOverlay"
                        ></div>

                        <div class="phan-image-drop-hint">
                            Bilder hier ablegen zum Hinzufügen
                        </div>

                    </div>


                    <?php if (count($charImages) > 1): ?>

                        <div class="phan-image-meta">

                            <label>
                                Bildtitel

                                <input
                                    type="text"
                                    id="imageTitle"
                                    class="phan-image-title-input"
                                    maxlength="120"
                                    value="<?= phan_h(
                                        $activeImage['title'] ?? ''
                                    ) ?>"
                                    placeholder="<?= phan_h(
                                        $activeImage['original_filename']
                                        ?? basename(
                                            (string)$activeImage['image_path']
                                        )
                                    ) ?>"
                                >
                            </label>

                            <div class="phan-image-filename">
                                Datei:
                                <strong>
                                    <?= phan_h(
                                        $activeImage['original_filename']
                                        ?? basename(
                                            (string)$activeImage['image_path']
                                        )
                                    ) ?>
                                </strong>
                            </div>

                        </div>

                    <?php endif; ?>


                    <div class="phan-image-actions">

                        <button
                            type="button"
                            id="cropModeButton"
                        >
                            Gesichtsausschnitt setzen
                        </button>

                        <button
                            type="button"
                            class="phan-danger"
                            id="removeImageButton"
                            data-image-id="<?= (int)$activeImage['id'] ?>"
                        >
                            Bild entfernen
                        </button>

                    </div>

                <?php else: ?>

                    <button
                        type="button"
                        class="
                            phan-image
                            phan-noimg
                            phan-image-empty
                            phan-image-dropzone
                        "
                        id="emptyImagePicker"
                    >
                        Noch kein Bild

                        <span class="phan-image-empty-sub">
                            Klicken oder Bilder hierher ziehen
                        </span>
                    </button>

                <?php endif; ?>

            </div>


            <div class="phan-card">

                <div class="phan-form-grid">

                    <label>
                        Rufname

                        <input
                            name="call_name"
                            value="<?= phan_h($char['call_name']) ?>"
                        >
                    </label>

                    <label>
                        Art / Spezies

                        <input
                            name="species"
                            value="<?= phan_h($char['species']) ?>"
                        >
                    </label>


                    <label>
                        Vorname

                        <input
                            name="first_name"
                            value="<?= phan_h($char['first_name']) ?>"
                        >
                    </label>


                    <label>
                        Nachname

                        <input
                            name="last_name"
                            value="<?= phan_h($char['last_name']) ?>"
                        >
                    </label>


                    <label>
                        Region

                        <select name="region_id">

                            <option value="0">
                                — keine —
                            </option>

                            <?php foreach ($regions as $region): ?>

                                <option
                                    value="<?= (int)$region['id'] ?>"
                                    <?= (int)($char['region_id'] ?? 0)
                                        === (int)$region['id']
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    <?= phan_h($region['title']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>
                    </label>


                    <label>
                        Geschlecht

                        <select name="gender">
                            <option value="">—</option>

                            <?php foreach (
                                ['♀', '⊕', '⚥', '♂']
                                as $gender
                            ): ?>

                                <option
                                    value="<?= phan_h($gender) ?>"
                                    <?= ($char['gender'] ?? '') === $gender
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    <?= phan_h($gender) ?>
                                </option>

                            <?php endforeach; ?>
                        </select>
                    </label>


                    <label>
                        Alter

                        <input
                            name="age_text"
                            value="<?= phan_h($char['age_text']) ?>"
                        >
                    </label>


                    <label>
                        Akzent

                        <input
                            name="accent"
                            value="<?= phan_h($char['accent']) ?>"
                        >
                    </label>


                    <label>
                        Beruf

                        <input
                            name="occupation"
                            value="<?= phan_h($char['occupation']) ?>"
                        >
                    </label>

                    <label>
                        Fraktion / Zugehörigkeit

                        <input
                            name="faction"
                            value="<?= phan_h($char['faction']) ?>"
                        >
                    </label>



                    <label>
                        Körpergröße

                        <input
                            type="number"
                            name="height_cm"
                            min="0"
                            step="1"
                            inputmode="numeric"
                            placeholder="cm"
                            value="<?= phan_h(
                                $char['height_cm'] !== null && $char['height_cm'] !== ''
                                    ? (int)$char['height_cm']
                                    : ''
                            ) ?>"
                        >
                    </label>


                    <label>
                        Gewicht

                        <input
                            type="number"
                            name="weight_kg"
                            min="0"
                            step="1"
                            inputmode="numeric"
                            placeholder="kg"
                            value="<?= phan_h(
                                $char['weight_kg'] !== null && $char['weight_kg'] !== ''
                                    ? (int)$char['weight_kg']
                                    : ''
                            ) ?>"
                        >
                    </label>


                    <label>
                        Persönlichkeit

                        <textarea
                            name="personality"
                            rows="2"
                        ><?= phan_h($char['personality']) ?></textarea>
                    </label>


                    <label>
                        Notizen

                        <textarea
                            name="notes"
                            rows="2"
                        ><?= phan_h($char['notes']) ?></textarea>
                    </label>


                    <div
                        class="phan-wide phan-prompt-editor"
                        id="promptEditor"
                        hidden
                    >
                        <label>
                            Prompt

                            <textarea
                                name="prompt"
                                id="promptField"
                                rows="12"
                            ><?= phan_h($char['prompt']) ?></textarea>
                        </label>
                    </div>

                </div>


                <div class="phan-bottom-actions">

                    <div class="phan-bottom-actions-left">

                        <button
                            type="button"
                            class="phan-danger"
                            id="deleteCharButton"
                            <?= (int)$char['id'] <= 0
                                ? 'hidden'
                                : ''
                            ?>
                        >
                            Charakter löschen
                        </button>

                    </div>


                    <div class="phan-bottom-actions-right">

                        <button
                            type="button"
                            id="copyPrompt"
                        >
                            Prompt kopieren
                        </button>

                        <button
                            type="button"
                            id="togglePrompt"
                        >
                            Prompt bearbeiten
                        </button>

                        <span
                            class="phan-copy-status"
                            id="copyStatus"
                        ></span>

                    </div>

                </div>

            </div>

        </form>

    <?php endif; ?>

</div>


<script>
(() => {
    'use strict';


    /* =====================================================
     * Listenansicht
     * ===================================================== */

    document
        .querySelectorAll('.phan-row')
        .forEach(row => {
            row.addEventListener('click', () => {
                location.href = row.dataset.href;
            });
        });


    /* =====================================================
     * Detailansicht
     * ===================================================== */

    const form =
        document.getElementById('charForm');

    if (!form) {
        return;
    }

    const charId =
        document.getElementById('charId');

    const autosaveStatus =
        document.getElementById('autosaveStatus');

    const lastSavedValue =
        document.getElementById('lastSavedValue');

    const imageInput =
        document.getElementById('charImageInput');

    const addImageButton =
        document.getElementById('addImageButton');

    const emptyPicker =
        document.getElementById('emptyImagePicker');

    const removeImageButton =
        document.getElementById('removeImageButton');

    const deleteCharButton =
        document.getElementById('deleteCharButton');

    const activeImageId =
        document.getElementById('activeImageId');

    const imageTitle =
        document.getElementById('imageTitle');

    let saveTimer = null;
    let imageSaveTimer = null;
    let saveChain = Promise.resolve();
    let statusTimer = null;


    function setStatus(text, isError = false) {
        if (!autosaveStatus) {
            return;
        }

        window.clearTimeout(statusTimer);

        autosaveStatus.textContent = text;
        autosaveStatus.classList.toggle(
            'is-error',
            isError
        );

        if (
            text === 'Gespeichert'
            || text === ''
        ) {
            statusTimer = window.setTimeout(
                () => {
                    autosaveStatus.textContent = '';
                    autosaveStatus.classList.remove(
                        'is-error'
                    );
                },
                1200
            );
        }
    }


    function applyReturnedId(payload) {
        const returnedId =
            Number(payload?.id ?? 0);

        if (
            !Number.isInteger(returnedId)
            || returnedId <= 0
        ) {
            return;
        }

        if (Number(charId.value) <= 0) {
            charId.value =
                String(returnedId);

            const currentUrl =
                new URL(
                    window.location.href
                );

            currentUrl.searchParams.delete(
                'new'
            );

            currentUrl.searchParams.set(
                'id',
                String(returnedId)
            );

            history.replaceState(
                null,
                '',
                currentUrl.pathname
                + currentUrl.search
            );

            if (deleteCharButton) {
                deleteCharButton.hidden = false;
            }
        }
    }


    async function performRequest(
        action = 'save',
        file = null,
        extra = {}
    ) {
        const data =
            new FormData(form);

        data.set('ajax', '1');
        data.set('action', action);
        data.delete('image');

        Object.entries(extra)
            .forEach(([key, value]) => {
                if (
                    value === null
                    || value === undefined
                ) {
                    data.delete(key);
                } else {
                    data.set(
                        key,
                        String(value)
                    );
                }
            });

        if (file instanceof File) {
            data.set(
                'image',
                file,
                file.name
            );
        }

        setStatus('Speichere…');

        const response =
            await fetch(
                '/phan/chars',
                {
                    method: 'POST',
                    body: data,
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                }
            );

        let payload = null;

        try {
            payload = await response.json();
        } catch (_) {
            throw new Error(
                'Ungültige Serverantwort.'
            );
        }

        if (
            !response.ok
            || !payload?.ok
        ) {
            throw new Error(
                payload?.message
                || 'Speichern fehlgeschlagen.'
            );
        }

        applyReturnedId(payload);

        if (
            lastSavedValue
            && payload?.updated_at
        ) {
            lastSavedValue.textContent =
                payload.updated_at;
        }

        setStatus('Gespeichert');

        return payload;
    }


    function queueRequest(
        action = 'save',
        file = null,
        extra = {}
    ) {
        saveChain =
            saveChain
                .catch(() => {})
                .then(
                    () => performRequest(
                        action,
                        file,
                        extra
                    )
                )
                .catch(error => {
                    setStatus(
                        error.message
                        || 'Fehler',
                        true
                    );

                    throw error;
                });

        return saveChain;
    }


    function scheduleAutosave() {
        window.clearTimeout(saveTimer);

        saveTimer =
            window.setTimeout(
                () => {
                    queueRequest('save')
                        .catch(() => {});
                },
                450
            );
    }


    form
        .querySelectorAll(
            'input:not([type="hidden"]):not([type="file"]):not(.phan-image-title-input), textarea'
        )
        .forEach(field => {
            field.addEventListener(
                'input',
                scheduleAutosave
            );
        });


    form
        .querySelectorAll('select')
        .forEach(field => {
            field.addEventListener(
                'change',
                () => {
                    window.clearTimeout(
                        saveTimer
                    );

                    queueRequest('save')
                        .catch(() => {});
                }
            );
        });


    /* =====================================================
     * Prompt
     * ===================================================== */

    const promptField =
        document.getElementById('promptField');

    const promptEditor =
        document.getElementById('promptEditor');

    const togglePrompt =
        document.getElementById('togglePrompt');

    const copyPrompt =
        document.getElementById('copyPrompt');

    const copyStatus =
        document.getElementById('copyStatus');

    const PROMPT_STYLE_PREFIX =
        "Heller (weiß/grau), Monotoner Hintergrund. kein text.\n"
        + "Skizze-Stil, Anime-Stil, Konzept Art, KEIN Photorealismus!\n"
        + "GROBE SKIZZE (mit farbe).\n"
        + "gesamtansicht, also von füßen bis kopf.\n\n";


    if (
        promptEditor
        && togglePrompt
    ) {
        togglePrompt.addEventListener(
            'click',
            () => {
                const opening =
                    promptEditor.hidden;

                promptEditor.hidden =
                    !opening;

                togglePrompt.textContent =
                    opening
                        ? 'Prompt schließen'
                        : 'Prompt bearbeiten';

                if (opening) {
                    promptField?.focus();
                }
            }
        );
    }


    async function copyText(text) {
        if (
            navigator.clipboard
            && window.isSecureContext
        ) {
            await navigator.clipboard.writeText(
                text
            );

            return;
        }

        const helper =
            document.createElement(
                'textarea'
            );

        helper.value = text;
        helper.style.position = 'fixed';
        helper.style.opacity = '0';

        document.body.appendChild(helper);
        helper.focus();
        helper.select();
        document.execCommand('copy');
        helper.remove();
    }


    if (
        copyPrompt
        && promptField
        && copyStatus
    ) {
        copyPrompt.addEventListener(
            'click',
            async () => {
                try {
                    await copyText(
                        PROMPT_STYLE_PREFIX
                        + promptField.value
                    );

                    copyStatus.textContent =
                        promptField.value
                            ? 'kopiert'
                            : 'Prompt ist leer';

                    window.setTimeout(
                        () => {
                            copyStatus.textContent = '';
                        },
                        1600
                    );

                } catch (_) {
                    copyStatus.textContent =
                        'Kopieren fehlgeschlagen';
                }
            }
        );
    }


    /* =====================================================
     * Bildgalerie / Upload
     * ===================================================== */

    function currentImageId() {
        return Number(
            activeImageId?.value
            || 0
        );
    }


    async function uploadImages(fileList) {
        const files =
            Array.from(fileList || [])
                .filter(
                    file =>
                        file instanceof File
                        && file.type.startsWith('image/')
                );

        if (!files.length) {
            return;
        }

        let lastId =
            Number(charId.value || 0);

        try {
            for (const file of files) {
                const payload =
                    await queueRequest(
                        'save',
                        file
                    );

                lastId =
                    Number(payload?.id ?? lastId);
            }

            if (lastId > 0) {
                const currentUrl =
                    new URL(
                        window.location.href
                    );

                currentUrl.searchParams.delete(
                    'new'
                );

                currentUrl.searchParams.set(
                    'id',
                    String(lastId)
                );

                location.replace(
                    currentUrl.pathname
                    + currentUrl.search
                );
            }

        } catch (_) {}
    }


    addImageButton?.addEventListener(
        'click',
        () => imageInput?.click()
    );


    emptyPicker?.addEventListener(
        'click',
        () => imageInput?.click()
    );


    imageInput?.addEventListener(
        'change',
        () => {
            uploadImages(
                imageInput.files
            );
        }
    );


    document
        .querySelectorAll(
            '.phan-image-gallery-item'
        )
        .forEach(button => {
            button.addEventListener(
                'click',
                async () => {
                    const imageId =
                        Number(
                            button.dataset.imageId
                            || 0
                        );

                    if (
                        imageId <= 0
                        || imageId === currentImageId()
                    ) {
                        return;
                    }

                    try {
                        await queueRequest(
                            'set_active_image',
                            null,
                            {
                                image_id: imageId,
                            }
                        );

                        location.reload();

                    } catch (_) {}
                }
            );
        });


    document
        .querySelectorAll(
            '.phan-image-dropzone'
        )
        .forEach(zone => {
            zone.addEventListener(
                'dragenter',
                event => {
                    event.preventDefault();
                    zone.classList.add(
                        'is-dragover'
                    );
                }
            );

            zone.addEventListener(
                'dragover',
                event => {
                    event.preventDefault();
                    zone.classList.add(
                        'is-dragover'
                    );
                }
            );

            zone.addEventListener(
                'dragleave',
                event => {
                    if (
                        event.relatedTarget
                        && zone.contains(
                            event.relatedTarget
                        )
                    ) {
                        return;
                    }

                    zone.classList.remove(
                        'is-dragover'
                    );
                }
            );

            zone.addEventListener(
                'drop',
                event => {
                    event.preventDefault();

                    zone.classList.remove(
                        'is-dragover'
                    );

                    uploadImages(
                        event.dataTransfer?.files
                    );
                }
            );
        });


    removeImageButton?.addEventListener(
        'click',
        async () => {
            const imageId =
                currentImageId();

            if (imageId <= 0) {
                return;
            }

            if (
                !confirm(
                    'Dieses Bild wirklich entfernen?'
                )
            ) {
                return;
            }

            try {
                await queueRequest(
                    'remove_image',
                    null,
                    {
                        image_id: imageId,
                    }
                );

                location.reload();

            } catch (_) {}
        }
    );


    /* Bildtitel separat autosaven */

    imageTitle?.addEventListener(
        'input',
        () => {
            window.clearTimeout(
                imageSaveTimer
            );

            imageSaveTimer =
                window.setTimeout(
                    () => {
                        const imageId =
                            currentImageId();

                        if (imageId <= 0) {
                            return;
                        }

                        queueRequest(
                            'save_image',
                            null,
                            {
                                image_id: imageId,
                                image_title:
                                    imageTitle.value,
                            }
                        ).catch(() => {});
                    },
                    450
                );
        }
    );


    /* =====================================================
     * Charakter löschen
     * ===================================================== */

    deleteCharButton?.addEventListener(
        'click',
        async () => {
            if (
                !confirm(
                    'Charakter wirklich löschen?'
                )
            ) {
                return;
            }

            try {
                await queueRequest('delete');

                const currentUrl =
                    new URL(
                        window.location.href
                    );

                currentUrl.searchParams.delete(
                    'id'
                );

                currentUrl.searchParams.delete(
                    'new'
                );

                location.href =
                    currentUrl.pathname
                    + currentUrl.search;
            } catch (_) {}
        }
    );


    /* =====================================================
     * Gesichtsausschnitt des aktiven Bildes
     * ===================================================== */

    const cropBox =
        document.getElementById('cropBox');

    const cropImg =
        document.getElementById('cropImage');

    const cropOverlay =
        document.getElementById('cropOverlay');

    const cropModeButton =
        document.getElementById('cropModeButton');

    const cropInputs = [
        document.getElementById('faceX'),
        document.getElementById('faceY'),
        document.getElementById('faceW'),
        document.getElementById('faceH')
    ];

    let cropMode = false;
    let cropStart = null;


    function hasSavedCrop() {
        const values =
            cropInputs.map(
                input => parseFloat(
                    input?.value ?? ''
                )
            );

        return (
            values.every(Number.isFinite)
            && values[2] > 0
            && values[3] > 0
        );
    }


    function showSavedCrop() {
        if (
            !cropOverlay
            || !cropMode
            || !hasSavedCrop()
        ) {
            if (cropOverlay) {
                cropOverlay.hidden = true;
            }

            return;
        }

        const [x, y, w, h] =
            cropInputs.map(
                input => parseFloat(
                    input.value
                )
            );

        cropOverlay.hidden = false;
        cropOverlay.style.left =
            (x * 100) + '%';
        cropOverlay.style.top =
            (y * 100) + '%';
        cropOverlay.style.width =
            (w * 100) + '%';
        cropOverlay.style.height =
            (h * 100) + '%';
    }


    function setCropMode(enabled) {
        cropMode = Boolean(enabled);
        cropStart = null;

        cropBox?.classList.toggle(
            'is-crop-mode',
            cropMode
        );

        if (cropModeButton) {
            cropModeButton.textContent =
                cropMode
                    ? 'Ausschnitt abbrechen'
                    : 'Gesichtsausschnitt setzen';
        }

        if (cropMode) {
            showSavedCrop();
        } else if (cropOverlay) {
            cropOverlay.hidden = true;
        }
    }


    if (
        cropModeButton
        && cropBox
        && cropImg
        && cropOverlay
    ) {
        cropModeButton.addEventListener(
            'click',
            () => {
                setCropMode(!cropMode);
            }
        );


        function cropPoint(event) {
            const rect =
                cropImg.getBoundingClientRect();

            return {
                x: Math.max(
                    0,
                    Math.min(
                        rect.width,
                        event.clientX - rect.left
                    )
                ),
                y: Math.max(
                    0,
                    Math.min(
                        rect.height,
                        event.clientY - rect.top
                    )
                ),
                rect
            };
        }


        cropBox.addEventListener(
            'pointerdown',
            event => {
                if (!cropMode) {
                    return;
                }

                if (
                    event.button !== undefined
                    && event.button !== 0
                ) {
                    return;
                }

                event.preventDefault();
                cropStart = cropPoint(event);
                cropOverlay.hidden = false;

                try {
                    cropBox.setPointerCapture(
                        event.pointerId
                    );
                } catch (_) {}
            }
        );


        cropBox.addEventListener(
            'pointermove',
            event => {
                if (
                    !cropMode
                    || !cropStart
                ) {
                    return;
                }

                const current = cropPoint(event);
                const dx = current.x - cropStart.x;
                const dy = current.y - cropStart.y;
                const side = Math.min(
                    Math.abs(dx),
                    Math.abs(dy)
                );

                const left =
                    dx >= 0
                        ? cropStart.x
                        : cropStart.x - side;

                const top =
                    dy >= 0
                        ? cropStart.y
                        : cropStart.y - side;

                cropOverlay.style.left = left + 'px';
                cropOverlay.style.top = top + 'px';
                cropOverlay.style.width = side + 'px';
                cropOverlay.style.height = side + 'px';
            }
        );


        cropBox.addEventListener(
            'pointerup',
            event => {
                if (
                    !cropMode
                    || !cropStart
                ) {
                    return;
                }

                const end = cropPoint(event);
                const dx = end.x - cropStart.x;
                const dy = end.y - cropStart.y;
                const side = Math.min(
                    Math.abs(dx),
                    Math.abs(dy)
                );

                const left =
                    dx >= 0
                        ? cropStart.x
                        : cropStart.x - side;

                const top =
                    dy >= 0
                        ? cropStart.y
                        : cropStart.y - side;

                cropStart = null;

                if (side < 10) {
                    showSavedCrop();
                    return;
                }

                cropInputs[0].value =
                    (left / end.rect.width).toFixed(6);
                cropInputs[1].value =
                    (top / end.rect.height).toFixed(6);
                cropInputs[2].value =
                    (side / end.rect.width).toFixed(6);
                cropInputs[3].value =
                    (side / end.rect.height).toFixed(6);

                const imageId =
                    currentImageId();

                if (imageId <= 0) {
                    return;
                }

                queueRequest(
                    'save_image',
                    null,
                    {
                        image_id: imageId,
                        image_title:
                            imageTitle?.value
                            || '',
                    }
                )
                    .then(
                        () => setCropMode(false)
                    )
                    .catch(() => {});
            }
        );


        cropBox.addEventListener(
            'pointercancel',
            () => {
                cropStart = null;
                showSavedCrop();
            }
        );
    }

})();
</script>

</body>
</html>
