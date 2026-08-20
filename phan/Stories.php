<?php
// phan/Stories.php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$phanconn->set_charset('utf8mb4');

const PHAN_STORY_UPLOAD_DIR = __DIR__ . '/../uploads/phan/stories';
const PHAN_STORY_PUBLIC_DIR = '/uploads/phan/stories';
const PHAN_STORY_THUMB_DIR = __DIR__ . '/../uploads/phan/stories/thumbs';
const PHAN_STORY_MAX_IMAGE_BYTES = 12582912; // 12 MB
const PHAN_STORY_THUMB_WIDTH = 220;
const PHAN_STORY_THUMB_HEIGHT = 220;

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

function story_h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function story_exec(
    mysqli $db,
    string $sql,
    array $params = []
): mysqli_stmt {
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

function story_one(
    mysqli $db,
    string $sql,
    array $params = []
): ?array {
    $stmt = story_exec(
        $db,
        $sql,
        $params
    );

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return is_array($row)
        ? $row
        : null;
}

function story_all(
    mysqli $db,
    string $sql,
    array $params = []
): array {
    $stmt = story_exec(
        $db,
        $sql,
        $params
    );

    $rows = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    return $rows;
}

function story_json(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    header(
        'Cache-Control: no-store, max-age=0'
    );

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function story_format_datetime(?string $value): string
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

function story_format_intime_date(
    mixed $year,
    mixed $month,
    mixed $day
): string {
    if (
        $year === null
        || $year === ''
    ) {
        return '—';
    }

    $year = (int)$year;

    $month = (
        $month !== null
        && $month !== ''
    )
        ? (int)$month
        : null;

    $day = (
        $day !== null
        && $day !== ''
    )
        ? (int)$day
        : null;

    $formattedYear =
        $year < 0
            ? '-' . str_pad(
                (string)abs($year),
                4,
                '0',
                STR_PAD_LEFT
            )
            : str_pad(
                (string)$year,
                4,
                '0',
                STR_PAD_LEFT
            );

    if (
        $month !== null
        && $day !== null
    ) {
        return sprintf(
            '%02d.%02d.%s',
            $day,
            $month,
            $formattedYear
        );
    }

    if ($month !== null) {
        return sprintf(
            '%02d.%s',
            $month,
            $formattedYear
        );
    }

    return $formattedYear;
}


function story_word_count(string $text): int
{
    if (
        !preg_match_all(
            "/[\\p{L}\\p{N}]+(?:[’'’-][\\p{L}\\p{N}]+)*/u",
            $text,
            $matches
        )
    ) {
        return 0;
    }

    return count($matches[0]);
}

function story_character_count(string $text): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }

    return strlen($text);
}

function story_initial(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '?';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 1, 'UTF-8');
    }

    return substr($value, 0, 1);
}


function story_crop_value(string $key): ?float
{
    $raw = trim(
        (string)($_POST[$key] ?? '')
    );

    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }

    $value = (float)$raw;

    return (
        $value >= 0.0
        && $value <= 1.0
    )
        ? $value
        : null;
}


function story_disk_path(?string $publicPath): ?string
{
    if (
        !$publicPath
        || !str_starts_with(
            $publicPath,
            PHAN_STORY_PUBLIC_DIR . '/'
        )
    ) {
        return null;
    }

    $filename = basename($publicPath);

    if (
        $filename === ''
        || $filename === '.'
        || $filename === '..'
    ) {
        return null;
    }

    return PHAN_STORY_UPLOAD_DIR . '/' . $filename;
}

function story_delete_image(?string $publicPath): void
{
    $path = story_disk_path($publicPath);

    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

function story_cleanup_thumbs(int $storyId): void
{
    if (!is_dir(PHAN_STORY_THUMB_DIR)) {
        return;
    }

    foreach (
        glob(
            PHAN_STORY_THUMB_DIR
            . '/story_'
            . $storyId
            . '_*.jpg'
        ) ?: []
        as $path
    ) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function story_upload_image(
    array $file,
    int $storyId
): ?string {
    $error = (int)(
        $file['error']
        ?? UPLOAD_ERR_NO_FILE
    );

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

    if (
        $tmp === ''
        || !is_uploaded_file($tmp)
    ) {
        throw new RuntimeException(
            'Ungültige Upload-Datei.'
        );
    }

    if (
        $size <= 0
        || $size > PHAN_STORY_MAX_IMAGE_BYTES
    ) {
        throw new RuntimeException(
            'Das Titelbild darf maximal 12 MB groß sein.'
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
        !is_dir(PHAN_STORY_UPLOAD_DIR)
        && !mkdir(PHAN_STORY_UPLOAD_DIR, 0755, true)
        && !is_dir(PHAN_STORY_UPLOAD_DIR)
    ) {
        throw new RuntimeException(
            'Story-Upload-Verzeichnis konnte nicht erstellt werden.'
        );
    }

    if (!is_writable(PHAN_STORY_UPLOAD_DIR)) {
        throw new RuntimeException(
            'Story-Upload-Verzeichnis ist für PHP nicht beschreibbar.'
        );
    }

    $filename = sprintf(
        'story_%d_%s.%s',
        $storyId,
        bin2hex(random_bytes(8)),
        $allowed[$mime]
    );

    $target =
        PHAN_STORY_UPLOAD_DIR
        . '/'
        . $filename;

    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException(
            'Titelbild konnte nicht gespeichert werden.'
        );
    }

    @chmod($target, 0644);

    return PHAN_STORY_PUBLIC_DIR
        . '/'
        . $filename;
}

function story_make_thumb(
    int $storyId,
    string $sourcePath,
    ?float $thumbX,
    ?float $thumbY,
    ?float $thumbW,
    ?float $thumbH
): string {
    if (!extension_loaded('gd')) {
        throw new RuntimeException(
            'PHP-GD ist für Story-Thumbnails nicht installiert.'
        );
    }

    if (
        !is_dir(PHAN_STORY_THUMB_DIR)
        && !mkdir(PHAN_STORY_THUMB_DIR, 0755, true)
        && !is_dir(PHAN_STORY_THUMB_DIR)
    ) {
        throw new RuntimeException(
            'Story-Thumbnail-Verzeichnis konnte nicht erstellt werden.'
        );
    }

    $mtime = (int)@filemtime($sourcePath);

    $cropKey = implode(
        '_',
        [
            $thumbX ?? 'null',
            $thumbY ?? 'null',
            $thumbW ?? 'null',
            $thumbH ?? 'null',
        ]
    );

    $hash = substr(
        sha1(
            $mtime
            . '|'
            . PHAN_STORY_THUMB_WIDTH
            . 'x'
            . PHAN_STORY_THUMB_HEIGHT
            . '|'
            . $cropKey
        ),
        0,
        12
    );

    $target =
        PHAN_STORY_THUMB_DIR
        . '/story_'
        . $storyId
        . '_'
        . $hash
        . '.jpg';

    if (is_file($target)) {
        return $target;
    }

    $info = @getimagesize($sourcePath);

    if (!$info) {
        throw new RuntimeException(
            'Titelbild konnte nicht gelesen werden.'
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
            'Titelbild konnte nicht als Thumbnail geladen werden.'
        );
    }

    $targetRatio =
        PHAN_STORY_THUMB_WIDTH
        / PHAN_STORY_THUMB_HEIGHT;

    $hasCrop =
        $thumbX !== null
        && $thumbY !== null
        && $thumbW !== null
        && $thumbH !== null
        && $thumbW > 0
        && $thumbH > 0;

    if ($hasCrop) {
        $cropX = max(
            0,
            min(
                $srcW - 1,
                (int)round($thumbX * $srcW)
            )
        );

        $cropY = max(
            0,
            min(
                $srcH - 1,
                (int)round($thumbY * $srcH)
            )
        );

        $cropW = max(
            1,
            min(
                $srcW - $cropX,
                (int)round($thumbW * $srcW)
            )
        );

        $cropH = max(
            1,
            min(
                $srcH - $cropY,
                (int)round($thumbH * $srcH)
            )
        );

        /*
         * Der JS-Crop ist bereits im Zielverhältnis.
         * Hier wird nur gegen Rundungsfehler abgesichert.
         */
        $cropRatio = $cropW / $cropH;

        if ($cropRatio > $targetRatio) {
            $newW = max(
                1,
                (int)round(
                    $cropH * $targetRatio
                )
            );

            $cropX += (int)round(
                ($cropW - $newW) / 2
            );

            $cropW = $newW;

        } elseif ($cropRatio < $targetRatio) {
            $newH = max(
                1,
                (int)round(
                    $cropW / $targetRatio
                )
            );

            $cropY += (int)round(
                ($cropH - $newH) / 2
            );

            $cropH = $newH;
        }

    } else {
        /*
         * Ohne manuell gesetzten Ausschnitt:
         * weiterhin automatischer Center-Crop.
         */
        $sourceRatio =
            $srcW / $srcH;

        if ($sourceRatio > $targetRatio) {
            $cropH = $srcH;
            $cropW = (int)round(
                $srcH * $targetRatio
            );
            $cropX = (int)round(
                ($srcW - $cropW) / 2
            );
            $cropY = 0;
        } else {
            $cropW = $srcW;
            $cropH = (int)round(
                $srcW / $targetRatio
            );
            $cropX = 0;
            $cropY = (int)round(
                ($srcH - $cropH) / 2
            );
        }
    }

    $dst = imagecreatetruecolor(
        PHAN_STORY_THUMB_WIDTH,
        PHAN_STORY_THUMB_HEIGHT
    );

    imagecopyresampled(
        $dst,
        $src,
        0,
        0,
        $cropX,
        $cropY,
        PHAN_STORY_THUMB_WIDTH,
        PHAN_STORY_THUMB_HEIGHT,
        $cropW,
        $cropH
    );

    if (!imagejpeg($dst, $target, 84)) {
        imagedestroy($src);
        imagedestroy($dst);

        throw new RuntimeException(
            'Story-Thumbnail konnte nicht gespeichert werden.'
        );
    }

    imagedestroy($src);
    imagedestroy($dst);

    @chmod($target, 0644);

    foreach (
        glob(
            PHAN_STORY_THUMB_DIR
            . '/story_'
            . $storyId
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


function story_cleanup_collection_thumbs(
    int $collectionId
): void {
    if (!is_dir(PHAN_STORY_THUMB_DIR)) {
        return;
    }

    foreach (
        glob(
            PHAN_STORY_THUMB_DIR
            . '/collection_'
            . $collectionId
            . '_*.jpg'
        ) ?: []
        as $path
    ) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}


function story_upload_collection_image(
    array $file,
    int $collectionId
): ?string {
    $error = (int)(
        $file['error']
        ?? UPLOAD_ERR_NO_FILE
    );

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

    if (
        $tmp === ''
        || !is_uploaded_file($tmp)
    ) {
        throw new RuntimeException(
            'Ungültige Upload-Datei.'
        );
    }

    if (
        $size <= 0
        || $size > PHAN_STORY_MAX_IMAGE_BYTES
    ) {
        throw new RuntimeException(
            'Das Titelbild darf maximal 12 MB groß sein.'
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
        !is_dir(PHAN_STORY_UPLOAD_DIR)
        && !mkdir(PHAN_STORY_UPLOAD_DIR, 0755, true)
        && !is_dir(PHAN_STORY_UPLOAD_DIR)
    ) {
        throw new RuntimeException(
            'Story-Upload-Verzeichnis konnte nicht erstellt werden.'
        );
    }

    if (!is_writable(PHAN_STORY_UPLOAD_DIR)) {
        throw new RuntimeException(
            'Story-Upload-Verzeichnis ist für PHP nicht beschreibbar.'
        );
    }

    $filename = sprintf(
        'collection_%d_%s.%s',
        $collectionId,
        bin2hex(random_bytes(8)),
        $allowed[$mime]
    );

    $target =
        PHAN_STORY_UPLOAD_DIR
        . '/'
        . $filename;

    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException(
            'Titelbild der Gesamtstory konnte nicht gespeichert werden.'
        );
    }

    @chmod($target, 0644);

    return PHAN_STORY_PUBLIC_DIR
        . '/'
        . $filename;
}


function story_make_collection_thumb(
    int $collectionId,
    string $sourcePath,
    ?float $thumbX,
    ?float $thumbY,
    ?float $thumbW,
    ?float $thumbH
): string {
    if (!extension_loaded('gd')) {
        throw new RuntimeException(
            'PHP-GD ist für Gesamtstory-Thumbnails nicht installiert.'
        );
    }

    if (
        !is_dir(PHAN_STORY_THUMB_DIR)
        && !mkdir(PHAN_STORY_THUMB_DIR, 0755, true)
        && !is_dir(PHAN_STORY_THUMB_DIR)
    ) {
        throw new RuntimeException(
            'Story-Thumbnail-Verzeichnis konnte nicht erstellt werden.'
        );
    }

    $mtime = (int)@filemtime($sourcePath);

    $cropKey = implode(
        '_',
        [
            $thumbX ?? 'null',
            $thumbY ?? 'null',
            $thumbW ?? 'null',
            $thumbH ?? 'null',
        ]
    );

    $hash = substr(
        sha1(
            $mtime
            . '|collection|'
            . PHAN_STORY_THUMB_WIDTH
            . 'x'
            . PHAN_STORY_THUMB_HEIGHT
            . '|'
            . $cropKey
        ),
        0,
        12
    );

    $target =
        PHAN_STORY_THUMB_DIR
        . '/collection_'
        . $collectionId
        . '_'
        . $hash
        . '.jpg';

    if (is_file($target)) {
        return $target;
    }

    $info = @getimagesize($sourcePath);

    if (!$info) {
        throw new RuntimeException(
            'Titelbild der Gesamtstory konnte nicht gelesen werden.'
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
            'Titelbild der Gesamtstory konnte nicht als Thumbnail geladen werden.'
        );
    }

    $targetRatio =
        PHAN_STORY_THUMB_WIDTH
        / PHAN_STORY_THUMB_HEIGHT;

    $hasCrop =
        $thumbX !== null
        && $thumbY !== null
        && $thumbW !== null
        && $thumbH !== null
        && $thumbW > 0
        && $thumbH > 0;

    if ($hasCrop) {
        $cropX = max(
            0,
            min(
                $srcW - 1,
                (int)round($thumbX * $srcW)
            )
        );

        $cropY = max(
            0,
            min(
                $srcH - 1,
                (int)round($thumbY * $srcH)
            )
        );

        $cropW = max(
            1,
            min(
                $srcW - $cropX,
                (int)round($thumbW * $srcW)
            )
        );

        $cropH = max(
            1,
            min(
                $srcH - $cropY,
                (int)round($thumbH * $srcH)
            )
        );

        $cropRatio = $cropW / $cropH;

        if ($cropRatio > $targetRatio) {
            $newW = max(
                1,
                (int)round(
                    $cropH * $targetRatio
                )
            );

            $cropX += (int)round(
                ($cropW - $newW) / 2
            );

            $cropW = $newW;

        } elseif ($cropRatio < $targetRatio) {
            $newH = max(
                1,
                (int)round(
                    $cropW / $targetRatio
                )
            );

            $cropY += (int)round(
                ($cropH - $newH) / 2
            );

            $cropH = $newH;
        }

    } else {
        $sourceRatio =
            $srcW / $srcH;

        if ($sourceRatio > $targetRatio) {
            $cropH = $srcH;
            $cropW = (int)round(
                $srcH * $targetRatio
            );
            $cropX = (int)round(
                ($srcW - $cropW) / 2
            );
            $cropY = 0;
        } else {
            $cropW = $srcW;
            $cropH = (int)round(
                $srcW / $targetRatio
            );
            $cropX = 0;
            $cropY = (int)round(
                ($srcH - $cropH) / 2
            );
        }
    }

    $dst = imagecreatetruecolor(
        PHAN_STORY_THUMB_WIDTH,
        PHAN_STORY_THUMB_HEIGHT
    );

    imagecopyresampled(
        $dst,
        $src,
        0,
        0,
        $cropX,
        $cropY,
        PHAN_STORY_THUMB_WIDTH,
        PHAN_STORY_THUMB_HEIGHT,
        $cropW,
        $cropH
    );

    if (!imagejpeg($dst, $target, 84)) {
        imagedestroy($src);
        imagedestroy($dst);

        throw new RuntimeException(
            'Gesamtstory-Thumbnail konnte nicht gespeichert werden.'
        );
    }

    imagedestroy($src);
    imagedestroy($dst);

    @chmod($target, 0644);

    foreach (
        glob(
            PHAN_STORY_THUMB_DIR
            . '/collection_'
            . $collectionId
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

function story_collection_next_order(
    mysqli $db,
    int $collectionId
): int {
    if ($collectionId <= 0) {
        return 1;
    }

    $row = story_one(
        $db,
        'SELECT COALESCE(MAX(chapter_order), 0) AS max_order
         FROM stories
         WHERE collection_id = ?',
        [$collectionId]
    );

    return max(
        1,
        (int)($row['max_order'] ?? 0) + 1
    );
}


function story_normalize_collection_order(
    mysqli $db,
    int $collectionId
): void {
    if ($collectionId <= 0) {
        return;
    }

    $rows = story_all(
        $db,
        'SELECT id
         FROM stories
         WHERE collection_id = ?
         ORDER BY
            CASE
                WHEN chapter_order IS NULL
                     OR chapter_order <= 0
                THEN 1
                ELSE 0
            END,
            chapter_order ASC,
            intime_year ASC,
            COALESCE(intime_month, 0) ASC,
            COALESCE(intime_day, 0) ASC,
            id ASC',
        [$collectionId]
    );

    foreach ($rows as $index => $row) {
        story_exec(
            $db,
            'UPDATE stories
             SET chapter_order = ?
             WHERE id = ?',
            [
                $index + 1,
                (int)$row['id'],
            ]
        )->close();
    }
}


function story_collection_ordered_story_ids(
    mysqli $db,
    int $collectionId
): array {
    if ($collectionId <= 0) {
        return [];
    }

    return array_map(
        static fn(array $row): int =>
            (int)$row['id'],
        story_all(
            $db,
            'SELECT id
             FROM stories
             WHERE collection_id = ?
             ORDER BY
                chapter_order ASC,
                id ASC',
            [$collectionId]
        )
    );
}


function story_place_chapter(
    mysqli $db,
    int $collectionId,
    int $storyId,
    int $requestedOrder
): void {
    if (
        $collectionId <= 0
        || $storyId <= 0
    ) {
        return;
    }

    $orderedIds =
        story_collection_ordered_story_ids(
            $db,
            $collectionId
        );

    $orderedIds = array_values(
        array_filter(
            $orderedIds,
            static fn(int $id): bool =>
                $id !== $storyId
        )
    );

    $targetIndex = max(
        0,
        min(
            count($orderedIds),
            max(1, $requestedOrder) - 1
        )
    );

    array_splice(
        $orderedIds,
        $targetIndex,
        0,
        [$storyId]
    );

    foreach (
        $orderedIds
        as $index => $orderedId
    ) {
        story_exec(
            $db,
            'UPDATE stories
             SET chapter_order = ?
             WHERE id = ?
               AND collection_id = ?',
            [
                $index + 1,
                $orderedId,
                $collectionId,
            ]
        )->close();
    }
}


function story_collection_date_label(
    array $chapters
): string {
    $dated = array_values(
        array_filter(
            $chapters,
            static fn(array $chapter): bool =>
                $chapter['intime_year'] !== null
                && $chapter['intime_year'] !== ''
        )
    );

    if (!$dated) {
        return '—';
    }

    usort(
        $dated,
        static function (
            array $a,
            array $b
        ): int {
            $aKey = [
                (int)$a['intime_year'],
                (int)($a['intime_month'] ?? 0),
                (int)($a['intime_day'] ?? 0),
                (int)$a['id'],
            ];

            $bKey = [
                (int)$b['intime_year'],
                (int)($b['intime_month'] ?? 0),
                (int)($b['intime_day'] ?? 0),
                (int)$b['id'],
            ];

            return $aKey <=> $bKey;
        }
    );

    $first = $dated[0];
    $last = $dated[count($dated) - 1];

    $firstLabel =
        story_format_intime_date(
            $first['intime_year'] ?? null,
            $first['intime_month'] ?? null,
            $first['intime_day'] ?? null
        );

    $lastLabel =
        story_format_intime_date(
            $last['intime_year'] ?? null,
            $last['intime_month'] ?? null,
            $last['intime_day'] ?? null
        );

    return $firstLabel === $lastLabel
        ? $firstLabel
        : $firstLabel . ' – ' . $lastLabel;
}


function story_sync_characters(
    mysqli $db,
    int $storyId,
    array $charIds
): void {
    $normalized = [];

    foreach ($charIds as $rawId) {
        $id = max(0, (int)$rawId);

        if ($id > 0) {
            $normalized[$id] = $id;
        }
    }

    $charIds = array_values($normalized);

    if ($charIds) {
        $placeholders = implode(
            ',',
            array_fill(0, count($charIds), '?')
        );

        $rows = story_all(
            $db,
            "SELECT id
             FROM chars
             WHERE id IN ($placeholders)",
            $charIds
        );

        $validIds = array_map(
            static fn(array $row): int =>
                (int)$row['id'],
            $rows
        );

        sort($validIds);

        $wanted = $charIds;
        sort($wanted);

        if ($validIds !== $wanted) {
            throw new RuntimeException(
                'Mindestens ein ausgewählter Charakter existiert nicht mehr.'
            );
        }
    }

    story_exec(
        $db,
        'DELETE FROM story_characters
         WHERE story_id = ?',
        [$storyId]
    )->close();

    foreach ($charIds as $sortOrder => $charId) {
        story_exec(
            $db,
            'INSERT INTO story_characters (
                story_id,
                char_id,
                sort_order
             ) VALUES (?, ?, ?)',
            [
                $storyId,
                $charId,
                $sortOrder,
            ]
        )->close();
    }
}


/* =========================================================
 * Geschütztes Titelbild einer Gesamtstory
 *
 *   /phan/stories?collection_image=<ID>
 *   /phan/stories?collection_thumb=<ID>
 * ========================================================= */

if (
    isset($_GET['collection_image'])
    || isset($_GET['collection_thumb'])
) {
    $isCollectionThumb =
        isset($_GET['collection_thumb']);

    $collectionImageId = max(
        0,
        (int)(
            $isCollectionThumb
                ? $_GET['collection_thumb']
                : $_GET['collection_image']
        )
    );

    if ($collectionImageId <= 0) {
        http_response_code(404);
        exit;
    }

    $collectionImageRow = story_one(
        $phanconn,
        'SELECT
            image_path,
            thumb_x,
            thumb_y,
            thumb_w,
            thumb_h
         FROM story_collections
         WHERE id = ?',
        [$collectionImageId]
    );

    $collectionSourcePath = story_disk_path(
        $collectionImageRow['image_path'] ?? null
    );

    if (
        $collectionSourcePath === null
        || !is_file($collectionSourcePath)
        || !is_readable($collectionSourcePath)
    ) {
        http_response_code(404);
        exit;
    }

    try {
        if ($isCollectionThumb) {
            $collectionDiskPath =
                story_make_collection_thumb(
                    $collectionImageId,
                    $collectionSourcePath,
                    isset($collectionImageRow['thumb_x'])
                        && $collectionImageRow['thumb_x'] !== null
                        ? (float)$collectionImageRow['thumb_x']
                        : null,
                    isset($collectionImageRow['thumb_y'])
                        && $collectionImageRow['thumb_y'] !== null
                        ? (float)$collectionImageRow['thumb_y']
                        : null,
                    isset($collectionImageRow['thumb_w'])
                        && $collectionImageRow['thumb_w'] !== null
                        ? (float)$collectionImageRow['thumb_w']
                        : null,
                    isset($collectionImageRow['thumb_h'])
                        && $collectionImageRow['thumb_h'] !== null
                        ? (float)$collectionImageRow['thumb_h']
                        : null
                );

            $collectionMime =
                'image/jpeg';
        } else {
            $collectionDiskPath =
                $collectionSourcePath;

            $finfo = new finfo(
                FILEINFO_MIME_TYPE
            );

            $collectionMime =
                (string)$finfo->file(
                    $collectionDiskPath
                );
        }
    } catch (Throwable) {
        http_response_code(500);
        exit;
    }

    if (
        !in_array(
            $collectionMime,
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

    header(
        'Content-Type: '
        . $collectionMime
    );

    header(
        'Content-Length: '
        . (string)filesize(
            $collectionDiskPath
        )
    );

    header(
        'Cache-Control: private, '
        . (
            $isCollectionThumb
                ? 'max-age=86400'
                : 'no-store, max-age=0'
        )
    );

    header(
        'X-Content-Type-Options: nosniff'
    );

    readfile($collectionDiskPath);
    exit;
}


/* =========================================================
 * Geschütztes Story-Titelbild / Thumbnail
 *
 * /uploads bleibt direkt gesperrt.
 * ========================================================= */

if (
    isset($_GET['image'])
    || isset($_GET['thumb'])
) {
    $isThumb = isset($_GET['thumb']);

    $storyImageId = max(
        0,
        (int)(
            $isThumb
                ? $_GET['thumb']
                : $_GET['image']
        )
    );

    if ($storyImageId <= 0) {
        http_response_code(404);
        exit;
    }

    $imageRow = story_one(
        $phanconn,
        'SELECT
            image_path,
            thumb_x,
            thumb_y,
            thumb_w,
            thumb_h
         FROM stories
         WHERE id = ?',
        [$storyImageId]
    );

    $sourcePath = story_disk_path(
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
            $diskPath = story_make_thumb(
                $storyImageId,
                $sourcePath,
                isset($imageRow['thumb_x'])
                    && $imageRow['thumb_x'] !== null
                    ? (float)$imageRow['thumb_x']
                    : null,
                isset($imageRow['thumb_y'])
                    && $imageRow['thumb_y'] !== null
                    ? (float)$imageRow['thumb_y']
                    : null,
                isset($imageRow['thumb_w'])
                    && $imageRow['thumb_w'] !== null
                    ? (float)$imageRow['thumb_w']
                    : null,
                isset($imageRow['thumb_h'])
                    && $imageRow['thumb_h'] !== null
                    ? (float)$imageRow['thumb_h']
                    : null
            );

            $mime = 'image/jpeg';
        } else {
            $diskPath = $sourcePath;

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file(
                $diskPath
            );
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
    header(
        'Content-Length: '
        . (string)filesize($diskPath)
    );

    header(
        'Cache-Control: private, '
        . (
            $isThumb
                ? 'max-age=86400'
                : 'no-store, max-age=0'
        )
    );

    header(
        'X-Content-Type-Options: nosniff'
    );

    readfile($diskPath);
    exit;
}


/* =========================================================
 * POST / AJAX
 * ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        $action = (string)(
            $_POST['action']
            ?? 'save'
        );

        $id = max(
            0,
            (int)($_POST['id'] ?? 0)
        );

        /* -------------------------------------------------
         * Gesamtstory speichern / anlegen
         * ------------------------------------------------- */

        if ($action === 'save_collection') {
            $collectionId = max(
                0,
                (int)($_POST['collection_id'] ?? 0)
            );

            $collectionTitle = trim(
                (string)(
                    $_POST['collection_title']
                    ?? ''
                )
            );

            if ($collectionId > 0) {
                $existingCollection = story_one(
                    $phanconn,
                    'SELECT id, image_path
                     FROM story_collections
                     WHERE id = ?',
                    [$collectionId]
                );

                if (!$existingCollection) {
                    throw new RuntimeException(
                        'Gesamtstory existiert nicht mehr.'
                    );
                }

                story_exec(
                    $phanconn,
                    'UPDATE story_collections
                     SET title = ?
                     WHERE id = ?',
                    [
                        $collectionTitle,
                        $collectionId,
                    ]
                )->close();

            } else {
                $stmt = story_exec(
                    $phanconn,
                    'INSERT INTO story_collections (
                        title
                     ) VALUES (?)',
                    [$collectionTitle]
                );

                $collectionId =
                    (int)$stmt->insert_id;

                $stmt->close();

                $existingCollection = [
                    'image_path' => null,
                ];
            }

            $collectionImageChanged =
                false;

            $newCollectionImage =
                isset(
                    $_FILES[
                        'collection_cover_image'
                    ]
                )
                    ? story_upload_collection_image(
                        $_FILES[
                            'collection_cover_image'
                        ],
                        $collectionId
                    )
                    : null;

            if ($newCollectionImage !== null) {
                $oldCollectionImage =
                    story_one(
                        $phanconn,
                        'SELECT image_path
                         FROM story_collections
                         WHERE id = ?',
                        [$collectionId]
                    );

                story_exec(
                    $phanconn,
                    'UPDATE story_collections
                     SET
                        image_path = ?,
                        thumb_x = NULL,
                        thumb_y = NULL,
                        thumb_w = NULL,
                        thumb_h = NULL
                     WHERE id = ?',
                    [
                        $newCollectionImage,
                        $collectionId,
                    ]
                )->close();

                story_delete_image(
                    $oldCollectionImage[
                        'image_path'
                    ] ?? null
                );

                story_cleanup_collection_thumbs(
                    $collectionId
                );

                $collectionImageChanged =
                    true;
            }

            $savedCollection = story_one(
                $phanconn,
                'SELECT updated_at
                 FROM story_collections
                 WHERE id = ?',
                [$collectionId]
            );

            story_json([
                'ok' => true,
                'collection_id' =>
                    $collectionId,
                'updated_at' =>
                    story_format_datetime(
                        $savedCollection[
                            'updated_at'
                        ] ?? null
                    ),
                'image_changed' =>
                    $collectionImageChanged,
            ]);
        }


        /* -------------------------------------------------
         * Thumbnail-Ausschnitt einer Gesamtstory speichern
         * ------------------------------------------------- */

        if ($action === 'save_collection_crop') {
            $collectionId = max(
                0,
                (int)($_POST['collection_id'] ?? 0)
            );

            if ($collectionId <= 0) {
                throw new RuntimeException(
                    'Ungültige Gesamtstory.'
                );
            }

            $row = story_one(
                $phanconn,
                'SELECT image_path
                 FROM story_collections
                 WHERE id = ?',
                [$collectionId]
            );

            if (!$row || empty($row['image_path'])) {
                throw new RuntimeException(
                    'Die Gesamtstory hat kein Titelbild.'
                );
            }

            $thumbX =
                story_crop_value('thumb_x');

            $thumbY =
                story_crop_value('thumb_y');

            $thumbW =
                story_crop_value('thumb_w');

            $thumbH =
                story_crop_value('thumb_h');

            $validCrop =
                $thumbX !== null
                && $thumbY !== null
                && $thumbW !== null
                && $thumbH !== null
                && $thumbW > 0.01
                && $thumbH > 0.01
                && ($thumbX + $thumbW) <= 1.0001
                && ($thumbY + $thumbH) <= 1.0001;

            if (!$validCrop) {
                throw new RuntimeException(
                    'Ungültiger Thumbnail-Ausschnitt.'
                );
            }

            story_exec(
                $phanconn,
                'UPDATE story_collections
                 SET
                    thumb_x = ?,
                    thumb_y = ?,
                    thumb_w = ?,
                    thumb_h = ?
                 WHERE id = ?',
                [
                    $thumbX,
                    $thumbY,
                    $thumbW,
                    $thumbH,
                    $collectionId,
                ]
            )->close();

            story_cleanup_collection_thumbs(
                $collectionId
            );

            $saved = story_one(
                $phanconn,
                'SELECT updated_at
                 FROM story_collections
                 WHERE id = ?',
                [$collectionId]
            );

            story_json([
                'ok' => true,
                'collection_id' => $collectionId,
                'crop_saved' => true,
                'updated_at' => story_format_datetime(
                    $saved['updated_at'] ?? null
                ),
            ]);
        }


        /* -------------------------------------------------
         * Titelbild einer Gesamtstory entfernen
         * ------------------------------------------------- */

        if (
            $action
            === 'remove_collection_image'
        ) {
            $collectionId = max(
                0,
                (int)($_POST['collection_id'] ?? 0)
            );

            if ($collectionId <= 0) {
                throw new RuntimeException(
                    'Ungültige Gesamtstory.'
                );
            }

            $collectionRow = story_one(
                $phanconn,
                'SELECT image_path
                 FROM story_collections
                 WHERE id = ?',
                [$collectionId]
            );

            if (!$collectionRow) {
                throw new RuntimeException(
                    'Gesamtstory existiert nicht mehr.'
                );
            }

            story_exec(
                $phanconn,
                'UPDATE story_collections
                 SET
                    image_path = NULL,
                    thumb_x = NULL,
                    thumb_y = NULL,
                    thumb_w = NULL,
                    thumb_h = NULL
                 WHERE id = ?',
                [$collectionId]
            )->close();

            story_delete_image(
                $collectionRow['image_path']
                ?? null
            );

            story_cleanup_collection_thumbs(
                $collectionId
            );

            story_json([
                'ok' => true,
                'collection_id' =>
                    $collectionId,
                'image_removed' => true,
            ]);
        }


        /* -------------------------------------------------
         * Gesamtstory löschen
         *
         * Kapitel bleiben erhalten und werden standalone.
         * ------------------------------------------------- */

        if ($action === 'delete_collection') {
            $collectionId = max(
                0,
                (int)($_POST['collection_id'] ?? 0)
            );

            if ($collectionId <= 0) {
                throw new RuntimeException(
                    'Ungültige Gesamtstory.'
                );
            }

            $collectionRow = story_one(
                $phanconn,
                'SELECT image_path
                 FROM story_collections
                 WHERE id = ?',
                [$collectionId]
            );

            if (!$collectionRow) {
                throw new RuntimeException(
                    'Gesamtstory existiert nicht mehr.'
                );
            }

            $phanconn->begin_transaction();

            try {
                story_exec(
                    $phanconn,
                    'UPDATE stories
                     SET
                        collection_id = NULL,
                        chapter_order = NULL
                     WHERE collection_id = ?',
                    [$collectionId]
                )->close();

                story_exec(
                    $phanconn,
                    'DELETE FROM story_collections
                     WHERE id = ?',
                    [$collectionId]
                )->close();

                $phanconn->commit();

            } catch (Throwable $e) {
                $phanconn->rollback();
                throw $e;
            }

            story_delete_image(
                $collectionRow['image_path']
                ?? null
            );

            story_cleanup_collection_thumbs(
                $collectionId
            );

            story_json([
                'ok' => true,
                'deleted' => true,
            ]);
        }


        /* -------------------------------------------------
         * Bestehende Story als Kapitel hinzufügen
         * ------------------------------------------------- */

        if ($action === 'collection_add_story') {
            $collectionId = max(
                0,
                (int)($_POST['collection_id'] ?? 0)
            );

            $storyToAddId = max(
                0,
                (int)($_POST['story_id'] ?? 0)
            );

            if (
                $collectionId <= 0
                || $storyToAddId <= 0
            ) {
                throw new RuntimeException(
                    'Ungültige Kapitel-Zuordnung.'
                );
            }

            if (
                !story_one(
                    $phanconn,
                    'SELECT id
                     FROM story_collections
                     WHERE id = ?',
                    [$collectionId]
                )
            ) {
                throw new RuntimeException(
                    'Gesamtstory existiert nicht mehr.'
                );
            }

            $storyToAdd = story_one(
                $phanconn,
                'SELECT id, collection_id
                 FROM stories
                 WHERE id = ?',
                [$storyToAddId]
            );

            if (!$storyToAdd) {
                throw new RuntimeException(
                    'Story existiert nicht mehr.'
                );
            }

            $oldCollectionId = max(
                0,
                (int)(
                    $storyToAdd['collection_id']
                    ?? 0
                )
            );

            $nextOrder =
                story_collection_next_order(
                    $phanconn,
                    $collectionId
                );

            story_exec(
                $phanconn,
                'UPDATE stories
                 SET
                    collection_id = ?,
                    chapter_order = ?
                 WHERE id = ?',
                [
                    $collectionId,
                    $nextOrder,
                    $storyToAddId,
                ]
            )->close();

            if (
                $oldCollectionId > 0
                && $oldCollectionId
                    !== $collectionId
            ) {
                story_normalize_collection_order(
                    $phanconn,
                    $oldCollectionId
                );
            }

            story_normalize_collection_order(
                $phanconn,
                $collectionId
            );

            story_json([
                'ok' => true,
                'collection_id' =>
                    $collectionId,
                'story_id' =>
                    $storyToAddId,
            ]);
        }


        /* -------------------------------------------------
         * Kapitel aus Gesamtstory lösen
         * ------------------------------------------------- */

        if (
            $action
            === 'collection_remove_story'
        ) {
            $collectionId = max(
                0,
                (int)($_POST['collection_id'] ?? 0)
            );

            $storyToRemoveId = max(
                0,
                (int)($_POST['story_id'] ?? 0)
            );

            if (
                $collectionId <= 0
                || $storyToRemoveId <= 0
            ) {
                throw new RuntimeException(
                    'Ungültige Kapitel-Zuordnung.'
                );
            }

            $chapter = story_one(
                $phanconn,
                'SELECT id
                 FROM stories
                 WHERE id = ?
                   AND collection_id = ?',
                [
                    $storyToRemoveId,
                    $collectionId,
                ]
            );

            if (!$chapter) {
                throw new RuntimeException(
                    'Kapitel gehört nicht zu dieser Gesamtstory.'
                );
            }

            story_exec(
                $phanconn,
                'UPDATE stories
                 SET
                    collection_id = NULL,
                    chapter_order = NULL
                 WHERE id = ?',
                [$storyToRemoveId]
            )->close();

            story_normalize_collection_order(
                $phanconn,
                $collectionId
            );

            story_json([
                'ok' => true,
                'collection_id' =>
                    $collectionId,
                'story_id' =>
                    $storyToRemoveId,
            ]);
        }


        /* -------------------------------------------------
         * Kapitel nach oben / unten verschieben
         * ------------------------------------------------- */

        if ($action === 'collection_move_story') {
            $collectionId = max(
                0,
                (int)($_POST['collection_id'] ?? 0)
            );

            $storyToMoveId = max(
                0,
                (int)($_POST['story_id'] ?? 0)
            );

            $direction = (string)(
                $_POST['direction']
                ?? ''
            );

            if (
                $collectionId <= 0
                || $storyToMoveId <= 0
                || !in_array(
                    $direction,
                    ['up', 'down'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Ungültige Kapitel-Sortierung.'
                );
            }

            story_normalize_collection_order(
                $phanconn,
                $collectionId
            );

            $orderedIds =
                story_collection_ordered_story_ids(
                    $phanconn,
                    $collectionId
                );

            $index = array_search(
                $storyToMoveId,
                $orderedIds,
                true
            );

            if ($index === false) {
                throw new RuntimeException(
                    'Kapitel gehört nicht zu dieser Gesamtstory.'
                );
            }

            $targetIndex =
                $direction === 'up'
                    ? $index - 1
                    : $index + 1;

            if (
                $targetIndex >= 0
                && $targetIndex
                    < count($orderedIds)
            ) {
                [
                    $orderedIds[$index],
                    $orderedIds[$targetIndex],
                ] = [
                    $orderedIds[$targetIndex],
                    $orderedIds[$index],
                ];

                foreach (
                    $orderedIds
                    as $orderIndex => $orderedId
                ) {
                    story_exec(
                        $phanconn,
                        'UPDATE stories
                         SET chapter_order = ?
                         WHERE id = ?
                           AND collection_id = ?',
                        [
                            $orderIndex + 1,
                            $orderedId,
                            $collectionId,
                        ]
                    )->close();
                }
            }

            story_json([
                'ok' => true,
                'collection_id' =>
                    $collectionId,
                'story_id' =>
                    $storyToMoveId,
            ]);
        }


        if ($action === 'delete') {
            if ($id <= 0) {
                throw new RuntimeException(
                    'Ungültige Story.'
                );
            }

            $row = story_one(
                $phanconn,
                'SELECT
                    image_path,
                    collection_id
                 FROM stories
                 WHERE id = ?',
                [$id]
            );

            $oldCollectionId = max(
                0,
                (int)(
                    $row['collection_id']
                    ?? 0
                )
            );

            story_exec(
                $phanconn,
                'DELETE FROM stories
                 WHERE id = ?',
                [$id]
            )->close();

            if ($oldCollectionId > 0) {
                story_normalize_collection_order(
                    $phanconn,
                    $oldCollectionId
                );
            }

            story_delete_image(
                $row['image_path'] ?? null
            );

            story_cleanup_thumbs($id);

            story_json([
                'ok' => true,
                'deleted' => true,
            ]);
        }

        if ($action === 'remove_image') {
            if ($id <= 0) {
                throw new RuntimeException(
                    'Ungültige Story.'
                );
            }

            $row = story_one(
                $phanconn,
                'SELECT image_path
                 FROM stories
                 WHERE id = ?',
                [$id]
            );

            if (!$row) {
                throw new RuntimeException(
                    'Story existiert nicht mehr.'
                );
            }

            story_exec(
                $phanconn,
                'UPDATE stories
                 SET
                    image_path = NULL,
                    thumb_x = NULL,
                    thumb_y = NULL,
                    thumb_w = NULL,
                    thumb_h = NULL
                 WHERE id = ?',
                [$id]
            )->close();

            story_delete_image(
                $row['image_path'] ?? null
            );

            story_cleanup_thumbs($id);

            story_json([
                'ok' => true,
                'id' => $id,
                'image_removed' => true,
            ]);
        }

        if ($action === 'save_crop') {
            if ($id <= 0) {
                throw new RuntimeException(
                    'Story wurde noch nicht angelegt.'
                );
            }

            $row = story_one(
                $phanconn,
                'SELECT image_path
                 FROM stories
                 WHERE id = ?',
                [$id]
            );

            if (!$row || empty($row['image_path'])) {
                throw new RuntimeException(
                    'Die Story hat kein Titelbild.'
                );
            }

            $thumbX =
                story_crop_value('thumb_x');

            $thumbY =
                story_crop_value('thumb_y');

            $thumbW =
                story_crop_value('thumb_w');

            $thumbH =
                story_crop_value('thumb_h');

            $validCrop =
                $thumbX !== null
                && $thumbY !== null
                && $thumbW !== null
                && $thumbH !== null
                && $thumbW > 0.01
                && $thumbH > 0.01
                && ($thumbX + $thumbW) <= 1.0001
                && ($thumbY + $thumbH) <= 1.0001;

            if (!$validCrop) {
                throw new RuntimeException(
                    'Ungültiger Thumbnail-Ausschnitt.'
                );
            }

            story_exec(
                $phanconn,
                'UPDATE stories
                 SET
                    thumb_x = ?,
                    thumb_y = ?,
                    thumb_w = ?,
                    thumb_h = ?
                 WHERE id = ?',
                [
                    $thumbX,
                    $thumbY,
                    $thumbW,
                    $thumbH,
                    $id,
                ]
            )->close();

            story_cleanup_thumbs($id);

            $saved = story_one(
                $phanconn,
                'SELECT updated_at
                 FROM stories
                 WHERE id = ?',
                [$id]
            );

            story_json([
                'ok' => true,
                'id' => $id,
                'crop_saved' => true,
                'updated_at' => story_format_datetime(
                    $saved['updated_at'] ?? null
                ),
            ]);
        }

        if ($action !== 'save') {
            throw new RuntimeException(
                'Unbekannte Aktion.'
            );
        }

        $title = trim(
            (string)($_POST['title'] ?? '')
        );

        $regionId = max(
            0,
            (int)($_POST['region_id'] ?? 0)
        );

        $intimeYearRaw = trim(
            (string)($_POST['intime_year'] ?? '')
        );

        $intimeMonthRaw = trim(
            (string)($_POST['intime_month'] ?? '')
        );

        $intimeDayRaw = trim(
            (string)($_POST['intime_day'] ?? '')
        );

        if (
            $intimeYearRaw !== ''
            && !preg_match(
                '/^-?\\d+$/',
                $intimeYearRaw
            )
        ) {
            throw new RuntimeException(
                'Ungültiges In-Time-Jahr.'
            );
        }

        $intimeYear =
            $intimeYearRaw === ''
                ? null
                : (int)$intimeYearRaw;

        $intimeMonth =
            $intimeMonthRaw === ''
                ? null
                : (int)$intimeMonthRaw;

        $intimeDay =
            $intimeDayRaw === ''
                ? null
                : (int)$intimeDayRaw;

        if (
            $intimeMonth !== null
            && (
                $intimeMonth < 1
                || $intimeMonth > 12
            )
        ) {
            throw new RuntimeException(
                'Ungültiger In-Time-Monat.'
            );
        }

        if (
            $intimeDay !== null
            && (
                $intimeDay < 1
                || $intimeDay > 31
            )
        ) {
            throw new RuntimeException(
                'Ungültiger In-Time-Tag.'
            );
        }

        /*
         * Monat/Tag ohne Jahr ergeben für die eigene
         * Zeitrechnung keinen eindeutigen Zeitpunkt.
         */
        if ($intimeYear === null) {
            $intimeMonth = null;
            $intimeDay = null;
        }

        if ($intimeMonth === null) {
            $intimeDay = null;
        }

        $content = (string)(
            $_POST['content']
            ?? ''
        );

        $imagePosition = (string)(
            $_POST['image_position']
            ?? 'start'
        );

        if (
            !in_array(
                $imagePosition,
                [
                    'start',
                    'background',
                    'end',
                ],
                true
            )
        ) {
            $imagePosition = 'start';
        }

        $charIdsRaw = $_POST['char_ids'] ?? [];

        if (!is_array($charIdsRaw)) {
            $charIdsRaw = [$charIdsRaw];
        }

        $narratorCharId = max(
            0,
            (int)($_POST['narrator_char_id'] ?? 0)
        );

        $collectionId = max(
            0,
            (int)($_POST['collection_id'] ?? 0)
        );

        $chapterOrderRaw = trim(
            (string)($_POST['chapter_order'] ?? '')
        );

        $chapterOrder =
            $chapterOrderRaw === ''
                ? 0
                : max(
                    0,
                    (int)$chapterOrderRaw
                );

        $normalizedStoryCharIds = [];

        foreach ($charIdsRaw as $rawCharId) {
            $charId = max(
                0,
                (int)$rawCharId
            );

            if ($charId > 0) {
                $normalizedStoryCharIds[$charId] =
                    $charId;
            }
        }

        $charIdsRaw =
            array_values(
                $normalizedStoryCharIds
            );

        if (
            $narratorCharId > 0
            && !in_array(
                $narratorCharId,
                $charIdsRaw,
                true
            )
        ) {
            /*
             * Erzähler darf ausschließlich einer der
             * beteiligten Charaktere sein.
             */
            $narratorCharId = 0;
        }


        if ($regionId > 0) {
            $regionExists = story_one(
                $phanconn,
                'SELECT id
                 FROM regions
                 WHERE id = ?',
                [$regionId]
            );

            if (!$regionExists) {
                throw new RuntimeException(
                    'Die ausgewählte Region existiert nicht mehr.'
                );
            }
        }

        if ($collectionId > 0) {
            $collectionExists = story_one(
                $phanconn,
                'SELECT id
                 FROM story_collections
                 WHERE id = ?',
                [$collectionId]
            );

            if (!$collectionExists) {
                throw new RuntimeException(
                    'Die ausgewählte Gesamtstory existiert nicht mehr.'
                );
            }
        }

        $phanconn->begin_transaction();

        try {
            $oldCollectionId = 0;

            if ($id > 0) {
                $exists = story_one(
                    $phanconn,
                    'SELECT
                        id,
                        collection_id,
                        chapter_order
                     FROM stories
                     WHERE id = ?',
                    [$id]
                );

                if (!$exists) {
                    throw new RuntimeException(
                        'Story existiert nicht mehr.'
                    );
                }

                $oldCollectionId = max(
                    0,
                    (int)(
                        $exists['collection_id']
                        ?? 0
                    )
                );

                if ($collectionId > 0) {
                    if ($chapterOrder <= 0) {
                        if (
                            $oldCollectionId === $collectionId
                            && (int)(
                                $exists['chapter_order']
                                ?? 0
                            ) > 0
                        ) {
                            $chapterOrder =
                                (int)$exists[
                                    'chapter_order'
                                ];
                        } else {
                            $chapterOrder =
                                story_collection_next_order(
                                    $phanconn,
                                    $collectionId
                                );
                        }
                    }
                } else {
                    $chapterOrder = 0;
                }

                story_exec(
                    $phanconn,
                    'UPDATE stories
                     SET
                        title = ?,
                        intime_year = ?,
                        intime_month = ?,
                        intime_day = ?,
                        region_id = NULLIF(?, 0),
                        content = ?,
                        image_position = ?,
                        narrator_char_id = NULLIF(?, 0),
                        collection_id = NULLIF(?, 0),
                        chapter_order = NULLIF(?, 0)
                     WHERE id = ?',
                    [
                        $title,
                        $intimeYear,
                        $intimeMonth,
                        $intimeDay,
                        $regionId,
                        $content,
                        $imagePosition,
                        $narratorCharId,
                        $collectionId,
                        $chapterOrder,
                        $id,
                    ]
                )->close();

            } else {
                if ($collectionId > 0) {
                    if ($chapterOrder <= 0) {
                        $chapterOrder =
                            story_collection_next_order(
                                $phanconn,
                                $collectionId
                            );
                    }
                } else {
                    $chapterOrder = 0;
                }

                $stmt = story_exec(
                    $phanconn,
                    'INSERT INTO stories (
                        title,
                        intime_year,
                        intime_month,
                        intime_day,
                        region_id,
                        content,
                        image_position,
                        narrator_char_id,
                        collection_id,
                        chapter_order
                     ) VALUES (
                        ?,
                        ?,
                        ?,
                        ?,
                        NULLIF(?, 0),
                        ?,
                        ?,
                        NULLIF(?, 0),
                        NULLIF(?, 0),
                        NULLIF(?, 0)
                     )',
                    [
                        $title,
                        $intimeYear,
                        $intimeMonth,
                        $intimeDay,
                        $regionId,
                        $content,
                        $imagePosition,
                        $narratorCharId,
                        $collectionId,
                        $chapterOrder,
                    ]
                );

                $id = (int)$stmt->insert_id;
                $stmt->close();
            }

            story_sync_characters(
                $phanconn,
                $id,
                $charIdsRaw
            );

            if (
                $oldCollectionId > 0
                && $oldCollectionId !== $collectionId
            ) {
                story_normalize_collection_order(
                    $phanconn,
                    $oldCollectionId
                );
            }

            if ($collectionId > 0) {
                story_place_chapter(
                    $phanconn,
                    $collectionId,
                    $id,
                    max(
                        1,
                        $chapterOrder
                    )
                );
            }

            $phanconn->commit();

        } catch (Throwable $e) {
            $phanconn->rollback();
            throw $e;
        }

        $imageChanged = false;

        $newImage = isset($_FILES['cover_image'])
            ? story_upload_image(
                $_FILES['cover_image'],
                $id
            )
            : null;

        if ($newImage !== null) {
            $oldImage = story_one(
                $phanconn,
                'SELECT image_path
                 FROM stories
                 WHERE id = ?',
                [$id]
            );

            story_exec(
                $phanconn,
                'UPDATE stories
                 SET
                    image_path = ?,
                    thumb_x = NULL,
                    thumb_y = NULL,
                    thumb_w = NULL,
                    thumb_h = NULL
                 WHERE id = ?',
                [
                    $newImage,
                    $id,
                ]
            )->close();

            story_delete_image(
                $oldImage['image_path'] ?? null
            );

            story_cleanup_thumbs($id);

            $imageChanged = true;
        }

        $saved = story_one(
            $phanconn,
            'SELECT
                updated_at,
                collection_id,
                chapter_order
             FROM stories
             WHERE id = ?',
            [$id]
        );

        story_json([
            'ok' => true,
            'id' => $id,
            'collection_id' =>
                isset($saved['collection_id'])
                    ? (int)$saved['collection_id']
                    : null,
            'chapter_order' =>
                isset($saved['chapter_order'])
                    ? (int)$saved['chapter_order']
                    : null,
            'updated_at' => story_format_datetime(
                $saved['updated_at'] ?? null
            ),
            'words' => story_word_count($content),
            'characters' => story_character_count($content),
            'image_changed' => $imageChanged,
            'image_url' => $imageChanged
                ? '/phan/stories?image=' . $id
                : null,
        ]);

    } catch (Throwable $e) {
        story_json(
            [
                'ok' => false,
                'message' => $e->getMessage(),
            ],
            400
        );
    }
}


/* =========================================================
 * Daten
 * ========================================================= */

$regions = story_all(
    $phanconn,
    'SELECT
        id,
        title,
        image_path
     FROM regions
     ORDER BY title'
);

$chars = story_all(
    $phanconn,
    'SELECT
        c.id,
        c.call_name,
        c.first_name,
        c.last_name,
        c.species,
        c.occupation,
        c.region_id,
        c.active_image_id,
        r.title AS region_title
     FROM chars c
     LEFT JOIN regions r
        ON r.id = c.region_id
     ORDER BY
        c.call_name,
        c.last_name,
        c.first_name'
);

$collections = story_all(
    $phanconn,
    'SELECT
        id,
        title,
        image_path,
        thumb_x,
        thumb_y,
        thumb_w,
        thumb_h,
        created_at,
        updated_at
     FROM story_collections
     ORDER BY
        title,
        id'
);

$collectionMap = [];

foreach ($collections as $collectionRow) {
    $collectionMap[
        (int)$collectionRow['id']
    ] = $collectionRow;
}


$detailId = max(
    0,
    (int)($_GET['id'] ?? 0)
);

$collectionDetailId = max(
    0,
    (int)($_GET['collection'] ?? 0)
);

$isNew = isset($_GET['new']);
$isNewCollection =
    isset($_GET['new_collection']);

$isStoryDetail =
    $detailId > 0
    || $isNew;

$isCollectionDetail =
    !$isStoryDetail
    && (
        $collectionDetailId > 0
        || $isNewCollection
    );

$isDetail =
    $isStoryDetail
    || $isCollectionDetail;

$forceEdit =
    $isNew
    || isset($_GET['edit']);

$forceCollectionEdit =
    $isNewCollection
    || isset($_GET['edit_collection']);

$newParentCollectionId = max(
    0,
    (int)(
        $_GET['parent_collection']
        ?? 0
    )
);

if (
    $newParentCollectionId > 0
    && !isset(
        $collectionMap[
            $newParentCollectionId
        ]
    )
) {
    $newParentCollectionId = 0;
}


$story = null;
$storyCharIds = [];
$storyChars = [];
$storyNarrator = null;
$storyCollection = null;
$storyCollectionChapters = [];
$storyChapterIndex = null;

$collection = null;
$collectionChapters = [];
$collectionChapterCharactersByStory = [];
$collectionStandaloneStories = [];
$collectionTotalWords = 0;
$collectionTotalCharacters = 0;

$stories = [];
$storyCharactersByStory = [];
$overviewItems = [];
$collectionSummaryById = [];


if ($isStoryDetail) {
    if ($detailId > 0) {
        $story = story_one(
            $phanconn,
            'SELECT
                s.*,
                r.title AS region_title,
                r.image_path AS region_image_path
             FROM stories s
             LEFT JOIN regions r
                ON r.id = s.region_id
             WHERE s.id = ?',
            [$detailId]
        );

        if (!$story) {
            http_response_code(404);
            exit('Story nicht gefunden.');
        }

        if (!empty($story['narrator_char_id'])) {
            $storyNarrator = story_one(
                $phanconn,
                'SELECT
                    c.id,
                    c.call_name,
                    c.first_name,
                    c.last_name,
                    c.species,
                    c.occupation,
                    c.region_id,
                    c.active_image_id,
                    r.title AS region_title
                 FROM chars c
                 LEFT JOIN regions r
                    ON r.id = c.region_id
                 WHERE c.id = ?',
                [
                    (int)$story[
                        'narrator_char_id'
                    ],
                ]
            );
        }

        $storyChars = story_all(
            $phanconn,
            'SELECT
                c.id,
                c.call_name,
                c.first_name,
                c.last_name,
                c.species,
                c.occupation,
                c.region_id,
                c.active_image_id,
                r.title AS region_title,
                sc.sort_order
             FROM story_characters sc
             INNER JOIN chars c
                ON c.id = sc.char_id
             LEFT JOIN regions r
                ON r.id = c.region_id
             WHERE sc.story_id = ?
             ORDER BY
                sc.sort_order,
                c.call_name',
            [$detailId]
        );

        $storyCharIds = array_map(
            static fn(array $char): int =>
                (int)$char['id'],
            $storyChars
        );

        $storyCollectionId = max(
            0,
            (int)(
                $story['collection_id']
                ?? 0
            )
        );

        if ($storyCollectionId > 0) {
            $storyCollection =
                $collectionMap[
                    $storyCollectionId
                ] ?? null;

            if ($storyCollection) {
                $storyCollectionChapters =
                    story_all(
                        $phanconn,
                        'SELECT
                            id,
                            title,
                            intime_year,
                            intime_month,
                            intime_day,
                            chapter_order
                         FROM stories
                         WHERE collection_id = ?
                         ORDER BY
                            chapter_order ASC,
                            id ASC',
                        [$storyCollectionId]
                    );

                foreach (
                    $storyCollectionChapters
                    as $index => $chapter
                ) {
                    if (
                        (int)$chapter['id']
                        === $detailId
                    ) {
                        $storyChapterIndex =
                            $index;
                        break;
                    }
                }
            }
        }
    }

    $story ??= [
        'id' => 0,
        'title' => '',
        'intime_year' => null,
        'intime_month' => null,
        'intime_day' => null,
        'region_id' => null,
        'region_title' => null,
        'region_image_path' => null,
        'content' => '',
        'image_path' => null,
        'image_position' => 'start',
        'thumb_x' => null,
        'thumb_y' => null,
        'thumb_w' => null,
        'thumb_h' => null,
        'narrator_char_id' => null,
        'collection_id' =>
            $newParentCollectionId > 0
                ? $newParentCollectionId
                : null,
        'chapter_order' =>
            $newParentCollectionId > 0
                ? story_collection_next_order(
                    $phanconn,
                    $newParentCollectionId
                )
                : null,
        'created_at' => null,
        'updated_at' => null,
    ];


} elseif ($isCollectionDetail) {
    if ($collectionDetailId > 0) {
        $collection =
            $collectionMap[
                $collectionDetailId
            ] ?? null;

        if (!$collection) {
            http_response_code(404);
            exit(
                'Gesamtstory nicht gefunden.'
            );
        }

        $collectionChapters = story_all(
            $phanconn,
            'SELECT
                s.id,
                s.title,
                s.intime_year,
                s.intime_month,
                s.intime_day,
                s.region_id,
                s.content,
                s.image_path,
                s.narrator_char_id,
                s.chapter_order,
                s.updated_at,
                r.title AS region_title,
                r.image_path AS region_image_path,
                narrator.call_name AS narrator_call_name,
                narrator.active_image_id AS narrator_active_image_id
             FROM stories s
             LEFT JOIN regions r
                ON r.id = s.region_id
             LEFT JOIN chars narrator
                ON narrator.id = s.narrator_char_id
             WHERE s.collection_id = ?
             ORDER BY
                s.chapter_order ASC,
                s.id ASC',
            [$collectionDetailId]
        );

        $collectionChapterCharacters =
            story_all(
                $phanconn,
                'SELECT
                    sc.story_id,
                    sc.sort_order,
                    c.id,
                    c.call_name,
                    c.first_name,
                    c.last_name,
                    c.active_image_id
                 FROM story_characters sc
                 INNER JOIN chars c
                    ON c.id = sc.char_id
                 INNER JOIN stories s
                    ON s.id = sc.story_id
                 WHERE s.collection_id = ?
                 ORDER BY
                    sc.story_id,
                    sc.sort_order,
                    c.call_name',
                [$collectionDetailId]
            );

        foreach (
            $collectionChapterCharacters
            as $chapterChar
        ) {
            $collectionChapterCharactersByStory[
                (int)$chapterChar['story_id']
            ][] = $chapterChar;
        }


        foreach ($collectionChapters as $chapter) {
            $collectionTotalWords +=
                story_word_count(
                    (string)$chapter['content']
                );

            $collectionTotalCharacters +=
                story_character_count(
                    (string)$chapter['content']
                );
        }
    }

    $collection ??= [
        'id' => 0,
        'title' => '',
        'image_path' => null,
        'thumb_x' => null,
        'thumb_y' => null,
        'thumb_w' => null,
        'thumb_h' => null,
        'created_at' => null,
        'updated_at' => null,
    ];

    $collectionStandaloneStories =
        story_all(
            $phanconn,
            'SELECT
                id,
                title,
                intime_year,
                intime_month,
                intime_day
             FROM stories
             WHERE collection_id IS NULL
             ORDER BY
                intime_year ASC,
                COALESCE(intime_month, 0) ASC,
                COALESCE(intime_day, 0) ASC,
                title,
                id'
        );


} else {
    $stories = story_all(
        $phanconn,
        'SELECT
            s.id,
            s.title,
            s.intime_year,
            s.intime_month,
            s.intime_day,
            s.region_id,
            s.content,
            s.image_path,
            s.narrator_char_id,
            s.collection_id,
            s.chapter_order,
            s.created_at,
            s.updated_at,
            r.title AS region_title,
            r.image_path AS region_image_path,
            narrator.call_name AS narrator_call_name,
            narrator.active_image_id AS narrator_active_image_id
        FROM stories s
        LEFT JOIN regions r
            ON r.id = s.region_id
        LEFT JOIN chars narrator
            ON narrator.id = s.narrator_char_id
        ORDER BY
            s.intime_year IS NULL ASC,
            s.intime_year ASC,
            COALESCE(s.intime_month, 0) ASC,
            COALESCE(s.intime_day, 0) ASC,
            s.id ASC'
    );

    $allStoryCharacters = story_all(
        $phanconn,
        'SELECT
            sc.story_id,
            sc.sort_order,
            c.id,
            c.call_name,
            c.first_name,
            c.last_name,
            c.active_image_id
         FROM story_characters sc
         INNER JOIN chars c
            ON c.id = sc.char_id
         ORDER BY
            sc.story_id,
            sc.sort_order,
            c.call_name'
    );

    foreach ($allStoryCharacters as $char) {
        $storyId =
            (int)$char['story_id'];

        $storyCharactersByStory[
            $storyId
        ][] = $char;
    }

    $collectionChaptersByCollection =
        [];

    foreach ($stories as $row) {
        $collectionId = max(
            0,
            (int)(
                $row['collection_id']
                ?? 0
            )
        );

        if ($collectionId > 0) {
            $collectionChaptersByCollection[
                $collectionId
            ][] = $row;
        }
    }

    foreach (
        $collections
        as $collectionRow
    ) {
        $collectionId =
            (int)$collectionRow['id'];

        $chapters =
            $collectionChaptersByCollection[
                $collectionId
            ] ?? [];

        usort(
            $chapters,
            static function (
                array $a,
                array $b
            ): int {
                return [
                    (int)(
                        $a['chapter_order']
                        ?? PHP_INT_MAX
                    ),
                    (int)$a['id'],
                ] <=> [
                    (int)(
                        $b['chapter_order']
                        ?? PHP_INT_MAX
                    ),
                    (int)$b['id'],
                ];
            }
        );

        $collectionWordCount = 0;
        $collectionCharacterCount = 0;
        $collectionRegions = [];
        $collectionNarrators = [];
        $collectionChars = [];
        $collectionSearchParts = [
            (string)$collectionRow[
                'title'
            ],
        ];

        foreach ($chapters as $chapter) {
            $chapterId =
                (int)$chapter['id'];

            $collectionWordCount +=
                story_word_count(
                    (string)$chapter['content']
                );

            $collectionCharacterCount +=
                story_character_count(
                    (string)$chapter['content']
                );

            $collectionSearchParts[] =
                (string)$chapter['title'];

            $collectionSearchParts[] =
                (string)$chapter['content'];

            $regionId = max(
                0,
                (int)(
                    $chapter['region_id']
                    ?? 0
                )
            );

            if ($regionId > 0) {
                $collectionRegions[$regionId] = [
                    'id' => $regionId,
                    'name' => (string)(
                        $chapter['region_title']
                        ?? ''
                    ),
                    'image_path' =>
                        $chapter[
                            'region_image_path'
                        ] ?? null,
                ];
            }

            $narratorId = max(
                0,
                (int)(
                    $chapter[
                        'narrator_char_id'
                    ] ?? 0
                )
            );

            if ($narratorId > 0) {
                $collectionNarrators[
                    $narratorId
                ] = [
                    'id' =>
                        $narratorId,
                    'name' =>
                        (string)(
                            $chapter[
                                'narrator_call_name'
                            ] ?? ''
                        ),
                    'active_image_id' =>
                        $chapter[
                            'narrator_active_image_id'
                        ] ?? null,
                ];
            }

            foreach (
                $storyCharactersByStory[
                    $chapterId
                ] ?? []
                as $char
            ) {
                $collectionChars[
                    (int)$char['id']
                ] = $char;
            }
        }

        $collectionSummaryById[
            $collectionId
        ] = [
            'collection' =>
                $collectionRow,
            'chapters' =>
                $chapters,
            'chapter_count' =>
                count($chapters),
            'word_count' =>
                $collectionWordCount,
            'character_count' =>
                $collectionCharacterCount,
            'date_label' =>
                story_collection_date_label(
                    $chapters
                ),
            'regions' =>
                $collectionRegions,
            'narrators' =>
                $collectionNarrators,
            'chars' =>
                array_values(
                    $collectionChars
                ),
            'region_ids' =>
                array_keys(
                    $collectionRegions
                ),
            'char_ids' =>
                array_keys(
                    $collectionChars
                ),
            'search' =>
                trim(
                    implode(
                        ' ',
                        $collectionSearchParts
                    )
                ),
        ];
    }

    /*
     * Übersicht chronologisch:
     * Eine Gesamtstory erscheint an der Position ihres
     * frühesten Kapitels. Ihre weiteren Kapitel werden
     * nicht nochmals als eigene Hauptzeilen ausgegeben.
     */
    $seenCollections = [];

    foreach ($stories as $row) {
        $collectionId = max(
            0,
            (int)(
                $row['collection_id']
                ?? 0
            )
        );

        if ($collectionId > 0) {
            if (
                isset(
                    $collectionMap[
                        $collectionId
                    ]
                )
                && !isset(
                    $seenCollections[
                        $collectionId
                    ]
                )
            ) {
                $overviewItems[] = [
                    'type' =>
                        'collection',
                    'id' =>
                        $collectionId,
                ];

                $seenCollections[
                    $collectionId
                ] = true;
            }

            continue;
        }

        $overviewItems[] = [
            'type' => 'story',
            'row' => $row,
        ];
    }

    /*
     * Leere Gesamtstorys haben noch kein Datum und landen
     * deshalb am Ende.
     */
    foreach ($collections as $collectionRow) {
        $collectionId =
            (int)$collectionRow['id'];

        if (
            !isset(
                $seenCollections[
                    $collectionId
                ]
            )
        ) {
            $overviewItems[] = [
                'type' =>
                    'collection',
                'id' =>
                    $collectionId,
            ];
        }
    }
}

$regionJson = array_map(
    static fn(array $region): array => [
        'id' => (int)$region['id'],
        'name' => (string)$region['title'],
        'thumb' => !empty($region['image_path'])
            ? '/phan/regions?thumb=' . (int)$region['id']
            : null,
    ],
    $regions
);

$charJson = array_map(
    static fn(array $char): array => [
        'id' => (int)$char['id'],
        'name' => (string)$char['call_name'],
        'full' => trim(
            (string)($char['first_name'] ?? '')
            . ' '
            . (string)($char['last_name'] ?? '')
        ),
        'species' => (string)($char['species'] ?? ''),
        'occupation' => (string)($char['occupation'] ?? ''),
        'region_id' => isset($char['region_id'])
            ? (int)$char['region_id']
            : null,
        'region' => (string)($char['region_title'] ?? ''),
        'thumb' => !empty($char['active_image_id'])
            ? '/phan/chars?thumb=' . (int)$char['id']
            : null,
    ],
    $chars
);

$page_title = 'Storys';

require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<?php
$storyHasBackground =
    $isStoryDetail
    && !empty($story['image_path'])
    && ($story['image_position'] ?? '') === 'background';
?>

<div
    class="phan-page stories-page <?= $storyHasBackground ? 'story-has-cover-background' : '' ?>"
    <?= $storyHasBackground
        ? 'style="--story-cover-background: url(&quot;/phan/stories?image='
            . (int)$story['id']
            . '&quot;);"'
        : ''
    ?>
>

    <?php if (!$isDetail): ?>

        <div class="phan-head">
            <div>
                <h1 class="ueberschrift phan-title">
                    Storys
                </h1>

                <div class="stories-result-count" id="storiesResultCount">
                    <?= count($overviewItems) ?> Story<?= count($overviewItems) === 1 ? '' : 's' ?>
                </div>
            </div>

            <div class="stories-head-actions">
                <button
                    type="button"
                    class="stories-secondary-button"
                    onclick="location.href='/phan/stories?new_collection=1'"
                >
                    + Gesamtstory
                </button>

                <button
                    type="button"
                    onclick="location.href='/phan/stories?new=1'"
                >
                    + Story
                </button>
            </div>
        </div>


        <div class="phan-card stories-toolbar">

            <label class="stories-search-wrap">
                <span>Suche</span>

                <input
                    type="search"
                    id="storiesSearch"
                    placeholder="Titel und Storytext durchsuchen …"
                    autocomplete="off"
                >
            </label>


            <div class="stories-filter" id="storyRegionFilter">
                <span class="stories-filter-label">
                    Region
                </span>

                <button
                    type="button"
                    class="stories-filter-trigger"
                    id="storyRegionFilterTrigger"
                >
                    <span class="relations-char-avatar story-filter-placeholder story-region-thumb--small"></span>
                    <span class="stories-filter-trigger-text">Alle Regionen</span>
                    <span aria-hidden="true">▾</span>
                </button>

                <div class="stories-filter-menu" id="storyRegionFilterMenu" hidden>
                    <input
                        type="search"
                        class="stories-filter-menu-search"
                        id="storyRegionFilterSearch"
                        placeholder="Region suchen …"
                        autocomplete="off"
                    >

                    <div
                        class="stories-filter-options"
                        id="storyRegionFilterOptions"
                    ></div>
                </div>
            </div>


            <div class="stories-filter" id="storyCharFilter">
                <span class="stories-filter-label">
                    Charakter
                </span>

                <button
                    type="button"
                    class="stories-filter-trigger"
                    id="storyCharFilterTrigger"
                >
                    <span class="relations-char-avatar story-filter-placeholder"></span>
                    <span class="stories-filter-trigger-text">Alle Charaktere</span>
                    <span aria-hidden="true">▾</span>
                </button>

                <div class="stories-filter-menu" id="storyCharFilterMenu" hidden>
                    <input
                        type="search"
                        class="stories-filter-menu-search"
                        id="storyCharFilterSearch"
                        placeholder="Charakter suchen …"
                        autocomplete="off"
                    >

                    <div
                        class="stories-filter-options"
                        id="storyCharFilterOptions"
                    ></div>
                </div>
            </div>


            <button
                type="button"
                class="stories-filter-reset"
                id="storiesFilterReset"
            >
                Filter zurücksetzen
            </button>

        </div>


        <div class="phan-table-wrap stories-table-wrap">
            <table class="phan-table stories-table">
                <thead>
                    <tr>
                        <th class="stories-cover-column"></th>
                        <th>Titel</th>
                        <th>Datum</th>
                        <th>Region</th>
                        <th>Erzähler</th>
                        <th>Charaktere</th>
                        <th>Länge</th>
                    </tr>
                </thead>

                <tbody id="storiesTableBody">

                    <?php foreach ($overviewItems as $item): ?>

                        <?php if ($item['type'] === 'collection'): ?>
                            <?php
                            $collectionId =
                                (int)$item['id'];

                            $summary =
                                $collectionSummaryById[
                                    $collectionId
                                ] ?? null;

                            if (!$summary) {
                                continue;
                            }

                            $collectionRow =
                                $summary['collection'];

                            $collectionChapters =
                                $summary['chapters'];

                            $collectionRegions =
                                $summary['regions'];

                            $collectionNarrators =
                                $summary['narrators'];

                            $collectionChars =
                                $summary['chars'];

                            $collectionRegionIds =
                                $summary['region_ids'];

                            $collectionCharIds =
                                $summary['char_ids'];

                            $collectionTitle =
                                trim(
                                    (string)(
                                        $collectionRow['title']
                                        ?? ''
                                    )
                                );

                            $collectionTitle =
                                $collectionTitle !== ''
                                    ? $collectionTitle
                                    : 'Unbenannte Gesamtstory';
                            ?>

                            <tr
                                class="phan-row stories-row stories-collection-row"
                                data-href="/phan/stories?collection=<?= $collectionId ?>"
                                data-collection-id="<?= $collectionId ?>"
                                data-region-ids="<?= story_h(
                                    implode(
                                        ',',
                                        $collectionRegionIds
                                    )
                                ) ?>"
                                data-char-ids="<?= story_h(
                                    implode(
                                        ',',
                                        $collectionCharIds
                                    )
                                ) ?>"
                                data-search="<?= story_h(
                                    $summary['search']
                                ) ?>"
                            >
                                <td class="stories-cover-cell">
                                    <?php if (!empty($collectionRow['image_path'])): ?>
                                        <img
                                            class="story-cover-table-thumb"
                                            src="/phan/stories?collection_thumb=<?= $collectionId ?>&v=<?= rawurlencode(
                                                (string)(
                                                    $collectionRow['updated_at']
                                                    ?? ''
                                                )
                                            ) ?>&shape=square"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        >
                                    <?php else: ?>
                                        <span class="story-cover-table-placeholder"></span>
                                    <?php endif; ?>
                                </td>

                                <td class="stories-title-cell">
                                    <div class="stories-collection-title">
                                        <button
                                            type="button"
                                            class="story-collection-expand"
                                            data-collection-toggle="<?= $collectionId ?>"
                                            aria-expanded="false"
                                            title="Kapitel ein-/ausblenden"
                                        >
                                            ▸
                                        </button>

                                        <div>
                                            <strong>
                                                <?= story_h(
                                                    $collectionTitle
                                                ) ?>
                                            </strong>

                                            <span>
                                                Gesamtstory ·
                                                <?= (int)$summary['chapter_count'] ?>
                                                Kapitel
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <td class="stories-intime-cell">
                                    <strong>
                                        <?= story_h(
                                            $summary['date_label']
                                        ) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?php if ($collectionRegions): ?>
                                        <div class="stories-region-stack">
                                            <?php foreach ($collectionRegions as $region): ?>
                                                <div class="stories-region-cell stories-region-cell--compact">
                                                    <?php if (!empty($region['image_path'])): ?>
                                                        <img
                                                            class="story-region-table-thumb"
                                                            src="/phan/regions?thumb=<?= (int)$region['id'] ?>"
                                                            alt=""
                                                            loading="lazy"
                                                            decoding="async"
                                                        >
                                                    <?php else: ?>
                                                        <span class="stories-region-placeholder"></span>
                                                    <?php endif; ?>

                                                    <span>
                                                        <?= story_h(
                                                            $region['name']
                                                            ?? '—'
                                                        ) ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="stories-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($collectionNarrators): ?>
                                        <div class="stories-narrator-stack">
                                            <?php foreach ($collectionNarrators as $narrator): ?>
                                                <div class="stories-narrator-item">
                                                    <div
                                                        class="relations-char-avatar stories-narrator-avatar"
                                                        title="<?= story_h(
                                                            $narrator['name']
                                                            ?? ''
                                                        ) ?>"
                                                    >
                                                        <?php if (!empty($narrator['active_image_id'])): ?>
                                                            <img
                                                                src="/phan/chars?thumb=<?= (int)$narrator['id'] ?>"
                                                                alt=""
                                                                loading="lazy"
                                                                decoding="async"
                                                            >
                                                        <?php else: ?>
                                                            <span>
                                                                <?= story_h(
                                                                    story_initial(
                                                                        (string)(
                                                                            $narrator['name']
                                                                            ?? ''
                                                                        )
                                                                    )
                                                                ) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <strong>
                                                        <?= story_h(
                                                            $narrator['name']
                                                            ?? '—'
                                                        ) ?>
                                                    </strong>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="stories-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($collectionChars): ?>
                                        <div class="stories-char-stack">
                                            <?php foreach (
                                                array_slice(
                                                    $collectionChars,
                                                    0,
                                                    6
                                                )
                                                as $char
                                            ): ?>
                                                <div
                                                    class="relations-char-avatar stories-char-mini"
                                                    title="<?= story_h(
                                                        $char['call_name']
                                                    ) ?>"
                                                >
                                                    <?php if (!empty($char['active_image_id'])): ?>
                                                        <img
                                                            src="/phan/chars?thumb=<?= (int)$char['id'] ?>"
                                                            alt=""
                                                            loading="lazy"
                                                            decoding="async"
                                                        >
                                                    <?php else: ?>
                                                        <span>
                                                            <?= story_h(
                                                                story_initial(
                                                                    (string)$char['call_name']
                                                                )
                                                            ) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>

                                            <span class="stories-char-names">
                                                <?= story_h(
                                                    implode(
                                                        ', ',
                                                        array_map(
                                                            static fn(array $char): string =>
                                                                (string)$char['call_name'],
                                                            $collectionChars
                                                        )
                                                    )
                                                ) ?>
                                            </span>

                                            <?php if (count($collectionChars) > 6): ?>
                                                <span class="stories-char-more">
                                                    +<?= count($collectionChars) - 6 ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="stories-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="stories-length-cell">
                                    <strong>
                                        <?= number_format(
                                            (int)$summary['word_count'],
                                            0,
                                            ',',
                                            '.'
                                        ) ?> Wörter
                                    </strong>
                                </td>
                            </tr>

                            <?php if ($collectionChapters): ?>
                                <?php foreach (
                                    $collectionChapters
                                    as $chapterIndex => $chapter
                                ): ?>
                                    <?php
                                    $chapterId =
                                        (int)$chapter['id'];

                                    $chapterChars =
                                        $storyCharactersByStory[
                                            $chapterId
                                        ] ?? [];
                                    ?>

                                    <tr
                                        class="phan-row stories-collection-chapters-row stories-collection-chapter-table-row"
                                        data-collection-children="<?= $collectionId ?>"
                                        data-href="/phan/stories?id=<?= $chapterId ?>"
                                        hidden
                                    >
                                        <td class="stories-cover-cell">
                                            <?php if (!empty($chapter['image_path'])): ?>
                                                <img
                                                    class="story-cover-table-thumb"
                                                    src="/phan/stories?thumb=<?= $chapterId ?>&v=<?= rawurlencode(
                                                        (string)(
                                                            $chapter['updated_at']
                                                            ?? ''
                                                        )
                                                    ) ?>&shape=square"
                                                    alt=""
                                                    loading="lazy"
                                                    decoding="async"
                                                >
                                            <?php else: ?>
                                                <span class="story-cover-table-placeholder"></span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="stories-title-cell stories-chapter-title-cell">
                                            <span class="stories-chapter-number">
                                                Kapitel <?= $chapterIndex + 1 ?>
                                            </span>

                                            <strong>
                                                <?= story_h(
                                                    trim(
                                                        (string)$chapter['title']
                                                    ) !== ''
                                                        ? $chapter['title']
                                                        : 'Unbenanntes Kapitel'
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td class="stories-intime-cell">
                                            <strong>
                                                <?= story_h(
                                                    story_format_intime_date(
                                                        $chapter['intime_year']
                                                        ?? null,
                                                        $chapter['intime_month']
                                                        ?? null,
                                                        $chapter['intime_day']
                                                        ?? null
                                                    )
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?php if (!empty($chapter['region_id'])): ?>
                                                <div class="stories-region-cell">
                                                    <?php if (!empty($chapter['region_image_path'])): ?>
                                                        <img
                                                            class="story-region-table-thumb"
                                                            src="/phan/regions?thumb=<?= (int)$chapter['region_id'] ?>"
                                                            alt=""
                                                            loading="lazy"
                                                            decoding="async"
                                                        >
                                                    <?php else: ?>
                                                        <span class="stories-region-placeholder"></span>
                                                    <?php endif; ?>

                                                    <span>
                                                        <?= story_h(
                                                            $chapter['region_title']
                                                            ?? '—'
                                                        ) ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span class="stories-muted">—</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if (!empty($chapter['narrator_char_id'])): ?>
                                                <div class="stories-narrator-cell">
                                                    <div
                                                        class="relations-char-avatar stories-narrator-avatar"
                                                        title="<?= story_h(
                                                            $chapter['narrator_call_name']
                                                            ?? ''
                                                        ) ?>"
                                                    >
                                                        <?php if (!empty($chapter['narrator_active_image_id'])): ?>
                                                            <img
                                                                src="/phan/chars?thumb=<?= (int)$chapter['narrator_char_id'] ?>"
                                                                alt=""
                                                                loading="lazy"
                                                                decoding="async"
                                                            >
                                                        <?php else: ?>
                                                            <span>
                                                                <?= story_h(
                                                                    story_initial(
                                                                        (string)(
                                                                            $chapter['narrator_call_name']
                                                                            ?? ''
                                                                        )
                                                                    )
                                                                ) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <strong>
                                                        <?= story_h(
                                                            $chapter['narrator_call_name']
                                                            ?? '—'
                                                        ) ?>
                                                    </strong>
                                                </div>
                                            <?php else: ?>
                                                <span class="stories-muted">—</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($chapterChars): ?>
                                                <div class="stories-char-stack">
                                                    <?php foreach (
                                                        array_slice(
                                                            $chapterChars,
                                                            0,
                                                            6
                                                        )
                                                        as $char
                                                    ): ?>
                                                        <div
                                                            class="relations-char-avatar stories-char-mini"
                                                            title="<?= story_h(
                                                                $char['call_name']
                                                            ) ?>"
                                                        >
                                                            <?php if (!empty($char['active_image_id'])): ?>
                                                                <img
                                                                    src="/phan/chars?thumb=<?= (int)$char['id'] ?>"
                                                                    alt=""
                                                                    loading="lazy"
                                                                    decoding="async"
                                                                >
                                                            <?php else: ?>
                                                                <span>
                                                                    <?= story_h(
                                                                        story_initial(
                                                                            (string)$char['call_name']
                                                                        )
                                                                    ) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>

                                                    <span class="stories-char-names">
                                                        <?= story_h(
                                                            implode(
                                                                ', ',
                                                                array_map(
                                                                    static fn(array $char): string =>
                                                                        (string)$char['call_name'],
                                                                    $chapterChars
                                                                )
                                                            )
                                                        ) ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span class="stories-muted">—</span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="stories-length-cell">
                                            <strong>
                                                <?= number_format(
                                                    story_word_count(
                                                        (string)$chapter['content']
                                                    ),
                                                    0,
                                                    ',',
                                                    '.'
                                                ) ?>
                                                Wörter
                                            </strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                        <?php else: ?>
                            <?php
                            $row = $item['row'];
                            $rowId = (int)$row['id'];
                            $rowChars =
                                $storyCharactersByStory[
                                    $rowId
                                ] ?? [];

                            $rowCharIds = array_map(
                                static fn(array $char): int =>
                                    (int)$char['id'],
                                $rowChars
                            );

                            $wordCount = story_word_count(
                                (string)$row['content']
                            );

                            $characterCount =
                                story_character_count(
                                    (string)$row['content']
                                );

                            $searchText = trim(
                                (string)$row['title']
                                . ' '
                                . (string)$row['content']
                            );
                            ?>

                            <tr
                                class="phan-row stories-row"
                                data-href="/phan/stories?id=<?= $rowId ?>"
                                data-region-ids="<?= (int)($row['region_id'] ?? 0) ?>"
                                data-char-ids="<?= story_h(implode(',', $rowCharIds)) ?>"
                                data-search="<?= story_h($searchText) ?>"
                            >
                                <td class="stories-cover-cell">
                                    <?php if (!empty($row['image_path'])): ?>
                                        <img
                                            class="story-cover-table-thumb"
                                            src="/phan/stories?thumb=<?= $rowId ?>&v=<?= rawurlencode((string)($row['updated_at'] ?? '')) ?>&shape=square"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        >
                                    <?php else: ?>
                                        <span class="story-cover-table-placeholder"></span>
                                    <?php endif; ?>
                                </td>

                                <td class="stories-title-cell">
                                    <strong>
                                        <?= story_h(
                                            trim((string)$row['title']) !== ''
                                                ? $row['title']
                                                : 'Unbenannte Story'
                                        ) ?>
                                    </strong>

                                    <span>
                                        zuletzt geändert
                                        <?= story_h(
                                            story_format_datetime(
                                                $row['updated_at'] ?? null
                                            )
                                        ) ?>
                                    </span>
                                </td>

                                <td class="stories-intime-cell">
                                    <strong>
                                        <?= story_h(
                                            story_format_intime_date(
                                                $row['intime_year'] ?? null,
                                                $row['intime_month'] ?? null,
                                                $row['intime_day'] ?? null
                                            )
                                        ) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?php if (!empty($row['region_id'])): ?>
                                        <div class="stories-region-cell">
                                            <?php if (!empty($row['region_image_path'])): ?>
                                                <img
                                                    class="story-region-table-thumb"
                                                    src="/phan/regions?thumb=<?= (int)$row['region_id'] ?>"
                                                    alt=""
                                                    loading="lazy"
                                                    decoding="async"
                                                >
                                            <?php else: ?>
                                                <span class="stories-region-placeholder"></span>
                                            <?php endif; ?>

                                            <span>
                                                <?= story_h($row['region_title'] ?? '—') ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span class="stories-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (!empty($row['narrator_char_id'])): ?>
                                        <div class="stories-narrator-cell">
                                            <div
                                                class="relations-char-avatar stories-narrator-avatar"
                                                title="<?= story_h(
                                                    $row['narrator_call_name'] ?? ''
                                                ) ?>"
                                            >
                                                <?php if (!empty($row['narrator_active_image_id'])): ?>
                                                    <img
                                                        src="/phan/chars?thumb=<?= (int)$row['narrator_char_id'] ?>"
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async"
                                                    >
                                                <?php else: ?>
                                                    <span>
                                                        <?= story_h(
                                                            story_initial(
                                                                (string)(
                                                                    $row['narrator_call_name']
                                                                    ?? ''
                                                                )
                                                            )
                                                        ) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <strong>
                                                <?= story_h(
                                                    $row['narrator_call_name']
                                                    ?? '—'
                                                ) ?>
                                            </strong>
                                        </div>
                                    <?php else: ?>
                                        <span class="stories-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($rowChars): ?>
                                        <div class="stories-char-stack">
                                            <?php foreach (array_slice($rowChars, 0, 6) as $char): ?>
                                                <div
                                                    class="relations-char-avatar stories-char-mini"
                                                    title="<?= story_h($char['call_name']) ?>"
                                                >
                                                    <?php if (!empty($char['active_image_id'])): ?>
                                                        <img
                                                            src="/phan/chars?thumb=<?= (int)$char['id'] ?>"
                                                            alt=""
                                                            loading="lazy"
                                                            decoding="async"
                                                        >
                                                    <?php else: ?>
                                                        <span>
                                                            <?= story_h(
                                                                story_initial(
                                                                    (string)$char['call_name']
                                                                )
                                                            ) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>

                                            <span class="stories-char-names">
                                                <?= story_h(
                                                    implode(
                                                        ', ',
                                                        array_map(
                                                            static fn(array $char): string =>
                                                                (string)$char['call_name'],
                                                            $rowChars
                                                        )
                                                    )
                                                ) ?>
                                            </span>

                                            <?php if (count($rowChars) > 6): ?>
                                                <span class="stories-char-more">
                                                    +<?= count($rowChars) - 6 ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="stories-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="stories-length-cell">
                                    <strong>
                                        <?= number_format($wordCount, 0, ',', '.') ?> Wörter
                                    </strong>
                                </td>
                            </tr>

                        <?php endif; ?>

                    <?php endforeach; ?>

                    <tr
                        class="stories-filter-empty"
                        id="storiesFilterEmpty"
                        hidden
                    >
                        <td colspan="7">
                            Keine Storys passen zu den aktuellen Filtern.
                        </td>
                    </tr>

                    <?php if (!$overviewItems): ?>
                        <tr class="stories-db-empty">
                            <td colspan="7">
                                Noch keine Storys vorhanden.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>
            </table>
        </div>


    <?php elseif ($isCollectionDetail): ?>

        <div class="phan-detail-head">
            <button
                type="button"
                onclick="location.href='/phan/stories'"
            >
                ← Zurück zur Übersicht
            </button>

            <div
                class="phan-autosave-status"
                id="collectionAutosaveStatus"
                aria-live="polite"
            ></div>

            <div class="story-detail-actions">
                <button
                    type="button"
                    id="collectionReadModeButton"
                    <?= !$forceCollectionEdit
                        ? 'hidden'
                        : ''
                    ?>
                >
                    Lesemodus
                </button>

                <button
                    type="button"
                    id="collectionEditModeButton"
                    <?= $forceCollectionEdit
                        ? 'hidden'
                        : ''
                    ?>
                >
                    Bearbeiten
                </button>
            </div>
        </div>


        <section
            class="phan-card story-collection-reader"
            id="collectionReader"
            <?= $forceCollectionEdit
                ? 'hidden'
                : ''
            ?>
        >
            <?php if (!empty($collection['image_path'])): ?>
                <figure class="story-collection-reader-cover">
                    <img
                        src="/phan/stories?collection_image=<?= (int)$collection['id'] ?>"
                        alt=""
                    >
                </figure>
            <?php endif; ?>

            <header class="story-collection-reader-header">
                <div class="story-collection-kicker">
                    Gesamtstory
                </div>

                <h1>
                    <?= story_h(
                        trim(
                            (string)$collection['title']
                        ) !== ''
                            ? $collection['title']
                            : 'Unbenannte Gesamtstory'
                    ) ?>
                </h1>

                <div class="story-reader-meta">
                    <span>
                        <?= count($collectionChapters) ?>
                        Kapitel
                    </span>

                    <span>·</span>

                    <span>
                        <?= number_format(
                            $collectionTotalWords,
                            0,
                            ',',
                            '.'
                        ) ?>
                        Wörter
                    </span>

                    <span>·</span>

                    <span>
                        <?= number_format(
                            $collectionTotalCharacters,
                            0,
                            ',',
                            '.'
                        ) ?>
                        Zeichen
                    </span>

                    <span>·</span>

                    <span>
                        zuletzt geändert
                        <?= story_h(
                            story_format_datetime(
                                $collection[
                                    'updated_at'
                                ] ?? null
                            )
                        ) ?>
                    </span>
                </div>
            </header>


            <div class="story-collection-reader-chapters">
                <?php if ($collectionChapters): ?>
                    <?php foreach (
                        $collectionChapters
                        as $chapterIndex => $chapter
                    ): ?>
                        <?php
                        $readerChapterChars =
                            $collectionChapterCharactersByStory[
                                (int)$chapter['id']
                            ] ?? [];
                        ?>

                        <a
                            class="story-collection-reader-chapter"
                            href="/phan/stories?id=<?= (int)$chapter['id'] ?>"
                        >
                            <span class="story-collection-reader-number">
                                <?= str_pad(
                                    (string)(
                                        $chapterIndex + 1
                                    ),
                                    2,
                                    '0',
                                    STR_PAD_LEFT
                                ) ?>
                            </span>

                            <span class="story-collection-reader-chapter-main">
                                <strong>
                                    <?= story_h(
                                        trim(
                                            (string)$chapter['title']
                                        ) !== ''
                                            ? $chapter['title']
                                            : 'Unbenanntes Kapitel'
                                    ) ?>
                                </strong>

                                <span class="story-collection-reader-chapter-info">
                                    <span class="story-collection-reader-info-item">
                                        <small>Datum</small>
                                        <b>
                                            <?= story_h(
                                                story_format_intime_date(
                                                    $chapter['intime_year']
                                                    ?? null,
                                                    $chapter['intime_month']
                                                    ?? null,
                                                    $chapter['intime_day']
                                                    ?? null
                                                )
                                            ) ?>
                                        </b>
                                    </span>

                                    <span class="story-collection-reader-info-item story-collection-reader-info-region">
                                        <small>Region</small>

                                        <span class="stories-region-cell stories-region-cell--reader">
                                            <?php if (!empty($chapter['region_id'])): ?>
                                                <?php if (!empty($chapter['region_image_path'])): ?>
                                                    <img
                                                        class="story-region-table-thumb"
                                                        src="/phan/regions?thumb=<?= (int)$chapter['region_id'] ?>"
                                                        alt=""
                                                    >
                                                <?php endif; ?>

                                                <b>
                                                    <?= story_h(
                                                        $chapter['region_title']
                                                        ?? '—'
                                                    ) ?>
                                                </b>
                                            <?php else: ?>
                                                <b>—</b>
                                            <?php endif; ?>
                                        </span>
                                    </span>

                                    <span class="story-collection-reader-info-item">
                                        <small>Erzähler</small>

                                        <?php if (!empty($chapter['narrator_char_id'])): ?>
                                            <span class="stories-narrator-item stories-narrator-item--reader">
                                                <span class="relations-char-avatar stories-narrator-avatar">
                                                    <?php if (!empty($chapter['narrator_active_image_id'])): ?>
                                                        <img
                                                            src="/phan/chars?thumb=<?= (int)$chapter['narrator_char_id'] ?>"
                                                            alt=""
                                                        >
                                                    <?php else: ?>
                                                        <span>
                                                            <?= story_h(
                                                                story_initial(
                                                                    (string)(
                                                                        $chapter['narrator_call_name']
                                                                        ?? ''
                                                                    )
                                                                )
                                                            ) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </span>

                                                <b>
                                                    <?= story_h(
                                                        $chapter['narrator_call_name']
                                                        ?? '—'
                                                    ) ?>
                                                </b>
                                            </span>
                                        <?php else: ?>
                                            <b>—</b>
                                        <?php endif; ?>
                                    </span>

                                    <span class="story-collection-reader-info-item story-collection-reader-info-chars">
                                        <small>Charaktere</small>

                                        <?php if ($readerChapterChars): ?>
                                            <span class="stories-reader-mini-char-list">
                                                <?php foreach ($readerChapterChars as $char): ?>
                                                    <span class="stories-reader-mini-char">
                                                        <span class="relations-char-avatar stories-char-mini">
                                                            <?php if (!empty($char['active_image_id'])): ?>
                                                                <img
                                                                    src="/phan/chars?thumb=<?= (int)$char['id'] ?>"
                                                                    alt=""
                                                                >
                                                            <?php else: ?>
                                                                <span>
                                                                    <?= story_h(
                                                                        story_initial(
                                                                            (string)$char['call_name']
                                                                        )
                                                                    ) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </span>

                                                        <b>
                                                            <?= story_h(
                                                                $char['call_name']
                                                            ) ?>
                                                        </b>
                                                    </span>
                                                <?php endforeach; ?>
                                            </span>
                                        <?php else: ?>
                                            <b>—</b>
                                        <?php endif; ?>
                                    </span>

                                    <span class="story-collection-reader-info-item story-collection-reader-info-length">
                                        <small>Länge</small>
                                        <b>
                                            <?= number_format(
                                                story_word_count(
                                                    (string)$chapter['content']
                                                ),
                                                0,
                                                ',',
                                                '.'
                                            ) ?>
                                            Wörter
                                        </b>
                                    </span>
                                </span>
                            </span>

                            <span class="story-collection-reader-arrow">
                                →
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="stories-collection-empty">
                        Diese Gesamtstory hat noch keine Kapitel.
                    </div>
                <?php endif; ?>
            </div>        </section>


        <form
            class="story-collection-editor"
            id="collectionEditor"
            autocomplete="off"
            enctype="multipart/form-data"
            <?= !$forceCollectionEdit
                ? 'hidden'
                : ''
            ?>
        >
            <input
                type="hidden"
                name="csrf"
                value="<?= story_h($csrf) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="save_collection"
            >

            <input
                type="hidden"
                name="collection_id"
                id="collectionId"
                value="<?= (int)$collection['id'] ?>"
            >

            <input
                type="file"
                name="collection_cover_image"
                id="collectionCoverInput"
                class="phan-image-upload-input"
                accept="image/jpeg,image/png,image/webp"
            >

            <input
                type="hidden"
                id="collectionThumbX"
                value="<?= story_h($collection['thumb_x'] ?? '') ?>"
            >

            <input
                type="hidden"
                id="collectionThumbY"
                value="<?= story_h($collection['thumb_y'] ?? '') ?>"
            >

            <input
                type="hidden"
                id="collectionThumbW"
                value="<?= story_h($collection['thumb_w'] ?? '') ?>"
            >

            <input
                type="hidden"
                id="collectionThumbH"
                value="<?= story_h($collection['thumb_h'] ?? '') ?>"
            >


            <section class="phan-card story-collection-editor-main">
                <label class="story-editor-title-field">
                    <span>Titel der Gesamtstory</span>

                    <input
                        type="text"
                        name="collection_title"
                        id="collectionTitle"
                        value="<?= story_h(
                            $collection['title']
                            ?? ''
                        ) ?>"
                        placeholder="Titel der Gesamtstory"
                        maxlength="255"
                    >
                </label>


                <div class="story-collection-editor-image-head">
                    <strong>Titelbild</strong>

                    <button
                        type="button"
                        id="collectionCoverChooseButton"
                    >
                        <?= !empty(
                            $collection['image_path']
                        )
                            ? 'Bild ersetzen'
                            : '+ Bild'
                        ?>
                    </button>
                </div>

                <?php if (!empty($collection['image_path'])): ?>
                    <div
                        class="phan-cropbox story-collection-cover-cropbox"
                        id="collectionCoverCropBox"
                    >
                        <img
                            class="story-collection-editor-cover"
                            id="collectionCoverCropImage"
                            src="/phan/stories?collection_image=<?= (int)$collection['id'] ?>"
                            alt=""
                            draggable="false"
                        >

                        <div
                            class="phan-crop-overlay"
                            id="collectionCoverCropOverlay"
                            hidden
                        ></div>
                    </div>

                    <div class="phan-image-actions story-collection-cover-actions">
                        <button
                            type="button"
                            id="collectionCoverCropButton"
                        >
                            Thumbnail-Ausschnitt setzen
                        </button>
                    </div>

                    <button
                        type="button"
                        class="phan-danger story-collection-remove-cover"
                        id="collectionCoverRemoveButton"
                    >
                        Titelbild entfernen
                    </button>
                <?php else: ?>
                    <button
                        type="button"
                        class="story-cover-editor-empty story-collection-cover-empty"
                        id="collectionCoverEmptyButton"
                    >
                        Noch kein Titelbild
                    </button>
                <?php endif; ?>


                <div class="story-editor-panel--meta story-collection-editor-meta">
                    <div>
                        Zuletzt gespeichert
                    </div>

                    <strong id="collectionLastSaved">
                        <?= story_h(
                            story_format_datetime(
                                $collection[
                                    'updated_at'
                                ] ?? null
                            )
                        ) ?>
                    </strong>
                </div>
            </section>


            <section class="phan-card story-collection-chapter-editor">
                <div class="story-collection-editor-section-head">
                    <div>
                        <h2>Kapitel</h2>

                        <span>
                            Reihenfolge ändern oder Texte
                            hinzufügen/entfernen.
                        </span>
                    </div>

                    <button
                        type="button"
                        id="collectionNewChapterButton"
                        <?= (int)$collection['id'] <= 0
                            ? 'disabled'
                            : ''
                        ?>
                    >
                        + Neues Kapitel
                    </button>
                </div>


                    <div
                        class="story-collection-editor-chapters"
                        id="collectionChapterList"
                    >
                        <?php if ($collectionChapters): ?>
                            <?php foreach (
                                $collectionChapters
                                as $chapterIndex => $chapter
                            ): ?>
                                <div
                                    class="story-collection-editor-chapter"
                                    data-story-id="<?= (int)$chapter['id'] ?>"
                                >
                                    <div class="story-collection-editor-order">
                                        <?= $chapterIndex + 1 ?>
                                    </div>

                                    <div class="story-collection-editor-chapter-text">
                                        <strong>
                                            <?= story_h(
                                                trim(
                                                    (string)$chapter['title']
                                                ) !== ''
                                                    ? $chapter['title']
                                                    : 'Unbenanntes Kapitel'
                                            ) ?>
                                        </strong>

                                        <span>
                                            <?= story_h(
                                                story_format_intime_date(
                                                    $chapter[
                                                        'intime_year'
                                                    ] ?? null,
                                                    $chapter[
                                                        'intime_month'
                                                    ] ?? null,
                                                    $chapter[
                                                        'intime_day'
                                                    ] ?? null
                                                )
                                            ) ?>

                                            ·

                                            <?= number_format(
                                                story_word_count(
                                                    (string)$chapter['content']
                                                ),
                                                0,
                                                ',',
                                                '.'
                                            ) ?>
                                            Wörter
                                        </span>
                                    </div>

                                    <div class="story-collection-editor-chapter-actions">
                                        <button
                                            type="button"
                                            class="story-collection-move"
                                            data-direction="up"
                                            data-story-id="<?= (int)$chapter['id'] ?>"
                                            <?= $chapterIndex === 0
                                                ? 'disabled'
                                                : ''
                                            ?>
                                            title="Kapitel nach oben"
                                        >
                                            ↑
                                        </button>

                                        <button
                                            type="button"
                                            class="story-collection-move"
                                            data-direction="down"
                                            data-story-id="<?= (int)$chapter['id'] ?>"
                                            <?= $chapterIndex
                                                === count(
                                                    $collectionChapters
                                                ) - 1
                                                ? 'disabled'
                                                : ''
                                            ?>
                                            title="Kapitel nach unten"
                                        >
                                            ↓
                                        </button>

                                        <button
                                            type="button"
                                            class="story-collection-open-chapter"
                                            data-story-id="<?= (int)$chapter['id'] ?>"
                                            title="Kapitel bearbeiten"
                                        >
                                            Bearbeiten
                                        </button>

                                        <button
                                            type="button"
                                            class="phan-danger story-collection-remove-chapter"
                                            data-story-id="<?= (int)$chapter['id'] ?>"
                                            title="Aus Gesamtstory lösen"
                                        >
                                            Lösen
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="stories-collection-empty">
                                Noch keine Kapitel.
                            </div>
                        <?php endif; ?>
                    </div>


                    <div class="story-collection-add-existing">
                        <label>
                            Bestehende alleinstehende Story
                            als Kapitel hinzufügen

                            <select
                                id="collectionAddStorySelect"
                                <?= (int)$collection['id'] <= 0
                                    ? 'disabled'
                                    : ''
                                ?>
                            >
                                <option value="">
                                    — Story auswählen —
                                </option>

                                <?php foreach (
                                    $collectionStandaloneStories
                                    as $standaloneStory
                                ): ?>
                                    <option
                                        value="<?= (int)$standaloneStory['id'] ?>"
                                    >
                                        <?= story_h(
                                            story_format_intime_date(
                                                $standaloneStory[
                                                    'intime_year'
                                                ] ?? null,
                                                $standaloneStory[
                                                    'intime_month'
                                                ] ?? null,
                                                $standaloneStory[
                                                    'intime_day'
                                                ] ?? null
                                            )
                                            . ' · '
                                            . (
                                                trim(
                                                    (string)$standaloneStory['title']
                                                ) !== ''
                                                    ? $standaloneStory['title']
                                                    : 'Unbenannte Story'
                                            )
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <button
                            type="button"
                            id="collectionAddStoryButton"
                            <?= (int)$collection['id'] <= 0
                                ? 'disabled'
                                : ''
                            ?>
                        >
                            Hinzufügen
                        </button>
                    </div>

                <?php if ((int)$collection['id'] <= 0): ?>
                    <div
                        class="stories-collection-empty"
                        id="collectionNeedsSaveHint"
                    >
                        Erst einen Titel eingeben bzw. speichern.
                        Danach können Kapitel zugeordnet werden.
                    </div>
                <?php endif; ?>
            </section>


            <button
                type="button"
                class="phan-danger story-collection-delete"
                id="collectionDeleteButton"
                <?= (int)$collection['id'] <= 0
                    ? 'hidden'
                    : ''
                ?>
            >
                Gesamtstory löschen
            </button>
        </form>


    <?php else: ?>

        <div class="phan-detail-head">
            <button
                type="button"
                onclick="location.href='/phan/stories'"
            >
                ← Zurück zur Übersicht
            </button>

            <div
                class="phan-autosave-status"
                id="storyAutosaveStatus"
                aria-live="polite"
            ></div>

            <div class="story-detail-actions">
                <button
                    type="button"
                    id="storyReadModeButton"
                    <?= !$forceEdit ? 'hidden' : '' ?>
                >
                    Lesemodus
                </button>

                <button
                    type="button"
                    id="storyEditModeButton"
                    <?= $forceEdit ? 'hidden' : '' ?>
                >
                    Bearbeiten
                </button>
            </div>
        </div>


        <section
            class="phan-card story-reader"
            id="storyReader"
            <?= $forceEdit ? 'hidden' : '' ?>
        >
            <header class="story-reader-header">
                <?php if ($storyCollection): ?>
                    <div class="story-chapter-breadcrumb">
                        <a
                            href="/phan/stories?collection=<?= (int)$storyCollection['id'] ?>"
                        >
                            <?= story_h(
                                trim(
                                    (string)$storyCollection['title']
                                ) !== ''
                                    ? $storyCollection['title']
                                    : 'Unbenannte Gesamtstory'
                            ) ?>
                        </a>

                        <?php if ($storyChapterIndex !== null): ?>
                            <span>
                                Kapitel
                                <?= $storyChapterIndex + 1 ?>
                                von
                                <?= count($storyCollectionChapters) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <h1>
                    <?= story_h(
                        trim((string)$story['title']) !== ''
                            ? $story['title']
                            : 'Unbenannte Story'
                    ) ?>
                </h1>

                <div class="story-reader-meta">
                    <span>
                        <?= number_format(
                            story_word_count(
                                (string)$story['content']
                            ),
                            0,
                            ',',
                            '.'
                        ) ?> Wörter
                    </span>

                    <span>·</span>

                    <span>
                        <?= number_format(
                            story_character_count(
                                (string)$story['content']
                            ),
                            0,
                            ',',
                            '.'
                        ) ?> Zeichen
                    </span>

                    <span>·</span>

                    <span>
                        zuletzt geändert
                        <?= story_h(
                            story_format_datetime(
                                $story['updated_at'] ?? null
                            )
                        ) ?>
                    </span>
                </div>
            </header>


            <div class="story-reader-context">
                <?php if (
                    $story['intime_year'] !== null
                    && $story['intime_year'] !== ''
                ): ?>
                    <div class="story-reader-context-card story-reader-context-card--intime">
                        <div class="story-reader-context-label">
                            Datum
                        </div>

                        <strong class="story-reader-intime">
                            <?= story_h(
                                story_format_intime_date(
                                    $story['intime_year'] ?? null,
                                    $story['intime_month'] ?? null,
                                    $story['intime_day'] ?? null
                                )
                            ) ?>
                        </strong>
                    </div>
                <?php endif; ?>


                <?php if (!empty($story['region_id'])): ?>
                    <div class="story-reader-context-card story-reader-context-card--region">
                        <div class="story-reader-context-label">
                            Region
                        </div>

                        <div class="story-reader-region">
                            <?php if (!empty($story['region_image_path'])): ?>
                                <img
                                    src="/phan/regions?thumb=<?= (int)$story['region_id'] ?>"
                                    alt=""
                                >
                            <?php endif; ?>

                            <strong>
                                <?= story_h($story['region_title'] ?? '—') ?>
                            </strong>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($storyNarrator): ?>
                    <div class="story-reader-context-card story-reader-context-card--narrator">
                        <div class="story-reader-context-label">
                            Erzähler
                        </div>

                        <div class="story-reader-char">
                            <div class="relations-char-avatar">
                                <?php if (!empty($storyNarrator['active_image_id'])): ?>
                                    <img
                                        src="/phan/chars?thumb=<?= (int)$storyNarrator['id'] ?>"
                                        alt=""
                                    >
                                <?php else: ?>
                                    <span>
                                        <?= story_h(
                                            story_initial(
                                                (string)$storyNarrator['call_name']
                                            )
                                        ) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <strong>
                                <?= story_h(
                                    $storyNarrator['call_name']
                                ) ?>
                            </strong>
                        </div>
                    </div>
                <?php endif; ?>


                <?php if ($storyChars): ?>
                    <div class="story-reader-context-card story-reader-context-card--chars">
                        <div class="story-reader-context-label">
                            Charaktere
                        </div>

                        <div class="story-reader-chars">
                            <?php foreach ($storyChars as $char): ?>
                                <div class="story-reader-char">
                                    <div class="relations-char-avatar">
                                        <?php if (!empty($char['active_image_id'])): ?>
                                            <img
                                                src="/phan/chars?thumb=<?= (int)$char['id'] ?>"
                                                alt=""
                                            >
                                        <?php else: ?>
                                            <span>
                                                <?= story_h(
                                                    story_initial(
                                                        (string)$char['call_name']
                                                    )
                                                ) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <strong>
                                        <?= story_h($char['call_name']) ?>
                                    </strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>


            <?php if (
                !empty($story['image_path'])
                && ($story['image_position'] ?? 'start') === 'start'
            ): ?>
                <figure class="story-reader-cover story-reader-cover--start">
                    <img
                        src="/phan/stories?image=<?= (int)$story['id'] ?>"
                        alt=""
                    >
                </figure>
            <?php endif; ?>


            <article class="story-reader-text">
                <?= story_h($story['content']) ?>
            </article>


            <?php if (
                !empty($story['image_path'])
                && ($story['image_position'] ?? '') === 'end'
            ): ?>
                <figure class="story-reader-cover story-reader-cover--end">
                    <img
                        src="/phan/stories?image=<?= (int)$story['id'] ?>"
                        alt=""
                    >
                </figure>
            <?php endif; ?>


            <?php if (
                $storyCollection
                && $storyChapterIndex !== null
            ): ?>
                <?php
                $previousChapter =
                    $storyChapterIndex > 0
                        ? $storyCollectionChapters[
                            $storyChapterIndex - 1
                        ]
                        : null;

                $nextChapter =
                    $storyChapterIndex
                        < count(
                            $storyCollectionChapters
                        ) - 1
                            ? $storyCollectionChapters[
                                $storyChapterIndex + 1
                            ]
                            : null;
                ?>

                <nav
                    class="story-chapter-navigation"
                    aria-label="Kapitel-Navigation"
                >
                    <div>
                        <?php if ($previousChapter): ?>
                            <a
                                class="story-chapter-nav-link"
                                href="/phan/stories?id=<?= (int)$previousChapter['id'] ?>"
                            >
                                <span>← Vorheriges Kapitel</span>

                                <strong>
                                    <?= story_h(
                                        trim(
                                            (string)$previousChapter['title']
                                        ) !== ''
                                            ? $previousChapter['title']
                                            : 'Unbenanntes Kapitel'
                                    ) ?>
                                </strong>
                            </a>
                        <?php endif; ?>
                    </div>

                    <a
                        class="story-chapter-nav-overview"
                        href="/phan/stories?collection=<?= (int)$storyCollection['id'] ?>"
                    >
                        Kapitelübersicht
                    </a>

                    <div>
                        <?php if ($nextChapter): ?>
                            <a
                                class="story-chapter-nav-link story-chapter-nav-link--next"
                                href="/phan/stories?id=<?= (int)$nextChapter['id'] ?>"
                            >
                                <span>Nächstes Kapitel →</span>

                                <strong>
                                    <?= story_h(
                                        trim(
                                            (string)$nextChapter['title']
                                        ) !== ''
                                            ? $nextChapter['title']
                                            : 'Unbenanntes Kapitel'
                                    ) ?>
                                </strong>
                            </a>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        </section>


        <form
            class="story-editor"
            id="storyEditor"
            autocomplete="off"
            enctype="multipart/form-data"
            <?= !$forceEdit ? 'hidden' : '' ?>
        >
            <input
                type="hidden"
                name="csrf"
                value="<?= story_h($csrf) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="save"
            >

            <input
                type="hidden"
                name="id"
                id="storyId"
                value="<?= (int)$story['id'] ?>"
            >

            <input
                type="hidden"
                name="region_id"
                id="storyRegionId"
                value="<?= (int)($story['region_id'] ?? 0) ?>"
            >

            <input
                type="hidden"
                name="narrator_char_id"
                id="storyNarratorCharId"
                value="<?= (int)($story['narrator_char_id'] ?? 0) ?>"
            >

            <input
                type="file"
                name="cover_image"
                id="storyCoverInput"
                class="phan-image-upload-input"
                accept="image/jpeg,image/png,image/webp"
            >

            <input
                type="hidden"
                id="storyThumbX"
                value="<?= story_h($story['thumb_x'] ?? '') ?>"
            >

            <input
                type="hidden"
                id="storyThumbY"
                value="<?= story_h($story['thumb_y'] ?? '') ?>"
            >

            <input
                type="hidden"
                id="storyThumbW"
                value="<?= story_h($story['thumb_w'] ?? '') ?>"
            >

            <input
                type="hidden"
                id="storyThumbH"
                value="<?= story_h($story['thumb_h'] ?? '') ?>"
            >

            <div id="storyCharacterInputs" hidden>
                <?php foreach ($storyCharIds as $charId): ?>
                    <input
                        type="hidden"
                        name="char_ids[]"
                        value="<?= (int)$charId ?>"
                    >
                <?php endforeach; ?>
            </div>


            <div class="phan-card story-editor-main">
                <label class="story-editor-title-field">
                    <span>Titel</span>

                    <input
                        type="text"
                        name="title"
                        id="storyTitle"
                        value="<?= story_h($story['title']) ?>"
                        placeholder="Titel der Story"
                        maxlength="255"
                    >
                </label>


                <label class="story-editor-text-field">
                    <span>Story</span>

                    <textarea
                        name="content"
                        id="storyContent"
                        placeholder="Story schreiben …"
                    ><?= story_h($story['content']) ?></textarea>
                </label>

                <div class="story-editor-live-length" id="storyLiveLength"></div>
            </div>


            <aside class="story-editor-side">
                <section class="phan-card story-editor-panel story-cover-editor-panel">
                    <div class="story-cover-editor-head">
                        <h2>Titelbild</h2>

                        <button
                            type="button"
                            id="storyCoverChooseButton"
                        >
                            <?= !empty($story['image_path'])
                                ? 'Bild ersetzen'
                                : '+ Bild'
                            ?>
                        </button>
                    </div>

                    <?php if (!empty($story['image_path'])): ?>
                        <div
                            class="phan-cropbox story-cover-cropbox"
                            id="storyCoverCropBox"
                        >
                            <img
                                class="story-cover-editor-preview"
                                id="storyCoverCropImage"
                                src="/phan/stories?image=<?= (int)$story['id'] ?>"
                                alt=""
                                draggable="false"
                            >

                            <div
                                class="phan-crop-overlay"
                                id="storyCoverCropOverlay"
                                hidden
                            ></div>
                        </div>

                        <div class="phan-image-actions story-cover-image-actions">
                            <button
                                type="button"
                                id="storyCoverCropButton"
                            >
                                Thumbnail-Ausschnitt setzen
                            </button>
                        </div>
                    <?php else: ?>
                        <button
                            type="button"
                            class="story-cover-editor-empty"
                            id="storyCoverEmptyButton"
                        >
                            Noch kein Titelbild
                        </button>
                    <?php endif; ?>

                    <div class="story-cover-position">
                        <span>Anzeige</span>

                        <?php
                        $imagePosition = (string)(
                            $story['image_position']
                            ?? 'start'
                        );

                        $positionOptions = [
                            'start' => 'Am Anfang',
                            'background' => 'Hintergrund',
                            'end' => 'Am Ende',
                        ];
                        ?>

                        <div class="story-cover-position-options">
                            <?php foreach ($positionOptions as $value => $label): ?>
                                <label>
                                    <input
                                        type="radio"
                                        name="image_position"
                                        value="<?= story_h($value) ?>"
                                        <?= $imagePosition === $value
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >
                                    <span>
                                        <?= story_h($label) ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (!empty($story['image_path'])): ?>
                        <button
                            type="button"
                            class="phan-danger story-cover-remove"
                            id="storyCoverRemoveButton"
                        >
                            Titelbild entfernen
                        </button>
                    <?php endif; ?>
                </section>


                <section class="phan-card story-editor-panel story-intime-panel">
                    <h2>Datum</h2>

                    <div class="story-intime-fields">
                        <label>
                            <span>Jahr</span>

                            <input
                                type="number"
                                name="intime_year"
                                id="storyIntimeYear"
                                step="1"
                                inputmode="numeric"
                                placeholder="0"
                                value="<?= story_h(
                                    $story['intime_year'] ?? ''
                                ) ?>"
                            >
                        </label>

                        <label>
                            <span>Monat</span>

                            <select
                                name="intime_month"
                                id="storyIntimeMonth"
                            >
                                <option value="">
                                    —
                                </option>

                                <?php
                                $monthNames = [
                                    1 => 'Jan',
                                    2 => 'Feb',
                                    3 => 'Mär',
                                    4 => 'Apr',
                                    5 => 'Mai',
                                    6 => 'Jun',
                                    7 => 'Jul',
                                    8 => 'Aug',
                                    9 => 'Sep',
                                    10 => 'Okt',
                                    11 => 'Nov',
                                    12 => 'Dez',
                                ];
                                ?>

                                <?php foreach ($monthNames as $month => $label): ?>
                                    <option
                                        value="<?= $month ?>"
                                        <?= (int)($story['intime_month'] ?? 0) === $month
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= story_h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            <span>Tag</span>

                            <select
                                name="intime_day"
                                id="storyIntimeDay"
                            >
                                <option value="">
                                    —
                                </option>

                                <?php for ($day = 1; $day <= 31; $day++): ?>
                                    <option
                                        value="<?= $day ?>"
                                        <?= (int)($story['intime_day'] ?? 0) === $day
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= $day ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </label>
                    </div>
                </section>


                <section class="phan-card story-editor-panel story-chapter-editor-panel">
                    <h2>Kapitel / Gesamtstory</h2>

                    <label class="story-chapter-collection-field">
                        <span>Gesamtstory</span>

                        <select
                            name="collection_id"
                            id="storyCollectionId"
                        >
                            <option value="0">
                                — eigenständige Story —
                            </option>

                            <?php foreach (
                                $collections
                                as $collectionOption
                            ): ?>
                                <option
                                    value="<?= (int)$collectionOption['id'] ?>"
                                    <?= (int)(
                                        $story['collection_id']
                                        ?? 0
                                    ) === (int)$collectionOption['id']
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    <?= story_h(
                                        trim(
                                            (string)$collectionOption['title']
                                        ) !== ''
                                            ? $collectionOption['title']
                                            : 'Unbenannte Gesamtstory'
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="story-chapter-order-field">
                        <span>Kapitelnummer</span>

                        <input
                            type="number"
                            name="chapter_order"
                            id="storyChapterOrder"
                            min="1"
                            step="1"
                            inputmode="numeric"
                            value="<?= (int)(
                                $story['chapter_order']
                                ?? 0
                            ) > 0
                                ? (int)$story['chapter_order']
                                : ''
                            ?>"
                            <?= empty(
                                $story['collection_id']
                            )
                                ? 'disabled'
                                : ''
                            ?>
                        >
                    </label>

                    <div class="story-chapter-editor-actions">
                        <button
                            type="button"
                            class="stories-secondary-button"
                            id="storyOpenCollectionButton"
                            <?= empty(
                                $story['collection_id']
                            )
                                ? 'hidden'
                                : ''
                            ?>
                        >
                            Gesamtstory öffnen
                        </button>
                    </div>

                    <div class="story-narrator-hint">
                        Ohne Gesamtstory bleibt der Text eine
                        eigenständige Story.
                    </div>
                </section>


                <section class="phan-card story-editor-panel">
                    <h2>Region</h2>

                    <div class="story-editor-picker" id="storyRegionPicker">
                        <button
                            type="button"
                            class="story-editor-picker-trigger"
                            id="storyRegionTrigger"
                        ></button>

                        <div
                            class="story-editor-picker-menu"
                            id="storyRegionMenu"
                            hidden
                        >
                            <input
                                type="search"
                                id="storyRegionSearch"
                                placeholder="Region suchen …"
                                autocomplete="off"
                            >

                            <div
                                class="story-editor-picker-results"
                                id="storyRegionResults"
                            ></div>
                        </div>
                    </div>
                </section>


                <section class="phan-card story-editor-panel">
                    <h2>Erzähler</h2>

                    <div
                        class="story-narrator-picker"
                        id="storyNarratorPicker"
                    >
                        <button
                            type="button"
                            class="story-editor-picker-trigger"
                            id="storyNarratorTrigger"
                        ></button>

                        <div
                            class="story-editor-picker-menu"
                            id="storyNarratorMenu"
                            hidden
                        >
                            <div
                                class="story-editor-picker-results"
                                id="storyNarratorResults"
                            ></div>
                        </div>
                    </div>

                    <div class="story-narrator-hint">
                        Nur bereits beteiligte Charaktere können Erzähler sein.
                    </div>
                </section>


                <section class="phan-card story-editor-panel">
                    <h2>Charaktere</h2>

                    <div
                        class="story-selected-chars"
                        id="storySelectedChars"
                    ></div>

                    <input
                        type="search"
                        id="storyCharSearch"
                        placeholder="Charakter hinzufügen …"
                        autocomplete="off"
                    >

                    <div
                        class="story-char-results"
                        id="storyCharResults"
                    ></div>
                </section>


                <section class="phan-card story-editor-panel story-editor-panel--meta">
                    <div>
                        Zuletzt gespeichert
                    </div>

                    <strong id="storyLastSaved">
                        <?= story_h(
                            story_format_datetime(
                                $story['updated_at'] ?? null
                            )
                        ) ?>
                    </strong>
                </section>


                <button
                    type="button"
                    class="phan-danger story-delete-button"
                    id="storyDeleteButton"
                    <?= (int)$story['id'] <= 0 ? 'hidden' : '' ?>
                >
                    Story löschen
                </button>
            </aside>
        </form>

    <?php endif; ?>

</div>


<script>
(() => {
    'use strict';

    const REGIONS =
        <?= json_encode(
            $regionJson,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) ?>;

    const CHARS =
        <?= json_encode(
            $charJson,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) ?>;

    const STORY_THUMB_RATIO =
        <?= PHAN_STORY_THUMB_WIDTH ?>
        / <?= PHAN_STORY_THUMB_HEIGHT ?>;


    function normalize(value) {
        return String(value ?? '')
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toLocaleLowerCase('de');
    }


    function initials(name) {
        return (
            String(name ?? '')
                .trim()
                .split(/\s+/)
                .filter(Boolean)
                .slice(0, 2)
                .map(part => part[0])
                .join('')
                .toUpperCase()
            || '?'
        );
    }


    function makeAvatar(
        item,
        className = ''
    ) {
        const avatar =
            document.createElement('span');

        avatar.className =
            'relations-char-avatar '
            + className;

        if (item.thumb) {
            const img =
                document.createElement('img');

            img.src = item.thumb;
            img.alt = '';
            img.loading = 'lazy';
            img.decoding = 'async';

            avatar.appendChild(img);
        } else {
            avatar.textContent =
                initials(item.name);
        }

        return avatar;
    }


    /* =====================================================
     * Tabellenansicht
     * ===================================================== */

    const tableRows =
        Array.from(
            document.querySelectorAll(
                '.stories-row'
            )
        );

    const listSearch =
        document.getElementById(
            'storiesSearch'
        );

    if (tableRows.length || listSearch) {
        const resultCount =
            document.getElementById(
                'storiesResultCount'
            );

        const emptyRow =
            document.getElementById(
                'storiesFilterEmpty'
            );

        const regionTrigger =
            document.getElementById(
                'storyRegionFilterTrigger'
            );

        const regionMenu =
            document.getElementById(
                'storyRegionFilterMenu'
            );

        const regionSearch =
            document.getElementById(
                'storyRegionFilterSearch'
            );

        const regionOptions =
            document.getElementById(
                'storyRegionFilterOptions'
            );

        const charTrigger =
            document.getElementById(
                'storyCharFilterTrigger'
            );

        const charMenu =
            document.getElementById(
                'storyCharFilterMenu'
            );

        const charSearch =
            document.getElementById(
                'storyCharFilterSearch'
            );

        const charOptions =
            document.getElementById(
                'storyCharFilterOptions'
            );

        const resetButton =
            document.getElementById(
                'storiesFilterReset'
            );

        let selectedRegionId = 0;
        let selectedCharId = 0;

        const expandedCollections =
            new Set();


        function syncCollectionChildRows() {
            document
                .querySelectorAll(
                    '.stories-collection-chapters-row'
                )
                .forEach(childRow => {
                    const collectionId =
                        Number(
                            childRow.dataset
                                .collectionChildren
                            || 0
                        );

                    const parentRow =
                        document.querySelector(
                            '.stories-collection-row'
                            + '[data-collection-id="'
                            + collectionId
                            + '"]'
                        );

                    const expanded =
                        expandedCollections.has(
                            collectionId
                        );

                    childRow.hidden =
                        !expanded
                        || !parentRow
                        || parentRow.hidden;
                });
        }


        function setFilterTrigger(
            trigger,
            item,
            emptyText,
            isRegion = false
        ) {
            if (!trigger) {
                return;
            }

            trigger.innerHTML = '';

            if (item) {
                trigger.appendChild(
                    makeAvatar(
                        item,
                        isRegion
                            ? 'story-region-thumb story-region-thumb--small'
                            : ''
                    )
                );
            } else {
                const blank =
                    document.createElement('span');

                blank.className =
                    'relations-char-avatar story-filter-placeholder'
                    + (
                        isRegion
                            ? ' story-region-thumb--small'
                            : ''
                    );

                trigger.appendChild(blank);
            }

            const text =
                document.createElement('span');

            text.className =
                'stories-filter-trigger-text';

            text.textContent =
                item?.name
                || emptyText;

            trigger.appendChild(text);

            const arrow =
                document.createElement('span');

            arrow.textContent = '▾';
            arrow.setAttribute(
                'aria-hidden',
                'true'
            );

            trigger.appendChild(arrow);
        }


        function renderRegionOptions(query = '') {
            if (!regionOptions) {
                return;
            }

            const needle = normalize(query);

            regionOptions.innerHTML = '';

            const all =
                document.createElement('button');

            all.type = 'button';
            all.className =
                'relations-char-result story-option-all';

            all.textContent =
                'Alle Regionen';

            all.addEventListener(
                'click',
                () => {
                    selectedRegionId = 0;
                    setFilterTrigger(
                        regionTrigger,
                        null,
                        'Alle Regionen',
                        true
                    );
                    regionMenu.hidden = true;
                    applyListFilters();
                }
            );

            regionOptions.appendChild(all);

            REGIONS
                .filter(
                    region =>
                        !needle
                        || normalize(
                            region.name
                        ).includes(needle)
                )
                .forEach(region => {
                    const button =
                        document.createElement('button');

                    button.type = 'button';
                    button.className =
                        'relations-char-result';

                    button.appendChild(
                        makeAvatar(
                            region,
                            'story-region-thumb story-region-thumb--small'
                        )
                    );

                    const name =
                        document.createElement('span');

                    name.textContent =
                        region.name;

                    button.appendChild(name);

                    button.addEventListener(
                        'click',
                        () => {
                            selectedRegionId =
                                region.id;

                            setFilterTrigger(
                                regionTrigger,
                                region,
                                'Alle Regionen',
                                true
                            );

                            regionMenu.hidden = true;
                            applyListFilters();
                        }
                    );

                    regionOptions.appendChild(button);
                });
        }


        function renderCharOptions(query = '') {
            if (!charOptions) {
                return;
            }

            const needle = normalize(query);

            charOptions.innerHTML = '';

            const all =
                document.createElement('button');

            all.type = 'button';
            all.className =
                'relations-char-result story-option-all';

            all.textContent =
                'Alle Charaktere';

            all.addEventListener(
                'click',
                () => {
                    selectedCharId = 0;
                    setFilterTrigger(
                        charTrigger,
                        null,
                        'Alle Charaktere'
                    );
                    charMenu.hidden = true;
                    applyListFilters();
                }
            );

            charOptions.appendChild(all);

            CHARS
                .filter(char => {
                    const searchText =
                        normalize(
                            [
                                char.name,
                                char.full,
                                char.species,
                                char.occupation,
                                char.region,
                            ].join(' ')
                        );

                    return (
                        !needle
                        || searchText.includes(
                            needle
                        )
                    );
                })
                .slice(0, 100)
                .forEach(char => {
                    const button =
                        document.createElement('button');

                    button.type = 'button';
                    button.className =
                        'relations-char-result';

                    button.appendChild(
                        makeAvatar(char)
                    );

                    const text =
                        document.createElement('span');

                    text.className =
                        'relations-char-result-text';

                    const strong =
                        document.createElement('strong');

                    strong.textContent =
                        char.name;

                    const meta =
                        document.createElement('small');

                    meta.textContent =
                        [
                            char.full,
                            char.species,
                            char.region,
                        ]
                            .filter(Boolean)
                            .join(' · ');

                    text.appendChild(strong);
                    text.appendChild(meta);
                    button.appendChild(text);

                    button.addEventListener(
                        'click',
                        () => {
                            selectedCharId =
                                char.id;

                            setFilterTrigger(
                                charTrigger,
                                char,
                                'Alle Charaktere'
                            );

                            charMenu.hidden = true;
                            applyListFilters();
                        }
                    );

                    charOptions.appendChild(button);
                });
        }


        function applyListFilters() {
            const needle = normalize(
                listSearch?.value
                || ''
            );

            let visible = 0;

            tableRows.forEach(row => {
                const regionIds =
                    String(
                        row.dataset.regionIds
                        || row.dataset.regionId
                        || ''
                    )
                        .split(',')
                        .filter(Boolean)
                        .map(Number);

                const charIds =
                    String(
                        row.dataset.charIds
                        || ''
                    )
                        .split(',')
                        .filter(Boolean)
                        .map(Number);

                const searchText =
                    normalize(
                        row.dataset.search
                        || ''
                    );

                const regionMatches =
                    !selectedRegionId
                    || regionIds.includes(
                        selectedRegionId
                    );

                const charMatches =
                    !selectedCharId
                    || charIds.includes(
                        selectedCharId
                    );

                const searchMatches =
                    !needle
                    || searchText.includes(
                        needle
                    );

                const show =
                    regionMatches
                    && charMatches
                    && searchMatches;

                row.hidden = !show;

                if (show) {
                    visible++;
                }
            });

            syncCollectionChildRows();

            if (emptyRow) {
                emptyRow.hidden =
                    visible !== 0
                    || tableRows.length === 0;
            }

            if (resultCount) {
                resultCount.textContent =
                    visible
                    + ' Story'
                    + (visible === 1 ? '' : 's');
            }
        }


        document
            .querySelectorAll(
                '.stories-collection-chapters-row[data-href]'
            )
            .forEach(row => {
                row.addEventListener(
                    'click',
                    () => {
                        location.href =
                            row.dataset.href;
                    }
                );
            });


        document
            .querySelectorAll(
                '[data-collection-toggle]'
            )
            .forEach(button => {
                button.addEventListener(
                    'click',
                    event => {
                        event.stopPropagation();

                        const collectionId =
                            Number(
                                button.dataset
                                    .collectionToggle
                                || 0
                            );

                        if (collectionId <= 0) {
                            return;
                        }

                        if (
                            expandedCollections.has(
                                collectionId
                            )
                        ) {
                            expandedCollections.delete(
                                collectionId
                            );
                        } else {
                            expandedCollections.add(
                                collectionId
                            );
                        }

                        const expanded =
                            expandedCollections.has(
                                collectionId
                            );

                        button.textContent =
                            expanded
                                ? '▾'
                                : '▸';

                        button.setAttribute(
                            'aria-expanded',
                            expanded
                                ? 'true'
                                : 'false'
                        );

                        syncCollectionChildRows();
                    }
                );
            });


        tableRows.forEach(row => {
            row.addEventListener(
                'click',
                () => {
                    location.href =
                        row.dataset.href;
                }
            );
        });

        listSearch?.addEventListener(
            'input',
            applyListFilters
        );

        regionTrigger?.addEventListener(
            'click',
            event => {
                event.stopPropagation();
                regionMenu.hidden =
                    !regionMenu.hidden;
                charMenu.hidden = true;
                renderRegionOptions(
                    regionSearch?.value
                    || ''
                );
                regionSearch?.focus();
            }
        );

        charTrigger?.addEventListener(
            'click',
            event => {
                event.stopPropagation();
                charMenu.hidden =
                    !charMenu.hidden;
                regionMenu.hidden = true;
                renderCharOptions(
                    charSearch?.value
                    || ''
                );
                charSearch?.focus();
            }
        );

        regionSearch?.addEventListener(
            'input',
            () => renderRegionOptions(
                regionSearch.value
            )
        );

        charSearch?.addEventListener(
            'input',
            () => renderCharOptions(
                charSearch.value
            )
        );

        resetButton?.addEventListener(
            'click',
            () => {
                selectedRegionId = 0;
                selectedCharId = 0;

                if (listSearch) {
                    listSearch.value = '';
                }

                if (regionSearch) {
                    regionSearch.value = '';
                }

                if (charSearch) {
                    charSearch.value = '';
                }

                setFilterTrigger(
                    regionTrigger,
                    null,
                    'Alle Regionen',
                    true
                );

                setFilterTrigger(
                    charTrigger,
                    null,
                    'Alle Charaktere'
                );

                applyListFilters();
            }
        );

        document.addEventListener(
            'click',
            event => {
                if (
                    regionMenu
                    && !regionMenu.hidden
                    && !event.target.closest(
                        '#storyRegionFilter'
                    )
                ) {
                    regionMenu.hidden = true;
                }

                if (
                    charMenu
                    && !charMenu.hidden
                    && !event.target.closest(
                        '#storyCharFilter'
                    )
                ) {
                    charMenu.hidden = true;
                }
            }
        );

        setFilterTrigger(
            regionTrigger,
            null,
            'Alle Regionen',
            true
        );

        setFilterTrigger(
            charTrigger,
            null,
            'Alle Charaktere'
        );

        renderRegionOptions();
        renderCharOptions();
        applyListFilters();
    }


    /* =====================================================
     * Detail / Editor
     * ===================================================== */

    /* =====================================================
     * Gesamtstory / Kapitelverwaltung
     * ===================================================== */

    const collectionEditor =
        document.getElementById(
            'collectionEditor'
        );

    const collectionReader =
        document.getElementById(
            'collectionReader'
        );

    const collectionEditModeButton =
        document.getElementById(
            'collectionEditModeButton'
        );

    const collectionReadModeButton =
        document.getElementById(
            'collectionReadModeButton'
        );

    const collectionIdInput =
        document.getElementById(
            'collectionId'
        );

    const collectionTitle =
        document.getElementById(
            'collectionTitle'
        );

    const collectionAutosaveStatus =
        document.getElementById(
            'collectionAutosaveStatus'
        );

    const collectionLastSaved =
        document.getElementById(
            'collectionLastSaved'
        );

    const collectionCoverInput =
        document.getElementById(
            'collectionCoverInput'
        );

    const collectionCoverChooseButton =
        document.getElementById(
            'collectionCoverChooseButton'
        );

    const collectionCoverEmptyButton =
        document.getElementById(
            'collectionCoverEmptyButton'
        );

    const collectionCoverRemoveButton =
        document.getElementById(
            'collectionCoverRemoveButton'
        );

    const collectionCoverCropBox =
        document.getElementById(
            'collectionCoverCropBox'
        );

    const collectionCoverCropImage =
        document.getElementById(
            'collectionCoverCropImage'
        );

    const collectionCoverCropOverlay =
        document.getElementById(
            'collectionCoverCropOverlay'
        );

    const collectionCoverCropButton =
        document.getElementById(
            'collectionCoverCropButton'
        );

    const collectionCropInputs = [
        document.getElementById('collectionThumbX'),
        document.getElementById('collectionThumbY'),
        document.getElementById('collectionThumbW'),
        document.getElementById('collectionThumbH')
    ];

    const collectionDeleteButton =
        document.getElementById(
            'collectionDeleteButton'
        );

    const collectionNewChapterButton =
        document.getElementById(
            'collectionNewChapterButton'
        );

    const collectionAddStorySelect =
        document.getElementById(
            'collectionAddStorySelect'
        );

    const collectionAddStoryButton =
        document.getElementById(
            'collectionAddStoryButton'
        );

    const collectionNeedsSaveHint =
        document.getElementById(
            'collectionNeedsSaveHint'
        );


    collectionEditModeButton
        ?.addEventListener(
            'click',
            () => {
                const id =
                    <?= (int)($collection['id'] ?? 0) ?>;

                if (id <= 0) {
                    return;
                }

                location.href =
                    '/phan/stories?collection='
                    + id
                    + '&edit_collection=1';
            }
        );


    if (collectionEditor) {
        let collectionSaveTimer = null;
        let collectionSaveChain =
            Promise.resolve();

        let collectionStatusTimer =
            null;


        function setCollectionStatus(
            text,
            isError = false
        ) {
            if (!collectionAutosaveStatus) {
                return;
            }

            clearTimeout(
                collectionStatusTimer
            );

            collectionAutosaveStatus
                .textContent = text;

            collectionAutosaveStatus
                .classList.toggle(
                    'is-error',
                    isError
                );

            if (
                text === 'Gespeichert'
                || text === ''
            ) {
                collectionStatusTimer =
                    setTimeout(
                        () => {
                            collectionAutosaveStatus
                                .textContent = '';

                            collectionAutosaveStatus
                                .classList.remove(
                                    'is-error'
                                );
                        },
                        1200
                    );
            }
        }


        function currentCollectionId() {
            return Number(
                collectionIdInput?.value
                || 0
            );
        }


        async function performCollectionRequest(
            action,
            extra = {},
            file = null
        ) {
            const data =
                action === 'save_collection'
                    ? new FormData(
                        collectionEditor
                    )
                    : new FormData();

            data.set(
                'csrf',
                <?= json_encode(
                    $csrf,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                ) ?>
            );

            data.set('ajax', '1');
            data.set('action', action);

            if (
                action !== 'save_collection'
            ) {
                data.set(
                    'collection_id',
                    String(
                        currentCollectionId()
                    )
                );
            }

            data.delete(
                'collection_cover_image'
            );

            if (file instanceof File) {
                data.set(
                    'collection_cover_image',
                    file,
                    file.name
                );
            }

            Object.entries(extra)
                .forEach(
                    ([key, value]) => {
                        data.set(
                            key,
                            String(value)
                        );
                    }
                );

            setCollectionStatus(
                action === 'delete_collection'
                    ? 'Lösche…'
                    : 'Speichere…'
            );

            const response =
                await fetch(
                    '/phan/stories',
                    {
                        method: 'POST',
                        body: data,
                        headers: {
                            'X-Requested-With':
                                'XMLHttpRequest',
                        },
                        credentials:
                            'same-origin',
                    }
                );

            const payload =
                await response
                    .json()
                    .catch(() => null);

            if (
                !response.ok
                || !payload?.ok
            ) {
                throw new Error(
                    payload?.message
                    || 'Aktion fehlgeschlagen.'
                );
            }

            if (
                collectionLastSaved
                && payload.updated_at
            ) {
                collectionLastSaved.textContent =
                    payload.updated_at;
            }

            setCollectionStatus(
                'Gespeichert'
            );

            return payload;
        }


        function queueCollectionRequest(
            action,
            extra = {},
            file = null
        ) {
            collectionSaveChain =
                collectionSaveChain
                    .catch(() => {})
                    .then(
                        () =>
                            performCollectionRequest(
                                action,
                                extra,
                                file
                            )
                    )
                    .catch(error => {
                        setCollectionStatus(
                            error.message
                            || 'Fehler',
                            true
                        );

                        throw error;
                    });

            return collectionSaveChain;
        }


        async function saveCollection(
            file = null
        ) {
            const wasNew =
                currentCollectionId()
                <= 0;

            const payload =
                await queueCollectionRequest(
                    'save_collection',
                    {},
                    file
                );

            const returnedId =
                Number(
                    payload.collection_id
                    || 0
                );

            if (
                returnedId > 0
                && collectionIdInput
            ) {
                collectionIdInput.value =
                    String(returnedId);
            }

            if (
                wasNew
                && returnedId > 0
            ) {
                const url =
                    new URL(
                        window.location.href
                    );

                url.searchParams.delete(
                    'new_collection'
                );

                url.searchParams.set(
                    'collection',
                    String(returnedId)
                );

                url.searchParams.set(
                    'edit_collection',
                    '1'
                );

                history.replaceState(
                    null,
                    '',
                    url.pathname
                    + url.search
                );

                if (collectionNewChapterButton) {
                    collectionNewChapterButton.disabled =
                        false;
                }

                if (collectionAddStorySelect) {
                    collectionAddStorySelect.disabled =
                        false;
                }

                if (collectionAddStoryButton) {
                    collectionAddStoryButton.disabled =
                        false;
                }

                if (collectionDeleteButton) {
                    collectionDeleteButton.hidden =
                        false;
                }

                if (collectionNeedsSaveHint) {
                    collectionNeedsSaveHint.hidden =
                        true;
                }
            }

            return payload;
        }


        function scheduleCollectionSave() {
            clearTimeout(
                collectionSaveTimer
            );

            collectionSaveTimer =
                setTimeout(
                    () => {
                        saveCollection()
                            .catch(() => {});
                    },
                    450
                );
        }


        collectionTitle?.addEventListener(
            'input',
            scheduleCollectionSave
        );


        function openCollectionCoverPicker() {
            collectionCoverInput?.click();
        }


        collectionCoverChooseButton
            ?.addEventListener(
                'click',
                openCollectionCoverPicker
            );

        collectionCoverEmptyButton
            ?.addEventListener(
                'click',
                openCollectionCoverPicker
            );


        collectionCoverInput
            ?.addEventListener(
                'change',
                async () => {
                    const file =
                        collectionCoverInput
                            .files?.[0];

                    if (
                        !(file instanceof File)
                        || !file.type.startsWith(
                            'image/'
                        )
                    ) {
                        return;
                    }

                    clearTimeout(
                        collectionSaveTimer
                    );

                    try {
                        await saveCollection(
                            file
                        );

                        if (
                            currentCollectionId()
                            > 0
                        ) {
                            location.reload();
                        }
                    } catch (_) {}
                }
            );


        collectionCoverRemoveButton
            ?.addEventListener(
                'click',
                async () => {
                    if (
                        currentCollectionId()
                        <= 0
                    ) {
                        return;
                    }

                    if (
                        !confirm(
                            'Titelbild der Gesamtstory wirklich entfernen?'
                        )
                    ) {
                        return;
                    }

                    try {
                        await queueCollectionRequest(
                            'remove_collection_image'
                        );

                        location.reload();
                    } catch (_) {}
                }
            );


        /* Gesamtstory: Thumbnail-Ausschnitt */

        let collectionCropMode = false;
        let collectionCropStart = null;


        function hasSavedCollectionCrop() {
            const values =
                collectionCropInputs.map(
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


        function showSavedCollectionCrop() {
            if (
                !collectionCoverCropOverlay
                || !collectionCropMode
                || !hasSavedCollectionCrop()
            ) {
                if (collectionCoverCropOverlay) {
                    collectionCoverCropOverlay.hidden = true;
                }

                return;
            }

            const [x, y, w, h] =
                collectionCropInputs.map(
                    input => parseFloat(
                        input.value
                    )
                );

            collectionCoverCropOverlay.hidden = false;
            collectionCoverCropOverlay.style.left =
                (x * 100) + '%';
            collectionCoverCropOverlay.style.top =
                (y * 100) + '%';
            collectionCoverCropOverlay.style.width =
                (w * 100) + '%';
            collectionCoverCropOverlay.style.height =
                (h * 100) + '%';
        }


        function setCollectionCropMode(enabled) {
            collectionCropMode =
                Boolean(enabled);

            collectionCropStart = null;

            collectionCoverCropBox?.classList.toggle(
                'is-crop-mode',
                collectionCropMode
            );

            if (collectionCoverCropButton) {
                collectionCoverCropButton.textContent =
                    collectionCropMode
                        ? 'Ausschnitt abbrechen'
                        : 'Thumbnail-Ausschnitt setzen';
            }

            if (collectionCropMode) {
                showSavedCollectionCrop();
            } else if (collectionCoverCropOverlay) {
                collectionCoverCropOverlay.hidden = true;
            }
        }


        function collectionCropPoint(event) {
            const rect =
                collectionCoverCropImage
                    .getBoundingClientRect();

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


        function collectionCropRect(
            start,
            current
        ) {
            const dx =
                current.x - start.x;

            const dy =
                current.y - start.y;

            const boxW = Math.abs(dx);
            const boxH = Math.abs(dy);

            let width;
            let height;

            if (
                boxH > 0
                && (boxW / boxH)
                    > STORY_THUMB_RATIO
            ) {
                height = boxH;
                width =
                    height
                    * STORY_THUMB_RATIO;
            } else {
                width = boxW;
                height =
                    width
                    / STORY_THUMB_RATIO;
            }

            const left =
                dx >= 0
                    ? start.x
                    : start.x - width;

            const top =
                dy >= 0
                    ? start.y
                    : start.y - height;

            return {
                left,
                top,
                width,
                height
            };
        }


        function renderCollectionCropRect(
            rect
        ) {
            if (!collectionCoverCropOverlay) {
                return;
            }

            collectionCoverCropOverlay.hidden =
                false;

            collectionCoverCropOverlay.style.left =
                rect.left + 'px';

            collectionCoverCropOverlay.style.top =
                rect.top + 'px';

            collectionCoverCropOverlay.style.width =
                rect.width + 'px';

            collectionCoverCropOverlay.style.height =
                rect.height + 'px';
        }


        async function saveCollectionCrop() {
            const collectionId =
                currentCollectionId();

            if (
                collectionId <= 0
                || !hasSavedCollectionCrop()
            ) {
                return;
            }

            await queueCollectionRequest(
                'save_collection_crop',
                {
                    thumb_x:
                        collectionCropInputs[0].value,
                    thumb_y:
                        collectionCropInputs[1].value,
                    thumb_w:
                        collectionCropInputs[2].value,
                    thumb_h:
                        collectionCropInputs[3].value,
                }
            );
        }


        if (
            collectionCoverCropButton
            && collectionCoverCropBox
            && collectionCoverCropImage
            && collectionCoverCropOverlay
        ) {
            collectionCoverCropButton.addEventListener(
                'click',
                () => {
                    setCollectionCropMode(
                        !collectionCropMode
                    );
                }
            );

            collectionCoverCropBox.addEventListener(
                'pointerdown',
                event => {
                    if (!collectionCropMode) {
                        return;
                    }

                    if (
                        event.button !== undefined
                        && event.button !== 0
                    ) {
                        return;
                    }

                    event.preventDefault();

                    collectionCropStart =
                        collectionCropPoint(
                            event
                        );

                    collectionCoverCropOverlay.hidden =
                        false;

                    try {
                        collectionCoverCropBox
                            .setPointerCapture(
                                event.pointerId
                            );
                    } catch (_) {}
                }
            );

            collectionCoverCropBox.addEventListener(
                'pointermove',
                event => {
                    if (
                        !collectionCropMode
                        || !collectionCropStart
                    ) {
                        return;
                    }

                    const current =
                        collectionCropPoint(
                            event
                        );

                    renderCollectionCropRect(
                        collectionCropRect(
                            collectionCropStart,
                            current
                        )
                    );
                }
            );

            collectionCoverCropBox.addEventListener(
                'pointerup',
                event => {
                    if (
                        !collectionCropMode
                        || !collectionCropStart
                    ) {
                        return;
                    }

                    const current =
                        collectionCropPoint(
                            event
                        );

                    const crop =
                        collectionCropRect(
                            collectionCropStart,
                            current
                        );

                    collectionCropStart = null;

                    if (
                        crop.width < 12
                        || crop.height < 12
                    ) {
                        showSavedCollectionCrop();
                        return;
                    }

                    collectionCropInputs[0].value =
                        (
                            crop.left
                            / current.rect.width
                        ).toFixed(6);

                    collectionCropInputs[1].value =
                        (
                            crop.top
                            / current.rect.height
                        ).toFixed(6);

                    collectionCropInputs[2].value =
                        (
                            crop.width
                            / current.rect.width
                        ).toFixed(6);

                    collectionCropInputs[3].value =
                        (
                            crop.height
                            / current.rect.height
                        ).toFixed(6);

                    showSavedCollectionCrop();

                    saveCollectionCrop()
                        .then(
                            () =>
                                setCollectionCropMode(
                                    false
                                )
                        )
                        .catch(() => {});
                }
            );

            collectionCoverCropBox.addEventListener(
                'pointercancel',
                () => {
                    collectionCropStart = null;
                    showSavedCollectionCrop();
                }
            );
        }


        collectionReadModeButton
            ?.addEventListener(
                'click',
                async () => {
                    clearTimeout(
                        collectionSaveTimer
                    );

                    try {
                        await saveCollection();
                    } catch (_) {
                        return;
                    }

                    const id =
                        currentCollectionId();

                    if (id > 0) {
                        location.href =
                            '/phan/stories?collection='
                            + id;
                    }
                }
            );


        collectionNewChapterButton
            ?.addEventListener(
                'click',
                () => {
                    const id =
                        currentCollectionId();

                    if (id <= 0) {
                        return;
                    }

                    location.href =
                        '/phan/stories?new=1'
                        + '&parent_collection='
                        + id;
                }
            );


        collectionAddStoryButton
            ?.addEventListener(
                'click',
                async () => {
                    const storyId =
                        Number(
                            collectionAddStorySelect
                                ?.value
                            || 0
                        );

                    if (
                        currentCollectionId()
                        <= 0
                        || storyId <= 0
                    ) {
                        return;
                    }

                    try {
                        await queueCollectionRequest(
                            'collection_add_story',
                            {
                                story_id:
                                    storyId,
                            }
                        );

                        location.reload();
                    } catch (_) {}
                }
            );


        document
            .querySelectorAll(
                '.story-collection-move'
            )
            .forEach(button => {
                button.addEventListener(
                    'click',
                    async () => {
                        const storyId =
                            Number(
                                button.dataset
                                    .storyId
                                || 0
                            );

                        const direction =
                            button.dataset
                                .direction
                            || '';

                        if (storyId <= 0) {
                            return;
                        }

                        try {
                            await queueCollectionRequest(
                                'collection_move_story',
                                {
                                    story_id:
                                        storyId,
                                    direction,
                                }
                            );

                            location.reload();
                        } catch (_) {}
                    }
                );
            });


        document
            .querySelectorAll(
                '.story-collection-remove-chapter'
            )
            .forEach(button => {
                button.addEventListener(
                    'click',
                    async () => {
                        const storyId =
                            Number(
                                button.dataset
                                    .storyId
                                || 0
                            );

                        if (storyId <= 0) {
                            return;
                        }

                        if (
                            !confirm(
                                'Kapitel aus der Gesamtstory lösen? Der Text bleibt als eigenständige Story erhalten.'
                            )
                        ) {
                            return;
                        }

                        try {
                            await queueCollectionRequest(
                                'collection_remove_story',
                                {
                                    story_id:
                                        storyId,
                                }
                            );

                            location.reload();
                        } catch (_) {}
                    }
                );
            });


        document
            .querySelectorAll(
                '.story-collection-open-chapter'
            )
            .forEach(button => {
                button.addEventListener(
                    'click',
                    () => {
                        const storyId =
                            Number(
                                button.dataset
                                    .storyId
                                || 0
                            );

                        if (storyId > 0) {
                            location.href =
                                '/phan/stories?id='
                                + storyId
                                + '&edit=1';
                        }
                    }
                );
            });


        collectionDeleteButton
            ?.addEventListener(
                'click',
                async () => {
                    if (
                        currentCollectionId()
                        <= 0
                    ) {
                        return;
                    }

                    if (
                        !confirm(
                            'Gesamtstory wirklich löschen? Die Kapitel bleiben erhalten und werden zu eigenständigen Storys.'
                        )
                    ) {
                        return;
                    }

                    try {
                        await queueCollectionRequest(
                            'delete_collection'
                        );

                        location.href =
                            '/phan/stories';
                    } catch (_) {}
                }
            );
    }


    const editor =
        document.getElementById(
            'storyEditor'
        );

    if (!editor) {
        return;
    }

    const reader =
        document.getElementById(
            'storyReader'
        );

    const editModeButton =
        document.getElementById(
            'storyEditModeButton'
        );

    const readModeButton =
        document.getElementById(
            'storyReadModeButton'
        );

    const storyId =
        document.getElementById(
            'storyId'
        );

    const storyTitle =
        document.getElementById(
            'storyTitle'
        );

    const storyContent =
        document.getElementById(
            'storyContent'
        );

    const regionIdInput =
        document.getElementById(
            'storyRegionId'
        );

    const intimeYear =
        document.getElementById(
            'storyIntimeYear'
        );

    const intimeMonth =
        document.getElementById(
            'storyIntimeMonth'
        );

    const intimeDay =
        document.getElementById(
            'storyIntimeDay'
        );

    const storyCollectionId =
        document.getElementById(
            'storyCollectionId'
        );

    const storyChapterOrder =
        document.getElementById(
            'storyChapterOrder'
        );

    const storyOpenCollectionButton =
        document.getElementById(
            'storyOpenCollectionButton'
        );

    const characterInputs =
        document.getElementById(
            'storyCharacterInputs'
        );

    const autosaveStatus =
        document.getElementById(
            'storyAutosaveStatus'
        );

    const lastSaved =
        document.getElementById(
            'storyLastSaved'
        );

    const liveLength =
        document.getElementById(
            'storyLiveLength'
        );

    const deleteButton =
        document.getElementById(
            'storyDeleteButton'
        );

    const coverInput =
        document.getElementById(
            'storyCoverInput'
        );

    const coverChooseButton =
        document.getElementById(
            'storyCoverChooseButton'
        );

    const coverEmptyButton =
        document.getElementById(
            'storyCoverEmptyButton'
        );

    const coverRemoveButton =
        document.getElementById(
            'storyCoverRemoveButton'
        );

    const coverCropBox =
        document.getElementById(
            'storyCoverCropBox'
        );

    const coverCropImage =
        document.getElementById(
            'storyCoverCropImage'
        );

    const coverCropOverlay =
        document.getElementById(
            'storyCoverCropOverlay'
        );

    const coverCropButton =
        document.getElementById(
            'storyCoverCropButton'
        );

    const coverCropInputs = [
        document.getElementById('storyThumbX'),
        document.getElementById('storyThumbY'),
        document.getElementById('storyThumbW'),
        document.getElementById('storyThumbH')
    ];

    const regionTrigger =
        document.getElementById(
            'storyRegionTrigger'
        );

    const regionMenu =
        document.getElementById(
            'storyRegionMenu'
        );

    const regionSearch =
        document.getElementById(
            'storyRegionSearch'
        );

    const regionResults =
        document.getElementById(
            'storyRegionResults'
        );

    const selectedCharsEl =
        document.getElementById(
            'storySelectedChars'
        );

    const narratorIdInput =
        document.getElementById(
            'storyNarratorCharId'
        );

    const narratorPicker =
        document.getElementById(
            'storyNarratorPicker'
        );

    const narratorTrigger =
        document.getElementById(
            'storyNarratorTrigger'
        );

    const narratorMenu =
        document.getElementById(
            'storyNarratorMenu'
        );

    const narratorResults =
        document.getElementById(
            'storyNarratorResults'
        );

    const charSearch =
        document.getElementById(
            'storyCharSearch'
        );

    const charResults =
        document.getElementById(
            'storyCharResults'
        );

    let selectedCharIds =
        new Set(
            Array.from(
                characterInputs?.querySelectorAll(
                    'input[name="char_ids[]"]'
                )
                || []
            ).map(
                input => Number(input.value)
            )
        );

    let saveTimer = null;
    let saveChain = Promise.resolve();
    let statusTimer = null;


    function setStatus(
        text,
        isError = false
    ) {
        if (!autosaveStatus) {
            return;
        }

        clearTimeout(statusTimer);

        autosaveStatus.textContent = text;
        autosaveStatus.classList.toggle(
            'is-error',
            isError
        );

        if (
            text === 'Gespeichert'
            || text === ''
        ) {
            statusTimer = setTimeout(
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


    function selectedCharsArray() {
        return CHARS.filter(
            char =>
                selectedCharIds.has(
                    char.id
                )
        );
    }


    function syncCharacterInputs() {
        if (!characterInputs) {
            return;
        }

        characterInputs.innerHTML = '';

        selectedCharsArray()
            .forEach(char => {
                const input =
                    document.createElement('input');

                input.type = 'hidden';
                input.name = 'char_ids[]';
                input.value = String(char.id);

                characterInputs.appendChild(input);
            });
    }


    function currentRegion() {
        const id = Number(
            regionIdInput?.value
            || 0
        );

        return REGIONS.find(
            region => region.id === id
        ) || null;
    }


    function renderRegionTrigger() {
        if (!regionTrigger) {
            return;
        }

        const region =
            currentRegion();

        regionTrigger.innerHTML = '';

        if (region) {
            regionTrigger.appendChild(
                makeAvatar(
                    region,
                    'story-region-thumb'
                )
            );
        } else {
            const placeholder =
                document.createElement('span');

            placeholder.className =
                'story-region-placeholder';

            regionTrigger.appendChild(
                placeholder
            );
        }

        const text =
            document.createElement('span');

        text.className =
            'relations-char-selected-text';

        const strong =
            document.createElement('strong');

        strong.textContent =
            region?.name
            || 'Keine Region';

        const small =
            document.createElement('small');

        small.textContent =
            region
                ? 'Region ändern'
                : 'Region auswählen';

        text.appendChild(strong);
        text.appendChild(small);
        regionTrigger.appendChild(text);

        const arrow =
            document.createElement('span');

        arrow.textContent = '▾';
        regionTrigger.appendChild(arrow);
    }


    function renderRegionResults(query = '') {
        if (!regionResults) {
            return;
        }

        const needle = normalize(query);
        regionResults.innerHTML = '';

        const none =
            document.createElement('button');

        none.type = 'button';
        none.className =
            'relations-char-result story-region-result';

        const blank =
            document.createElement('span');

        blank.className =
            'story-region-placeholder';

        none.appendChild(blank);

        const noneText =
            document.createElement('span');

        noneText.className =
            'relations-char-result-text';

        noneText.innerHTML =
            '<strong>Keine Region</strong><small>Zuordnung entfernen</small>';

        none.appendChild(noneText);

        none.addEventListener(
            'click',
            () => {
                regionIdInput.value = '0';
                regionMenu.hidden = true;
                renderRegionTrigger();
                queueSaveNow();
            }
        );

        regionResults.appendChild(none);

        REGIONS
            .filter(
                region =>
                    !needle
                    || normalize(
                        region.name
                    ).includes(needle)
            )
            .forEach(region => {
                const button =
                    document.createElement('button');

                button.type = 'button';
                button.className =
                    'relations-char-result story-region-result';

                button.appendChild(
                    makeAvatar(
                        region,
                        'story-region-thumb'
                    )
                );

                const text =
                    document.createElement('span');

                text.className =
                    'relations-char-result-text';

                const strong =
                    document.createElement('strong');

                strong.textContent =
                    region.name;

                const small =
                    document.createElement('small');

                small.textContent =
                    region.id
                        === Number(regionIdInput.value)
                            ? 'Aktuell ausgewählt'
                            : 'Auswählen';

                text.appendChild(strong);
                text.appendChild(small);
                button.appendChild(text);

                button.addEventListener(
                    'click',
                    () => {
                        regionIdInput.value =
                            String(region.id);

                        regionMenu.hidden = true;
                        renderRegionTrigger();
                        queueSaveNow();
                    }
                );

                regionResults.appendChild(button);
            });
    }


    function currentNarrator() {
        const narratorId =
            Number(
                narratorIdInput?.value
                || 0
            );

        return CHARS.find(
            char =>
                char.id === narratorId
                && selectedCharIds.has(
                    char.id
                )
        ) || null;
    }


    function renderNarratorTrigger() {
        if (!narratorTrigger) {
            return;
        }

        const narrator =
            currentNarrator();

        narratorTrigger.innerHTML = '';

        if (narrator) {
            narratorTrigger.appendChild(
                makeAvatar(narrator)
            );
        } else {
            const blank =
                document.createElement('span');

            blank.className =
                'relations-char-avatar story-filter-placeholder';

            narratorTrigger.appendChild(blank);
        }

        const text =
            document.createElement('span');

        text.className =
            'relations-char-selected-text';

        const strong =
            document.createElement('strong');

        strong.textContent =
            narrator?.name
            || 'Kein Erzähler';

        const small =
            document.createElement('small');

        small.textContent =
            narrator
                ? 'Erzähler ändern'
                : (
                    selectedCharIds.size
                        ? 'Erzähler auswählen'
                        : 'Zuerst Charaktere hinzufügen'
                );

        text.appendChild(strong);
        text.appendChild(small);
        narratorTrigger.appendChild(text);

        const arrow =
            document.createElement('span');

        arrow.textContent = '▾';
        narratorTrigger.appendChild(arrow);

        narratorTrigger.disabled =
            selectedCharIds.size === 0;
    }


    function renderNarratorResults() {
        if (!narratorResults) {
            return;
        }

        narratorResults.innerHTML = '';

        const none =
            document.createElement('button');

        none.type = 'button';
        none.className =
            'relations-char-result';

        const blank =
            document.createElement('span');

        blank.className =
            'relations-char-avatar story-filter-placeholder';

        none.appendChild(blank);

        const noneText =
            document.createElement('span');

        noneText.className =
            'relations-char-result-text';

        const noneStrong =
            document.createElement('strong');

        noneStrong.textContent =
            'Kein Erzähler';

        const noneSmall =
            document.createElement('small');

        noneSmall.textContent =
            'Erzähler-Zuordnung entfernen';

        noneText.appendChild(noneStrong);
        noneText.appendChild(noneSmall);
        none.appendChild(noneText);

        none.addEventListener(
            'click',
            () => {
                narratorIdInput.value = '0';
                narratorMenu.hidden = true;
                renderNarratorTrigger();
                queueSaveNow();
            }
        );

        narratorResults.appendChild(none);

        selectedCharsArray()
            .forEach(char => {
                const button =
                    document.createElement('button');

                button.type = 'button';
                button.className =
                    'relations-char-result';

                button.appendChild(
                    makeAvatar(char)
                );

                const text =
                    document.createElement('span');

                text.className =
                    'relations-char-result-text';

                const strong =
                    document.createElement('strong');

                strong.textContent =
                    char.name;

                const small =
                    document.createElement('small');

                small.textContent =
                    char.id
                        === Number(
                            narratorIdInput.value
                            || 0
                        )
                            ? 'Aktuell ausgewählt'
                            : [
                                char.full,
                                char.species,
                                char.region,
                            ]
                                .filter(Boolean)
                                .join(' · ');

                text.appendChild(strong);
                text.appendChild(small);
                button.appendChild(text);

                button.addEventListener(
                    'click',
                    () => {
                        narratorIdInput.value =
                            String(char.id);

                        narratorMenu.hidden = true;

                        renderNarratorTrigger();
                        renderNarratorResults();
                        queueSaveNow();
                    }
                );

                narratorResults.appendChild(button);
            });
    }


    function ensureValidNarrator() {
        const narratorId =
            Number(
                narratorIdInput?.value
                || 0
            );

        if (
            narratorId > 0
            && !selectedCharIds.has(
                narratorId
            )
        ) {
            narratorIdInput.value = '0';
        }

        renderNarratorTrigger();
        renderNarratorResults();
    }


    function renderSelectedChars() {
        if (!selectedCharsEl) {
            return;
        }

        selectedCharsEl.innerHTML = '';

        const selected =
            selectedCharsArray();

        if (!selected.length) {
            const empty =
                document.createElement('div');

            empty.className =
                'relations-char-empty';

            empty.textContent =
                'Noch keine Charaktere zugewiesen.';

            selectedCharsEl.appendChild(empty);
            return;
        }

        selected.forEach(char => {
            const card =
                document.createElement('div');

            card.className =
                'relations-char-selected-card';

            card.appendChild(
                makeAvatar(char)
            );

            const text =
                document.createElement('span');

            text.className =
                'relations-char-selected-text';

            const strong =
                document.createElement('strong');

            strong.textContent =
                char.name;

            const small =
                document.createElement('small');

            small.textContent =
                [
                    char.full,
                    char.species,
                    char.region,
                ]
                    .filter(Boolean)
                    .join(' · ');

            text.appendChild(strong);
            text.appendChild(small);
            card.appendChild(text);

            const remove =
                document.createElement('button');

            remove.type = 'button';
            remove.className =
                'story-selected-char-remove';
            remove.textContent = '×';
            remove.title =
                'Charakter entfernen';

            remove.addEventListener(
                'click',
                () => {
                    selectedCharIds.delete(
                        char.id
                    );

                    syncCharacterInputs();
                    renderSelectedChars();
                    renderCharResults(
                        charSearch?.value
                        || ''
                    );
                    ensureValidNarrator();
                    queueSaveNow();
                }
            );

            card.appendChild(remove);
            selectedCharsEl.appendChild(card);
        });
    }


    function renderCharResults(query = '') {
        if (!charResults) {
            return;
        }

        const needle = normalize(query);

        const filtered =
            CHARS.filter(char => {
                if (
                    selectedCharIds.has(
                        char.id
                    )
                ) {
                    return false;
                }

                const text = normalize(
                    [
                        char.name,
                        char.full,
                        char.species,
                        char.occupation,
                        char.region,
                    ].join(' ')
                );

                return (
                    !needle
                    || text.includes(needle)
                );
            }).slice(0, 60);

        charResults.innerHTML = '';

        if (!filtered.length) {
            const empty =
                document.createElement('div');

            empty.className =
                'relations-char-empty';

            empty.textContent =
                needle
                    ? 'Keine weiteren Charaktere gefunden.'
                    : 'Alle Charaktere sind bereits ausgewählt.';

            charResults.appendChild(empty);
            return;
        }

        filtered.forEach(char => {
            const button =
                document.createElement('button');

            button.type = 'button';
            button.className =
                'relations-char-result';

            button.appendChild(
                makeAvatar(char)
            );

            const text =
                document.createElement('span');

            text.className =
                'relations-char-result-text';

            const strong =
                document.createElement('strong');

            strong.textContent =
                char.name;

            const small =
                document.createElement('small');

            small.textContent =
                [
                    char.full,
                    char.species,
                    char.occupation,
                    char.region,
                ]
                    .filter(Boolean)
                    .join(' · ');

            text.appendChild(strong);
            text.appendChild(small);
            button.appendChild(text);

            button.addEventListener(
                'click',
                () => {
                    selectedCharIds.add(
                        char.id
                    );

                    syncCharacterInputs();
                    renderSelectedChars();
                    ensureValidNarrator();

                    if (charSearch) {
                        charSearch.value = '';
                    }

                    renderCharResults('');
                    queueSaveNow();
                }
            );

            charResults.appendChild(button);
        });
    }


    function countWords(text) {
        const matches =
            String(text ?? '')
                .match(
                    /[\p{L}\p{N}]+(?:[’'’-][\p{L}\p{N}]+)*/gu
                );

        return matches?.length || 0;
    }


    function countCharacters(text) {
        return Array.from(
            String(text ?? '')
        ).length;
    }


    function renderLiveLength() {
        if (!liveLength) {
            return;
        }

        const text =
            storyContent?.value
            || '';

        liveLength.textContent =
            countWords(text).toLocaleString('de-DE')
            + ' Wörter · '
            + countCharacters(text).toLocaleString('de-DE')
            + ' Zeichen';
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

        if (Number(storyId.value) <= 0) {
            storyId.value =
                String(returnedId);

            const url =
                new URL(
                    window.location.href
                );

            url.searchParams.delete('new');
            url.searchParams.set(
                'id',
                String(returnedId)
            );
            url.searchParams.set(
                'edit',
                '1'
            );

            history.replaceState(
                null,
                '',
                url.pathname
                + url.search
            );

            if (deleteButton) {
                deleteButton.hidden = false;
            }
        }
    }


    async function performSave(
        coverFile = null
    ) {
        const data =
            new FormData(editor);

        data.set('ajax', '1');
        data.set('action', 'save');

        /*
         * Normale Autosaves dürfen eine einmal gewählte
         * Datei nicht immer wieder hochladen.
         */
        data.delete('cover_image');

        if (coverFile instanceof File) {
            data.set(
                'cover_image',
                coverFile,
                coverFile.name
            );
        }

        setStatus('Speichere…');

        const response =
            await fetch(
                '/phan/stories',
                {
                    method: 'POST',
                    body: data,
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest',
                    },
                    credentials:
                        'same-origin',
                }
            );

        const payload =
            await response
                .json()
                .catch(() => null);

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
            storyCollectionId
            && payload.collection_id !== undefined
        ) {
            storyCollectionId.value =
                payload.collection_id
                    ? String(
                        payload.collection_id
                    )
                    : '0';
        }

        if (
            storyChapterOrder
            && payload.chapter_order !== undefined
        ) {
            storyChapterOrder.value =
                payload.chapter_order
                    ? String(
                        payload.chapter_order
                    )
                    : '';

            storyChapterOrder.disabled =
                !payload.collection_id;
        }

        if (
            lastSaved
            && payload.updated_at
        ) {
            lastSaved.textContent =
                payload.updated_at;
        }

        setStatus('Gespeichert');

        return payload;
    }


    function queueSave(
        coverFile = null
    ) {
        saveChain =
            saveChain
                .catch(() => {})
                .then(
                    () => performSave(
                        coverFile
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


    function scheduleSave() {
        clearTimeout(saveTimer);

        saveTimer = setTimeout(
            () => {
                queueSave()
                    .catch(() => {});
            },
            450
        );
    }


    function queueSaveNow() {
        clearTimeout(saveTimer);

        queueSave()
            .catch(() => {});
    }


    function openCoverPicker() {
        coverInput?.click();
    }


    coverChooseButton?.addEventListener(
        'click',
        openCoverPicker
    );

    coverEmptyButton?.addEventListener(
        'click',
        openCoverPicker
    );


    coverInput?.addEventListener(
        'change',
        async () => {
            const file =
                coverInput.files?.[0];

            if (
                !(file instanceof File)
                || !file.type.startsWith(
                    'image/'
                )
            ) {
                return;
            }

            clearTimeout(saveTimer);

            try {
                await queueSave(file);

                /*
                 * Server-rendered Preview und ggf. neue Story-ID
                 * direkt sauber neu laden.
                 */
                location.reload();
            } catch (_) {}
        }
    );


    editor
        .querySelectorAll(
            'input[name="image_position"]'
        )
        .forEach(
            input => {
                input.addEventListener(
                    'change',
                    queueSaveNow
                );
            }
        );


    coverRemoveButton?.addEventListener(
        'click',
        async () => {
            const id = Number(
                storyId.value
                || 0
            );

            if (id <= 0) {
                return;
            }

            if (
                !confirm(
                    'Titelbild wirklich entfernen?'
                )
            ) {
                return;
            }

            const data =
                new FormData();

            data.set(
                'csrf',
                <?= json_encode(
                    $csrf,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                ) ?>
            );

            data.set(
                'action',
                'remove_image'
            );

            data.set(
                'id',
                String(id)
            );

            data.set(
                'ajax',
                '1'
            );

            setStatus('Speichere…');

            try {
                const response =
                    await fetch(
                        '/phan/stories',
                        {
                            method: 'POST',
                            body: data,
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest',
                            },
                            credentials:
                                'same-origin',
                        }
                    );

                const payload =
                    await response
                        .json()
                        .catch(() => null);

                if (
                    !response.ok
                    || !payload?.ok
                ) {
                    throw new Error(
                        payload?.message
                        || 'Titelbild konnte nicht entfernt werden.'
                    );
                }

                location.reload();

            } catch (error) {
                setStatus(
                    error.message
                    || 'Fehler',
                    true
                );
            }
        }
    );


    /* =====================================================
     * Thumbnail-Ausschnitt
     *
     * Das Originalbild bleibt vollständig sichtbar.
     * Der Rahmen hat exakt dasselbe Seitenverhältnis wie
     * die Story-Thumbnails: 220:140 = 88:56 = 11:7.
     * ===================================================== */

    let coverCropMode = false;
    let coverCropStart = null;


    function hasSavedCoverCrop() {
        const values =
            coverCropInputs.map(
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


    function showSavedCoverCrop() {
        if (
            !coverCropOverlay
            || !coverCropMode
            || !hasSavedCoverCrop()
        ) {
            if (coverCropOverlay) {
                coverCropOverlay.hidden = true;
            }

            return;
        }

        const [x, y, w, h] =
            coverCropInputs.map(
                input => parseFloat(
                    input.value
                )
            );

        coverCropOverlay.hidden = false;
        coverCropOverlay.style.left =
            (x * 100) + '%';
        coverCropOverlay.style.top =
            (y * 100) + '%';
        coverCropOverlay.style.width =
            (w * 100) + '%';
        coverCropOverlay.style.height =
            (h * 100) + '%';
    }


    function setCoverCropMode(enabled) {
        coverCropMode = Boolean(enabled);
        coverCropStart = null;

        coverCropBox?.classList.toggle(
            'is-crop-mode',
            coverCropMode
        );

        if (coverCropButton) {
            coverCropButton.textContent =
                coverCropMode
                    ? 'Ausschnitt abbrechen'
                    : 'Thumbnail-Ausschnitt setzen';
        }

        if (coverCropMode) {
            showSavedCoverCrop();
        } else if (coverCropOverlay) {
            coverCropOverlay.hidden = true;
        }
    }


    function coverCropPoint(event) {
        const rect =
            coverCropImage
                .getBoundingClientRect();

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


    function coverCropRect(start, current) {
        const dx =
            current.x - start.x;

        const dy =
            current.y - start.y;

        const boxW = Math.abs(dx);
        const boxH = Math.abs(dy);

        let width;
        let height;

        if (
            boxH > 0
            && (boxW / boxH) > STORY_THUMB_RATIO
        ) {
            height = boxH;
            width =
                height * STORY_THUMB_RATIO;
        } else {
            width = boxW;
            height =
                width / STORY_THUMB_RATIO;
        }

        const left =
            dx >= 0
                ? start.x
                : start.x - width;

        const top =
            dy >= 0
                ? start.y
                : start.y - height;

        return {
            left,
            top,
            width,
            height
        };
    }


    function renderCoverCropRect(rect) {
        if (!coverCropOverlay) {
            return;
        }

        coverCropOverlay.hidden = false;
        coverCropOverlay.style.left =
            rect.left + 'px';
        coverCropOverlay.style.top =
            rect.top + 'px';
        coverCropOverlay.style.width =
            rect.width + 'px';
        coverCropOverlay.style.height =
            rect.height + 'px';
    }


    async function saveCoverCrop() {
        const id = Number(
            storyId?.value
            || 0
        );

        if (
            id <= 0
            || !hasSavedCoverCrop()
        ) {
            return;
        }

        const data =
            new FormData();

        data.set(
            'csrf',
            <?= json_encode(
                $csrf,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            ) ?>
        );

        data.set('ajax', '1');
        data.set('action', 'save_crop');
        data.set('id', String(id));

        data.set(
            'thumb_x',
            coverCropInputs[0].value
        );

        data.set(
            'thumb_y',
            coverCropInputs[1].value
        );

        data.set(
            'thumb_w',
            coverCropInputs[2].value
        );

        data.set(
            'thumb_h',
            coverCropInputs[3].value
        );

        setStatus('Speichere…');

        const response =
            await fetch(
                '/phan/stories',
                {
                    method: 'POST',
                    body: data,
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest',
                    },
                    credentials:
                        'same-origin',
                }
            );

        const payload =
            await response
                .json()
                .catch(() => null);

        if (
            !response.ok
            || !payload?.ok
        ) {
            throw new Error(
                payload?.message
                || 'Thumbnail-Ausschnitt konnte nicht gespeichert werden.'
            );
        }

        if (
            lastSaved
            && payload.updated_at
        ) {
            lastSaved.textContent =
                payload.updated_at;
        }

        setStatus('Gespeichert');
    }


    function queueCoverCropSave() {
        saveChain =
            saveChain
                .catch(() => {})
                .then(saveCoverCrop)
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


    if (
        coverCropButton
        && coverCropBox
        && coverCropImage
        && coverCropOverlay
    ) {
        coverCropButton.addEventListener(
            'click',
            () => {
                setCoverCropMode(
                    !coverCropMode
                );
            }
        );


        coverCropBox.addEventListener(
            'pointerdown',
            event => {
                if (!coverCropMode) {
                    return;
                }

                if (
                    event.button !== undefined
                    && event.button !== 0
                ) {
                    return;
                }

                event.preventDefault();

                coverCropStart =
                    coverCropPoint(event);

                coverCropOverlay.hidden = false;

                try {
                    coverCropBox.setPointerCapture(
                        event.pointerId
                    );
                } catch (_) {}
            }
        );


        coverCropBox.addEventListener(
            'pointermove',
            event => {
                if (
                    !coverCropMode
                    || !coverCropStart
                ) {
                    return;
                }

                const current =
                    coverCropPoint(event);

                renderCoverCropRect(
                    coverCropRect(
                        coverCropStart,
                        current
                    )
                );
            }
        );


        coverCropBox.addEventListener(
            'pointerup',
            event => {
                if (
                    !coverCropMode
                    || !coverCropStart
                ) {
                    return;
                }

                const current =
                    coverCropPoint(event);

                const crop =
                    coverCropRect(
                        coverCropStart,
                        current
                    );

                coverCropStart = null;

                if (
                    crop.width < 12
                    || crop.height < 8
                ) {
                    showSavedCoverCrop();
                    return;
                }

                const imageRect =
                    current.rect;

                coverCropInputs[0].value =
                    (
                        crop.left
                        / imageRect.width
                    ).toFixed(6);

                coverCropInputs[1].value =
                    (
                        crop.top
                        / imageRect.height
                    ).toFixed(6);

                coverCropInputs[2].value =
                    (
                        crop.width
                        / imageRect.width
                    ).toFixed(6);

                coverCropInputs[3].value =
                    (
                        crop.height
                        / imageRect.height
                    ).toFixed(6);

                showSavedCoverCrop();

                queueCoverCropSave()
                    .catch(() => {});
            }
        );


        coverCropBox.addEventListener(
            'pointercancel',
            () => {
                coverCropStart = null;
                showSavedCoverCrop();
            }
        );
    }


    storyTitle?.addEventListener(
        'input',
        scheduleSave
    );

    intimeYear?.addEventListener(
        'input',
        scheduleSave
    );

    intimeMonth?.addEventListener(
        'change',
        queueSaveNow
    );

    intimeDay?.addEventListener(
        'change',
        queueSaveNow
    );

    storyCollectionId?.addEventListener(
        'change',
        () => {
            const collectionId =
                Number(
                    storyCollectionId.value
                    || 0
                );

            if (storyChapterOrder) {
                storyChapterOrder.disabled =
                    collectionId <= 0;

                if (collectionId <= 0) {
                    storyChapterOrder.value = '';
                } else {
                    /*
                     * Leer lassen -> Server hängt das Kapitel
                     * automatisch ans Ende der Gesamtstory.
                     */
                    storyChapterOrder.value = '';
                }
            }

            if (storyOpenCollectionButton) {
                storyOpenCollectionButton.hidden =
                    collectionId <= 0;
            }

            queueSaveNow();
        }
    );

    storyChapterOrder?.addEventListener(
        'input',
        scheduleSave
    );

    storyOpenCollectionButton
        ?.addEventListener(
            'click',
            () => {
                const collectionId =
                    Number(
                        storyCollectionId?.value
                        || 0
                    );

                if (collectionId > 0) {
                    location.href =
                        '/phan/stories?collection='
                        + collectionId;
                }
            }
        );

    storyContent?.addEventListener(
        'input',
        () => {
            renderLiveLength();
            scheduleSave();
        }
    );


    regionTrigger?.addEventListener(
        'click',
        event => {
            event.stopPropagation();
            regionMenu.hidden =
                !regionMenu.hidden;

            renderRegionResults(
                regionSearch?.value
                || ''
            );

            regionSearch?.focus();
        }
    );

    regionSearch?.addEventListener(
        'input',
        () => renderRegionResults(
            regionSearch.value
        )
    );

    narratorTrigger?.addEventListener(
        'click',
        event => {
            event.stopPropagation();

            if (
                narratorTrigger.disabled
                || !narratorMenu
            ) {
                return;
            }

            narratorMenu.hidden =
                !narratorMenu.hidden;

            renderNarratorResults();
        }
    );


    charSearch?.addEventListener(
        'input',
        () => renderCharResults(
            charSearch.value
        )
    );

    charSearch?.addEventListener(
        'focus',
        () => renderCharResults(
            charSearch.value
        )
    );

    document.addEventListener(
        'click',
        event => {
            if (
                regionMenu
                && !regionMenu.hidden
                && !event.target.closest(
                    '#storyRegionPicker'
                )
            ) {
                regionMenu.hidden = true;
            }

            if (
                narratorMenu
                && !narratorMenu.hidden
                && !event.target.closest(
                    '#storyNarratorPicker'
                )
            ) {
                narratorMenu.hidden = true;
            }
        }
    );


    editModeButton?.addEventListener(
        'click',
        () => {
            reader.hidden = true;
            editor.hidden = false;
            editModeButton.hidden = true;
            readModeButton.hidden = false;

            const url =
                new URL(
                    window.location.href
                );

            url.searchParams.set(
                'edit',
                '1'
            );

            history.replaceState(
                null,
                '',
                url.pathname
                + url.search
            );

            storyTitle?.focus();
        }
    );


    readModeButton?.addEventListener(
        'click',
        async () => {
            clearTimeout(saveTimer);

            try {
                await queueSave();
            } catch (_) {
                return;
            }

            const id = Number(
                storyId.value
                || 0
            );

            if (id <= 0) {
                return;
            }

            location.href =
                '/phan/stories?id='
                + id;
        }
    );


    deleteButton?.addEventListener(
        'click',
        async () => {
            const id = Number(
                storyId.value
                || 0
            );

            if (id <= 0) {
                return;
            }

            if (
                !confirm(
                    'Story wirklich löschen?'
                )
            ) {
                return;
            }

            const data =
                new FormData();

            data.set(
                'csrf',
                <?= json_encode(
                    $csrf,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                ) ?>
            );

            data.set('action', 'delete');
            data.set('id', String(id));
            data.set('ajax', '1');

            setStatus('Lösche…');

            try {
                const response =
                    await fetch(
                        '/phan/stories',
                        {
                            method: 'POST',
                            body: data,
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest',
                            },
                            credentials:
                                'same-origin',
                        }
                    );

                const payload =
                    await response
                        .json()
                        .catch(() => null);

                if (
                    !response.ok
                    || !payload?.ok
                ) {
                    throw new Error(
                        payload?.message
                        || 'Löschen fehlgeschlagen.'
                    );
                }

                location.href =
                    '/phan/stories';

            } catch (error) {
                setStatus(
                    error.message
                    || 'Fehler',
                    true
                );
            }
        }
    );


    renderRegionTrigger();
    renderRegionResults();
    renderSelectedChars();
    renderCharResults();
    ensureValidNarrator();
    renderLiveLength();

})();
</script>

</body>
</html>
