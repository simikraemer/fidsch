<?php
// phan/Alben.php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$phanconn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['phan_albums_csrf'])) {
    $_SESSION['phan_albums_csrf'] = bin2hex(random_bytes(32));
}

$csrf = (string)$_SESSION['phan_albums_csrf'];

const ALB_SPOTIFY_ENV = '/opt/spotify-top-tracks/spotify_monthly_top50.env';
const ALB_SPOTIFY_ACCOUNTS = 'https://accounts.spotify.com';
const ALB_SPOTIFY_API = 'https://api.spotify.com/v1';
const ALB_UPLOAD_DIR = __DIR__ . '/../uploads/phan/albums';
const ALB_PUBLIC_DIR = '/uploads/phan/albums';
const ALB_MAX_IMAGE_BYTES = 12582912;
const ALB_THUMB_SIZE = 320;

function alb_spotify_collection_path(): string
{
    return '/play' . 'lists';
}

function alb_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function alb_all_filter_icon(): string
{
    return '
        <span class="phan-list-filter-icon">
            <span class="phan-list-filter-icon-symbol">
                ∞
            </span>
        </span>
    ';
}

function alb_exec(mysqli $db, string $sql, array $params = []): mysqli_stmt
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
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $refs[$i] = &$values[$i];
        }

        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();
    return $stmt;
}

function alb_one(mysqli $db, string $sql, array $params = []): ?array
{
    $stmt = alb_exec($db, $sql, $params);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : null;
}

function alb_all(mysqli $db, string $sql, array $params = []): array
{
    $stmt = alb_exec($db, $sql, $params);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function alb_json(array $payload, int $status = 200): never
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

function alb_require_csrf(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    $real = (string)($_SESSION['phan_albums_csrf'] ?? '');

    if ($sent === '' || $real === '' || !hash_equals($real, $sent)) {
        throw new RuntimeException('Ungültiges Formular-Token. Seite neu laden.');
    }
}

function alb_int_ids(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $raw) {
        $id = (int)$raw;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function alb_crop_value(mixed $value): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return max(0.0, min(1.0, (float)$value));
}

function alb_filter_state(): array
{
    $state = [];

    $labelId = max(
        0,
        (int)($_GET['label'] ?? 0)
    );

    $artistId = max(
        0,
        (int)($_GET['artist'] ?? 0)
    );

    $search = trim(
        (string)($_GET['q'] ?? '')
    );

    if (function_exists('mb_substr')) {
        $search = mb_substr(
            $search,
            0,
            200,
            'UTF-8'
        );
    } else {
        $search = substr(
            $search,
            0,
            200
        );
    }

    if ($labelId > 0) {
        $state['label'] =
            $labelId;
    }

    if ($artistId > 0) {
        $state['artist'] =
            $artistId;
    }

    if ($search !== '') {
        $state['q'] =
            $search;
    }

    return $state;
}

function alb_list_url(): string
{
    $state =
        alb_filter_state();

    return '/phan/alben'
        . (
            $state
                ? '?'
                    . http_build_query(
                        $state,
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    )
                : ''
        );
}

function alb_album_url(int $id): string
{
    $query =
        ['id' => $id]
        + alb_filter_state();

    return '/phan/alben?'
        . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}


/* =========================================================
 * Spotify
 * ========================================================= */

function alb_read_env(): array
{
    if (!is_file(ALB_SPOTIFY_ENV) || !is_readable(ALB_SPOTIFY_ENV)) {
        throw new RuntimeException('Spotify-Konfiguration ist nicht lesbar.');
    }

    $lines = file(ALB_SPOTIFY_ENV, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Spotify-Konfiguration konnte nicht gelesen werden.');
    }

    $env = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $match)) {
            continue;
        }

        $key = $match[1];
        $value = trim($match[2]);

        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

function alb_env_need(array $env, string $key): string
{
    $value = trim((string)($env[$key] ?? ''));
    if ($value === '') {
        throw new RuntimeException($key . ' fehlt in der Spotify-Konfiguration.');
    }
    return $value;
}

function alb_http(
    string $method,
    string $url,
    array $headers = [],
    ?array $form = null
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP-cURL fehlt.');
    }

    $ch = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($form !== null) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($form);
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException('Spotify-Verbindungsfehler: ' . $error);
    }

    $json = json_decode((string)$body, true);

    return [
        'status' => $status,
        'body' => (string)$body,
        'json' => is_array($json) ? $json : null,
    ];
}

function alb_spotify_access_token(): string
{
    $env = alb_read_env();
    $clientId = alb_env_need($env, 'SPOTIFY_CLIENT_ID');
    $clientSecret = alb_env_need($env, 'SPOTIFY_CLIENT_SECRET');
    $refreshToken = alb_env_need($env, 'SPOTIFY_REFRESH_TOKEN');

    $response = alb_http(
        'POST',
        ALB_SPOTIFY_ACCOUNTS . '/api/token',
        [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]
    );

    if ($response['status'] !== 200 || !is_array($response['json'])) {
        throw new RuntimeException('Spotify-Anmeldung fehlgeschlagen.');
    }

    $token = trim((string)($response['json']['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Spotify hat keinen Access Token geliefert.');
    }

    return $token;
}

function alb_spotify_get(string $token, string $path, array $query = []): array
{
    $url = ALB_SPOTIFY_API . $path;
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $response = alb_http(
        'GET',
        $url,
        [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]
    );

    if ($response['status'] === 403) {
        throw new RuntimeException(
            'Spotify verweigert den Zugriff auf dieses Album. '
            . 'Der Token muss privaten Lesezugriff enthalten und das Album muss dir gehören.'
        );
    }

    if ($response['status'] === 401) {
        throw new RuntimeException('Spotify-Anmeldung ist nicht mehr gültig.');
    }

    if ($response['status'] === 404) {
        throw new RuntimeException('Spotify-Album wurde nicht gefunden.');
    }

    if (
        $response['status'] < 200
        || $response['status'] >= 300
        || !is_array($response['json'])
    ) {
        throw new RuntimeException(
            'Spotify-Abfrage fehlgeschlagen (HTTP ' . $response['status'] . ').'
        );
    }

    return $response['json'];
}

function alb_spotify_source_id(string $input): string
{
    $input = trim($input);

    if ($input === '') {
        throw new RuntimeException('Bitte eine Spotify-URL eintragen.');
    }

    if (preg_match('/^[A-Za-z0-9]{10,100}$/', $input)) {
        return $input;
    }

    if (preg_match('/^spotify:[^:]+:([A-Za-z0-9]+)$/i', $input, $match)) {
        return $match[1];
    }

    $parts = parse_url($input);

    if (is_array($parts) && !empty($parts['path'])) {
        $segments = array_values(
            array_filter(
                explode('/', trim((string)$parts['path'], '/')),
                static fn(string $part): bool => $part !== ''
            )
        );

        if (count($segments) >= 2) {
            $candidate = (string)end($segments);

            if (preg_match('/^[A-Za-z0-9]{10,100}$/', $candidate)) {
                return $candidate;
            }
        }
    }

    throw new RuntimeException('Spotify-URL konnte nicht erkannt werden.');
}

function alb_spotify_album(string $sourceId): array
{
    $token = alb_spotify_access_token();
    $base = alb_spotify_collection_path() . '/' . rawurlencode($sourceId);

    $meta = alb_spotify_get($token, $base);

    $tracks = [];
    $offset = 0;
    $limit = 50;

    do {
        $page = alb_spotify_get(
            $token,
            $base . '/items',
            [
                'limit' => $limit,
                'offset' => $offset,
            ]
        );

        $items = is_array($page['items'] ?? null) ? $page['items'] : [];

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = $row['item'] ?? $row['track'] ?? null;
            if (!is_array($item)) {
                continue;
            }

            $id = trim((string)($item['id'] ?? ''));
            $name = trim((string)($item['name'] ?? ''));

            if ($id === '' || $name === '') {
                continue;
            }

            $tracks[] = [
                'spotify_track_id' => $id,
                'original_title' => $name,
                'spotify_uri' => trim((string)($item['uri'] ?? '')),
                'spotify_url' => trim((string)($item['external_urls']['spotify'] ?? '')),
            ];
        }

        $offset += count($items);
        $next = $page['next'] ?? null;

    } while ($next && count($items) > 0);

    return [
        'source_id' => $sourceId,
        'source_title' => trim((string)($meta['name'] ?? '')),
        'spotify_url' => trim((string)($meta['external_urls']['spotify'] ?? '')),
        'snapshot_id' => trim((string)($meta['snapshot_id'] ?? '')),
        'tracks' => $tracks,
    ];
}


/* =========================================================
 * Synchronisation
 * ========================================================= */

function alb_sync_into_db(mysqli $db, int $albumId, array $remote): int
{
    $tracks = is_array($remote['tracks'] ?? null) ? $remote['tracks'] : [];

    $db->begin_transaction();

    try {
        alb_exec(
            $db,
            '
            UPDATE album_songs
            SET
                is_present = 0,
                removed_at = COALESCE(removed_at, NOW())
            WHERE album_id = ?
              AND is_present = 1
            ',
            [$albumId]
        )->close();

        foreach ($tracks as $index => $track) {
            $spotifyTrackId = (string)($track['spotify_track_id'] ?? '');
            $originalTitle = (string)($track['original_title'] ?? '');
            $spotifyUri = (string)($track['spotify_uri'] ?? '');
            $spotifyUrl = (string)($track['spotify_url'] ?? '');

            if ($spotifyTrackId === '' || $originalTitle === '') {
                continue;
            }

            /*
             * original_title wird bei einem bestehenden Song
             * absichtlich nicht überschrieben.
             */
            alb_exec(
                $db,
                '
                INSERT INTO songs (
                    spotify_track_id,
                    original_title,
                    spotify_uri,
                    spotify_url
                )
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    spotify_uri = VALUES(spotify_uri),
                    spotify_url = VALUES(spotify_url)
                ',
                [
                    $spotifyTrackId,
                    $originalTitle,
                    $spotifyUri,
                    $spotifyUrl,
                ]
            )->close();

            $song = alb_one(
                $db,
                '
                SELECT id
                FROM songs
                WHERE spotify_track_id = ?
                LIMIT 1
                ',
                [$spotifyTrackId]
            );

            if (!$song) {
                throw new RuntimeException('Song konnte nicht gespeichert werden.');
            }

            $songId = (int)$song['id'];

            /*
             * position_no ist nur Sortierung.
             * display_title, notes und Features bleiben beim
             * Synchronisieren unangetastet.
             */
            alb_exec(
                $db,
                '
                INSERT INTO album_songs (
                    album_id,
                    song_id,
                    position_no,
                    display_title,
                    notes,
                    is_present,
                    first_seen_at,
                    last_seen_at,
                    removed_at
                )
                VALUES (?, ?, ?, ?, NULL, 1, NOW(), NOW(), NULL)
                ON DUPLICATE KEY UPDATE
                    position_no = VALUES(position_no),
                    is_present = 1,
                    last_seen_at = NOW(),
                    removed_at = NULL
                ',
                [
                    $albumId,
                    $songId,
                    $index + 1,
                    $originalTitle,
                ]
            )->close();
        }

        alb_exec(
            $db,
            '
            UPDATE albums
            SET
                spotify_url = ?,
                spotify_source_title = ?,
                spotify_snapshot_id = ?,
                last_synced_at = NOW()
            WHERE id = ?
            ',
            [
                (string)($remote['spotify_url'] ?? ''),
                (string)($remote['source_title'] ?? ''),
                (string)($remote['snapshot_id'] ?? ''),
                $albumId,
            ]
        )->close();

        $db->commit();
        return count($tracks);

    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function alb_create_from_spotify(mysqli $db, string $input): int
{
    $sourceId = alb_spotify_source_id($input);

    $existing = alb_one(
        $db,
        '
        SELECT id
        FROM albums
        WHERE spotify_source_id = ?
        LIMIT 1
        ',
        [$sourceId]
    );

    if ($existing) {
        return (int)$existing['id'];
    }

    $remote = alb_spotify_album($sourceId);

    $title = trim((string)($remote['source_title'] ?? ''));
    if ($title === '') {
        $title = 'Unbenanntes Album';
    }

    $stmt = alb_exec(
        $db,
        '
        INSERT INTO albums (
            spotify_source_id,
            spotify_url,
            spotify_source_title,
            spotify_snapshot_id,
            title,
            notes,
            last_synced_at
        )
        VALUES (?, ?, ?, ?, ?, NULL, NOW())
        ',
        [
            $sourceId,
            (string)($remote['spotify_url'] ?? ''),
            (string)($remote['source_title'] ?? ''),
            (string)($remote['snapshot_id'] ?? ''),
            $title,
        ]
    );

    $albumId = (int)$stmt->insert_id;
    $stmt->close();

    try {
        alb_sync_into_db($db, $albumId, $remote);
        return $albumId;

    } catch (Throwable $e) {
        alb_exec($db, 'DELETE FROM albums WHERE id = ?', [$albumId])->close();
        throw $e;
    }
}


/* =========================================================
 * Cover
 * ========================================================= */

function alb_ensure_upload_dir(): void
{
    if (
        !is_dir(ALB_UPLOAD_DIR)
        && !mkdir(ALB_UPLOAD_DIR, 0775, true)
        && !is_dir(ALB_UPLOAD_DIR)
    ) {
        throw new RuntimeException('Album-Bildordner konnte nicht angelegt werden.');
    }
}

function alb_cover_disk_path(?string $stored): ?string
{
    $stored = trim((string)$stored);
    if ($stored === '') {
        return null;
    }

    $name = basename($stored);
    return $name === '' ? null : ALB_UPLOAD_DIR . '/' . $name;
}

function alb_delete_cover_file(?string $stored): void
{
    $path = alb_cover_disk_path($stored);
    if ($path && is_file($path)) {
        @unlink($path);
    }
}

function alb_upload_cover(mysqli $db, int $albumId, array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Bild-Upload fehlgeschlagen.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > ALB_MAX_IMAGE_BYTES) {
        throw new RuntimeException('Bild ist leer oder größer als 12 MB.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Ungültige Upload-Datei.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Erlaubt sind JPG, PNG und WebP.');
    }

    if (!@getimagesize($tmp)) {
        throw new RuntimeException('Datei ist kein gültiges Bild.');
    }

    alb_ensure_upload_dir();

    $filename =
        'album_'
        . $albumId
        . '_'
        . bin2hex(random_bytes(8))
        . '.'
        . $extensions[$mime];

    $disk = ALB_UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($tmp, $disk)) {
        throw new RuntimeException('Bild konnte nicht gespeichert werden.');
    }

    $album = alb_one(
        $db,
        'SELECT cover_path FROM albums WHERE id = ? LIMIT 1',
        [$albumId]
    );

    if (!$album) {
        @unlink($disk);
        throw new RuntimeException('Album existiert nicht mehr.');
    }

    $stored = ALB_PUBLIC_DIR . '/' . $filename;

    alb_exec(
        $db,
        '
        UPDATE albums
        SET
            cover_path = ?,
            thumb_x = NULL,
            thumb_y = NULL,
            thumb_w = NULL,
            thumb_h = NULL
        WHERE id = ?
        ',
        [$stored, $albumId]
    )->close();

    alb_delete_cover_file($album['cover_path'] ?? null);
}

function alb_load_image(string $path, string $mime): GdImage|false
{
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        default => false,
    };
}

function alb_output_cover(mysqli $db, int $albumId, bool $thumb): never
{
    $album = alb_one(
        $db,
        '
        SELECT
            cover_path,
            thumb_x,
            thumb_y,
            thumb_w,
            thumb_h
        FROM albums
        WHERE id = ?
        LIMIT 1
        ',
        [$albumId]
    );

    if (!$album || empty($album['cover_path'])) {
        http_response_code(404);
        exit;
    }

    $path = alb_cover_disk_path($album['cover_path']);

    if (!$path || !is_file($path)) {
        http_response_code(404);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($path);

    if (!$thumb) {
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    if (!extension_loaded('gd')) {
        header('Content-Type: ' . $mime);
        readfile($path);
        exit;
    }

    $source = alb_load_image($path, $mime);
    if (!$source) {
        http_response_code(415);
        exit;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);

    $x = alb_crop_value($album['thumb_x'] ?? null);
    $y = alb_crop_value($album['thumb_y'] ?? null);
    $w = alb_crop_value($album['thumb_w'] ?? null);
    $h = alb_crop_value($album['thumb_h'] ?? null);

    if (
        $x !== null
        && $y !== null
        && $w !== null
        && $h !== null
        && $w > 0
        && $h > 0
    ) {
        $srcX = (int)round($x * $sourceWidth);
        $srcY = (int)round($y * $sourceHeight);
        $srcW = max(1, (int)round($w * $sourceWidth));
        $srcH = max(1, (int)round($h * $sourceHeight));
    } else {
        $side = min($sourceWidth, $sourceHeight);
        $srcW = $side;
        $srcH = $side;
        $srcX = (int)floor(($sourceWidth - $side) / 2);
        $srcY = (int)floor(($sourceHeight - $side) / 2);
    }

    $srcX = max(0, min($sourceWidth - 1, $srcX));
    $srcY = max(0, min($sourceHeight - 1, $srcY));
    $srcW = min($srcW, $sourceWidth - $srcX);
    $srcH = min($srcH, $sourceHeight - $srcY);

    $dest = imagecreatetruecolor(ALB_THUMB_SIZE, ALB_THUMB_SIZE);
    $white = imagecolorallocate($dest, 255, 255, 255);
    imagefill($dest, 0, 0, $white);

    imagecopyresampled(
        $dest,
        $source,
        0,
        0,
        $srcX,
        $srcY,
        ALB_THUMB_SIZE,
        ALB_THUMB_SIZE,
        $srcW,
        $srcH
    );

    imagedestroy($source);

    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    imagejpeg($dest, null, 88);
    imagedestroy($dest);
    exit;
}

if (isset($_GET['cover'])) {
    alb_output_cover(
        $phanconn,
        max(0, (int)$_GET['cover']),
        false
    );
}

if (isset($_GET['thumb'])) {
    alb_output_cover(
        $phanconn,
        max(0, (int)$_GET['thumb']),
        true
    );
}


/* =========================================================
 * POST
 * ========================================================= */

$pageError = '';
$pageMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = isset($_POST['ajax']);

    try {
        alb_require_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_album') {
            $albumId = alb_create_from_spotify(
                $phanconn,
                (string)($_POST['spotify_url'] ?? '')
            );

            header('Location: ' . alb_album_url($albumId) . '&created=1');
            exit;
        }

        if ($action === 'sync_album') {
            $albumId = max(0, (int)($_POST['album_id'] ?? 0));

            $album = alb_one(
                $phanconn,
                '
                SELECT id, spotify_source_id
                FROM albums
                WHERE id = ?
                LIMIT 1
                ',
                [$albumId]
            );

            if (!$album) {
                throw new RuntimeException('Album existiert nicht mehr.');
            }

            $remote = alb_spotify_album((string)$album['spotify_source_id']);
            $count = alb_sync_into_db($phanconn, $albumId, $remote);

            header('Location: ' . alb_album_url($albumId) . '&synced=' . $count);
            exit;
        }

        if ($action === 'save_album_meta') {
            $albumId = max(0, (int)($_POST['album_id'] ?? 0));
            $title = trim((string)($_POST['title'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $albumYearRaw = trim((string)($_POST['album_year'] ?? ''));
            $albumMonthRaw = trim((string)($_POST['album_month'] ?? ''));

            if ($title === '') {
                throw new RuntimeException('Albumtitel darf nicht leer sein.');
            }

            if (
                $albumYearRaw !== ''
                && !preg_match('/^-?\d+$/', $albumYearRaw)
            ) {
                throw new RuntimeException(
                    'Jahr muss eine ganze Zahl sein.'
                );
            }

            $albumYear =
                $albumYearRaw === ''
                    ? ''
                    : (string)(int)$albumYearRaw;

            if (
                $albumMonthRaw !== ''
                && (
                    !preg_match('/^\d+$/', $albumMonthRaw)
                    || (int)$albumMonthRaw < 1
                    || (int)$albumMonthRaw > 12
                )
            ) {
                throw new RuntimeException(
                    'Monat muss zwischen 1 und 12 liegen.'
                );
            }

            /*
             * Ein Monat ohne Jahr wird nicht gespeichert.
             * Wird das Jahr geleert, wird damit auch der Monat
             * automatisch auf NULL gesetzt.
             */
            $albumMonth =
                $albumYear === ''
                    || $albumMonthRaw === ''
                        ? ''
                        : (string)(int)$albumMonthRaw;

            alb_exec(
                $phanconn,
                '
                UPDATE albums
                SET
                    title = ?,
                    notes = ?,
                    album_year = NULLIF(?, \'\'),
                    album_month = NULLIF(?, \'\')
                WHERE id = ?
                ',
                [
                    $title,
                    $notes,
                    $albumYear,
                    $albumMonth,
                    $albumId,
                ]
            )->close();

            alb_json([
                'ok' => true,
                'saved_at' => date('d.m.Y H:i:s'),
            ]);
        }

        if ($action === 'save_album_entities') {
            $albumId = max(0, (int)($_POST['album_id'] ?? 0));
            $kind = (string)($_POST['kind'] ?? '');
            $ids = alb_int_ids($_POST['ids'] ?? []);

            if ($kind !== 'labels' && $kind !== 'artists') {
                throw new RuntimeException('Ungültige Zuordnung.');
            }

            $phanconn->begin_transaction();

            try {
                if ($kind === 'labels') {
                    alb_exec(
                        $phanconn,
                        'DELETE FROM album_factions WHERE album_id = ?',
                        [$albumId]
                    )->close();

                    foreach ($ids as $id) {
                        alb_exec(
                            $phanconn,
                            '
                            INSERT IGNORE INTO album_factions (
                                album_id,
                                faction_id
                            )
                            VALUES (?, ?)
                            ',
                            [$albumId, $id]
                        )->close();
                    }
                } else {
                    alb_exec(
                        $phanconn,
                        'DELETE FROM album_chars WHERE album_id = ?',
                        [$albumId]
                    )->close();

                    foreach ($ids as $id) {
                        alb_exec(
                            $phanconn,
                            '
                            INSERT IGNORE INTO album_chars (
                                album_id,
                                char_id
                            )
                            VALUES (?, ?)
                            ',
                            [$albumId, $id]
                        )->close();
                    }
                }

                $phanconn->commit();

            } catch (Throwable $e) {
                $phanconn->rollback();
                throw $e;
            }

            alb_json([
                'ok' => true,
                'ids' => $ids,
            ]);
        }

        if ($action === 'save_song') {
            $albumSongId = max(0, (int)($_POST['album_song_id'] ?? 0));
            $displayTitle = trim((string)($_POST['display_title'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($displayTitle === '') {
                throw new RuntimeException('Songtitel darf nicht leer sein.');
            }

            alb_exec(
                $phanconn,
                '
                UPDATE album_songs
                SET display_title = ?, notes = ?
                WHERE id = ?
                ',
                [$displayTitle, $notes, $albumSongId]
            )->close();

            alb_json([
                'ok' => true,
                'saved_at' => date('d.m.Y H:i:s'),
            ]);
        }

        if ($action === 'save_song_features') {
            $albumSongId = max(0, (int)($_POST['album_song_id'] ?? 0));
            $ids = alb_int_ids($_POST['ids'] ?? []);

            $phanconn->begin_transaction();

            try {
                alb_exec(
                    $phanconn,
                    'DELETE FROM album_song_features WHERE album_song_id = ?',
                    [$albumSongId]
                )->close();

                foreach ($ids as $id) {
                    alb_exec(
                        $phanconn,
                        '
                        INSERT IGNORE INTO album_song_features (
                            album_song_id,
                            char_id
                        )
                        VALUES (?, ?)
                        ',
                        [$albumSongId, $id]
                    )->close();
                }

                $phanconn->commit();

            } catch (Throwable $e) {
                $phanconn->rollback();
                throw $e;
            }

            alb_json([
                'ok' => true,
                'ids' => $ids,
            ]);
        }

        if ($action === 'upload_cover') {
            $albumId = max(0, (int)($_POST['album_id'] ?? 0));

            alb_upload_cover(
                $phanconn,
                $albumId,
                $_FILES['cover'] ?? []
            );

            if ($ajax) {
                alb_json(['ok' => true]);
            }

            header('Location: ' . alb_album_url($albumId));
            exit;
        }

        if ($action === 'save_crop') {
            $albumId = max(0, (int)($_POST['album_id'] ?? 0));

            $x = alb_crop_value($_POST['x'] ?? null);
            $y = alb_crop_value($_POST['y'] ?? null);
            $w = alb_crop_value($_POST['w'] ?? null);
            $h = alb_crop_value($_POST['h'] ?? null);

            if (
                $x === null
                || $y === null
                || $w === null
                || $h === null
                || $w <= 0
                || $h <= 0
            ) {
                throw new RuntimeException('Ungültiger Thumbnail-Ausschnitt.');
            }

            alb_exec(
                $phanconn,
                '
                UPDATE albums
                SET
                    thumb_x = ?,
                    thumb_y = ?,
                    thumb_w = ?,
                    thumb_h = ?
                WHERE id = ?
                ',
                [$x, $y, $w, $h, $albumId]
            )->close();

            alb_json(['ok' => true]);
        }

        if ($action === 'remove_cover') {
            $albumId = max(0, (int)($_POST['album_id'] ?? 0));

            $album = alb_one(
                $phanconn,
                'SELECT cover_path FROM albums WHERE id = ? LIMIT 1',
                [$albumId]
            );

            if (!$album) {
                throw new RuntimeException('Album existiert nicht mehr.');
            }

            alb_exec(
                $phanconn,
                '
                UPDATE albums
                SET
                    cover_path = NULL,
                    thumb_x = NULL,
                    thumb_y = NULL,
                    thumb_w = NULL,
                    thumb_h = NULL
                WHERE id = ?
                ',
                [$albumId]
            )->close();

            alb_delete_cover_file($album['cover_path'] ?? null);
            alb_json(['ok' => true]);
        }

        if ($action === 'delete_album') {
            $albumId = max(0, (int)($_POST['album_id'] ?? 0));

            $album = alb_one(
                $phanconn,
                'SELECT cover_path FROM albums WHERE id = ? LIMIT 1',
                [$albumId]
            );

            if (!$album) {
                throw new RuntimeException('Album existiert nicht mehr.');
            }

            alb_exec(
                $phanconn,
                'DELETE FROM albums WHERE id = ?',
                [$albumId]
            )->close();

            alb_delete_cover_file($album['cover_path'] ?? null);

            alb_exec(
                $phanconn,
                '
                DELETE s
                FROM songs s
                LEFT JOIN album_songs alsg
                    ON alsg.song_id = s.id
                WHERE alsg.id IS NULL
                '
            )->close();

            $listUrl =
                alb_list_url();

            header(
                'Location: '
                . $listUrl
                . (
                    str_contains(
                        $listUrl,
                        '?'
                    )
                        ? '&'
                        : '?'
                )
                . 'deleted=1'
            );
            exit;
        }

        throw new RuntimeException('Unbekannte Aktion.');

    } catch (Throwable $e) {
        if ($ajax) {
            alb_json(
                [
                    'ok' => false,
                    'message' => $e->getMessage(),
                ],
                400
            );
        }

        $pageError = $e->getMessage();
    }
}


/* =========================================================
 * Daten
 * ========================================================= */

$chars = alb_all(
    $phanconn,
    '
    SELECT
        id,
        call_name,
        first_name,
        last_name,
        image_path
    FROM chars
    ORDER BY
        call_name,
        last_name,
        first_name
    '
);

$factions = alb_all(
    $phanconn,
    '
    SELECT
        id,
        title,
        image_path
    FROM factions
    ORDER BY title
    '
);

$albumId = max(0, (int)($_GET['id'] ?? 0));

$album = null;
$albumLabels = [];
$albumArtists = [];
$albumSongs = [];
$featuresByAlbumSong = [];

if ($albumId > 0) {
    $album = alb_one(
        $phanconn,
        '
        SELECT *
        FROM albums
        WHERE id = ?
        LIMIT 1
        ',
        [$albumId]
    );

    if (!$album) {
        http_response_code(404);
        $pageError = 'Album nicht gefunden.';
        $albumId = 0;
    }
}

if ($album) {
    $albumLabels = alb_all(
        $phanconn,
        '
        SELECT
            f.id,
            f.title,
            f.image_path
        FROM album_factions af
        INNER JOIN factions f
            ON f.id = af.faction_id
        WHERE af.album_id = ?
        ORDER BY f.title
        ',
        [$albumId]
    );

    $albumArtists = alb_all(
        $phanconn,
        '
        SELECT
            c.id,
            c.call_name,
            c.first_name,
            c.last_name,
            c.image_path
        FROM album_chars ac
        INNER JOIN chars c
            ON c.id = ac.char_id
        WHERE ac.album_id = ?
        ORDER BY
            c.call_name,
            c.last_name,
            c.first_name
        ',
        [$albumId]
    );

    $albumSongs = alb_all(
        $phanconn,
        '
        SELECT
            alsg.id AS album_song_id,
            alsg.position_no,
            alsg.display_title,
            alsg.notes,
            s.id AS song_id,
            s.original_title,
            s.spotify_track_id,
            s.spotify_url
        FROM album_songs alsg
        INNER JOIN songs s
            ON s.id = alsg.song_id
        WHERE alsg.album_id = ?
          AND alsg.is_present = 1
        ORDER BY
            alsg.position_no,
            alsg.id
        ',
        [$albumId]
    );

    if ($albumSongs) {
        $albumSongIds = array_map(
            static fn(array $row): int => (int)$row['album_song_id'],
            $albumSongs
        );

        $placeholders = implode(
            ',',
            array_fill(0, count($albumSongIds), '?')
        );

        $featureRows = alb_all(
            $phanconn,
            '
            SELECT
                asf.album_song_id,
                c.id,
                c.call_name,
                c.first_name,
                c.last_name,
                c.image_path
            FROM album_song_features asf
            INNER JOIN chars c
                ON c.id = asf.char_id
            WHERE asf.album_song_id IN (' . $placeholders . ')
            ORDER BY
                asf.album_song_id,
                c.call_name,
                c.last_name,
                c.first_name
            ',
            $albumSongIds
        );

        foreach ($featureRows as $row) {
            $key = (int)$row['album_song_id'];
            $featuresByAlbumSong[$key][] = $row;
        }
    }
}

$albums = [];

$albumFilterFactions = [];
$albumFilterChars = [];

if (!$album) {
    $albums = alb_all(
        $phanconn,
        '
        SELECT
            a.*,
            SUM(
                CASE
                    WHEN alsg.is_present = 1
                    THEN 1
                    ELSE 0
                END
            ) AS song_count
        FROM albums a
        LEFT JOIN album_songs alsg
            ON alsg.album_id = a.id
        GROUP BY a.id
        ORDER BY
            CASE
                WHEN a.album_year IS NULL THEN 2
                WHEN a.album_year >= 1000 THEN 0
                ELSE 1
            END ASC,
            a.album_year ASC,
            CASE
                WHEN a.album_month IS NULL THEN 1
                ELSE 0
            END ASC,
            a.album_month ASC,
            a.title ASC
        '
    );

    if ($albums) {
        $albumIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            $albums
        );

        $placeholders = implode(
            ',',
            array_fill(0, count($albumIds), '?')
        );

        $labelRows = alb_all(
            $phanconn,
            '
            SELECT
                af.album_id,
                f.id,
                f.title,
                f.image_path
            FROM album_factions af
            INNER JOIN factions f
                ON f.id = af.faction_id
            WHERE af.album_id IN (' . $placeholders . ')
            ORDER BY
                af.album_id,
                f.title
            ',
            $albumIds
        );

        $artistRows = alb_all(
            $phanconn,
            '
            SELECT
                ac.album_id,
                c.id,
                c.call_name,
                c.image_path
            FROM album_chars ac
            INNER JOIN chars c
                ON c.id = ac.char_id
            WHERE ac.album_id IN (' . $placeholders . ')
            ORDER BY
                ac.album_id,
                c.call_name
            ',
            $albumIds
        );

        $labelsByAlbum = [];
        $artistsByAlbum = [];

        $usedFactionIds = [];
        $usedCharIds = [];

        foreach ($labelRows as $row) {
            $usedFactionIds[
                (int)$row['id']
            ] = true;

            $albumKey =
                (int)$row['album_id'];

            $labelId =
                (int)$row['id'];

            $labelsByAlbum[$albumKey][] = [
                'id' =>
                    $labelId,

                'name' =>
                    (string)$row['title'],

                'thumb' =>
                    !empty($row['image_path'])
                        ? '/phan/factions?thumb='
                            . $labelId
                        : null,
            ];
        }

        foreach ($artistRows as $row) {
            $usedCharIds[
                (int)$row['id']
            ] = true;

            $albumKey =
                (int)$row['album_id'];

            $charId =
                (int)$row['id'];

            $artistsByAlbum[$albumKey][] = [
                'id' =>
                    $charId,

                'name' =>
                    (string)$row['call_name'],

                'thumb' =>
                    !empty($row['image_path'])
                        ? '/phan/chars?thumb='
                            . $charId
                        : null,
            ];
        }

        foreach ($albums as &$row) {
            $id =
                (int)$row['id'];

            $row['labels'] =
                $labelsByAlbum[$id]
                ?? [];

            $row['artists'] =
                $artistsByAlbum[$id]
                ?? [];

            $row['label_ids'] =
                array_map(
                    static fn(array $label): int =>
                        (int)$label['id'],
                    $row['labels']
                );

            $row['artist_ids'] =
                array_map(
                    static fn(array $artist): int =>
                        (int)$artist['id'],
                    $row['artists']
                );
        }
        unset($row);

        $albumFilterFactions =
            array_values(
                array_filter(
                    $factions,
                    static fn(array $faction): bool =>
                        isset(
                            $usedFactionIds[
                                (int)$faction['id']
                            ]
                        )
                )
            );

        $albumFilterChars =
            array_values(
                array_filter(
                    $chars,
                    static fn(array $char): bool =>
                        isset(
                            $usedCharIds[
                                (int)$char['id']
                            ]
                        )
                )
            );
    }
}

if (isset($_GET['created'])) {
    $pageMessage = 'Album importiert.';
}

if (isset($_GET['synced'])) {
    $pageMessage =
        'Album synchronisiert: '
        . max(0, (int)$_GET['synced'])
        . ' Songs.';
}

if (isset($_GET['deleted'])) {
    $pageMessage = 'Album lokal gelöscht.';
}

$frontendChars = array_map(
    static fn(array $char): array => [
        'id' => (int)$char['id'],
        'name' => (string)$char['call_name'],
        'full' => trim(
            (string)($char['first_name'] ?? '')
            . ' '
            . (string)($char['last_name'] ?? '')
        ),
        'thumb' => !empty($char['image_path'])
            ? '/phan/chars?thumb=' . (int)$char['id']
            : null,
    ],
    $chars
);

$frontendFactions = array_map(
    static fn(array $faction): array => [
        'id' => (int)$faction['id'],
        'name' => (string)$faction['title'],
        'full' => '',
        'thumb' => !empty($faction['image_path'])
            ? '/phan/factions?thumb=' . (int)$faction['id']
            : null,
    ],
    $factions
);

$page_title = $album
    ? 'Album · ' . (string)$album['title']
    : 'Alben';

require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>


<main class="phan-page albums-page">

    <?php if ($pageError !== ''): ?>
        <div class="phan-msg phan-error">
            <?= alb_h($pageError) ?>
        </div>
    <?php endif; ?>

    <?php if ($pageMessage !== ''): ?>
        <div class="phan-msg">
            <?= alb_h($pageMessage) ?>
        </div>
    <?php endif; ?>


    <?php if (!$album): ?>

        <div class="phan-head albums-head">
            <div>
                <h1 class="phan-title">Alben</h1>

                <div
                    class="albums-head-sub"
                    id="albumVisibleCount"
                >
                    <?= count($albums) ?>
                    <?= count($albums) === 1 ? 'Album' : 'Alben' ?>
                </div>
            </div>

            <form method="post" class="albums-import-form">
                <input
                    type="hidden"
                    name="csrf"
                    value="<?= alb_h($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="create_album"
                >

                <label class="albums-import-field">
                    <span>Spotify-URL</span>

                    <input
                        type="url"
                        name="spotify_url"
                        placeholder="https://open.spotify.com/…"
                        required
                    >
                </label>

                <button type="submit">
                    Album importieren
                </button>
            </form>
        </div>


        <div
            class="phan-list-filterbar-3zellen"
            id="albumListFilters"
        >

            <!-- Label -->

            <div
                class="phan-list-filter-dropdown"
                data-album-filter-dropdown
            >
                <button
                    type="button"
                    class="phan-list-filter-trigger"
                    data-album-filter-trigger
                    aria-haspopup="listbox"
                    aria-expanded="false"
                >
                    <span
                        id="albumLabelFilterVisual"
                    >
                        <?= alb_all_filter_icon() ?>
                    </span>

                    <span class="phan-list-filter-text">
                        <span>Label</span>

                        <strong id="albumLabelFilterText">
                            Alle
                        </strong>
                    </span>

                    <span
                        class="phan-list-filter-arrow"
                        aria-hidden="true"
                    >
                        ▾
                    </span>
                </button>

                <div
                    class="phan-list-filter-menu"
                    data-album-filter-menu
                    role="listbox"
                    hidden
                >
                    <button
                        type="button"
                        class="phan-list-filter-option active"
                        data-album-label-id=""
                        data-album-label-name="Alle"
                        data-album-label-thumb=""
                    >
                        <?= alb_all_filter_icon() ?>

                        <strong>Alle</strong>
                    </button>

                    <?php foreach ($albumFilterFactions as $faction): ?>

                        <button
                            type="button"
                            class="phan-list-filter-option"
                            data-album-label-id="<?= (int)$faction['id'] ?>"
                            data-album-label-name="<?= alb_h($faction['title']) ?>"
                            data-album-label-thumb="<?= !empty($faction['image_path'])
                                ? '/phan/factions?thumb=' . (int)$faction['id']
                                : ''
                            ?>"
                        >
                            <?php if (!empty($faction['image_path'])): ?>

                                <img
                                    class="phan-list-filter-thumb"
                                    src="/phan/factions?thumb=<?= (int)$faction['id'] ?>"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                >

                            <?php else: ?>

                                <span class="phan-list-filter-icon">
                                    <?= alb_h(
                                        function_exists('mb_substr')
                                            ? mb_substr(
                                                (string)$faction['title'],
                                                0,
                                                1,
                                                'UTF-8'
                                            )
                                            : substr(
                                                (string)$faction['title'],
                                                0,
                                                1
                                            )
                                    ) ?>
                                </span>

                            <?php endif; ?>

                            <strong>
                                <?= alb_h($faction['title']) ?>
                            </strong>
                        </button>

                    <?php endforeach; ?>
                </div>
            </div>


            <!-- Künstler -->

            <div
                class="phan-list-filter-dropdown"
                data-album-filter-dropdown
            >
                <button
                    type="button"
                    class="phan-list-filter-trigger"
                    data-album-filter-trigger
                    aria-haspopup="listbox"
                    aria-expanded="false"
                >
                    <span
                        id="albumArtistFilterVisual"
                    >
                        <?= alb_all_filter_icon() ?>
                    </span>

                    <span class="phan-list-filter-text">
                        <span>Künstler</span>

                        <strong id="albumArtistFilterText">
                            Alle
                        </strong>
                    </span>

                    <span
                        class="phan-list-filter-arrow"
                        aria-hidden="true"
                    >
                        ▾
                    </span>
                </button>

                <div
                    class="phan-list-filter-menu"
                    data-album-filter-menu
                    role="listbox"
                    hidden
                >
                    <button
                        type="button"
                        class="phan-list-filter-option active"
                        data-album-artist-id=""
                        data-album-artist-name="Alle"
                        data-album-artist-thumb=""
                    >
                        <?= alb_all_filter_icon() ?>

                        <strong>Alle</strong>
                    </button>

                    <?php foreach ($albumFilterChars as $char): ?>

                        <button
                            type="button"
                            class="phan-list-filter-option"
                            data-album-artist-id="<?= (int)$char['id'] ?>"
                            data-album-artist-name="<?= alb_h($char['call_name']) ?>"
                            data-album-artist-thumb="<?= !empty($char['image_path'])
                                ? '/phan/chars?thumb=' . (int)$char['id']
                                : ''
                            ?>"
                        >
                            <?php if (!empty($char['image_path'])): ?>

                                <img
                                    class="phan-list-filter-thumb"
                                    src="/phan/chars?thumb=<?= (int)$char['id'] ?>"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                >

                            <?php else: ?>

                                <span class="phan-list-filter-icon">
                                    <?= alb_h(
                                        function_exists('mb_substr')
                                            ? mb_substr(
                                                (string)$char['call_name'],
                                                0,
                                                1,
                                                'UTF-8'
                                            )
                                            : substr(
                                                (string)$char['call_name'],
                                                0,
                                                1
                                            )
                                    ) ?>
                                </span>

                            <?php endif; ?>

                            <strong>
                                <?= alb_h($char['call_name']) ?>
                            </strong>
                        </button>

                    <?php endforeach; ?>
                </div>
            </div>


            <!-- Sofortsuche -->

            <div class="phan-list-search-wrap">
                <input
                    type="search"
                    class="phan-list-search"
                    id="albumListSearch"
                    placeholder="Suchen: Album, Label, Künstler …"
                    autocomplete="off"
                    spellcheck="false"
                    aria-label="Alben durchsuchen"
                >
            </div>

        </div>


        <?php if (!$albums): ?>

            <div class="phan-card albums-empty">
                Noch keine Alben vorhanden.
            </div>

        <?php else: ?>

            <div class="albums-grid">

                <?php foreach ($albums as $albumRow): ?>
                    <?php $listAlbumId = (int)$albumRow['id']; ?>

                    <a
                        class="album-card"
                        href="<?= alb_h(alb_album_url($listAlbumId)) ?>"
                        data-album-id="<?= $listAlbumId ?>"
                        data-label-ids="<?= alb_h(
                            implode(
                                ',',
                                array_map(
                                    'strval',
                                    $albumRow['label_ids']
                                    ?? []
                                )
                            )
                        ) ?>"
                        data-artist-ids="<?= alb_h(
                            implode(
                                ',',
                                array_map(
                                    'strval',
                                    $albumRow['artist_ids']
                                    ?? []
                                )
                            )
                        ) ?>"
                        data-search-text="<?= alb_h(
                            implode(
                                ' ',
                                array_filter(
                                    [
                                        (string)($albumRow['title'] ?? ''),
                                        (string)($albumRow['spotify_source_title'] ?? ''),
                                        (string)($albumRow['album_year'] ?? ''),
                                        implode(
                                            ' ',
                                            array_map(
                                                static fn(array $label): string =>
                                                    (string)$label['name'],
                                                $albumRow['labels']
                                                    ?? []
                                            )
                                        ),
                                        implode(
                                            ' ',
                                            array_map(
                                                static fn(array $artist): string =>
                                                    (string)$artist['name'],
                                                $albumRow['artists']
                                                    ?? []
                                            )
                                        ),
                                    ],
                                    static fn(string $value): bool =>
                                        trim($value) !== ''
                                )
                            )
                        ) ?>"
                    >
                        <div class="album-card-cover">

                            <?php if (!empty($albumRow['cover_path'])): ?>

                                <img
                                    src="/phan/alben?thumb=<?= $listAlbumId ?>&v=<?= alb_h(
                                        (string)($albumRow['updated_at'] ?? '')
                                    ) ?>"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                >

                            <?php else: ?>

                                <div class="album-cover-placeholder">
                                    ♪
                                </div>

                            <?php endif; ?>

                        </div>

                        <div class="album-card-body">
                            <h2>
                                <?= alb_h($albumRow['title']) ?>
                            </h2>

                            <div class="album-card-meta">

                                <div class="album-card-meta-section">
                                    <strong class="album-card-meta-label">
                                        Label
                                    </strong>

                                    <?php if ($albumRow['labels']): ?>

                                        <div class="album-card-entities">

                                            <?php foreach (
                                                $albumRow['labels']
                                                as $label
                                            ): ?>

                                                <span class="album-card-entity">

                                                    <?php if (!empty($label['thumb'])): ?>

                                                        <img
                                                            class="album-card-entity-thumb album-card-entity-thumb--label"
                                                            src="<?= alb_h($label['thumb']) ?>"
                                                            alt=""
                                                            loading="lazy"
                                                            decoding="async"
                                                        >

                                                    <?php else: ?>

                                                        <span
                                                            class="album-card-entity-thumb album-card-entity-thumb--label album-card-entity-placeholder"
                                                        >
                                                            L
                                                        </span>

                                                    <?php endif; ?>

                                                    <!-- <span class="album-card-entity-name">
                                                        <?= alb_h(
                                                            $label['name'] === 'The Even Greater Ape Project'
                                                                ? 'TEGAP'
                                                                : $label['name']
                                                        ) ?>
                                                    </span> -->
                                                </span>

                                            <?php endforeach; ?>

                                        </div>

                                    <?php else: ?>

                                        <span class="album-card-meta-empty">
                                            —
                                        </span>

                                    <?php endif; ?>
                                </div>


                                <div class="album-card-meta-section">
                                    <strong class="album-card-meta-label">
                                        Künstler
                                    </strong>

                                    <?php if ($albumRow['artists']): ?>

                                        <div class="album-card-entities">

                                            <?php foreach (
                                                $albumRow['artists']
                                                as $artist
                                            ): ?>

                                                <span class="album-card-entity">

                                                    <?php if (!empty($artist['thumb'])): ?>

                                                        <img
                                                            class="album-card-entity-thumb album-card-entity-thumb--artist"
                                                            src="<?= alb_h($artist['thumb']) ?>"
                                                            alt=""
                                                            loading="lazy"
                                                            decoding="async"
                                                        >

                                                    <?php else: ?>

                                                        <span
                                                            class="album-card-entity-thumb album-card-entity-thumb--artist album-card-entity-placeholder"
                                                        >
                                                            ?
                                                        </span>

                                                    <?php endif; ?>

                                                    <!-- <span class="album-card-entity-name">
                                                        <?= alb_h($artist['name']) ?>
                                                    </span> -->
                                                </span>

                                            <?php endforeach; ?>

                                        </div>

                                    <?php else: ?>

                                        <span class="album-card-meta-empty">
                                            —
                                        </span>

                                    <?php endif; ?>
                                </div>

                            </div>

                            <div class="album-card-count">
                                <span>
                                    <?= (int)($albumRow['song_count'] ?? 0) ?>
                                    Songs
                                </span>

                                <span>
                                    <?php if (
                                        $albumRow['album_year'] !== null
                                        && $albumRow['album_year'] !== ''
                                    ): ?>
                                        <?= alb_h((int)$albumRow['album_year']) ?>

                                        <?php if (
                                            $albumRow['album_month'] !== null
                                            && $albumRow['album_month'] !== ''
                                        ): ?>
                                            <?= alb_h(
                                                str_pad(
                                                    (string)(int)$albumRow['album_month'],
                                                    2,
                                                    '0',
                                                    STR_PAD_LEFT
                                                )
                                            ) ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>

            </div>

            <div
                class="phan-card albums-empty album-filter-empty"
                id="albumFilterEmpty"
                hidden
            >
                Keine Alben in dieser Auswahl.
            </div>


            <script>
            (() => {
                'use strict';

                const cards =
                    Array.from(
                        document.querySelectorAll(
                            '.album-card'
                        )
                    );

                const count =
                    document.getElementById(
                        'albumVisibleCount'
                    );

                const empty =
                    document.getElementById(
                        'albumFilterEmpty'
                    );

                const search =
                    document.getElementById(
                        'albumListSearch'
                    );

                const dropdowns =
                    Array.from(
                        document.querySelectorAll(
                            '[data-album-filter-dropdown]'
                        )
                    );

                const labelOptions =
                    Array.from(
                        document.querySelectorAll(
                            '[data-album-label-id]'
                        )
                    );

                const artistOptions =
                    Array.from(
                        document.querySelectorAll(
                            '[data-album-artist-id]'
                        )
                    );

                const labelText =
                    document.getElementById(
                        'albumLabelFilterText'
                    );

                const labelVisual =
                    document.getElementById(
                        'albumLabelFilterVisual'
                    );

                const artistText =
                    document.getElementById(
                        'albumArtistFilterText'
                    );

                const artistVisual =
                    document.getElementById(
                        'albumArtistFilterVisual'
                    );


                const initialUrl =
                    new URL(
                        window.location.href
                    );

                let selectedLabelId =
                    String(
                        initialUrl.searchParams.get(
                            'label'
                        )
                        || ''
                    );

                let selectedArtistId =
                    String(
                        initialUrl.searchParams.get(
                            'artist'
                        )
                        || ''
                    );

                const initialSearch =
                    String(
                        initialUrl.searchParams.get(
                            'q'
                        )
                        || ''
                    );


                function normalize(value) {
                    return String(value ?? '')
                        .normalize('NFKD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .trim()
                        .toLocaleLowerCase('de');
                }


                function allIconHtml() {
                    return `
                        <span class="phan-list-filter-icon">
                            <span class="phan-list-filter-icon-symbol">
                                ∞
                            </span>
                        </span>
                    `;
                }


                function entityVisualHtml(
                    thumb,
                    name
                ) {
                    if (thumb) {
                        const image =
                            document.createElement(
                                'img'
                            );

                        image.className =
                            'phan-list-filter-thumb';

                        image.src =
                            thumb;

                        image.alt =
                            '';

                        image.loading =
                            'lazy';

                        image.decoding =
                            'async';

                        const wrapper =
                            document.createElement(
                                'span'
                            );

                        wrapper.appendChild(
                            image
                        );

                        return wrapper.innerHTML;
                    }

                    const initial =
                        Array.from(
                            String(
                                name
                                || ''
                            ).trim()
                        )[0]?.toUpperCase()
                        || '?';

                    const span =
                        document.createElement(
                            'span'
                        );

                    span.className =
                        'phan-list-filter-icon';

                    span.textContent =
                        initial;

                    return span.outerHTML;
                }


                function closeDropdowns(
                    except = null
                ) {
                    dropdowns.forEach(
                        dropdown => {
                            if (
                                except
                                && dropdown === except
                            ) {
                                return;
                            }

                            const trigger =
                                dropdown.querySelector(
                                    '[data-album-filter-trigger]'
                                );

                            const menu =
                                dropdown.querySelector(
                                    '[data-album-filter-menu]'
                                );

                            dropdown.classList.remove(
                                'is-open'
                            );

                            trigger?.setAttribute(
                                'aria-expanded',
                                'false'
                            );

                            if (menu) {
                                menu.hidden = true;
                            }
                        }
                    );
                }


                dropdowns.forEach(
                    dropdown => {
                        const trigger =
                            dropdown.querySelector(
                                '[data-album-filter-trigger]'
                            );

                        const menu =
                            dropdown.querySelector(
                                '[data-album-filter-menu]'
                            );

                        trigger?.addEventListener(
                            'click',
                            event => {
                                event.stopPropagation();

                                const opening =
                                    menu?.hidden
                                    ?? false;

                                closeDropdowns(
                                    dropdown
                                );

                                dropdown.classList.toggle(
                                    'is-open',
                                    opening
                                );

                                trigger.setAttribute(
                                    'aria-expanded',
                                    opening
                                        ? 'true'
                                        : 'false'
                                );

                                if (menu) {
                                    menu.hidden =
                                        !opening;
                                }
                            }
                        );
                    }
                );


                document.addEventListener(
                    'click',
                    event => {
                        if (
                            !event.target.closest(
                                '[data-album-filter-dropdown]'
                            )
                        ) {
                            closeDropdowns();
                        }
                    }
                );


                function cardIds(
                    card,
                    key
                ) {
                    return String(
                        card.dataset[key]
                        || ''
                    )
                        .split(',')
                        .filter(Boolean);
                }


                function selectLabelOption(
                    option
                ) {
                    if (!option) {
                        return;
                    }

                    selectedLabelId =
                        String(
                            option.dataset.albumLabelId
                            || ''
                        );

                    const name =
                        String(
                            option.dataset.albumLabelName
                            || 'Alle'
                        );

                    const thumb =
                        String(
                            option.dataset.albumLabelThumb
                            || ''
                        );

                    labelOptions.forEach(
                        item => {
                            item.classList.toggle(
                                'active',
                                item === option
                            );
                        }
                    );

                    if (labelText) {
                        labelText.textContent =
                            name;
                    }

                    if (labelVisual) {
                        labelVisual.innerHTML =
                            selectedLabelId === ''
                                ? allIconHtml()
                                : entityVisualHtml(
                                    thumb,
                                    name
                                );
                    }
                }


                function selectArtistOption(
                    option
                ) {
                    if (!option) {
                        return;
                    }

                    selectedArtistId =
                        String(
                            option.dataset.albumArtistId
                            || ''
                        );

                    const name =
                        String(
                            option.dataset.albumArtistName
                            || 'Alle'
                        );

                    const thumb =
                        String(
                            option.dataset.albumArtistThumb
                            || ''
                        );

                    artistOptions.forEach(
                        item => {
                            item.classList.toggle(
                                'active',
                                item === option
                            );
                        }
                    );

                    if (artistText) {
                        artistText.textContent =
                            name;
                    }

                    if (artistVisual) {
                        artistVisual.innerHTML =
                            selectedArtistId === ''
                                ? allIconHtml()
                                : entityVisualHtml(
                                    thumb,
                                    name
                                );
                    }
                }


                function syncFilterState() {
                    const url =
                        new URL(
                            window.location.href
                        );

                    url.searchParams.delete(
                        'id'
                    );

                    url.searchParams.delete(
                        'created'
                    );

                    url.searchParams.delete(
                        'synced'
                    );

                    url.searchParams.delete(
                        'deleted'
                    );

                    if (selectedLabelId !== '') {
                        url.searchParams.set(
                            'label',
                            selectedLabelId
                        );
                    } else {
                        url.searchParams.delete(
                            'label'
                        );
                    }

                    if (selectedArtistId !== '') {
                        url.searchParams.set(
                            'artist',
                            selectedArtistId
                        );
                    } else {
                        url.searchParams.delete(
                            'artist'
                        );
                    }

                    const query =
                        search?.value.trim()
                        || '';

                    if (query !== '') {
                        url.searchParams.set(
                            'q',
                            query
                        );
                    } else {
                        url.searchParams.delete(
                            'q'
                        );
                    }

                    history.replaceState(
                        null,
                        '',
                        url.pathname
                        + url.search
                    );


                    cards.forEach(
                        card => {
                            const albumId =
                                String(
                                    card.dataset.albumId
                                    || ''
                                );

                            if (albumId === '') {
                                return;
                            }

                            const detailUrl =
                                new URL(
                                    '/phan/alben',
                                    window.location.origin
                                );

                            detailUrl.searchParams.set(
                                'id',
                                albumId
                            );

                            if (
                                selectedLabelId !== ''
                            ) {
                                detailUrl.searchParams.set(
                                    'label',
                                    selectedLabelId
                                );
                            }

                            if (
                                selectedArtistId !== ''
                            ) {
                                detailUrl.searchParams.set(
                                    'artist',
                                    selectedArtistId
                                );
                            }

                            if (query !== '') {
                                detailUrl.searchParams.set(
                                    'q',
                                    query
                                );
                            }

                            card.href =
                                detailUrl.pathname
                                + detailUrl.search;
                        }
                    );
                }


                function applyFilters() {
                    const needle =
                        normalize(
                            search?.value
                            || ''
                        );

                    let visibleCount = 0;


                    cards.forEach(
                        card => {
                            const labelIds =
                                cardIds(
                                    card,
                                    'labelIds'
                                );

                            const artistIds =
                                cardIds(
                                    card,
                                    'artistIds'
                                );

                            const matchesLabel =
                                selectedLabelId === ''
                                || labelIds.includes(
                                    selectedLabelId
                                );

                            const matchesArtist =
                                selectedArtistId === ''
                                || artistIds.includes(
                                    selectedArtistId
                                );

                            const matchesSearch =
                                needle === ''
                                || normalize(
                                    card.dataset.searchText
                                    || ''
                                ).includes(
                                    needle
                                );

                            const visible =
                                matchesLabel
                                && matchesArtist
                                && matchesSearch;

                            card.hidden =
                                !visible;

                            if (visible) {
                                visibleCount++;
                            }
                        }
                    );


                    if (count) {
                        count.textContent =
                            visibleCount
                            + ' '
                            + (
                                visibleCount === 1
                                    ? 'Album'
                                    : 'Alben'
                            );
                    }


                    if (empty) {
                        empty.hidden =
                            visibleCount !== 0;
                    }

                    syncFilterState();
                }


                labelOptions.forEach(
                    option => {
                        option.addEventListener(
                            'click',
                            () => {
                                selectLabelOption(
                                    option
                                );

                                closeDropdowns();
                                applyFilters();
                            }
                        );
                    }
                );


                artistOptions.forEach(
                    option => {
                        option.addEventListener(
                            'click',
                            () => {
                                selectArtistOption(
                                    option
                                );

                                closeDropdowns();
                                applyFilters();
                            }
                        );
                    }
                );


                const initialLabelOption =
                    labelOptions.find(
                        option =>
                            String(
                                option.dataset.albumLabelId
                                || ''
                            ) === selectedLabelId
                    )
                    || labelOptions.find(
                        option =>
                            String(
                                option.dataset.albumLabelId
                                || ''
                            ) === ''
                    );

                const initialArtistOption =
                    artistOptions.find(
                        option =>
                            String(
                                option.dataset.albumArtistId
                                || ''
                            ) === selectedArtistId
                    )
                    || artistOptions.find(
                        option =>
                            String(
                                option.dataset.albumArtistId
                                || ''
                            ) === ''
                    );

                selectLabelOption(
                    initialLabelOption
                );

                selectArtistOption(
                    initialArtistOption
                );

                if (search) {
                    search.value =
                        initialSearch;
                }


                let searchTimer = null;


                search?.addEventListener(
                    'input',
                    () => {
                        window.clearTimeout(
                            searchTimer
                        );

                        searchTimer =
                            window.setTimeout(
                                applyFilters,
                                180
                            );
                    }
                );


                search?.addEventListener(
                    'keydown',
                    event => {
                        if (
                            event.key !== 'Enter'
                        ) {
                            return;
                        }

                        event.preventDefault();

                        window.clearTimeout(
                            searchTimer
                        );

                        applyFilters();
                    }
                );


                applyFilters();
            })();
            </script>

        <?php endif; ?>


    <?php else: ?>

        <?php
        $albumLabelIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            $albumLabels
        );

        $albumArtistIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            $albumArtists
        );
        ?>


        <div class="albums-detail-grid">

            <div class="album-cover-sticky">

                <a
                    class="phan-page-button albums-back"
                    href="<?= alb_h(alb_list_url()) ?>"
                >
                    ← Zurück zu Alben
                </a>

                <section class="phan-card album-cover-panel">

                <div class="album-cover-panel-head">
                    <h2>Cover</h2>

                    <?php if (!empty($album['cover_path'])): ?>
                        <button
                            type="button"
                            class="albums-secondary-button"
                            id="albumCropToggle"
                        >
                            Thumbnail-Ausschnitt
                        </button>
                    <?php endif; ?>
                </div>

                <input
                    type="file"
                    id="albumCoverInput"
                    class="phan-image-upload-input"
                    accept="image/jpeg,image/png,image/webp"
                >

                <div
                    class="album-cover-dropzone"
                    id="albumCoverDropzone"
                >

                    <?php if (!empty($album['cover_path'])): ?>

                        <div
                            class="album-cover-cropbox"
                            id="albumCoverCropbox"
                        >
                            <img
                                id="albumCoverImage"
                                src="/phan/alben?cover=<?= $albumId ?>&v=<?= alb_h(
                                    (string)($album['updated_at'] ?? '')
                                ) ?>"
                                alt=""
                                draggable="false"
                            >

                            <div
                                class="phan-crop-overlay"
                                id="albumCropOverlay"
                                hidden
                            ></div>

                            <div class="album-cover-drop-hint">
                                Cover hier ablegen
                            </div>
                        </div>

                    <?php else: ?>

                        <button
                            type="button"
                            class="album-cover-empty"
                            id="albumCoverEmpty"
                        >
                            <span class="album-cover-empty-icon">♪</span>
                            <strong>Cover setzen</strong>
                            <span>Klicken oder Bild hier ablegen</span>
                        </button>

                    <?php endif; ?>

                </div>

                <div class="album-cover-actions">

                    <button
                        type="button"
                        class="albums-secondary-button"
                        id="albumCoverChoose"
                    >
                        <?= !empty($album['cover_path'])
                            ? 'Cover ändern'
                            : 'Cover auswählen'
                        ?>
                    </button>

                    <?php if (!empty($album['cover_path'])): ?>

                        <button
                            type="button"
                            class="albums-secondary-button"
                            id="albumCropSave"
                            hidden
                        >
                            Ausschnitt speichern
                        </button>

                        <button
                            type="button"
                            class="phan-danger"
                            id="albumCoverRemove"
                        >
                            Cover entfernen
                        </button>

                    <?php endif; ?>

                </div>
                </section>

            </div>


            <div class="albums-detail-main">

                <div class="phan-detail-head albums-detail-head">

                    <div
                        class="phan-autosave-status"
                        id="albumAutosaveStatus"
                        aria-live="polite"
                    ></div>

                    <div class="albums-detail-actions">
                        <form method="post" class="albums-sync-form">
                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= alb_h($csrf) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="sync_album"
                            >

                            <input
                                type="hidden"
                                name="album_id"
                                value="<?= $albumId ?>"
                            >

                            <button type="submit">
                                Mit Spotify synchronisieren
                            </button>
                        </form>
                    </div>

                </div>


                <section class="phan-card album-meta-panel">

                <div class="album-meta-title-row">

                    <label class="album-title-field">
                        <span>Albumtitel</span>

                        <input
                            type="text"
                            id="albumTitle"
                            value="<?= alb_h($album['title']) ?>"
                        >
                    </label>

                    <div class="album-sync-meta">
                        <span>Zuletzt synchronisiert</span>

                        <strong>
                            <?= alb_h($album['last_synced_at'] ?? '—') ?>
                        </strong>
                    </div>

                </div>

                <label class="album-notes-field">
                    <span>Notizen</span>

                    <textarea
                        id="albumNotes"
                        rows="5"
                    ><?= alb_h($album['notes'] ?? '') ?></textarea>
                </label>


                <div class="album-entity-section">

                    <div class="album-entity-head">
                        <div>
                            <span class="album-entity-kicker">Label</span>
                            <strong>Fraktionen</strong>
                        </div>

                        <button
                            type="button"
                            class="albums-secondary-button"
                            data-open-entity-modal="labels"
                        >
                            Bearbeiten
                        </button>
                    </div>

                    <div
                        class="album-chip-list"
                        id="albumLabelChips"
                    >
                        <?php if (!$albumLabels): ?>

                            <span class="album-empty-chip">
                                Kein Label
                            </span>

                        <?php else: ?>

                            <?php foreach ($albumLabels as $label): ?>
                                <span class="album-entity-chip">
                                    <?php if (!empty($label['image_path'])): ?>
                                        <img
                                            src="/phan/factions?thumb=<?= (int)$label['id'] ?>"
                                            alt=""
                                            loading="lazy"
                                        >
                                    <?php endif; ?>

                                    <span>
                                        <?= alb_h($label['title']) ?>
                                    </span>
                                </span>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </div>
                </div>


                <div class="album-entity-section">

                    <div class="album-entity-head">
                        <div>
                            <span class="album-entity-kicker">Künstler</span>
                            <strong>Charaktere</strong>
                        </div>

                        <button
                            type="button"
                            class="albums-secondary-button"
                            data-open-entity-modal="artists"
                        >
                            Bearbeiten
                        </button>
                    </div>

                    <div
                        class="album-chip-list"
                        id="albumArtistChips"
                    >
                        <?php if (!$albumArtists): ?>

                            <span class="album-empty-chip">
                                Keine Künstler
                            </span>

                        <?php else: ?>

                            <?php foreach ($albumArtists as $artist): ?>
                                <span class="album-entity-chip">
                                    <?php if (!empty($artist['image_path'])): ?>
                                        <img
                                            src="/phan/chars?thumb=<?= (int)$artist['id'] ?>"
                                            alt=""
                                            loading="lazy"
                                        >
                                    <?php endif; ?>

                                    <span>
                                        <?= alb_h($artist['call_name']) ?>
                                    </span>
                                </span>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </div>
                </div>


                <div class="album-entity-section">
                    <div class="album-release-date-row">

                        <label class="album-title-field">
                            <span>Jahr</span>

                            <input
                                type="number"
                                id="albumYear"
                                step="1"
                                inputmode="numeric"
                                value="<?= $album['album_year'] !== null
                                    && $album['album_year'] !== ''
                                        ? alb_h((int)$album['album_year'])
                                        : ''
                                ?>"
                            >
                        </label>

                        <label class="album-title-field">
                            <span>Monat</span>

                            <select id="albumMonth">
                                <option value="">—</option>

                                <?php
                                $albumMonthValue =
                                    $album['album_month'] !== null
                                    && $album['album_month'] !== ''
                                        ? (int)$album['album_month']
                                        : 0;

                                $albumMonths = [
                                    1 => 'Januar',
                                    2 => 'Februar',
                                    3 => 'März',
                                    4 => 'April',
                                    5 => 'Mai',
                                    6 => 'Juni',
                                    7 => 'Juli',
                                    8 => 'August',
                                    9 => 'September',
                                    10 => 'Oktober',
                                    11 => 'November',
                                    12 => 'Dezember',
                                ];
                                ?>

                                <?php foreach ($albumMonths as $monthNo => $monthName): ?>
                                    <option
                                        value="<?= $monthNo ?>"
                                        <?= $albumMonthValue === $monthNo
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= alb_h($monthName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                    </div>
                </div>


                <div class="album-meta-bottom">
                    <a
                        class="albums-spotify-link"
                        href="<?= alb_h($album['spotify_url'] ?? '#') ?>"
                        target="_blank"
                        rel="noopener"
                    >
                        In Spotify öffnen
                    </a>

                    <form
                        method="post"
                        id="albumDeleteForm"
                    >
                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= alb_h($csrf) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="delete_album"
                        >

                        <input
                            type="hidden"
                            name="album_id"
                            value="<?= $albumId ?>"
                        >

                        <button
                            type="submit"
                            class="phan-danger"
                        >
                            Album lokal löschen
                        </button>
                    </form>
                </div>

            </section>


        <section class="phan-card album-songs-panel">

            <div class="album-songs-head">
                <div>
                    <h2>Songs</h2>

                    <span>
                        <?= count($albumSongs) ?>
                        aktuell in diesem Album
                    </span>
                </div>
            </div>


            <?php if (!$albumSongs): ?>

                <div class="albums-empty">
                    Keine Songs vorhanden.
                </div>

            <?php else: ?>

                <div class="album-song-list">

                    <?php foreach ($albumSongs as $song): ?>

                        <?php
                        $albumSongId = (int)$song['album_song_id'];
                        $songFeatures = $featuresByAlbumSong[$albumSongId] ?? [];

                        $songFeatureIds = array_map(
                            static fn(array $row): int => (int)$row['id'],
                            $songFeatures
                        );
                        ?>

                        <article
                            class="album-song-row"
                            data-album-song-id="<?= $albumSongId ?>"
                            data-original-title="<?= alb_h($song['original_title']) ?>"
                            data-feature-ids="<?= alb_h(implode(',', $songFeatureIds)) ?>"
                        >

                            <div class="album-song-position">
                                <?= (int)$song['position_no'] ?>
                            </div>

                            <div class="album-song-main">

                                <div class="album-song-title-row">
                                    <input
                                        type="text"
                                        class="album-song-title"
                                        value="<?= alb_h($song['display_title']) ?>"
                                        aria-label="Songtitel"
                                    >

                                    <button
                                        type="button"
                                        class="album-song-original-button"
                                        title="Originaltitel wiederherstellen"
                                    >
                                        ↺
                                    </button>
                                </div>

                                <textarea
                                    class="album-song-notes"
                                    rows="2"
                                    placeholder="Notizen zum Song …"
                                    aria-label="Songnotizen"
                                ><?= alb_h($song['notes'] ?? '') ?></textarea>

                            </div>

                            <div class="album-song-features">

                                <div class="album-song-feature-head">
                                    <span>Features</span>

                                    <button
                                        type="button"
                                        class="album-feature-button"
                                        data-open-feature-modal="<?= $albumSongId ?>"
                                    >
                                        Bearbeiten
                                    </button>
                                </div>

                                <div
                                    class="album-feature-chips"
                                    id="songFeatures<?= $albumSongId ?>"
                                >

                                    <?php if (!$songFeatures): ?>

                                        <span class="album-empty-chip">
                                            Keine Features
                                        </span>

                                    <?php else: ?>

                                        <?php foreach ($songFeatures as $feature): ?>
                                            <span class="album-entity-chip album-entity-chip--small">

                                                <?php if (!empty($feature['image_path'])): ?>
                                                    <img
                                                        src="/phan/chars?thumb=<?= (int)$feature['id'] ?>"
                                                        alt=""
                                                        loading="lazy"
                                                    >
                                                <?php endif; ?>

                                                <span>
                                                    <?= alb_h($feature['call_name']) ?>
                                                </span>
                                            </span>
                                        <?php endforeach; ?>

                                    <?php endif; ?>

                                </div>
                            </div>

                            <div
                                class="album-song-save-state"
                                aria-live="polite"
                            ></div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

                </section>

            </div>

        </div>


        <div
            class="albums-modal"
            id="albumEntityModal"
            hidden
        >
            <div
                class="albums-modal-backdrop"
                data-close-album-modal
            ></div>

            <div
                class="albums-modal-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="albumEntityModalTitle"
            >
                <div class="albums-modal-head">
                    <h2 id="albumEntityModalTitle">
                        Auswahl
                    </h2>

                    <button
                        type="button"
                        class="albums-modal-close"
                        data-close-album-modal
                        aria-label="Schließen"
                    >
                        ×
                    </button>
                </div>

                <div class="albums-modal-body">
                    <input
                        type="search"
                        id="albumEntitySearch"
                        placeholder="Suchen …"
                        autocomplete="off"
                    >

                    <div
                        class="albums-entity-results"
                        id="albumEntityResults"
                    ></div>
                </div>

                <div class="albums-modal-footer">
                    <div
                        class="albums-modal-count"
                        id="albumEntityCount"
                    ></div>

                    <button
                        type="button"
                        id="albumEntitySave"
                    >
                        Auswahl speichern
                    </button>
                </div>
            </div>
        </div>


        <script>
        (() => {
            'use strict';

            const CSRF =
                <?= json_encode(
                    $csrf,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                ) ?>;

            const ALBUM_ID = <?= $albumId ?>;

            const CHARS =
                <?= json_encode(
                    $frontendChars,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                ) ?>;

            const FACTIONS =
                <?= json_encode(
                    $frontendFactions,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                ) ?>;

            let albumLabelIds =
                new Set(
                    <?= json_encode($albumLabelIds) ?>
                );

            let albumArtistIds =
                new Set(
                    <?= json_encode($albumArtistIds) ?>
                );


            async function albumApi(
                action,
                values = {}
            ) {
                const body =
                    new URLSearchParams();

                body.set('ajax', '1');
                body.set('csrf', CSRF);
                body.set('action', action);

                Object.entries(values)
                    .forEach(
                        ([key, value]) => {
                            if (Array.isArray(value)) {
                                value.forEach(
                                    item =>
                                        body.append(
                                            key + '[]',
                                            String(item)
                                        )
                                );
                                return;
                            }

                            body.set(
                                key,
                                String(value ?? '')
                            );
                        }
                    );

                const response =
                    await fetch(
                        window.location.pathname
                        + window.location.search,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type':
                                    'application/x-www-form-urlencoded;charset=UTF-8',
                            },
                            body,
                        }
                    );

                const payload =
                    await response
                        .json()
                        .catch(() => null);

                if (!response.ok || !payload?.ok) {
                    throw new Error(
                        payload?.message
                        || 'Aktion fehlgeschlagen.'
                    );
                }

                return payload;
            }

            function normalize(value) {
                return String(value ?? '')
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

            function debounce(fn, delay) {
                let timer = null;

                return (...args) => {
                    window.clearTimeout(timer);
                    timer = window.setTimeout(
                        () => fn(...args),
                        delay
                    );
                };
            }


            /* =============================================
             * Album-Autosave
             * ============================================= */

            const albumTitle =
                document.getElementById(
                    'albumTitle'
                );

            const albumNotes =
                document.getElementById(
                    'albumNotes'
                );

            const albumYear =
                document.getElementById(
                    'albumYear'
                );

            const albumMonth =
                document.getElementById(
                    'albumMonth'
                );

            const albumStatus =
                document.getElementById(
                    'albumAutosaveStatus'
                );

            function setAlbumStatus(
                text,
                error = false
            ) {
                if (!albumStatus) {
                    return;
                }

                albumStatus.textContent = text;
                albumStatus.classList.toggle(
                    'is-error',
                    error
                );
            }

            const saveAlbumMeta =
                debounce(
                    async () => {
                        if (
                            !albumTitle
                            || !albumNotes
                            || !albumYear
                            || !albumMonth
                        ) {
                            return;
                        }

                        setAlbumStatus('Speichere …');

                        try {
                            const payload =
                                await albumApi(
                                    'save_album_meta',
                                    {
                                        album_id: ALBUM_ID,
                                        title: albumTitle.value,
                                        notes: albumNotes.value,
                                        album_year:
                                            albumYear.value,
                                        album_month:
                                            albumMonth.value,
                                    }
                                );

                            setAlbumStatus(
                                'Gespeichert · '
                                + payload.saved_at
                            );

                        } catch (error) {
                            setAlbumStatus(
                                error.message || 'Fehler',
                                true
                            );
                        }
                    },
                    550
                );

            albumTitle?.addEventListener(
                'input',
                saveAlbumMeta
            );

            albumNotes?.addEventListener(
                'input',
                saveAlbumMeta
            );

            albumYear?.addEventListener(
                'input',
                saveAlbumMeta
            );

            albumMonth?.addEventListener(
                'change',
                saveAlbumMeta
            );


            /* =============================================
             * Song-Autosave
             * ============================================= */

            document
                .querySelectorAll(
                    '.album-song-row'
                )
                .forEach(
                    row => {
                        const songId =
                            Number(
                                row.dataset.albumSongId
                                || 0
                            );

                        const title =
                            row.querySelector(
                                '.album-song-title'
                            );

                        const notes =
                            row.querySelector(
                                '.album-song-notes'
                            );

                        const state =
                            row.querySelector(
                                '.album-song-save-state'
                            );

                        const reset =
                            row.querySelector(
                                '.album-song-original-button'
                            );

                        const save =
                            debounce(
                                async () => {
                                    if (
                                        !songId
                                        || !title
                                        || !notes
                                    ) {
                                        return;
                                    }

                                    if (state) {
                                        state.textContent =
                                            'Speichere …';
                                        state.classList.remove(
                                            'is-error'
                                        );
                                    }

                                    try {
                                        const payload =
                                            await albumApi(
                                                'save_song',
                                                {
                                                    album_song_id:
                                                        songId,
                                                    display_title:
                                                        title.value,
                                                    notes:
                                                        notes.value,
                                                }
                                            );

                                        if (state) {
                                            state.textContent =
                                                'Gespeichert · '
                                                + payload.saved_at;
                                        }

                                    } catch (error) {
                                        if (state) {
                                            state.textContent =
                                                error.message
                                                || 'Fehler';

                                            state.classList.add(
                                                'is-error'
                                            );
                                        }
                                    }
                                },
                                500
                            );

                        title?.addEventListener(
                            'input',
                            save
                        );

                        notes?.addEventListener(
                            'input',
                            save
                        );

                        reset?.addEventListener(
                            'click',
                            () => {
                                if (!title) {
                                    return;
                                }

                                title.value =
                                    row.dataset.originalTitle
                                    || '';

                                title.dispatchEvent(
                                    new Event(
                                        'input',
                                        {
                                            bubbles: true,
                                        }
                                    )
                                );
                            }
                        );
                    }
                );


            /* =============================================
             * Zuordnungsmodal
             * ============================================= */

            const modal =
                document.getElementById(
                    'albumEntityModal'
                );

            const modalTitle =
                document.getElementById(
                    'albumEntityModalTitle'
                );

            const modalSearch =
                document.getElementById(
                    'albumEntitySearch'
                );

            const modalResults =
                document.getElementById(
                    'albumEntityResults'
                );

            const modalCount =
                document.getElementById(
                    'albumEntityCount'
                );

            const modalSave =
                document.getElementById(
                    'albumEntitySave'
                );

            let modalMode = null;
            let modalSongId = null;
            let modalSelected = new Set();

            function closeModal() {
                if (!modal) {
                    return;
                }

                modal.hidden = true;
                document.body.classList.remove(
                    'albums-modal-open'
                );
                modalMode = null;
                modalSongId = null;
            }

            function modalSource() {
                return modalMode === 'labels'
                    ? FACTIONS
                    : CHARS;
            }

            function modalCurrentIds() {
                if (modalMode === 'labels') {
                    return new Set(albumLabelIds);
                }

                if (modalMode === 'artists') {
                    return new Set(albumArtistIds);
                }

                if (
                    modalMode === 'features'
                    && modalSongId
                ) {
                    const row =
                        document.querySelector(
                            '.album-song-row'
                            + '[data-album-song-id="'
                            + modalSongId
                            + '"]'
                        );

                    const raw =
                        row?.dataset?.featureIds
                        || '';

                    return new Set(
                        raw
                            .split(',')
                            .map(value => Number(value))
                            .filter(value => value > 0)
                    );
                }

                return new Set();
            }

            function updateModalCount() {
                if (modalCount) {
                    modalCount.textContent =
                        modalSelected.size
                        + ' ausgewählt';
                }
            }

            function renderModalResults() {
                if (!modalResults) {
                    return;
                }

                const needle =
                    normalize(
                        modalSearch?.value
                        || ''
                    );

                const items =
                    modalSource()
                        .filter(
                            item =>
                                !needle
                                || normalize(
                                    [
                                        item.name,
                                        item.full,
                                    ]
                                        .filter(Boolean)
                                        .join(' ')
                                ).includes(needle)
                        );

                modalResults.innerHTML = '';

                if (!items.length) {
                    const empty =
                        document.createElement('div');

                    empty.className =
                        'albums-entity-empty';

                    empty.textContent =
                        'Keine Treffer.';

                    modalResults.appendChild(empty);
                    updateModalCount();
                    return;
                }

                items.forEach(
                    item => {
                        const button =
                            document.createElement(
                                'button'
                            );

                        button.type = 'button';
                        button.className =
                            'albums-entity-option';

                        if (
                            modalSelected.has(
                                item.id
                            )
                        ) {
                            button.classList.add(
                                'is-selected'
                            );
                        }

                        const avatar =
                            document.createElement(
                                'span'
                            );

                        avatar.className =
                            'relations-char-avatar';

                        if (item.thumb) {
                            const image =
                                document.createElement(
                                    'img'
                                );

                            image.src = item.thumb;
                            image.alt = '';
                            image.loading = 'lazy';
                            avatar.appendChild(image);
                        } else {
                            avatar.textContent =
                                initials(item.name);
                        }

                        const text =
                            document.createElement(
                                'span'
                            );

                        text.className =
                            'albums-entity-option-text';

                        const strong =
                            document.createElement(
                                'strong'
                            );

                        strong.textContent =
                            item.name;

                        text.appendChild(strong);

                        if (item.full) {
                            const small =
                                document.createElement(
                                    'small'
                                );

                            small.textContent =
                                item.full;

                            text.appendChild(small);
                        }

                        const check =
                            document.createElement(
                                'span'
                            );

                        check.className =
                            'albums-entity-check';

                        check.textContent =
                            modalSelected.has(
                                item.id
                            )
                                ? '✓'
                                : '';

                        button.appendChild(avatar);
                        button.appendChild(text);
                        button.appendChild(check);

                        button.addEventListener(
                            'click',
                            () => {
                                if (
                                    modalSelected.has(
                                        item.id
                                    )
                                ) {
                                    modalSelected.delete(
                                        item.id
                                    );
                                } else {
                                    modalSelected.add(
                                        item.id
                                    );
                                }

                                renderModalResults();
                            }
                        );

                        modalResults.appendChild(
                            button
                        );
                    }
                );

                updateModalCount();
            }

            function openModal(
                mode,
                songId = null
            ) {
                if (!modal) {
                    return;
                }

                modalMode = mode;
                modalSongId = songId;
                modalSelected =
                    modalCurrentIds();

                if (modalTitle) {
                    modalTitle.textContent =
                        mode === 'labels'
                            ? 'Labels auswählen'
                            : (
                                mode === 'artists'
                                    ? 'Künstler auswählen'
                                    : 'Features auswählen'
                            );
                }

                if (modalSearch) {
                    modalSearch.value = '';
                }

                modal.hidden = false;
                document.body.classList.add(
                    'albums-modal-open'
                );

                renderModalResults();

                window.setTimeout(
                    () => modalSearch?.focus(),
                    0
                );
            }

            document
                .querySelectorAll(
                    '[data-open-entity-modal]'
                )
                .forEach(
                    button => {
                        button.addEventListener(
                            'click',
                            () => {
                                openModal(
                                    button.dataset
                                        .openEntityModal
                                );
                            }
                        );
                    }
                );

            document
                .querySelectorAll(
                    '[data-open-feature-modal]'
                )
                .forEach(
                    button => {
                        button.addEventListener(
                            'click',
                            () => {
                                openModal(
                                    'features',
                                    Number(
                                        button.dataset
                                            .openFeatureModal
                                    )
                                );
                            }
                        );
                    }
                );

            modalSearch?.addEventListener(
                'input',
                renderModalResults
            );

            modal
                ?.querySelectorAll(
                    '[data-close-album-modal]'
                )
                .forEach(
                    element => {
                        element.addEventListener(
                            'click',
                            closeModal
                        );
                    }
                );

            function itemById(list, id) {
                return list.find(
                    item => item.id === id
                ) || null;
            }

            function renderChipList(
                target,
                ids,
                list,
                emptyText,
                small = false
            ) {
                if (!target) {
                    return;
                }

                target.innerHTML = '';

                const ordered =
                    [...ids]
                        .map(id => itemById(list, id))
                        .filter(Boolean)
                        .sort(
                            (a, b) =>
                                a.name.localeCompare(
                                    b.name,
                                    'de'
                                )
                        );

                if (!ordered.length) {
                    const empty =
                        document.createElement(
                            'span'
                        );

                    empty.className =
                        'album-empty-chip';

                    empty.textContent =
                        emptyText;

                    target.appendChild(empty);
                    return;
                }

                ordered.forEach(
                    item => {
                        const chip =
                            document.createElement(
                                'span'
                            );

                        chip.className =
                            'album-entity-chip'
                            + (
                                small
                                    ? ' album-entity-chip--small'
                                    : ''
                            );

                        if (item.thumb) {
                            const image =
                                document.createElement(
                                    'img'
                                );

                            image.src = item.thumb;
                            image.alt = '';
                            image.loading = 'lazy';
                            chip.appendChild(image);
                        }

                        const text =
                            document.createElement(
                                'span'
                            );

                        text.textContent =
                            item.name;

                        chip.appendChild(text);
                        target.appendChild(chip);
                    }
                );
            }

            modalSave?.addEventListener(
                'click',
                async () => {
                    modalSave.disabled = true;

                    try {
                        const ids =
                            [...modalSelected];

                        if (
                            modalMode === 'labels'
                            || modalMode === 'artists'
                        ) {
                            await albumApi(
                                'save_album_entities',
                                {
                                    album_id: ALBUM_ID,
                                    kind: modalMode,
                                    ids,
                                }
                            );

                            if (modalMode === 'labels') {
                                albumLabelIds =
                                    new Set(ids);

                                renderChipList(
                                    document.getElementById(
                                        'albumLabelChips'
                                    ),
                                    albumLabelIds,
                                    FACTIONS,
                                    'Kein Label'
                                );
                            } else {
                                albumArtistIds =
                                    new Set(ids);

                                renderChipList(
                                    document.getElementById(
                                        'albumArtistChips'
                                    ),
                                    albumArtistIds,
                                    CHARS,
                                    'Keine Künstler'
                                );
                            }

                            setAlbumStatus('Gespeichert');

                        } else if (
                            modalMode === 'features'
                            && modalSongId
                        ) {
                            await albumApi(
                                'save_song_features',
                                {
                                    album_song_id:
                                        modalSongId,
                                    ids,
                                }
                            );

                            const row =
                                document.querySelector(
                                    '.album-song-row'
                                    + '[data-album-song-id="'
                                    + modalSongId
                                    + '"]'
                                );

                            if (row) {
                                row.dataset.featureIds =
                                    ids.join(',');
                            }

                            renderChipList(
                                document.getElementById(
                                    'songFeatures'
                                    + modalSongId
                                ),
                                new Set(ids),
                                CHARS,
                                'Keine Features',
                                true
                            );
                        }

                        closeModal();

                    } catch (error) {
                        alert(
                            error.message
                            || 'Speichern fehlgeschlagen.'
                        );
                    } finally {
                        modalSave.disabled = false;
                    }
                }
            );


            /* =============================================
             * Cover Upload
             * ============================================= */

            const coverInput =
                document.getElementById(
                    'albumCoverInput'
                );

            const coverChoose =
                document.getElementById(
                    'albumCoverChoose'
                );

            const coverEmpty =
                document.getElementById(
                    'albumCoverEmpty'
                );

            const coverDropzone =
                document.getElementById(
                    'albumCoverDropzone'
                );

            async function uploadCover(file) {
                if (!file) {
                    return;
                }

                const body =
                    new FormData();

                body.set('ajax', '1');
                body.set('csrf', CSRF);
                body.set('action', 'upload_cover');
                body.set(
                    'album_id',
                    String(ALBUM_ID)
                );
                body.set('cover', file);

                setAlbumStatus(
                    'Cover wird gespeichert …'
                );

                const response =
                    await fetch(
                        window.location.href,
                        {
                            method: 'POST',
                            body,
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
                        || 'Cover-Upload fehlgeschlagen.'
                    );
                }

                window.location.reload();
            }

            coverChoose?.addEventListener(
                'click',
                () => coverInput?.click()
            );

            coverEmpty?.addEventListener(
                'click',
                () => coverInput?.click()
            );

            coverInput?.addEventListener(
                'change',
                async () => {
                    try {
                        await uploadCover(
                            coverInput.files?.[0]
                        );
                    } catch (error) {
                        setAlbumStatus(
                            error.message || 'Fehler',
                            true
                        );
                    }
                }
            );

            [
                'dragenter',
                'dragover',
            ].forEach(
                name => {
                    coverDropzone
                        ?.addEventListener(
                            name,
                            event => {
                                event.preventDefault();
                                coverDropzone
                                    .classList
                                    .add(
                                        'is-dragover'
                                    );
                            }
                        );
                }
            );

            [
                'dragleave',
                'drop',
            ].forEach(
                name => {
                    coverDropzone
                        ?.addEventListener(
                            name,
                            event => {
                                event.preventDefault();
                                coverDropzone
                                    .classList
                                    .remove(
                                        'is-dragover'
                                    );
                            }
                        );
                }
            );

            coverDropzone?.addEventListener(
                'drop',
                async event => {
                    try {
                        await uploadCover(
                            event.dataTransfer
                                ?.files?.[0]
                        );
                    } catch (error) {
                        setAlbumStatus(
                            error.message || 'Fehler',
                            true
                        );
                    }
                }
            );

            document
                .getElementById(
                    'albumCoverRemove'
                )
                ?.addEventListener(
                    'click',
                    async () => {
                        if (
                            !confirm(
                                'Cover wirklich entfernen?'
                            )
                        ) {
                            return;
                        }

                        try {
                            await albumApi(
                                'remove_cover',
                                {
                                    album_id:
                                        ALBUM_ID,
                                }
                            );

                            window.location.reload();

                        } catch (error) {
                            setAlbumStatus(
                                error.message || 'Fehler',
                                true
                            );
                        }
                    }
                );


            /* =============================================
             * 1:1 Thumbnail-Ausschnitt
             * ============================================= */

            const cropToggle =
                document.getElementById(
                    'albumCropToggle'
                );

            const cropSave =
                document.getElementById(
                    'albumCropSave'
                );

            const cropbox =
                document.getElementById(
                    'albumCoverCropbox'
                );

            const cropImage =
                document.getElementById(
                    'albumCoverImage'
                );

            const cropOverlay =
                document.getElementById(
                    'albumCropOverlay'
                );

            let cropMode = false;
            let crop = null;
            let cropPointer = null;

            function cropBoxRect() {
                return cropImage
                    ?.getBoundingClientRect()
                    || null;
            }

            function drawCrop() {
                if (
                    !cropOverlay
                    || !cropImage
                    || !crop
                ) {
                    return;
                }

                const rect = cropBoxRect();
                if (!rect) {
                    return;
                }

                cropOverlay.hidden = false;
                cropOverlay.style.left =
                    crop.x * rect.width + 'px';
                cropOverlay.style.top =
                    crop.y * rect.height + 'px';
                cropOverlay.style.width =
                    crop.w * rect.width + 'px';
                cropOverlay.style.height =
                    crop.h * rect.height + 'px';
            }

            function setCropMode(active) {
                cropMode = active;

                cropbox?.classList.toggle(
                    'is-crop-mode',
                    active
                );

                if (cropSave) {
                    cropSave.hidden = !active;
                }

                if (cropToggle) {
                    cropToggle.textContent =
                        active
                            ? 'Ausschnitt abbrechen'
                            : 'Thumbnail-Ausschnitt';
                }

                if (
                    !active
                    && cropOverlay
                ) {
                    cropOverlay.hidden = true;
                    crop = null;
                }
            }

            cropToggle?.addEventListener(
                'click',
                () =>
                    setCropMode(
                        !cropMode
                    )
            );

            cropbox?.addEventListener(
                'pointerdown',
                event => {
                    if (
                        !cropMode
                        || !cropImage
                    ) {
                        return;
                    }

                    event.preventDefault();

                    const rect =
                        cropBoxRect();

                    if (!rect) {
                        return;
                    }

                    const startX =
                        Math.max(
                            0,
                            Math.min(
                                rect.width,
                                event.clientX
                                - rect.left
                            )
                        );

                    const startY =
                        Math.max(
                            0,
                            Math.min(
                                rect.height,
                                event.clientY
                                - rect.top
                            )
                        );

                    cropPointer = {
                        id: event.pointerId,
                        startX,
                        startY,
                        rect,
                    };

                    try {
                        cropbox.setPointerCapture(
                            event.pointerId
                        );
                    } catch (_) {}

                    crop = {
                        x: startX / rect.width,
                        y: startY / rect.height,
                        w: 0,
                        h: 0,
                    };

                    drawCrop();
                }
            );

            cropbox?.addEventListener(
                'pointermove',
                event => {
                    if (
                        !cropMode
                        || !cropPointer
                        || cropPointer.id
                            !== event.pointerId
                    ) {
                        return;
                    }

                    event.preventDefault();

                    const rect =
                        cropPointer.rect;

                    const currentX =
                        Math.max(
                            0,
                            Math.min(
                                rect.width,
                                event.clientX
                                - rect.left
                            )
                        );

                    const currentY =
                        Math.max(
                            0,
                            Math.min(
                                rect.height,
                                event.clientY
                                - rect.top
                            )
                        );

                    const dx =
                        currentX
                        - cropPointer.startX;

                    const dy =
                        currentY
                        - cropPointer.startY;

                    let side =
                        Math.min(
                            Math.abs(dx),
                            Math.abs(dy)
                        );

                    side =
                        Math.min(
                            side,
                            dx >= 0
                                ? rect.width
                                    - cropPointer.startX
                                : cropPointer.startX,
                            dy >= 0
                                ? rect.height
                                    - cropPointer.startY
                                : cropPointer.startY
                        );

                    side =
                        Math.max(
                            0,
                            side
                        );

                    const left =
                        dx >= 0
                            ? cropPointer.startX
                            : cropPointer.startX
                                - side;

                    const top =
                        dy >= 0
                            ? cropPointer.startY
                            : cropPointer.startY
                                - side;

                    crop = {
                        x: left / rect.width,
                        y: top / rect.height,
                        w: side / rect.width,
                        h: side / rect.height,
                    };

                    drawCrop();
                }
            );

            function endCropPointer(event) {
                if (
                    cropPointer
                    && (
                        !event
                        || cropPointer.id
                            === event.pointerId
                    )
                ) {
                    cropPointer = null;
                }
            }

            cropbox?.addEventListener(
                'pointerup',
                endCropPointer
            );

            cropbox?.addEventListener(
                'pointercancel',
                endCropPointer
            );

            cropSave?.addEventListener(
                'click',
                async () => {
                    if (
                        !crop
                        || crop.w <= 0
                        || crop.h <= 0
                    ) {
                        setAlbumStatus(
                            'Bitte zuerst einen 1:1-Ausschnitt ziehen.',
                            true
                        );
                        return;
                    }

                    try {
                        await albumApi(
                            'save_crop',
                            {
                                album_id:
                                    ALBUM_ID,
                                x: crop.x,
                                y: crop.y,
                                w: crop.w,
                                h: crop.h,
                            }
                        );

                        setAlbumStatus(
                            'Thumbnail-Ausschnitt gespeichert'
                        );

                        setCropMode(false);

                    } catch (error) {
                        setAlbumStatus(
                            error.message || 'Fehler',
                            true
                        );
                    }
                }
            );

            window.addEventListener(
                'resize',
                drawCrop
            );


            document
                .getElementById(
                    'albumDeleteForm'
                )
                ?.addEventListener(
                    'submit',
                    event => {
                        if (
                            !confirm(
                                'Album wirklich nur aus PHAN löschen? '
                                + 'Das Spotify-Album bleibt unverändert.'
                            )
                        ) {
                            event.preventDefault();
                        }
                    }
                );

            document.addEventListener(
                'keydown',
                event => {
                    if (
                        event.key === 'Escape'
                        && modal
                        && !modal.hidden
                    ) {
                        closeModal();
                    }
                }
            );

        })();
        </script>

    <?php endif; ?>

</main>

</body>
</html>
