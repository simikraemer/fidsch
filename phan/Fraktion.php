<?php
// phan/Fraktion.php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$phanconn->set_charset('utf8mb4');

const PF_FACTION_UPLOAD_DIR =
    __DIR__ . '/../uploads/phan/factions';

const PF_FACTION_PUBLIC_DIR =
    '/uploads/phan/factions';

const PF_FACTION_THUMB_DIR =
    __DIR__ . '/../uploads/phan/factions/thumbs';

const PF_FACTION_MAX_IMAGE_BYTES =
    12582912; // 12 MB

const PF_FACTION_THUMB_SIZE =
    160;

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

function pf_h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function pf_exec(
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

        $stmt->bind_param(
            $types,
            ...$refs
        );
    }

    $stmt->execute();

    return $stmt;
}

function pf_one(
    mysqli $db,
    string $sql,
    array $params = []
): ?array {
    $stmt = pf_exec(
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

function pf_all(
    mysqli $db,
    string $sql,
    array $params = []
): array {
    $stmt = pf_exec(
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

function pf_json(
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

function pf_format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    try {
        return (
            new DateTimeImmutable($value)
        )->format('d.m.Y, H:i');
    } catch (Throwable) {
        return '—';
    }
}

function pf_legacy_faction_text(
    mysqli $db,
    int $charId
): string {
    if ($charId <= 0) {
        return '';
    }

    $titles = array_map(
        static fn(array $row): string =>
            (string)$row['title'],
        pf_all(
            $db,
            'SELECT f.title
             FROM char_factions cf
             INNER JOIN factions f
                ON f.id = cf.faction_id
             WHERE cf.char_id = ?
             ORDER BY f.title',
            [$charId]
        )
    );

    $text = implode(', ', $titles);

    if (function_exists('mb_substr')) {
        return mb_substr(
            $text,
            0,
            255,
            'UTF-8'
        );
    }

    return substr(
        $text,
        0,
        255
    );
}

function pf_refresh_char_legacy_faction(
    mysqli $db,
    int $charId
): void {
    if ($charId <= 0) {
        return;
    }

    pf_exec(
        $db,
        'UPDATE chars
         SET faction = ?
         WHERE id = ?',
        [
            pf_legacy_faction_text(
                $db,
                $charId
            ),
            $charId,
        ]
    )->close();
}


function pf_crop_value(string $key): ?float
{
    $raw = trim(
        (string)($_POST[$key] ?? '')
    );

    if (
        $raw === ''
        || !is_numeric($raw)
    ) {
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


function pf_faction_disk_path(
    ?string $publicPath
): ?string {
    if (
        !$publicPath
        || !str_starts_with(
            $publicPath,
            PF_FACTION_PUBLIC_DIR . '/'
        )
    ) {
        return null;
    }

    $filename =
        basename($publicPath);

    if (
        $filename === ''
        || $filename === '.'
        || $filename === '..'
    ) {
        return null;
    }

    return PF_FACTION_UPLOAD_DIR
        . '/'
        . $filename;
}


function pf_delete_faction_image(
    ?string $publicPath
): void {
    $path =
        pf_faction_disk_path(
            $publicPath
        );

    if (
        $path !== null
        && is_file($path)
    ) {
        @unlink($path);
    }
}


function pf_cleanup_faction_thumbs(
    int $factionId
): void {
    if (
        $factionId <= 0
        || !is_dir(
            PF_FACTION_THUMB_DIR
        )
    ) {
        return;
    }

    foreach (
        glob(
            PF_FACTION_THUMB_DIR
            . '/faction_'
            . $factionId
            . '_*.jpg'
        ) ?: []
        as $path
    ) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}


function pf_upload_faction_image(
    array $file,
    int $factionId
): ?string {
    $error =
        (int)(
            $file['error']
            ?? UPLOAD_ERR_NO_FILE
        );

    if (
        $error
        === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if (
        $error
        !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            'Bild-Upload fehlgeschlagen '
            . '(PHP-Code '
            . $error
            . ').'
        );
    }

    $tmp =
        (string)(
            $file['tmp_name']
            ?? ''
        );

    $size =
        (int)(
            $file['size']
            ?? 0
        );

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
        || $size
            > PF_FACTION_MAX_IMAGE_BYTES
    ) {
        throw new RuntimeException(
            'Das Bild darf maximal 12 MB groß sein.'
        );
    }

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $mime =
        (string)$finfo->file(
            $tmp
        );

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (
        !isset(
            $allowed[$mime]
        )
        || @getimagesize($tmp)
            === false
    ) {
        throw new RuntimeException(
            'Erlaubt sind ausschließlich gültige '
            . 'JPG-, PNG- und WebP-Bilder.'
        );
    }

    if (
        !is_dir(
            PF_FACTION_UPLOAD_DIR
        )
        && !mkdir(
            PF_FACTION_UPLOAD_DIR,
            0755,
            true
        )
        && !is_dir(
            PF_FACTION_UPLOAD_DIR
        )
    ) {
        throw new RuntimeException(
            'Upload-Verzeichnis konnte nicht erstellt werden.'
        );
    }

    if (
        !is_writable(
            PF_FACTION_UPLOAD_DIR
        )
    ) {
        throw new RuntimeException(
            'Upload-Verzeichnis ist für PHP nicht beschreibbar.'
        );
    }

    $filename =
        sprintf(
            'faction_%d_%s.%s',
            $factionId,
            bin2hex(
                random_bytes(8)
            ),
            $allowed[$mime]
        );

    $target =
        PF_FACTION_UPLOAD_DIR
        . '/'
        . $filename;

    if (
        !move_uploaded_file(
            $tmp,
            $target
        )
    ) {
        throw new RuntimeException(
            'Bild konnte nicht gespeichert werden.'
        );
    }

    @chmod(
        $target,
        0644
    );

    return PF_FACTION_PUBLIC_DIR
        . '/'
        . $filename;
}


function pf_make_faction_thumb(
    int $factionId,
    string $sourcePath,
    ?float $thumbX,
    ?float $thumbY,
    ?float $thumbW,
    ?float $thumbH
): string {
    if (
        !extension_loaded('gd')
    ) {
        throw new RuntimeException(
            'PHP-GD ist für Fraktions-Thumbnails nicht installiert.'
        );
    }

    if (
        !is_dir(
            PF_FACTION_THUMB_DIR
        )
        && !mkdir(
            PF_FACTION_THUMB_DIR,
            0755,
            true
        )
        && !is_dir(
            PF_FACTION_THUMB_DIR
        )
    ) {
        throw new RuntimeException(
            'Thumbnail-Verzeichnis konnte nicht erstellt werden.'
        );
    }

    $mtime =
        (int)@filemtime(
            $sourcePath
        );

    $cropKey =
        implode(
            '_',
            [
                $thumbX ?? 'null',
                $thumbY ?? 'null',
                $thumbW ?? 'null',
                $thumbH ?? 'null',
            ]
        );

    $hash =
        substr(
            sha1(
                $mtime
                . '|'
                . $cropKey
                . '|'
                . PF_FACTION_THUMB_SIZE
            ),
            0,
            12
        );

    $target =
        PF_FACTION_THUMB_DIR
        . '/faction_'
        . $factionId
        . '_'
        . $hash
        . '.jpg';

    if (is_file($target)) {
        return $target;
    }

    $info =
        @getimagesize(
            $sourcePath
        );

    if (!$info) {
        throw new RuntimeException(
            'Fraktionsbild konnte nicht gelesen werden.'
        );
    }

    [
        $srcW,
        $srcH,
    ] = $info;

    if (
        $srcW <= 0
        || $srcH <= 0
    ) {
        throw new RuntimeException(
            'Ungültige Bildgröße.'
        );
    }

    $mime =
        (string)(
            $info['mime']
            ?? ''
        );

    $src = match ($mime) {
        'image/jpeg' =>
            @imagecreatefromjpeg(
                $sourcePath
            ),

        'image/png' =>
            @imagecreatefrompng(
                $sourcePath
            ),

        'image/webp' =>
            function_exists(
                'imagecreatefromwebp'
            )
                ? @imagecreatefromwebp(
                    $sourcePath
                )
                : false,

        default => false,
    };

    if (!$src) {
        throw new RuntimeException(
            'Fraktionsbild konnte nicht für das Thumbnail geladen werden.'
        );
    }

    $hasCrop =
        $thumbX !== null
        && $thumbY !== null
        && $thumbW !== null
        && $thumbH !== null
        && $thumbW > 0
        && $thumbH > 0;

    if ($hasCrop) {
        $cropX =
            max(
                0,
                min(
                    $srcW - 1,
                    (int)round(
                        $thumbX
                        * $srcW
                    )
                )
            );

        $cropY =
            max(
                0,
                min(
                    $srcH - 1,
                    (int)round(
                        $thumbY
                        * $srcH
                    )
                )
            );

        $cropW =
            max(
                1,
                min(
                    $srcW - $cropX,
                    (int)round(
                        $thumbW
                        * $srcW
                    )
                )
            );

        $cropH =
            max(
                1,
                min(
                    $srcH - $cropY,
                    (int)round(
                        $thumbH
                        * $srcH
                    )
                )
            );

        $side =
            min(
                $cropW,
                $cropH
            );

        $cropW = $side;
        $cropH = $side;

    } else {
        $side =
            min(
                $srcW,
                $srcH
            );

        $cropW = $side;
        $cropH = $side;

        $cropX =
            (int)round(
                (
                    $srcW
                    - $side
                )
                / 2
            );

        $cropY =
            (int)round(
                (
                    $srcH
                    - $side
                )
                / 2
            );
    }

    $dst =
        imagecreatetruecolor(
            PF_FACTION_THUMB_SIZE,
            PF_FACTION_THUMB_SIZE
        );

    imagecopyresampled(
        $dst,
        $src,
        0,
        0,
        $cropX,
        $cropY,
        PF_FACTION_THUMB_SIZE,
        PF_FACTION_THUMB_SIZE,
        $cropW,
        $cropH
    );

    if (
        !imagejpeg(
            $dst,
            $target,
            84
        )
    ) {
        imagedestroy($src);
        imagedestroy($dst);

        throw new RuntimeException(
            'Fraktions-Thumbnail konnte nicht gespeichert werden.'
        );
    }

    imagedestroy($src);
    imagedestroy($dst);

    @chmod(
        $target,
        0644
    );

    foreach (
        glob(
            PF_FACTION_THUMB_DIR
            . '/faction_'
            . $factionId
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
 * Geschütztes Fraktionsbild / Thumbnail
 *
 *   /phan/factions?image=<id>
 *   /phan/factions?thumb=<id>
 * ========================================================= */

if (
    isset($_GET['image'])
    || isset($_GET['thumb'])
) {
    $isThumb =
        isset($_GET['thumb']);

    $requestedId =
        max(
            0,
            (int)(
                $_GET['thumb']
                ?? $_GET['image']
                ?? 0
            )
        );

    if ($requestedId <= 0) {
        http_response_code(404);
        exit;
    }

    $imageRow =
        pf_one(
            $phanconn,
            'SELECT
                id,
                image_path,
                thumb_x,
                thumb_y,
                thumb_w,
                thumb_h
             FROM factions
             WHERE id = ?',
            [$requestedId]
        );

    if (
        !$imageRow
        || empty(
            $imageRow['image_path']
        )
    ) {
        http_response_code(404);
        exit;
    }

    $sourcePath =
        pf_faction_disk_path(
            $imageRow['image_path']
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
            $diskPath =
                pf_make_faction_thumb(
                    $requestedId,
                    $sourcePath,
                    $imageRow['thumb_x'] !== null
                        ? (float)$imageRow['thumb_x']
                        : null,
                    $imageRow['thumb_y'] !== null
                        ? (float)$imageRow['thumb_y']
                        : null,
                    $imageRow['thumb_w'] !== null
                        ? (float)$imageRow['thumb_w']
                        : null,
                    $imageRow['thumb_h'] !== null
                        ? (float)$imageRow['thumb_h']
                        : null
                );

            $mime =
                'image/jpeg';

        } else {
            $diskPath =
                $sourcePath;

            $finfo =
                new finfo(
                    FILEINFO_MIME_TYPE
                );

            $mime =
                (string)$finfo->file(
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

    header(
        'Content-Type: '
        . $mime
    );

    header(
        'Content-Length: '
        . (string)filesize(
            $diskPath
        )
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

    readfile(
        $diskPath
    );

    exit;
}


/* =========================================================
 * POST / AJAX
 * ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (
        (string)($_POST['ajax'] ?? '') === '1'
        || strtolower(
            (string)(
                $_SERVER[
                    'HTTP_X_REQUESTED_WITH'
                ] ?? ''
            )
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

        $action = (string)(
            $_POST['action']
            ?? 'save'
        );

        $id = max(
            0,
            (int)($_POST['id'] ?? 0)
        );


        /* -------------------------------------------------
         * Thumbnail-Ausschnitt speichern
         * ------------------------------------------------- */

        if ($action === 'save_crop') {
            if ($id <= 0) {
                throw new RuntimeException(
                    'Ungültige Fraktion.'
                );
            }

            $row =
                pf_one(
                    $phanconn,
                    'SELECT image_path
                     FROM factions
                     WHERE id = ?',
                    [$id]
                );

            if (
                !$row
                || empty(
                    $row['image_path']
                )
            ) {
                throw new RuntimeException(
                    'Die Fraktion hat kein Bild.'
                );
            }

            $x =
                pf_crop_value(
                    'thumb_x'
                );

            $y =
                pf_crop_value(
                    'thumb_y'
                );

            $w =
                pf_crop_value(
                    'thumb_w'
                );

            $h =
                pf_crop_value(
                    'thumb_h'
                );

            $validCrop =
                $x !== null
                && $y !== null
                && $w !== null
                && $h !== null
                && $w > 0.01
                && $h > 0.01
                && (
                    $x + $w
                ) <= 1.0001
                && (
                    $y + $h
                ) <= 1.0001;

            if (!$validCrop) {
                throw new RuntimeException(
                    'Ungültiger Thumbnail-Ausschnitt.'
                );
            }

            pf_exec(
                $phanconn,
                'UPDATE factions
                 SET
                    thumb_x = ?,
                    thumb_y = ?,
                    thumb_w = ?,
                    thumb_h = ?
                 WHERE id = ?',
                [
                    $x,
                    $y,
                    $w,
                    $h,
                    $id,
                ]
            )->close();

            pf_cleanup_faction_thumbs(
                $id
            );

            $saved =
                pf_one(
                    $phanconn,
                    'SELECT updated_at
                     FROM factions
                     WHERE id = ?',
                    [$id]
                );

            pf_json([
                'ok' => true,
                'id' => $id,
                'crop_saved' => true,
                'updated_at' =>
                    pf_format_datetime(
                        $saved['updated_at']
                        ?? null
                    ),
            ]);
        }


        /* -------------------------------------------------
         * Fraktionsbild entfernen
         * ------------------------------------------------- */

        if ($action === 'remove_image') {
            if ($id <= 0) {
                throw new RuntimeException(
                    'Ungültige Fraktion.'
                );
            }

            $row =
                pf_one(
                    $phanconn,
                    'SELECT image_path
                     FROM factions
                     WHERE id = ?',
                    [$id]
                );

            if (!$row) {
                throw new RuntimeException(
                    'Fraktion existiert nicht mehr.'
                );
            }

            pf_exec(
                $phanconn,
                'UPDATE factions
                 SET
                    image_path = NULL,
                    thumb_x = NULL,
                    thumb_y = NULL,
                    thumb_w = NULL,
                    thumb_h = NULL
                 WHERE id = ?',
                [$id]
            )->close();

            pf_delete_faction_image(
                $row['image_path']
                ?? null
            );

            pf_cleanup_faction_thumbs(
                $id
            );

            $saved =
                pf_one(
                    $phanconn,
                    'SELECT updated_at
                     FROM factions
                     WHERE id = ?',
                    [$id]
                );

            pf_json([
                'ok' => true,
                'id' => $id,
                'image_removed' => true,
                'updated_at' =>
                    pf_format_datetime(
                        $saved['updated_at']
                        ?? null
                    ),
            ]);
        }


        /* -------------------------------------------------
         * Fraktion löschen
         * ------------------------------------------------- */

        if ($action === 'delete') {
            if ($id <= 0) {
                throw new RuntimeException(
                    'Ungültige Fraktion.'
                );
            }

            $factionRow =
                pf_one(
                    $phanconn,
                    'SELECT image_path
                     FROM factions
                     WHERE id = ?',
                    [$id]
                );

            $affectedCharIds = array_map(
                static fn(array $row): int =>
                    (int)$row['char_id'],
                pf_all(
                    $phanconn,
                    'SELECT char_id
                     FROM char_factions
                     WHERE faction_id = ?',
                    [$id]
                )
            );

            $phanconn->begin_transaction();

            try {
                pf_exec(
                    $phanconn,
                    'DELETE FROM factions
                     WHERE id = ?',
                    [$id]
                )->close();

                foreach (
                    $affectedCharIds
                    as $charId
                ) {
                    pf_refresh_char_legacy_faction(
                        $phanconn,
                        $charId
                    );
                }

                $phanconn->commit();

            } catch (Throwable $e) {
                $phanconn->rollback();
                throw $e;
            }

            pf_delete_faction_image(
                $factionRow['image_path']
                ?? null
            );

            pf_cleanup_faction_thumbs(
                $id
            );

            if ($isAjax) {
                pf_json([
                    'ok' => true,
                    'deleted' => true,
                ]);
            }

            header(
                'Location: /phan/factions?deleted=1',
                true,
                303
            );
            exit;
        }


        /* -------------------------------------------------
         * Fraktion anlegen / umbenennen
         * ------------------------------------------------- */

        if ($action !== 'save') {
            throw new RuntimeException(
                'Unbekannte Aktion.'
            );
        }

        $title = trim(
            (string)($_POST['title'] ?? '')
        );

        if ($title === '') {
            throw new RuntimeException(
                'Bitte einen Namen für die Fraktion eingeben.'
            );
        }

        if (function_exists('mb_strlen')) {
            if (
                mb_strlen(
                    $title,
                    'UTF-8'
                ) > 255
            ) {
                throw new RuntimeException(
                    'Der Fraktionsname darf maximal 255 Zeichen lang sein.'
                );
            }
        } elseif (strlen($title) > 255) {
            throw new RuntimeException(
                'Der Fraktionsname darf maximal 255 Zeichen lang sein.'
            );
        }

        $duplicateSql =
            'SELECT id
             FROM factions
             WHERE title = ?';

        $duplicateParams = [
            $title,
        ];

        if ($id > 0) {
            $duplicateSql .=
                ' AND id <> ?';

            $duplicateParams[] = $id;
        }

        if (
            pf_one(
                $phanconn,
                $duplicateSql,
                $duplicateParams
            )
        ) {
            throw new RuntimeException(
                'Diese Fraktion existiert bereits.'
            );
        }

        $affectedCharIds = [];

        if ($id > 0) {
            if (
                !pf_one(
                    $phanconn,
                    'SELECT id
                     FROM factions
                     WHERE id = ?',
                    [$id]
                )
            ) {
                throw new RuntimeException(
                    'Fraktion existiert nicht mehr.'
                );
            }

            $affectedCharIds = array_map(
                static fn(array $row): int =>
                    (int)$row['char_id'],
                pf_all(
                    $phanconn,
                    'SELECT char_id
                     FROM char_factions
                     WHERE faction_id = ?',
                    [$id]
                )
            );
        }

        $phanconn->begin_transaction();

        try {
            if ($id > 0) {
                pf_exec(
                    $phanconn,
                    'UPDATE factions
                     SET title = ?
                     WHERE id = ?',
                    [
                        $title,
                        $id,
                    ]
                )->close();

            } else {
                $stmt = pf_exec(
                    $phanconn,
                    'INSERT INTO factions (
                        title
                     ) VALUES (?)',
                    [$title]
                );

                $id = (int)$stmt->insert_id;
                $stmt->close();
            }

            foreach (
                $affectedCharIds
                as $charId
            ) {
                pf_refresh_char_legacy_faction(
                    $phanconn,
                    $charId
                );
            }

            $phanconn->commit();

        } catch (Throwable $e) {
            $phanconn->rollback();
            throw $e;
        }

        $imageChanged = false;

        if (
            isset($_FILES['image'])
        ) {
            $newImage =
                pf_upload_faction_image(
                    $_FILES['image'],
                    $id
                );

            if (
                $newImage !== null
            ) {
                $oldImage =
                    pf_one(
                        $phanconn,
                        'SELECT image_path
                         FROM factions
                         WHERE id = ?',
                        [$id]
                    );

                pf_exec(
                    $phanconn,
                    'UPDATE factions
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

                pf_delete_faction_image(
                    $oldImage['image_path']
                    ?? null
                );

                pf_cleanup_faction_thumbs(
                    $id
                );

                $imageChanged =
                    true;
            }
        }

        $saved = pf_one(
            $phanconn,
            'SELECT updated_at
             FROM factions
             WHERE id = ?',
            [$id]
        );

        if ($isAjax) {
            pf_json([
                'ok' => true,
                'id' => $id,
                'image_changed' =>
                    $imageChanged,
                'updated_at' =>
                    pf_format_datetime(
                        $saved['updated_at']
                        ?? null
                    ),
            ]);
        }

        header(
            'Location: /phan/factions?id='
            . $id
            . '&saved=1',
            true,
            303
        );
        exit;

    } catch (Throwable $e) {
        if ($isAjax) {
            pf_json(
                [
                    'ok' => false,
                    'message' =>
                        $e->getMessage(),
                ],
                400
            );
        }

        $error = $e->getMessage();
    }
}


/* =========================================================
 * Daten
 * ========================================================= */

$factions = pf_all(
    $phanconn,
    'SELECT
        f.id,
        f.title,
        f.image_path,
        f.thumb_x,
        f.thumb_y,
        f.thumb_w,
        f.thumb_h,
        f.created_at,
        f.updated_at,
        COUNT(cf.char_id) AS char_count
     FROM factions f
     LEFT JOIN char_factions cf
        ON cf.faction_id = f.id
     GROUP BY
        f.id,
        f.title,
        f.image_path,
        f.thumb_x,
        f.thumb_y,
        f.thumb_w,
        f.thumb_h,
        f.created_at,
        f.updated_at
     ORDER BY f.title'
);

$id = max(
    0,
    (int)($_GET['id'] ?? 0)
);

$isNew = isset($_GET['new']);
$faction = null;
$error ??= '';
$flash = '';

if ($id > 0) {
    $faction = pf_one(
        $phanconn,
        'SELECT *
         FROM factions
         WHERE id = ?',
        [$id]
    );

    if (!$faction) {
        http_response_code(404);
        exit('Fraktion nicht gefunden.');
    }

} elseif ($isNew) {
    $faction = [
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
}

if (isset($_GET['saved'])) {
    $flash = 'Gespeichert.';
}

if (isset($_GET['deleted'])) {
    $flash =
        'Fraktion gelöscht. Die Charaktere bleiben erhalten.';
}


/* =========================================================
 * Rendering
 * ========================================================= */

$page_title = 'Fraktionen';

require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div class="phan-page">

    <div class="phan-head">
        <h1 class="ueberschrift phan-title">
            Fraktionen
        </h1>

        <div class="phan-actions phan-actions--top">
            <button
                type="button"
                onclick="location.href='/phan/factions?new=1'"
            >
                + Fraktion
            </button>
        </div>
    </div>


    <?php if ($flash): ?>
        <div class="phan-msg">
            <?= pf_h($flash) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="phan-msg phan-error">
            <?= pf_h($error) ?>
        </div>
    <?php endif; ?>


    <div class="phan-detail">

        <div class="phan-card">
            <div class="relations-char-results">

                <?php if (!$factions): ?>
                    <div class="relations-char-empty">
                        Noch keine Fraktionen vorhanden.
                    </div>
                <?php endif; ?>


                <?php foreach ($factions as $row): ?>
                    <?php
                    $rowId = (int)$row['id'];
                    $isActive =
                        $faction
                        && $rowId
                            === (int)$faction['id'];
                    ?>

                    <button
                        type="button"
                        class="relations-char-result"
                        style="<?= $isActive
                            ? 'border-color:var(--primary);background:#fff2e5;'
                            : ''
                        ?>"
                        onclick="location.href='/phan/factions?id=<?= $rowId ?>'"
                    >
                        <span class="relations-char-avatar">
                            <?php if (!empty($row['image_path'])): ?>
                                <img
                                    src="/phan/factions?thumb=<?= $rowId ?>"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                >
                            <?php else: ?>
                                <?= pf_h(
                                    function_exists('mb_substr')
                                        ? mb_substr(
                                            (string)$row['title'],
                                            0,
                                            1,
                                            'UTF-8'
                                        )
                                        : substr(
                                            (string)$row['title'],
                                            0,
                                            1
                                        )
                                ) ?>
                            <?php endif; ?>
                        </span>

                        <span class="relations-char-result-text">
                            <strong>
                                <?= pf_h($row['title']) ?>
                            </strong>

                            <span>
                                <?= (int)$row['char_count'] ?>
                                Charakter<?= (int)$row['char_count'] === 1 ? '' : 'e' ?>
                            </span>
                        </span>
                    </button>

                <?php endforeach; ?>

            </div>
        </div>


        <div class="phan-card">

            <?php if (!$faction): ?>

                <div class="relations-char-empty">
                    Links eine Fraktion auswählen oder eine neue Fraktion anlegen.
                </div>

            <?php else: ?>

                <form
                    method="post"
                    enctype="multipart/form-data"
                    id="factionForm"
                    autocomplete="off"
                >
                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= pf_h($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="save"
                    >

                    <input
                        type="hidden"
                        name="id"
                        id="factionId"
                        value="<?= (int)$faction['id'] ?>"
                    >

                    <input
                        type="hidden"
                        id="factionThumbX"
                        value="<?= pf_h($faction['thumb_x'] ?? '') ?>"
                    >

                    <input
                        type="hidden"
                        id="factionThumbY"
                        value="<?= pf_h($faction['thumb_y'] ?? '') ?>"
                    >

                    <input
                        type="hidden"
                        id="factionThumbW"
                        value="<?= pf_h($faction['thumb_w'] ?? '') ?>"
                    >

                    <input
                        type="hidden"
                        id="factionThumbH"
                        value="<?= pf_h($faction['thumb_h'] ?? '') ?>"
                    >

                    <input
                        type="file"
                        name="image"
                        id="factionImageInput"
                        class="phan-image-upload-input"
                        accept="image/jpeg,image/png,image/webp"
                    >


                    <div class="phan-form-grid">
                        <label class="phan-wide">
                            Name

                            <input
                                type="text"
                                name="title"
                                id="factionTitle"
                                maxlength="255"
                                value="<?= pf_h(
                                    $faction['title']
                                ) ?>"
                                placeholder="Name der Fraktion"
                            >
                        </label>
                    </div>


                    <div
                        class="phan-image-card"
                        style="margin-top:16px;"
                    >
                        <div class="phan-image-card-head">
                            <div>
                                <strong>Bild</strong>
                            </div>

                            <button
                                type="button"
                                id="factionImageButton"
                            >
                                <?= !empty($faction['image_path'])
                                    ? 'Bild ersetzen'
                                    : '+ Bild'
                                ?>
                            </button>
                        </div>

                        <?php if (!empty($faction['image_path'])): ?>

                            <div
                                class="phan-cropbox phan-image-dropzone"
                                id="factionCropBox"
                            >
                                <img
                                    src="/phan/factions?image=<?= (int)$faction['id'] ?>"
                                    alt="<?= pf_h($faction['title']) ?>"
                                    id="factionCropImage"
                                    draggable="false"
                                >

                                <div
                                    class="phan-crop-overlay"
                                    id="factionCropOverlay"
                                    hidden
                                ></div>

                                <div class="phan-image-drop-hint">
                                    Bild hier ablegen zum Ersetzen
                                </div>
                            </div>

                            <div class="phan-image-actions">
                                <button
                                    type="button"
                                    id="factionCropButton"
                                >
                                    Thumbnail-Ausschnitt setzen
                                </button>

                                <button
                                    type="button"
                                    class="phan-danger"
                                    id="removeFactionImageButton"
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
                                id="factionEmptyImagePicker"
                                style="aspect-ratio:1 / 1;"
                            >
                                Noch kein Bild

                                <span class="phan-image-empty-sub">
                                    Klicken oder Bild hierher ziehen
                                </span>
                            </button>

                        <?php endif; ?>
                    </div>


                    <div class="phan-bottom-actions">
                        <div class="phan-bottom-actions-left">
                            <button
                                type="button"
                                class="phan-danger"
                                id="deleteFactionButton"
                                <?= (int)$faction['id'] <= 0
                                    ? 'hidden'
                                    : ''
                                ?>
                            >
                                Fraktion löschen
                            </button>
                        </div>

                        <div class="phan-bottom-actions-right">
                            <span
                                class="phan-autosave-status"
                                id="factionAutosaveStatus"
                                aria-live="polite"
                            ></span>

                            <span class="phan-last-saved">
                                Zuletzt gespeichert am
                                <span id="factionLastSaved">
                                    <?= pf_h(
                                        pf_format_datetime(
                                            $faction['updated_at']
                                            ?? null
                                        )
                                    ) ?>
                                </span>
                            </span>
                        </div>
                    </div>

                </form>

            <?php endif; ?>

        </div>

    </div>

</div>


<script>
(() => {
    'use strict';

    const form =
        document.getElementById(
            'factionForm'
        );

    if (!form) {
        return;
    }

    const factionId =
        document.getElementById(
            'factionId'
        );

    const title =
        document.getElementById(
            'factionTitle'
        );

    const status =
        document.getElementById(
            'factionAutosaveStatus'
        );

    const lastSaved =
        document.getElementById(
            'factionLastSaved'
        );

    const deleteButton =
        document.getElementById(
            'deleteFactionButton'
        );

    const imageInput =
        document.getElementById(
            'factionImageInput'
        );

    const imageButton =
        document.getElementById(
            'factionImageButton'
        );

    const emptyImagePicker =
        document.getElementById(
            'factionEmptyImagePicker'
        );

    const removeImageButton =
        document.getElementById(
            'removeFactionImageButton'
        );

    const cropBox =
        document.getElementById(
            'factionCropBox'
        );

    const cropImage =
        document.getElementById(
            'factionCropImage'
        );

    const cropOverlay =
        document.getElementById(
            'factionCropOverlay'
        );

    const cropButton =
        document.getElementById(
            'factionCropButton'
        );

    const cropInputs = [
        document.getElementById(
            'factionThumbX'
        ),
        document.getElementById(
            'factionThumbY'
        ),
        document.getElementById(
            'factionThumbW'
        ),
        document.getElementById(
            'factionThumbH'
        ),
    ];

    let saveTimer = null;
    let saveChain = Promise.resolve();
    let statusTimer = null;

    let cropMode = false;
    let cropStart = null;


    function setStatus(
        text,
        isError = false
    ) {
        if (!status) {
            return;
        }

        window.clearTimeout(
            statusTimer
        );

        status.textContent =
            text;

        status.classList.toggle(
            'is-error',
            isError
        );

        if (
            text === 'Gespeichert'
            || text === ''
        ) {
            statusTimer =
                window.setTimeout(
                    () => {
                        status.textContent =
                            '';

                        status.classList.remove(
                            'is-error'
                        );
                    },
                    1200
                );
        }
    }


    function titleReady() {
        return Boolean(
            title
            && title.value.trim() !== ''
        );
    }


    function applyReturnedId(
        payload
    ) {
        const returnedId =
            Number(
                payload?.id
                ?? 0
            );

        if (
            !Number.isInteger(
                returnedId
            )
            || returnedId <= 0
        ) {
            return;
        }

        if (
            Number(
                factionId.value
            ) <= 0
        ) {
            factionId.value =
                String(
                    returnedId
                );

            history.replaceState(
                null,
                '',
                '/phan/factions?id='
                    + returnedId
            );

            if (deleteButton) {
                deleteButton.hidden =
                    false;
            }
        }
    }


    async function performRequest(
        action = 'save',
        file = null,
        extra = {}
    ) {
        if (
            action === 'save'
            && !titleReady()
        ) {
            setStatus(
                'Erst Namen eingeben',
                true
            );

            return {
                ok: false,
                skipped: true,
            };
        }

        const data =
            new FormData(
                form
            );

        data.set(
            'ajax',
            '1'
        );

        data.set(
            'action',
            action
        );

        data.delete(
            'image'
        );

        Object.entries(
            extra
        ).forEach(
            ([
                key,
                value,
            ]) => {
                if (
                    value === null
                    || value === undefined
                ) {
                    data.delete(
                        key
                    );
                } else {
                    data.set(
                        key,
                        String(value)
                    );
                }
            }
        );

        if (
            file instanceof File
        ) {
            data.set(
                'image',
                file,
                file.name
            );
        }

        setStatus(
            action === 'delete'
                ? 'Lösche…'
                : 'Speichere…'
        );

        const response =
            await fetch(
                '/phan/factions',
                {
                    method:
                        'POST',

                    body:
                        data,

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
                .catch(
                    () => null
                );

        if (
            !response.ok
            || !payload?.ok
        ) {
            throw new Error(
                payload?.message
                || 'Aktion fehlgeschlagen.'
            );
        }

        applyReturnedId(
            payload
        );

        if (
            lastSaved
            && payload.updated_at
        ) {
            lastSaved.textContent =
                payload.updated_at;
        }

        if (
            action !== 'delete'
        ) {
            setStatus(
                'Gespeichert'
            );
        }

        return payload;
    }


    function queueRequest(
        action = 'save',
        file = null,
        extra = {}
    ) {
        saveChain =
            saveChain
                .catch(
                    () => {}
                )
                .then(
                    () =>
                        performRequest(
                            action,
                            file,
                            extra
                        )
                )
                .catch(
                    error => {
                        setStatus(
                            error.message
                            || 'Fehler',
                            true
                        );

                        throw error;
                    }
                );

        return saveChain;
    }


    function scheduleAutosave() {
        window.clearTimeout(
            saveTimer
        );

        saveTimer =
            window.setTimeout(
                () => {
                    queueRequest(
                        'save'
                    ).catch(
                        () => {}
                    );
                },
                450
            );
    }


    title?.addEventListener(
        'input',
        scheduleAutosave
    );


    /* =====================================================
     * Bild-Upload / Drag & Drop
     * ===================================================== */

    async function uploadImage(
        file
    ) {
        if (
            !(file instanceof File)
            || !file.type.startsWith(
                'image/'
            )
        ) {
            return;
        }

        if (!titleReady()) {
            setStatus(
                'Erst Namen eingeben',
                true
            );

            title?.focus();
            return;
        }

        window.clearTimeout(
            saveTimer
        );

        try {
            const payload =
                await queueRequest(
                    'save',
                    file
                );

            if (
                payload?.id
            ) {
                location.href =
                    '/phan/factions?id='
                    + Number(
                        payload.id
                    );
            }

        } catch (_) {}
    }


    imageButton?.addEventListener(
        'click',
        () =>
            imageInput?.click()
    );


    emptyImagePicker
        ?.addEventListener(
            'click',
            () =>
                imageInput?.click()
        );


    imageInput?.addEventListener(
        'change',
        () => {
            const file =
                imageInput.files?.[0]
                ?? null;

            if (file) {
                uploadImage(
                    file
                );
            }
        }
    );


    document
        .querySelectorAll(
            '.phan-image-dropzone'
        )
        .forEach(
            zone => {
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
                                ?.files?.[0]
                            ?? null;

                        if (file) {
                            uploadImage(
                                file
                            );
                        }
                    }
                );
            }
        );


    removeImageButton
        ?.addEventListener(
            'click',
            async () => {
                if (
                    !confirm(
                        'Fraktionsbild wirklich entfernen?'
                    )
                ) {
                    return;
                }

                try {
                    await queueRequest(
                        'remove_image'
                    );

                    location.reload();

                } catch (_) {}
            }
        );


    /* =====================================================
     * 1:1 Thumbnail-Ausschnitt
     * ===================================================== */

    function hasSavedCrop() {
        const values =
            cropInputs.map(
                input =>
                    parseFloat(
                        input?.value
                        ?? ''
                    )
            );

        return (
            values.every(
                Number.isFinite
            )
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
                cropOverlay.hidden =
                    true;
            }

            return;
        }

        const [
            x,
            y,
            w,
            h,
        ] =
            cropInputs.map(
                input =>
                    parseFloat(
                        input.value
                    )
            );

        cropOverlay.hidden =
            false;

        cropOverlay.style.left =
            (x * 100)
            + '%';

        cropOverlay.style.top =
            (y * 100)
            + '%';

        cropOverlay.style.width =
            (w * 100)
            + '%';

        cropOverlay.style.height =
            (h * 100)
            + '%';
    }


    function setCropMode(
        enabled
    ) {
        cropMode =
            Boolean(
                enabled
            );

        cropStart =
            null;

        cropBox?.classList.toggle(
            'is-crop-mode',
            cropMode
        );

        if (cropButton) {
            cropButton.textContent =
                cropMode
                    ? 'Ausschnitt abbrechen'
                    : 'Thumbnail-Ausschnitt setzen';
        }

        if (cropMode) {
            showSavedCrop();

        } else if (cropOverlay) {
            cropOverlay.hidden =
                true;
        }
    }


    function cropPoint(
        event
    ) {
        const rect =
            cropImage
                .getBoundingClientRect();

        return {
            x:
                Math.max(
                    0,
                    Math.min(
                        rect.width,
                        event.clientX
                            - rect.left
                    )
                ),

            y:
                Math.max(
                    0,
                    Math.min(
                        rect.height,
                        event.clientY
                            - rect.top
                    )
                ),

            rect,
        };
    }


    function drawSquare(
        start,
        current
    ) {
        const dx =
            current.x
            - start.x;

        const dy =
            current.y
            - start.y;

        const side =
            Math.min(
                Math.abs(dx),
                Math.abs(dy)
            );

        const left =
            dx >= 0
                ? start.x
                : start.x
                    - side;

        const top =
            dy >= 0
                ? start.y
                : start.y
                    - side;

        return {
            left,
            top,
            side,
        };
    }


    if (
        cropButton
        && cropBox
        && cropImage
        && cropOverlay
    ) {
        cropButton.addEventListener(
            'click',
            () => {
                setCropMode(
                    !cropMode
                );
            }
        );


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

                cropStart =
                    cropPoint(
                        event
                    );

                cropOverlay.hidden =
                    false;

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

                const current =
                    cropPoint(
                        event
                    );

                const crop =
                    drawSquare(
                        cropStart,
                        current
                    );

                cropOverlay.style.left =
                    crop.left
                    + 'px';

                cropOverlay.style.top =
                    crop.top
                    + 'px';

                cropOverlay.style.width =
                    crop.side
                    + 'px';

                cropOverlay.style.height =
                    crop.side
                    + 'px';
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

                const current =
                    cropPoint(
                        event
                    );

                const crop =
                    drawSquare(
                        cropStart,
                        current
                    );

                cropStart =
                    null;

                if (
                    crop.side < 10
                ) {
                    showSavedCrop();
                    return;
                }

                cropInputs[0].value =
                    (
                        crop.left
                        / current.rect.width
                    ).toFixed(6);

                cropInputs[1].value =
                    (
                        crop.top
                        / current.rect.height
                    ).toFixed(6);

                cropInputs[2].value =
                    (
                        crop.side
                        / current.rect.width
                    ).toFixed(6);

                cropInputs[3].value =
                    (
                        crop.side
                        / current.rect.height
                    ).toFixed(6);

                queueRequest(
                    'save_crop',
                    null,
                    {
                        thumb_x:
                            cropInputs[0].value,

                        thumb_y:
                            cropInputs[1].value,

                        thumb_w:
                            cropInputs[2].value,

                        thumb_h:
                            cropInputs[3].value,
                    }
                )
                    .then(
                        () =>
                            setCropMode(
                                false
                            )
                    )
                    .catch(
                        () => {}
                    );
            }
        );


        cropBox.addEventListener(
            'pointercancel',
            () => {
                cropStart =
                    null;

                showSavedCrop();
            }
        );
    }


    /* =====================================================
     * Fraktion löschen
     * ===================================================== */

    deleteButton?.addEventListener(
        'click',
        async () => {
            if (
                !confirm(
                    'Fraktion wirklich löschen? '
                    + 'Die Charaktere bleiben bestehen und verlieren nur diese Zuordnung.'
                )
            ) {
                return;
            }

            window.clearTimeout(
                saveTimer
            );

            try {
                await queueRequest(
                    'delete'
                );

                location.href =
                    '/phan/factions';

            } catch (_) {}
        }
    );

})();
</script>

</body>
</html>
