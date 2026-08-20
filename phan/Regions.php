<?php
// phan/Regions.php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$phanconn->set_charset('utf8mb4');

const PHAN_REGION_UPLOAD_DIR = __DIR__ . '/../uploads/phan/regions';
const PHAN_REGION_PUBLIC_DIR = '/uploads/phan/regions';
const PHAN_REGION_THUMB_DIR = __DIR__ . '/../uploads/phan/regions/thumbs';
const PHAN_REGION_MAX_IMAGE_BYTES = 12582912; // 12 MB
const PHAN_REGION_THUMB_WIDTH = 320;
const PHAN_REGION_THUMB_HEIGHT = 180;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['phan_csrf'])) {
    $_SESSION['phan_csrf'] = bin2hex(random_bytes(32));
}

$csrf = (string)$_SESSION['phan_csrf'];

function preg_h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function preg_json_out(array $payload, int $status = 200): never
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

function preg_exec(mysqli $db, string $sql, array $params = []): mysqli_stmt
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

function preg_one(
    mysqli $db,
    string $sql,
    array $params = []
): ?array {
    $stmt = preg_exec($db, $sql, $params);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

function preg_all(
    mysqli $db,
    string $sql,
    array $params = []
): array {
    $stmt = preg_exec($db, $sql, $params);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function preg_format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    try {
        return (new DateTimeImmutable($value))->format('d.m.Y, H:i');
    } catch (Throwable) {
        return '—';
    }
}

function preg_region_disk_path(?string $publicPath): ?string
{
    if (
        !$publicPath
        || !str_starts_with($publicPath, PHAN_REGION_PUBLIC_DIR . '/')
    ) {
        return null;
    }

    $filename = basename($publicPath);

    if ($filename === '' || $filename === '.' || $filename === '..') {
        return null;
    }

    return PHAN_REGION_UPLOAD_DIR . '/' . $filename;
}

function preg_delete_image(?string $publicPath): void
{
    $path = preg_region_disk_path($publicPath);

    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

function preg_upload_image(array $file, int $regionId): ?string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE =>
                'Das Bild ist größer als das PHP-Limit upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE =>
                'Das Bild ist größer als das erlaubte Formular-Limit.',
            UPLOAD_ERR_PARTIAL =>
                'Das Bild wurde nur teilweise hochgeladen.',
            UPLOAD_ERR_NO_TMP_DIR =>
                'PHP hat kein temporäres Upload-Verzeichnis.',
            UPLOAD_ERR_CANT_WRITE =>
                'PHP konnte die Upload-Datei nicht auf die Festplatte schreiben.',
            UPLOAD_ERR_EXTENSION =>
                'Eine PHP-Erweiterung hat den Upload abgebrochen.',
        ];

        throw new RuntimeException(
            $uploadErrors[$error]
            ?? ('Bild-Upload fehlgeschlagen (PHP-Code ' . $error . ').')
        );
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Ungültige Upload-Datei.');
    }

    if ($size <= 0 || $size > PHAN_REGION_MAX_IMAGE_BYTES) {
        throw new RuntimeException('Das Bild darf maximal 12 MB groß sein.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime]) || @getimagesize($tmp) === false) {
        throw new RuntimeException(
            'Erlaubt sind ausschließlich gültige JPG-, PNG- und WebP-Bilder.'
        );
    }

    if (
        !is_dir(PHAN_REGION_UPLOAD_DIR)
        && !mkdir(PHAN_REGION_UPLOAD_DIR, 0755, true)
        && !is_dir(PHAN_REGION_UPLOAD_DIR)
    ) {
        throw new RuntimeException(
            'Upload-Verzeichnis konnte nicht erstellt werden.'
        );
    }

    if (!is_writable(PHAN_REGION_UPLOAD_DIR)) {
        throw new RuntimeException(
            'Upload-Verzeichnis ist für PHP nicht beschreibbar.'
        );
    }

    $filename = sprintf(
        'region_%d_%s.%s',
        $regionId,
        bin2hex(random_bytes(8)),
        $allowed[$mime]
    );

    $target = PHAN_REGION_UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Bild konnte nicht gespeichert werden.');
    }

    @chmod($target, 0644);

    return PHAN_REGION_PUBLIC_DIR . '/' . $filename;
}



function preg_region_thumb_path(
    int $regionId,
    string $sourcePath
): string {
    $mtime = (int)@filemtime($sourcePath);

    return PHAN_REGION_THUMB_DIR
        . '/region_'
        . $regionId
        . '_'
        . $mtime
        . '.jpg';
}

function preg_cleanup_region_thumbs(int $regionId): void
{
    if (!is_dir(PHAN_REGION_THUMB_DIR)) {
        return;
    }

    foreach (
        glob(
            PHAN_REGION_THUMB_DIR
            . '/region_'
            . $regionId
            . '_*.jpg'
        ) ?: []
        as $path
    ) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function preg_make_region_thumb(
    int $regionId,
    string $sourcePath
): string {
    if (!extension_loaded('gd')) {
        throw new RuntimeException(
            'PHP-GD ist für Region-Thumbnails nicht installiert.'
        );
    }

    if (
        !is_dir(PHAN_REGION_THUMB_DIR)
        && !mkdir(PHAN_REGION_THUMB_DIR, 0755, true)
        && !is_dir(PHAN_REGION_THUMB_DIR)
    ) {
        throw new RuntimeException(
            'Thumbnail-Verzeichnis konnte nicht erstellt werden.'
        );
    }

    $target = preg_region_thumb_path(
        $regionId,
        $sourcePath
    );

    if (is_file($target)) {
        return $target;
    }

    $info = @getimagesize($sourcePath);

    if (!$info) {
        throw new RuntimeException(
            'Regionsbild konnte nicht gelesen werden.'
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
            'Regionsbild konnte nicht für Thumbnail geladen werden.'
        );
    }

    $targetRatio =
        PHAN_REGION_THUMB_WIDTH
        / PHAN_REGION_THUMB_HEIGHT;

    $sourceRatio =
        $srcW / $srcH;

    if ($sourceRatio > $targetRatio) {
        $cropH = $srcH;
        $cropW = (int)round(
            $srcH * $targetRatio
        );
        $srcX = (int)round(
            ($srcW - $cropW) / 2
        );
        $srcY = 0;
    } else {
        $cropW = $srcW;
        $cropH = (int)round(
            $srcW / $targetRatio
        );
        $srcX = 0;
        $srcY = (int)round(
            ($srcH - $cropH) / 2
        );
    }

    $dst = imagecreatetruecolor(
        PHAN_REGION_THUMB_WIDTH,
        PHAN_REGION_THUMB_HEIGHT
    );

    imagecopyresampled(
        $dst,
        $src,
        0,
        0,
        $srcX,
        $srcY,
        PHAN_REGION_THUMB_WIDTH,
        PHAN_REGION_THUMB_HEIGHT,
        $cropW,
        $cropH
    );

    if (!imagejpeg($dst, $target, 82)) {
        imagedestroy($src);
        imagedestroy($dst);

        throw new RuntimeException(
            'Thumbnail konnte nicht gespeichert werden.'
        );
    }

    imagedestroy($src);
    imagedestroy($dst);

    @chmod($target, 0644);

    preg_cleanup_region_thumbs($regionId);

    /*
     * cleanup hat auch das gerade erzeugte Bild getroffen,
     * daher genau dieses nach dem Cleanup erneut erzeugen,
     * falls mehrere alte Versionen vorhanden waren.
     */
    if (!is_file($target)) {
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
                'Regionsbild konnte nicht für Thumbnail geladen werden.'
            );
        }

        $dst = imagecreatetruecolor(
            PHAN_REGION_THUMB_WIDTH,
            PHAN_REGION_THUMB_HEIGHT
        );

        imagecopyresampled(
            $dst,
            $src,
            0,
            0,
            $srcX,
            $srcY,
            PHAN_REGION_THUMB_WIDTH,
            PHAN_REGION_THUMB_HEIGHT,
            $cropW,
            $cropH
        );

        imagejpeg($dst, $target, 82);

        imagedestroy($src);
        imagedestroy($dst);

        @chmod($target, 0644);
    }

    return $target;
}


/* =========================================================
 * Geschütztes Regionenbild / Thumbnail
 * ========================================================= */

if (
    isset($_GET['image'])
    || isset($_GET['thumb'])
) {
    $isThumb = isset($_GET['thumb']);

    $imageRegionId = max(
        0,
        (int)(
            $isThumb
                ? $_GET['thumb']
                : $_GET['image']
        )
    );

    if ($imageRegionId <= 0) {
        http_response_code(404);
        exit;
    }

    $imageRow = preg_one(
        $phanconn,
        'SELECT image_path
         FROM regions
         WHERE id = ?',
        [$imageRegionId]
    );

    $sourcePath = preg_region_disk_path(
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
            $diskPath = preg_make_region_thumb(
                $imageRegionId,
                $sourcePath
            );

            $mime = 'image/jpeg';
        } else {
            $diskPath = $sourcePath;

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($diskPath);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        exit;
    }

    if (
        !in_array(
            $mime,
            ['image/jpeg', 'image/png', 'image/webp'],
            true
        )
    ) {
        http_response_code(415);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($diskPath));
    header('Cache-Control: private, max-age=86400');
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
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Ungültiges Formular-Token.');
        }

        $action = (string)($_POST['action'] ?? 'save');
        $id = max(0, (int)($_POST['id'] ?? 0));

        if ($action === 'delete') {
            if ($id > 0) {
                $region = preg_one(
                    $phanconn,
                    'SELECT image_path FROM regions WHERE id = ?',
                    [$id]
                );

                if ($region) {
                    preg_delete_image($region['image_path'] ?? null);
                    preg_cleanup_region_thumbs($id);

                    preg_exec(
                        $phanconn,
                        'DELETE FROM regions WHERE id = ?',
                        [$id]
                    )->close();
                }
            }

            if ($isAjax) {
                preg_json_out([
                    'ok' => true,
                    'deleted' => true,
                ]);
            }

            header('Location: /phan/regions?deleted=1', true, 303);
            exit;
        }

        if ($action === 'remove_image') {
            if ($id <= 0) {
                throw new RuntimeException(
                    'Region wurde noch nicht angelegt.'
                );
            }

            $region = preg_one(
                $phanconn,
                'SELECT image_path FROM regions WHERE id = ?',
                [$id]
            );

            if ($region) {
                preg_delete_image($region['image_path'] ?? null);
                preg_cleanup_region_thumbs($id);

                preg_exec(
                    $phanconn,
                    'UPDATE regions
                     SET image_path = NULL
                     WHERE id = ?',
                    [$id]
                )->close();
            }

            if ($isAjax) {
                preg_json_out([
                    'ok' => true,
                    'id' => $id,
                    'image_removed' => true,
                ]);
            }

            header(
                'Location: /phan/regions?id=' . $id,
                true,
                303
            );
            exit;
        }

        if ($action !== 'save') {
            throw new RuntimeException('Unbekannte Aktion.');
        }

        $fields = [
            trim((string)($_POST['title'] ?? '')),
            trim((string)($_POST['population'] ?? '')),
            trim((string)($_POST['properties'] ?? '')),
            trim((string)($_POST['climate'] ?? '')),
            trim((string)($_POST['culture'] ?? '')),
            trim((string)($_POST['government'] ?? '')),
            trim((string)($_POST['economy'] ?? '')),
            trim((string)($_POST['conflicts'] ?? '')),
            trim((string)($_POST['notable_places'] ?? '')),
            trim((string)($_POST['notes'] ?? '')),
        ];

        if ($fields[0] === '') {
            throw new RuntimeException(
                'Bitte zuerst einen Titel eingeben.'
            );
        }

        if ($id > 0) {
            preg_exec(
                $phanconn,
                '
                UPDATE regions
                SET
                    title = ?,
                    population = ?,
                    properties = ?,
                    climate = ?,
                    culture = ?,
                    government = ?,
                    economy = ?,
                    conflicts = ?,
                    notable_places = ?,
                    notes = ?
                WHERE id = ?
                ',
                [...$fields, $id]
            )->close();

        } else {
            $stmt = preg_exec(
                $phanconn,
                '
                INSERT INTO regions (
                    title,
                    population,
                    properties,
                    climate,
                    culture,
                    government,
                    economy,
                    conflicts,
                    notable_places,
                    notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ',
                $fields
            );

            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        $imageChanged = false;

        $newImage = isset($_FILES['image'])
            ? preg_upload_image($_FILES['image'], $id)
            : null;

        if ($newImage !== null) {
            $old = preg_one(
                $phanconn,
                'SELECT image_path FROM regions WHERE id = ?',
                [$id]
            );

            preg_delete_image($old['image_path'] ?? null);
            preg_cleanup_region_thumbs($id);

            preg_exec(
                $phanconn,
                'UPDATE regions
                 SET image_path = ?
                 WHERE id = ?',
                [$newImage, $id]
            )->close();

            $imageChanged = true;
        }

        if ($isAjax) {
            $savedRow = preg_one(
                $phanconn,
                'SELECT updated_at FROM regions WHERE id = ?',
                [$id]
            );

            preg_json_out([
                'ok' => true,
                'id' => $id,
                'image_changed' => $imageChanged,
                'image_url' => $imageChanged
                    ? '/phan/regions?image=' . $id
                    : null,
                'updated_at' => preg_format_datetime(
                    $savedRow['updated_at'] ?? null
                ),
            ]);
        }

        header(
            'Location: /phan/regions?id=' . $id . '&saved=1',
            true,
            303
        );
        exit;

    } catch (Throwable $e) {
        if ($isAjax) {
            preg_json_out(
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

$regions = preg_all(
    $phanconn,
    '
    SELECT
        r.*,
        COUNT(c.id) AS char_count
    FROM regions r
    LEFT JOIN chars c
        ON c.region_id = r.id
    GROUP BY r.id
    ORDER BY r.title
    '
);

$id = max(0, (int)($_GET['id'] ?? 0));
$isNew = isset($_GET['new']);
$region = null;

if ($id > 0) {
    $region = preg_one(
        $phanconn,
        'SELECT * FROM regions WHERE id = ?',
        [$id]
    );

    if (!$region) {
        http_response_code(404);
        exit('Region nicht gefunden.');
    }

} elseif ($isNew) {
    $region = [
        'id' => 0,
        'title' => '',
        'population' => '',
        'properties' => '',
        'climate' => '',
        'culture' => '',
        'government' => '',
        'economy' => '',
        'conflicts' => '',
        'notable_places' => '',
        'notes' => '',
        'image_path' => null,
        'updated_at' => null,
    ];
}

if (isset($_GET['saved'])) {
    $flash = 'Gespeichert.';
}

if (isset($_GET['deleted'])) {
    $flash =
        'Region gelöscht. Charaktere der Region bleiben erhalten '
        . 'und werden auf „keine Region“ gesetzt.';
}


/* =========================================================
 * Rendering
 * ========================================================= */

$page_title = 'Regionen';

require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div
    class="
        phan-region-page
        <?= (
            $region
            && !empty($region['image_path'])
        )
            ? 'has-region-page-background'
            : ''
        ?>
    "
    <?php if (
        $region
        && !empty($region['image_path'])
    ): ?>
        style="
            --region-background-image:
            url('/phan/regions?image=<?= (int)$region['id'] ?>');
        "
    <?php endif; ?>
>

    <div class="phan-region-head">

        <h1 class="ueberschrift phan-title">
            Regionen
        </h1>

        <div class="phan-region-head-actions">

            <?php if ($region): ?>

                <span
                    class="phan-autosave-status"
                    id="regionAutosaveStatus"
                    aria-live="polite"
                ></span>

                <div class="phan-last-saved">
                    Zuletzt gespeichert am
                    <span id="regionLastSavedValue">
                        <?= preg_h(
                            preg_format_datetime(
                                $region['updated_at'] ?? null
                            )
                        ) ?>
                    </span>
                </div>

            <?php endif; ?>

            <button
                type="button"
                onclick="location.href='/phan/regions?new=1'"
            >
                + Region
            </button>

        </div>

    </div>


    <?php if ($flash): ?>
        <div class="phan-region-msg">
            <?= preg_h($flash) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="phan-region-msg phan-region-error">
            <?= preg_h($error) ?>
        </div>
    <?php endif; ?>


    <div class="phan-region-layout">

        <div class="phan-region-list">

            <?php if (!$regions): ?>
                <div class="phan-region-empty">
                    Noch keine Regionen.
                </div>
            <?php endif; ?>


            <?php foreach ($regions as $r): ?>

                <div
                    class="
                        phan-region-item
                        <?= $id === (int)$r['id'] ? 'active' : '' ?>
                    "
                    data-href="/phan/regions?id=<?= (int)$r['id'] ?>"
                >

                    <?php if (!empty($r['image_path'])): ?>

                        <img
                            class="phan-region-thumb"
                            src="/phan/regions?thumb=<?= (int)$r['id'] ?>"
                            alt="<?= preg_h($r['title']) ?>"
                        >

                    <?php else: ?>

                        <div
                            class="
                                phan-region-thumb
                                phan-region-noimg
                            "
                        >
                            kein Bild
                        </div>

                    <?php endif; ?>


                    <div class="phan-region-meta">

                        <strong>
                            <?= preg_h($r['title']) ?>
                        </strong>

                        <!-- <span>
                            <?= preg_h(
                                $r['population']
                                    ?: 'Population: —'
                            ) ?>
                        </span> -->

                        <span>
                            <?= (int)$r['char_count'] ?>
                            Charakter<?= (int)$r['char_count'] === 1 ? '' : 'e' ?>
                        </span>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>


        <div class="phan-region-card">

            <?php if (!$region): ?>

                <div class="phan-region-select-hint">
                    Region links auswählen oder eine neue Region anlegen.
                </div>

            <?php else: ?>

                <form
                    method="post"
                    enctype="multipart/form-data"
                    id="regionForm"
                    autocomplete="off"
                >

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= preg_h($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="save"
                    >

                    <input
                        type="hidden"
                        name="id"
                        id="regionId"
                        value="<?= (int)$region['id'] ?>"
                    >

                    <input
                        type="file"
                        name="image"
                        id="regionImageInput"
                        class="phan-image-upload-input"
                        accept="image/jpeg,image/png,image/webp"
                    >


                    <div
                        class="
                            phan-region-image-controls
                            phan-region-image-dropzone
                        "
                        id="regionImageControls"
                    >

                        <button
                            type="button"
                            id="regionImagePicker"
                        >
                            <?= !empty($region['image_path'])
                                ? 'Bild ersetzen'
                                : 'Bild hochladen'
                            ?>
                        </button>

                        <span class="phan-region-image-droptext">
                            oder Bild hierher ziehen
                        </span>

                        <?php if (!empty($region['image_path'])): ?>

                            <button
                                type="button"
                                class="phan-region-danger"
                                id="regionRemoveImageButton"
                            >
                                Bild entfernen
                            </button>

                        <?php endif; ?>

                    </div>


                    <div class="phan-region-form">

                        <label class="phan-region-wide">
                            Titel
                            <input
                                name="title"
                                id="regionTitle"
                                required
                                value="<?= preg_h($region['title']) ?>"
                            >
                        </label>


                        <label>
                            Population
                            <textarea
                                name="population"
                                rows="4"
                            ><?= preg_h($region['population']) ?></textarea>
                        </label>


                        <label>
                            Eigenschaften
                            <textarea
                                name="properties"
                                rows="4"
                            ><?= preg_h($region['properties']) ?></textarea>
                        </label>


                        <label>
                            Klima
                            <textarea
                                name="climate"
                                rows="5"
                            ><?= preg_h($region['climate']) ?></textarea>
                        </label>


                        <label>
                            Kultur
                            <textarea
                                name="culture"
                                rows="5"
                            ><?= preg_h($region['culture']) ?></textarea>
                        </label>


                        <label>
                            Regierung
                            <textarea
                                name="government"
                                rows="5"
                            ><?= preg_h($region['government']) ?></textarea>
                        </label>


                        <label>
                            Wirtschaft
                            <textarea
                                name="economy"
                                rows="5"
                            ><?= preg_h($region['economy']) ?></textarea>
                        </label>


                        <label>
                            Konflikte
                            <textarea
                                name="conflicts"
                                rows="5"
                            ><?= preg_h($region['conflicts']) ?></textarea>
                        </label>


                        <label>
                            Wichtige Orte
                            <textarea
                                name="notable_places"
                                rows="5"
                            ><?= preg_h($region['notable_places']) ?></textarea>
                        </label>


                        <!-- <label>
                            Notizen
                            <textarea
                                name="notes"
                                rows="5"
                            ><?= preg_h($region['notes']) ?></textarea>
                        </label> -->

                    </div>


                    <div class="phan-region-bottom-actions">

                        <button
                            type="button"
                            class="phan-region-danger"
                            id="regionDeleteButton"
                            <?= (int)$region['id'] <= 0
                                ? 'hidden'
                                : ''
                            ?>
                        >
                            Region löschen
                        </button>

                    </div>

                </form>

            <?php endif; ?>

        </div>

    </div>

</div>


<script>
(() => {
    'use strict';

    document
        .querySelectorAll('.phan-region-item')
        .forEach(item => {
            item.addEventListener(
                'click',
                () => {
                    location.href = item.dataset.href;
                }
            );
        });

    const form =
        document.getElementById('regionForm');

    if (!form) {
        return;
    }

    const regionId =
        document.getElementById('regionId');

    const regionTitle =
        document.getElementById('regionTitle');

    const autosaveStatus =
        document.getElementById('regionAutosaveStatus');

    const lastSavedValue =
        document.getElementById('regionLastSavedValue');

    const imageInput =
        document.getElementById('regionImageInput');

    const imagePicker =
        document.getElementById('regionImagePicker');

    const removeImageButton =
        document.getElementById('regionRemoveImageButton');

    const deleteButton =
        document.getElementById('regionDeleteButton');

    let saveTimer = null;
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

        if (text === 'Gespeichert' || text === '') {
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

        if (Number(regionId.value) <= 0) {
            regionId.value = String(returnedId);

            history.replaceState(
                null,
                '',
                '/phan/regions?id=' + returnedId
            );

            if (deleteButton) {
                deleteButton.hidden = false;
            }
        }
    }


    function titleReady() {
        return Boolean(
            regionTitle
            && regionTitle.value.trim() !== ''
        );
    }


    async function performRequest(
        action = 'save',
        file = null
    ) {
        if (
            action === 'save'
            && !titleReady()
        ) {
            setStatus(
                'Erst Titel eingeben',
                true
            );

            return {
                ok: false,
                skipped: true
            };
        }

        const data = new FormData(form);

        data.set('ajax', '1');
        data.set('action', action);
        data.delete('image');

        if (file instanceof File) {
            data.set('image', file, file.name);
        }

        setStatus('Speichere…');

        const response = await fetch(
            '/phan/regions',
            {
                method: 'POST',
                body: data,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
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

        if (!response.ok || !payload?.ok) {
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
        file = null
    ) {
        saveChain =
            saveChain
                .catch(() => {})
                .then(
                    () => performRequest(
                        action,
                        file
                    )
                )
                .catch(error => {
                    setStatus(
                        error.message || 'Fehler',
                        true
                    );

                    throw error;
                });

        return saveChain;
    }


    function scheduleAutosave() {
        window.clearTimeout(saveTimer);

        if (!titleReady()) {
            setStatus(
                'Erst Titel eingeben',
                true
            );

            return;
        }

        saveTimer = window.setTimeout(
            () => {
                queueRequest('save')
                    .catch(() => {});
            },
            450
        );
    }


    form
        .querySelectorAll(
            'input:not([type="hidden"]):not([type="file"]), textarea'
        )
        .forEach(field => {
            field.addEventListener(
                'input',
                scheduleAutosave
            );
        });


    async function uploadImage(file) {
        if (
            !(file instanceof File)
            || !file.type.startsWith('image/')
        ) {
            return;
        }

        if (!titleReady()) {
            setStatus(
                'Erst Titel eingeben',
                true
            );

            regionTitle?.focus();
            return;
        }

        try {
            const payload = await queueRequest(
                'save',
                file
            );

            const id = Number(payload?.id ?? 0);

            if (id > 0) {
                location.replace(
                    '/phan/regions?id=' + id
                );
            }

        } catch (_) {}
    }


    if (
        imagePicker
        && imageInput
    ) {
        imagePicker.addEventListener(
            'click',
            () => imageInput.click()
        );
    }


    if (imageInput) {
        imageInput.addEventListener(
            'change',
            () => {
                const file =
                    imageInput.files?.[0];

                if (file) {
                    uploadImage(file);
                }
            }
        );
    }


    document
        .querySelectorAll(
            '.phan-region-image-dropzone'
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

                    const file =
                        event.dataTransfer
                            ?.files?.[0];

                    if (file) {
                        uploadImage(file);
                    }
                }
            );
        });


    if (removeImageButton) {
        removeImageButton.addEventListener(
            'click',
            async () => {
                if (!confirm('Bild wirklich entfernen?')) {
                    return;
                }

                try {
                    await queueRequest('remove_image');
                    location.reload();
                } catch (_) {}
            }
        );
    }


    if (deleteButton) {
        deleteButton.addEventListener(
            'click',
            async () => {
                if (
                    !confirm(
                        'Region wirklich löschen? '
                        + 'Die Charaktere bleiben bestehen '
                        + 'und verlieren nur ihre Regionszuordnung.'
                    )
                ) {
                    return;
                }

                try {
                    await queueRequest('delete');
                    location.href = '/phan/regions';
                } catch (_) {}
            }
        );
    }

})();
</script>

</body>
</html>
