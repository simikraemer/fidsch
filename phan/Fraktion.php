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
         * Fraktionsspezifisches Charakterbild speichern.
         * Unabhängig vom globalen aktiven Bild und von
         * char_factions (Chars.php erstellt diese neu).
         * ------------------------------------------------- */
        if ($action === 'set_faction_image') {
            $charId = max(0, (int)($_POST['char_id'] ?? 0));
            $imageId = max(0, (int)($_POST['image_id'] ?? 0));

            if ($id <= 0 || $charId <= 0 || $imageId <= 0) {
                throw new RuntimeException('Ungültige Bildauswahl.');
            }
            if (!pf_one($phanconn,
                'SELECT char_id FROM char_factions WHERE faction_id = ? AND char_id = ?',
                [$id, $charId])) {
                throw new RuntimeException('Der Charakter gehört nicht zu dieser Fraktion.');
            }
            if (!pf_one($phanconn,
                'SELECT id FROM char_images WHERE id = ? AND char_id = ?',
                [$imageId, $charId])) {
                throw new RuntimeException('Das Bild gehört nicht zu diesem Charakter.');
            }

            pf_exec($phanconn,
                'INSERT INTO faction_char_images (faction_id, char_id, image_id)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE image_id = VALUES(image_id)',
                [$id, $charId, $imageId])->close();

            pf_json(['ok' => true, 'image_id' => $imageId]);
        }


        /* -------------------------------------------------
         * Gruppen und Reihenfolge verwalten
         * ------------------------------------------------- */
        if (in_array($action, ['group_add', 'group_rename', 'group_delete', 'group_layout'], true)) {
            if ($id <= 0 || !pf_one($phanconn, 'SELECT id FROM factions WHERE id = ?', [$id])) {
                throw new RuntimeException('Fraktion nicht gefunden.');
            }

            $groupId = max(0, (int)($_POST['group_id'] ?? 0));
            $title = trim((string)($_POST['group_title'] ?? ''));
            $phanconn->begin_transaction();
            try {
                if ($action === 'group_add') {
                    if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) > 255) {
                        throw new RuntimeException('Gruppenname muss 1 bis 255 Zeichen enthalten.');
                    }
                    $max = pf_one($phanconn,
                        'SELECT COALESCE(MAX(sort_order), -1) + 1 AS pos FROM faction_groups WHERE faction_id = ?', [$id]);
                    pf_exec($phanconn,
                        'INSERT INTO faction_groups (faction_id, title, sort_order) VALUES (?, ?, ?)',
                        [$id, $title, (int)$max['pos']])->close();
                } elseif ($action === 'group_rename') {
                    if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) > 255) {
                        throw new RuntimeException('Gruppenname muss 1 bis 255 Zeichen enthalten.');
                    }
                    $stmt = pf_exec($phanconn,
                        'UPDATE faction_groups SET title = ? WHERE id = ? AND faction_id = ?',
                        [$title, $groupId, $id]);
                    if ($stmt->affected_rows === 0 && !pf_one($phanconn,
                        'SELECT id FROM faction_groups WHERE id = ? AND faction_id = ?', [$groupId, $id])) {
                        throw new RuntimeException('Gruppe nicht gefunden.');
                    }
                    $stmt->close();
                } elseif ($action === 'group_delete') {
                    $stmt = pf_exec($phanconn,
                        'DELETE FROM faction_groups WHERE id = ? AND faction_id = ?', [$groupId, $id]);
                    if ($stmt->affected_rows !== 1) {
                        throw new RuntimeException('Gruppe nicht gefunden.');
                    }
                    $stmt->close();
                } else {
                    $layout = json_decode((string)($_POST['layout'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($layout) || !isset($layout['groups'], $layout['chars']) ||
                        !is_array($layout['groups']) || !is_array($layout['chars'])) {
                        throw new RuntimeException('Ungültige Sortierdaten.');
                    }
                    $groups = pf_all($phanconn,
                        'SELECT id FROM faction_groups WHERE faction_id = ? FOR UPDATE', [$id]);
                    $chars = pf_all($phanconn,
                        'SELECT char_id FROM char_factions WHERE faction_id = ? FOR UPDATE', [$id]);
                    $validGroups = array_map(fn($r) => (int)$r['id'], $groups);
                    $validChars = array_map(fn($r) => (int)$r['char_id'], $chars);
                    $givenGroups = array_map('intval', $layout['groups']);
                    $givenChars = array_map(fn($r) => (int)($r['id'] ?? 0), $layout['chars']);
                    sort($validGroups); sort($givenGroups);
                    sort($validChars); sort($givenChars);
                    if ($validGroups !== $givenGroups || $validChars !== $givenChars) {
                        throw new RuntimeException('Die Sortierung ist veraltet. Seite neu laden.');
                    }
                    foreach ($layout['groups'] as $index => $gid) {
                        pf_exec($phanconn,
                            'UPDATE faction_groups SET sort_order = ? WHERE id = ? AND faction_id = ?',
                            [(int)$index, (int)$gid, $id])->close();
                    }
                    $groupSet = array_fill_keys($validGroups, true);
                    $positions = [];
                    foreach ($layout['chars'] as $entry) {
                        $gid = ($entry['group'] ?? null);
                        $gid = ($gid === null || $gid === '') ? null : (int)$gid;
                        if ($gid !== null && !isset($groupSet[$gid])) {
                            throw new RuntimeException('Ungültige Gruppenzuordnung.');
                        }
                        $key = $gid === null ? 'undefined' : (string)$gid;
                        $position = $positions[$key] ?? 0;
                        $positions[$key] = $position + 1;
                        pf_exec($phanconn,
                            'UPDATE char_factions SET group_id = ?, sort_order = ? WHERE faction_id = ? AND char_id = ?',
                            [$gid, $position, $id, (int)$entry['id']])->close();
                    }
                }
                $phanconn->commit();
            } catch (Throwable $e) {
                $phanconn->rollback();
                throw $e;
            }
            pf_json(['ok' => true]);
        }


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

$factionGroups = [];
$factionCharacters = [];
$factionCharacterImages = [];
if ($faction && (int)$faction['id'] > 0) {
    $factionGroups = pf_all($phanconn,
        'SELECT id, title, sort_order FROM faction_groups WHERE faction_id = ? ORDER BY sort_order, id',
        [(int)$faction['id']]);
    $factionCharacters = pf_all($phanconn,
        'SELECT
            cf.char_id,
            cf.group_id,
            cf.sort_order,
            c.call_name AS char_label,
            c.active_image_id,
            fci.image_id AS faction_image_id
         FROM char_factions cf
         INNER JOIN chars c ON c.id = cf.char_id
         LEFT JOIN faction_char_images fci
            ON fci.faction_id = cf.faction_id
           AND fci.char_id = cf.char_id
         WHERE cf.faction_id = ?
         ORDER BY cf.sort_order, c.call_name, cf.char_id',
        [(int)$faction['id']]);
    // Alle Bilder der zugehörigen Charaktere inkl. Galerie-Sortierung.
    $factionCharacterImages = pf_all($phanconn,
        'SELECT ci.id, ci.char_id, ci.title
         FROM char_images ci
         INNER JOIN char_factions cf
            ON cf.char_id = ci.char_id
           AND cf.faction_id = ?
         ORDER BY ci.char_id, ci.sort_order, ci.id',
        [(int)$faction['id']]);
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

<div
    id="factionsPage"
    class="phan-page factions-page <?= $faction ? 'factions-page--detail' : 'factions-page--overview' ?>"
>

    <div class="phan-head factions-head">
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


    <div class="phan-detail factions-layout">

        <div class="phan-card factions-list-card">
            <div class="relations-char-results factions-list">

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
                        class="relations-char-result faction-list-item<?= $isActive ? ' is-active' : '' ?>"
                        style="<?= $isActive
                            ? 'border-color:var(--primary);background:#fff2e5;'
                            : ''
                        ?>"
                        onclick="location.href='/phan/factions?id=<?= $rowId ?>'"
                        <?= $isActive ? 'aria-current="true"' : '' ?>
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


        <div class="phan-card factions-editor-card">

            <?php if (!$faction): ?>

                <div class="relations-char-empty">
                    Links eine Fraktion auswählen oder eine neue Fraktion anlegen.
                </div>

            <?php else: ?>

                <form
                    method="post"
                    enctype="multipart/form-data"
                    id="factionForm"
                    class="faction-form"
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
                        type="file"
                        name="image"
                        id="factionImageInput"
                        class="phan-image-upload-input"
                        accept="image/jpeg,image/png,image/webp"
                    >


                    <div class="faction-editor-toolbar" aria-label="Fraktion bearbeiten">
                        <label class="faction-title-field" for="factionTitle">
                            <span>Name</span>
                            <input
                                type="text"
                                name="title"
                                id="factionTitle"
                                maxlength="255"
                                value="<?= pf_h($faction['title']) ?>"
                                placeholder="Fraktionsname"
                                required
                            >
                        </label>

                        <div class="faction-image-preview phan-image-dropzone"
                             id="factionImagePreview" title="Fraktionsbild">
                            <?php if (!empty($faction['image_path'])): ?>
                                <img
                                    id="factionPreviewImage"
                                    src="/phan/factions?thumb=<?= (int)$faction['id'] ?>"
                                    alt="Bild der Fraktion <?= pf_h($faction['title']) ?>"
                                    draggable="false"
                                >
                            <?php else: ?>
                                <button type="button" class="faction-image-placeholder"
                                        id="factionEmptyImagePicker"
                                        title="Bild hinzufügen" aria-label="Bild hinzufügen">
                                    +
                                </button>
                            <?php endif; ?>
                            <span class="faction-image-drop-hint">Bild ablegen</span>
                        </div>

                        <div class="faction-toolbar-buttons">
                            <button type="button" id="factionImageButton"
                                    title="Fraktionsbild wählen oder ersetzen">
                                <span class="faction-label-long"><?= !empty($faction['image_path']) ? 'Bild ersetzen' : 'Bild wählen' ?></span>
                                <span class="faction-label-short"><?= !empty($faction['image_path']) ? 'Ersetzen' : 'Wählen' ?></span>
                            </button>
                            <button type="button" class="phan-danger"
                                    id="removeFactionImageButton"
                                    title="Fraktionsbild entfernen"
                                    <?= empty($faction['image_path']) ? 'disabled' : '' ?>>
                                <span class="faction-label-long">Bild entfernen</span>
                                <span class="faction-label-short">Entfernen</span>
                            </button>
                        </div>
                    </div>


                    <?php if ((int)$faction['id'] > 0): ?>
                        <section class="faction-groups-panel" id="factionGroupsPanel"
                            data-faction-id="<?= (int)$faction['id'] ?>"
                            data-csrf="<?= pf_h($csrf) ?>">
                            <div class="faction-groups-top">
                                <div>
                                    <h2>Gruppen und Charaktere</h2>
                                    <p>Am Griff links ziehen, um Gruppen und Charaktere zu verschieben.</p>
                                </div>
                                <button type="button" id="factionAddGroup">+ Gruppe</button>
                            </div>
                            <div id="factionGroupList" class="faction-group-list">
                                <?php foreach ($factionGroups as $group): ?>
                                    <section class="faction-group"
                                        data-group-id="<?= (int)$group['id'] ?>">
                                        <div class="faction-group-header">
                                            <button type="button" class="faction-grip faction-group-grip" title="Gruppe ziehen" aria-label="Gruppe ziehen">&#9776;</button>
                                            <strong class="faction-group-title"><?= pf_h($group['title']) ?></strong>
                                            <span class="faction-group-count" aria-label="Anzahl Charaktere">0</span>
                                            <div class="faction-group-controls">
                                                <button type="button" data-group-view title="Charakterbilder der Gruppe anschauen">Anschauen</button>
                                                <button type="button" data-group-rename title="Umbenennen">Bearbeiten</button>
                                                <button type="button" data-group-delete class="phan-danger" title="Gruppe löschen">Löschen</button>
                                            </div>
                                        </div>
                                        <div class="faction-char-list" data-char-group="<?= (int)$group['id'] ?>"></div>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                            <section class="faction-group faction-undefined">
                                <div class="faction-group-header">
                                    <strong>Undefiniert</strong>
                                    <span class="faction-group-count" aria-label="Anzahl Charaktere">0</span>
                                    <span class="faction-undefined-hint">Ohne Gruppenzuordnung</span>
                                    <div class="faction-group-controls">
                                        <button type="button" data-group-view title="Nicht zugeordnete Charakterbilder anschauen">Anschauen</button>
                                    </div>
                                </div>
                                <div class="faction-char-list" data-char-group=""></div>
                            </section>
                            <div class="faction-groups-status" id="factionGroupsStatus" role="status"></div>
                        </section>
                    <?php endif; ?>

                    <div class="phan-bottom-actions faction-bottom-actions">
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

    <?php if ($faction && (int)$faction['id'] > 0): ?>
        <div class="faction-gallery-modal" id="factionGroupGallery"
             role="dialog" aria-modal="true" aria-labelledby="factionGalleryTitle"
             aria-hidden="true" hidden>
            <div class="faction-gallery-dialog">
                <header class="faction-gallery-header">
                    <div class="faction-gallery-heading">
                        <h2 id="factionGalleryTitle">Gruppe anschauen</h2>
                        <span id="factionGalleryCount"></span>
                    </div>
                    <button type="button" class="faction-gallery-close" id="factionGalleryClose"
                            aria-label="Galerie schließen" title="Schließen">&times;</button>
                </header>
                <div class="faction-gallery-grid" id="factionGalleryGrid"></div>
            </div>
        </div>
    <?php endif; ?>
</div>



<script>
(() => {
    'use strict';

    const panel = document.getElementById('factionGroupsPanel');
    if (!panel) return;

    const groups = document.getElementById('factionGroupList');
    const status = document.getElementById('factionGroupsStatus');
    const chars = <?= json_encode($factionCharacters, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    const imageRows = <?= json_encode($factionCharacterImages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    const imagesByChar = new Map();
    const imageTitles = new Map();
    for (const image of imageRows) {
        const key = Number(image.char_id);
        const imageId = Number(image.id);
        if (!imagesByChar.has(key)) imagesByChar.set(key, []);
        imagesByChar.get(key).push(imageId);
        imageTitles.set(imageId, String(image.title ?? '').trim());
    }
    const lists = () => [...panel.querySelectorAll('.faction-char-list')];

    let savedLayout = '';
    let saving = false;
    let pointerDrag = null;

    function show(message, isError = false) {
        status.textContent = message;
        status.classList.toggle('is-error', isError);
    }

    function updateCounts() {
        for (const group of panel.querySelectorAll('.faction-group')) {
            const count = group.querySelectorAll('.faction-char-list > .faction-char').length;
            const badge = group.querySelector('.faction-group-count');
            if (badge) badge.textContent = String(count);
        }
    }

    function layout() {
        return {
            groups: [...groups.children].map(group => Number(group.dataset.groupId)),
            chars: lists().flatMap(list => [...list.children].map(char => ({
                id: Number(char.dataset.charId),
                group: list.dataset.charGroup === '' ? null : Number(list.dataset.charGroup)
            })))
        };
    }

    async function post(action, values = {}) {
        const body = new FormData();
        body.set('csrf', panel.dataset.csrf);
        body.set('id', panel.dataset.factionId);
        body.set('action', action);
        body.set('ajax', '1');
        for (const [key, value] of Object.entries(values)) body.set(key, value);
        const response = await fetch('/phan/factions', {
            method: 'POST',
            body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) {
            throw new Error(data?.message || 'Speichern fehlgeschlagen.');
        }
        return data;
    }

    // Serialisiert Änderungen. Eine weitere Sortierung während eines Requests geht nicht verloren.
    async function persist() {
        if (saving) return;
        saving = true;
        try {
            while (true) {
                const current = JSON.stringify(layout());
                if (current === savedLayout) break;
                show('Speichere …');
                await post('group_layout', { layout: current });
                savedLayout = current;
            }
            show('Gespeichert.');
        } catch (error) {
            show(error.message, true);
            // Nach einem Serverfehler nicht mit potenziell veralteten Daten fortfahren.
            panel.classList.add('faction-layout-error');
        } finally {
            saving = false;
        }
    }

    // Ein Charakter ist nur über den Griff ganz links ziehbar.
    // Die Fraktionsauswahl ist unabhängig von chars.active_image_id.
    function renderFactionImage(char, imageId) {
        const charId = Number(char.dataset.charId);
        const choices = imagesByChar.get(charId) || [];
        const index = choices.indexOf(Number(imageId));
        const selected = index >= 0 ? choices[index] : 0;
        char.dataset.imageId = selected ? String(selected) : '';
        const avatar = char.querySelector('.faction-char-avatar');
        avatar.replaceChildren();

        if (selected) {
            const image = document.createElement('img');
            image.src = '/phan/chars?thumb_image=' + selected;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            image.draggable = false;
            image.addEventListener('error', () => {
                avatar.replaceChildren();
                avatar.textContent = '◯';
            }, { once: true });
            avatar.append(image);
        } else {
            avatar.textContent = '◯';
        }
        const left = char.querySelector('[data-image-direction="-1"]');
        const right = char.querySelector('[data-image-direction="1"]');
        if (left) left.disabled = char.dataset.imageSaving === '1' || index <= 0;
        if (right) right.disabled = char.dataset.imageSaving === '1' || index < 0 || index >= choices.length - 1;
    }

    for (const item of chars) {
        const char = document.createElement('div');
        char.className = 'faction-char';
        char.dataset.charId = String(item.char_id);
        char.dataset.imageSaving = '0';

        const grip = document.createElement('button');
        grip.type = 'button';
        grip.className = 'faction-grip faction-char-grip';
        grip.textContent = '☰';
        grip.title = 'Charakter ziehen';
        grip.setAttribute('aria-label', 'Charakter ziehen: ' + item.char_label);

        const avatar = document.createElement('span');
        avatar.className = 'faction-char-avatar';

        const name = document.createElement('span');
        name.className = 'faction-char-name';
        name.textContent = item.char_label || 'Unbenannter Charakter';

        const controls = document.createElement('span');
        controls.className = 'faction-char-image-nav';
        for (const direction of [-1, 1]) {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.imageDirection = String(direction);
            button.textContent = direction === -1 ? '‹' : '›';
            button.title = direction === -1 ? 'Vorheriges Charakterbild' : 'Nächstes Charakterbild';
            button.setAttribute('aria-label', button.title + ': ' + name.textContent);
            controls.append(button);
        }

        char.append(grip, avatar, name, controls);
        (lists().find(list => list.dataset.charGroup ===
            (item.group_id === null ? '' : String(item.group_id))) || lists().at(-1)).append(char);

        const choices = imagesByChar.get(Number(item.char_id)) || [];
        const preferred = Number(item.faction_image_id);
        const globalProfile = Number(item.active_image_id);
        const initial = choices.includes(preferred) ? preferred
            : choices.includes(globalProfile) ? globalProfile
            : choices[0] || 0;
        renderFactionImage(char, initial);
    }

    // Jede Änderung wird sofort gespeichert. Während des Requests sind
    // die Bildwechsel-Buttons gesperrt, um Schreib-Rennen zu vermeiden.
    panel.addEventListener('click', async event => {
        const button = event.target.closest('[data-image-direction]');
        if (!button || button.disabled) return;
        const char = button.closest('.faction-char');
        if (!char || char.dataset.imageSaving === '1') return;
        const charId = Number(char.dataset.charId);
        const choices = imagesByChar.get(charId) || [];
        const previous = Number(char.dataset.imageId);
        const next = choices[choices.indexOf(previous) + Number(button.dataset.imageDirection)];
        if (!next) return;

        char.dataset.imageSaving = '1';
        renderFactionImage(char, next);
        try {
            await post('set_faction_image', { char_id: String(charId), image_id: String(next) });
            show('Charakterbild gespeichert.');
        } catch (error) {
            renderFactionImage(char, previous);
            show(error.message, true);
        } finally {
            char.dataset.imageSaving = '0';
            renderFactionImage(char, Number(char.dataset.imageId));
        }
    });

    updateCounts();
    savedLayout = JSON.stringify(layout());

    document.getElementById('factionAddGroup').addEventListener('click', async () => {
        const name = prompt('Name der neuen Gruppe:');
        if (!name?.trim()) return;
        try {
            await post('group_add', { group_title: name.trim() });
            location.reload();
        } catch (error) {
            show(error.message, true);
        }
    });

    const modal = document.getElementById('factionGroupGallery');
    const modalTitle = document.getElementById('factionGalleryTitle');
    const modalCount = document.getElementById('factionGalleryCount');
    const modalGrid = document.getElementById('factionGalleryGrid');
    const closeGalleryButton = document.getElementById('factionGalleryClose');
    let galleryPreviousFocus = null;

    // Bildbreite ergibt sich aus ihrer Originalproportion und der
    // tatsächlich verfügbaren Galeriehöhe. Kein Shrinking im Flex-Layout.
    function fitGalleryImageWidths() {
        if (!modal || modal.hidden) return;
        for (const card of modalGrid.querySelectorAll('.faction-gallery-card')) {
            const image = card.querySelector('img');
            const imageBox = card.querySelector('.faction-gallery-card-image');
            if (!image?.naturalWidth || !image?.naturalHeight || !imageBox) continue;
            const height = imageBox.clientHeight;
            if (height <= 0) continue;
            card.style.width = Math.ceil(height * image.naturalWidth / image.naturalHeight) + 'px';
        }
    }
    modalGrid?.addEventListener('load', event => {
        if (event.target instanceof HTMLImageElement) fitGalleryImageWidths();
    }, true);
    window.addEventListener('resize', fitGalleryImageWidths);
    if (typeof ResizeObserver !== 'undefined' && modalGrid) {
        new ResizeObserver(fitGalleryImageWidths).observe(modalGrid);
    }

    function closeGallery() {
        if (!modal || modal.hidden) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        modalGrid.replaceChildren();
        document.body.classList.remove('faction-gallery-open');
        galleryPreviousFocus?.focus();
        galleryPreviousFocus = null;
    }

    function viewGroup(group) {
        if (!modal) return;
        const groupName = group.querySelector('.faction-group-title')?.textContent?.trim()
            || 'Undefiniert';
        const members = [...group.querySelectorAll('.faction-char-list > .faction-char')];
        galleryPreviousFocus = document.activeElement;
        modalTitle.textContent = groupName;
        modalCount.textContent = members.length + (members.length === 1 ? ' Charakter' : ' Charaktere');
        modalGrid.replaceChildren();

        if (!members.length) {
            const empty = document.createElement('p');
            empty.className = 'faction-gallery-empty';
            empty.textContent = 'In dieser Gruppe befinden sich keine Charaktere.';
            modalGrid.append(empty);
        }

        for (const member of members) {
            const card = document.createElement('figure');
            card.className = 'faction-gallery-card';
            const photo = document.createElement('div');
            photo.className = 'faction-gallery-card-image';
            const displayName = member.querySelector('.faction-char-name')?.textContent?.trim()
                || 'Unbenannter Charakter';
            const missingPhoto = () => {
                photo.replaceChildren();
                const fallback = document.createElement('span');
                fallback.className = 'faction-gallery-fallback';
                fallback.textContent = 'Kein Profilbild vorhanden';
                photo.append(fallback);
            };
            const chosenImageId = Number(member.dataset.imageId);
            if (chosenImageId > 0) {
                const image = document.createElement('img');
                image.src = '/phan/chars?image_id=' + chosenImageId;
                image.alt = 'Profilbild von ' + displayName;
                image.loading = 'lazy';
                image.decoding = 'async';
                image.draggable = false;
                image.addEventListener('error', missingPhoto, { once: true });
                photo.append(image);
            } else {
                missingPhoto();
            }
            const caption = document.createElement('figcaption');
            caption.className = 'faction-gallery-caption';
            const nameLine = document.createElement('span');
            nameLine.className = 'faction-gallery-char-name';
            nameLine.textContent = displayName;
            nameLine.title = displayName;
            const titleLine = document.createElement('span');
            titleLine.className = 'faction-gallery-image-title';
            const imageTitle = imageTitles.get(chosenImageId) || '';
            titleLine.textContent = imageTitle || 'Ohne Bildtitel';
            titleLine.title = titleLine.textContent;
            caption.append(nameLine, titleLine);
            card.append(photo, caption);
            modalGrid.append(card);
        }
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('faction-gallery-open');
        closeGalleryButton.focus();
        modalGrid.scrollLeft = 0;
        requestAnimationFrame(fitGalleryImageWidths);
    }

    // Fotoalbum: per gehaltener Maustaste oder Touch horizontal verschieben.
    let galleryPan = null;
    modalGrid?.addEventListener('pointerdown', event => {
        if (event.button !== 0 || !modalGrid.scrollWidth) return;
        galleryPan = {
            id: event.pointerId,
            x: event.clientX,
            scrollLeft: modalGrid.scrollLeft
        };
        modalGrid.setPointerCapture(event.pointerId);
        modalGrid.classList.add('is-panning');
        event.preventDefault();
    });
    modalGrid?.addEventListener('pointermove', event => {
        if (!galleryPan || galleryPan.id !== event.pointerId) return;
        modalGrid.scrollLeft = galleryPan.scrollLeft + galleryPan.x - event.clientX;
        event.preventDefault();
    });
    function stopGalleryPan(event) {
        if (!galleryPan || galleryPan.id !== event.pointerId) return;
        galleryPan = null;
        modalGrid.classList.remove('is-panning');
        if (modalGrid.hasPointerCapture(event.pointerId)) {
            modalGrid.releasePointerCapture(event.pointerId);
        }
    }
    modalGrid?.addEventListener('pointerup', stopGalleryPan);
    modalGrid?.addEventListener('pointercancel', stopGalleryPan);
    modalGrid?.addEventListener('lostpointercapture', () => {
        galleryPan = null;
        modalGrid.classList.remove('is-panning');
    });
    // Klassische Mausräder können ebenfalls horizontal navigieren.
    modalGrid?.addEventListener('wheel', event => {
        if (modalGrid.scrollWidth <= modalGrid.clientWidth || event.ctrlKey) return;
        if (Math.abs(event.deltaY) > Math.abs(event.deltaX)) {
            modalGrid.scrollLeft += event.deltaY;
            event.preventDefault();
        }
    }, { passive: false });

    closeGalleryButton?.addEventListener('click', closeGallery);
    modal?.addEventListener('click', event => {
        if (event.target === modal) closeGallery();
    });
    document.addEventListener('keydown', event => {
        if (!modal || modal.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeGallery();
        } else if (event.key === 'Tab') {
            // Der Schließen-Button ist das einzige fokussierbare Element im Modal.
            event.preventDefault();
            closeGalleryButton.focus();
        }
    });

    panel.addEventListener('click', event => {
        const button = event.target.closest('[data-group-view]');
        if (!button) return;
        const group = button.closest('.faction-group');
        if (group) viewGroup(group);
    });

    groups.addEventListener('click', async event => {
        const button = event.target.closest('[data-group-rename], [data-group-delete]');
        if (!button) return;
        const group = button.closest('[data-group-id]');
        if (!group) return;
        try {
            if (button.hasAttribute('data-group-rename')) {
                const name = prompt('Gruppenname:', group.querySelector('.faction-group-title').textContent);
                if (!name?.trim()) return;
                await post('group_rename', { group_id: group.dataset.groupId, group_title: name.trim() });
            } else {
                if (!confirm('Gruppe löschen? Charaktere wechseln nach „Undefiniert“.')) return;
                await post('group_delete', { group_id: group.dataset.groupId });
            }
            location.reload();
        } catch (error) {
            show(error.message, true);
        }
    });

    // Pointer Events statt nativem HTML-DnD: funktioniert auch auf Touchscreens.
    panel.addEventListener('pointerdown', event => {
        if (event.button !== 0 || panel.classList.contains('faction-layout-error')) return;
        const grip = event.target.closest('.faction-group-grip, .faction-char-grip');
        if (!grip) return;
        const char = grip.closest('.faction-char');
        const group = grip.closest('#factionGroupList > .faction-group');
        const element = char || group;
        if (!element) return;
        pointerDrag = {
            element,
            kind: char ? 'char' : 'group',
            pointerId: event.pointerId,
            initialX: event.clientX,
            initialY: event.clientY,
            initialLayout: JSON.stringify(layout()),
            active: false
        };
        event.preventDefault();
    });

    function pointerMove(event) {
        const drag = pointerDrag;
        if (!drag || event.pointerId !== drag.pointerId) return;
        if (!drag.active) {
            const distance = Math.hypot(event.clientX - drag.initialX, event.clientY - drag.initialY);
            if (distance < 5) return;
            drag.active = true;
            drag.element.classList.add('is-dragging');
            document.body.classList.add('faction-pointer-sorting');
        }
        event.preventDefault();

        const target = document.elementFromPoint(event.clientX, event.clientY);
        if (!target || !panel.contains(target)) return;

        if (drag.kind === 'group') {
            const over = target.closest('#factionGroupList > .faction-group');
            if (!over || over === drag.element) return;
            const midpoint = over.getBoundingClientRect();
            groups.insertBefore(drag.element,
                event.clientY < midpoint.top + midpoint.height / 2 ? over : over.nextSibling);
            return;
        }

        const region = target.closest('.faction-group');
        const list = target.closest('.faction-char-list') || region?.querySelector('.faction-char-list');
        if (!list) return;
        const over = target.closest('.faction-char');
        if (over && over !== drag.element && over.parentElement === list) {
            const rect = over.getBoundingClientRect();
            list.insertBefore(drag.element,
                event.clientY < rect.top + rect.height / 2 ? over : over.nextSibling);
        } else if (!over) {
            // Sortiert auch innerhalb leerer Gruppen sowie über den Gruppenheader.
            list.append(drag.element);
        }
        updateCounts();
    }

    function pointerEnd(event) {
        const drag = pointerDrag;
        if (!drag || event.pointerId !== drag.pointerId) return;
        pointerDrag = null;
        drag.element.classList.remove('is-dragging');
        document.body.classList.remove('faction-pointer-sorting');
        updateCounts();
        if (drag.active && JSON.stringify(layout()) !== drag.initialLayout) void persist();
    }

    document.addEventListener('pointermove', pointerMove, { passive: false });
    document.addEventListener('pointerup', pointerEnd);
    document.addEventListener('pointercancel', pointerEnd);
})();
</script>

<script>
(() => {
    'use strict';

    const PHONE_UI = !!(
        window.matchMedia
        && window.matchMedia('(max-width: 650px)').matches
    );

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

    let saveTimer = null;
    let saveChain = Promise.resolve();
    let statusTimer = null;


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


    if (!PHONE_UI) {
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
    }


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
