<?php
$page_title = 'Spotify Token';

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php'; //$checkconn

if (!isset($checkconn) || !($checkconn instanceof mysqli)) {
    if (isset($conn) && $conn instanceof mysqli) {
        $checkconn = $conn;
    } else {
        die('Keine gültige DB-Verbindung gefunden ($checkconn).');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$checkconn->set_charset('utf8mb4');

const SPOTIFY_APP_KEY = 'spotify_monthly_top50';
const SPOTIFY_APP_NAME = 'Spotify Monthly Top 50';
const SPOTIFY_ENV_PATH = '/opt/spotify-top-tracks/spotify_monthly_top50.env';
const SPOTIFY_SCRIPT_PATH = '/opt/spotify-top-tracks/spotify_monthly_top50.py';
const SPOTIFY_LOG_PATH = '/var/log/spotify-top50.log';

const SPOTIFY_ACCOUNTS_URL = 'https://accounts.spotify.com';
const SPOTIFY_API_URL = 'https://api.spotify.com/v1';

const SPOTIFY_DEFAULT_SCOPES = 'user-top-read user-read-private playlist-modify-public ugc-image-upload';
const SPOTIFY_TOKEN_MONTHS = 6;
const SPOTIFY_REMIND_DAYS = 30;

if (empty($_SESSION['spotify_token_csrf'])) {
    $_SESSION['spotify_token_csrf'] = bin2hex(random_bytes(32));
}

function spotify_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function spotify_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function spotify_require_csrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    $real = $_SESSION['spotify_token_csrf'] ?? '';

    if (!$sent || !$real || !hash_equals($real, $sent)) {
        spotify_json([
            'ok' => false,
            'error' => 'csrf',
            'message' => 'CSRF-Token ungültig. Seite neu laden.'
        ], 403);
    }
}

function spotify_now_iso(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format(DateTimeInterface::ATOM);
}

function spotify_page_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? 'SpotifyToken.php';
    $path = strtok($uri, '?');
    return $path ?: 'SpotifyToken.php';
}

function spotify_redirect_self(array $params = []): never
{
    $url = spotify_page_path();
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

function spotify_ensure_state_row(mysqli $checkconn): void
{
    $appKey = SPOTIFY_APP_KEY;
    $appName = SPOTIFY_APP_NAME;
    $scopes = SPOTIFY_DEFAULT_SCOPES;
    $envPath = SPOTIFY_ENV_PATH;
    $scriptPath = SPOTIFY_SCRIPT_PATH;
    $notes = 'Monatlicher Cronjob für Spotify Top Tracks Playlist. Refresh Token muss spätestens alle 6 Monate erneuert werden.';

    $sql = "
        INSERT INTO spotify_token_state (
            app_key,
            app_name,
            scopes,
            env_path,
            script_path,
            reauth_required,
            notes
        ) VALUES (?, ?, ?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE
            app_name = VALUES(app_name),
            scopes = COALESCE(NULLIF(spotify_token_state.scopes, ''), VALUES(scopes)),
            env_path = VALUES(env_path),
            script_path = VALUES(script_path),
            updated_at = CURRENT_TIMESTAMP
    ";

    $stmt = $checkconn->prepare($sql);
    $stmt->bind_param('ssssss', $appKey, $appName, $scopes, $envPath, $scriptPath, $notes);
    $stmt->execute();
}

function spotify_get_state_row(mysqli $checkconn): array
{
    spotify_ensure_state_row($checkconn);

    $appKey = SPOTIFY_APP_KEY;
    $stmt = $checkconn->prepare("SELECT * FROM spotify_token_state WHERE app_key = ? LIMIT 1");
    $stmt->bind_param('s', $appKey);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('spotify_token_state row missing.');
    }

    return $row;
}

function spotify_read_env(string $envPath = SPOTIFY_ENV_PATH): array
{
    if (!is_file($envPath)) {
        throw new RuntimeException("Env-Datei fehlt: {$envPath}");
    }

    if (!is_readable($envPath)) {
        throw new RuntimeException("Env-Datei ist nicht lesbar für PHP: {$envPath}");
    }

    $env = [];
    $lines = file($envPath, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new RuntimeException("Env-Datei konnte nicht gelesen werden: {$envPath}");
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $trimmed, $m)) {
            continue;
        }

        $key = $m[1];
        $value = trim($m[2]);

        if (
            strlen($value) >= 2 &&
            (
                ($value[0] === '"' && substr($value, -1) === '"') ||
                ($value[0] === "'" && substr($value, -1) === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

function spotify_env_require(array $env, string $key): string
{
    $value = trim((string)($env[$key] ?? ''));

    if ($value === '') {
        throw new RuntimeException("{$key} fehlt in " . SPOTIFY_ENV_PATH);
    }

    return $value;
}

function spotify_write_env_values(array $updates, string $envPath = SPOTIFY_ENV_PATH): void
{
    if (!is_file($envPath)) {
        throw new RuntimeException("Env-Datei fehlt: {$envPath}");
    }

    if (!is_writable($envPath)) {
        throw new RuntimeException("Env-Datei ist nicht schreibbar für PHP: {$envPath}");
    }

    $fp = fopen($envPath, 'c+');
    if (!$fp) {
        throw new RuntimeException("Env-Datei konnte nicht geöffnet werden: {$envPath}");
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException("Env-Datei konnte nicht gelockt werden: {$envPath}");
        }

        rewind($fp);
        $content = stream_get_contents($fp);
        if ($content === false) {
            throw new RuntimeException("Env-Datei konnte nicht gelesen werden: {$envPath}");
        }

        $lines = preg_split('/\R/', $content);
        if ($lines === false) {
            $lines = [];
        }

        $seen = [];
        $out = [];

        foreach ($lines as $line) {
            if ($line === '' && count($lines) === 1) {
                continue;
            }

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=/', $line, $m)) {
                $key = $m[1];

                if (array_key_exists($key, $updates)) {
                    $out[] = $key . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$updates[$key]) . '"';
                    $seen[$key] = true;
                    continue;
                }
            }

            if ($line !== '' || !empty($out)) {
                $out[] = $line;
            }
        }

        foreach ($updates as $key => $value) {
            if (!isset($seen[$key])) {
                if (!empty($out) && trim(end($out)) !== '') {
                    $out[] = '';
                }
                $out[] = $key . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$value) . '"';
            }
        }

        $newContent = rtrim(implode("\n", $out), "\n") . "\n";

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $newContent);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

function spotify_token_preview(string $token): string
{
    $token = trim($token);

    if ($token === '') {
        return '';
    }

    if (strlen($token) <= 18) {
        return substr($token, 0, 4) . '…';
    }

    return substr($token, 0, 8) . '…' . substr($token, -8);
}

function spotify_http_post_form(string $url, array $data, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP-cURL fehlt. Installiere php8.4-curl und starte PHP-FPM/Apache neu.');
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    curl_close($ch);

    if ($errno) {
        throw new RuntimeException("cURL Fehler {$errno}: {$error}");
    }

    $decoded = json_decode((string)$body, true);

    return [
        'status' => $status,
        'body' => (string)$body,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function spotify_http_get_json(string $url, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP-cURL fehlt. Installiere php8.4-curl und starte PHP-FPM/Apache neu.');
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    curl_close($ch);

    if ($errno) {
        throw new RuntimeException("cURL Fehler {$errno}: {$error}");
    }

    $decoded = json_decode((string)$body, true);

    return [
        'status' => $status,
        'body' => (string)$body,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function spotify_basic_auth_header(string $clientId, string $clientSecret): string
{
    return 'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret);
}

function spotify_set_last_error(mysqli $checkconn, string $code, string $message, bool $reauthRequired = false): void
{
    $appKey = SPOTIFY_APP_KEY;
    $reauth = $reauthRequired ? 1 : 0;

    $stmt = $checkconn->prepare("
        UPDATE spotify_token_state
        SET
            reauth_required = ?,
            last_error_at = NOW(),
            last_error_code = ?,
            last_error_message = ?
        WHERE app_key = ?
    ");
    $stmt->bind_param('isss', $reauth, $code, $message, $appKey);
    $stmt->execute();
}

function spotify_clear_error(mysqli $checkconn): void
{
    $appKey = SPOTIFY_APP_KEY;

    $stmt = $checkconn->prepare("
        UPDATE spotify_token_state
        SET
            last_error_at = NULL,
            last_error_code = NULL,
            last_error_message = NULL
        WHERE app_key = ?
    ");
    $stmt->bind_param('s', $appKey);
    $stmt->execute();
}

function spotify_update_after_successful_auth(
    mysqli $checkconn,
    string $refreshToken,
    string $clientId,
    string $redirectUri,
    string $scopes,
    ?array $me
): void {
    $appKey = SPOTIFY_APP_KEY;
    $preview = spotify_token_preview($refreshToken);
    $hash = hash('sha256', $refreshToken);
    $userId = $me['id'] ?? null;
    $displayName = $me['display_name'] ?? null;

    $stmt = $checkconn->prepare("
        UPDATE spotify_token_state
        SET
            client_id = ?,
            redirect_uri = ?,
            scopes = ?,
            spotify_user_id = ?,
            spotify_display_name = ?,
            refresh_token_present = 1,
            refresh_token_preview = ?,
            refresh_token_sha256 = ?,
            authorized_at = NOW(),
            refresh_token_expires_at = DATE_ADD(NOW(), INTERVAL 6 MONTH),
            remind_from = DATE_SUB(DATE_ADD(NOW(), INTERVAL 6 MONTH), INTERVAL 30 DAY),
            reauth_required = 0,
            auth_state = NULL,
            auth_state_created_at = NULL,
            auth_state_expires_at = NULL,
            auth_started_at = NULL,
            auth_finished_at = NOW(),
            last_refresh_ok_at = NOW(),
            last_error_at = NULL,
            last_error_code = NULL,
            last_error_message = NULL
        WHERE app_key = ?
    ");

    $stmt->bind_param(
        'ssssssss',
        $clientId,
        $redirectUri,
        $scopes,
        $userId,
        $displayName,
        $preview,
        $hash,
        $appKey
    );
    $stmt->execute();
}

function spotify_sync_db_from_env_created_at(mysqli $checkconn, array $env, array $row): void
{
    if (!empty($row['authorized_at']) || empty($env['SPOTIFY_REFRESH_TOKEN']) || empty($env['SPOTIFY_REFRESH_TOKEN_CREATED_AT'])) {
        return;
    }

    try {
        $dt = new DateTimeImmutable($env['SPOTIFY_REFRESH_TOKEN_CREATED_AT']);
    } catch (Throwable) {
        return;
    }

    $authorizedAt = $dt->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i:s');
    $expiresAt = $dt->modify('+' . SPOTIFY_TOKEN_MONTHS . ' months')->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i:s');
    $remindFrom = $dt->modify('+' . SPOTIFY_TOKEN_MONTHS . ' months')->modify('-' . SPOTIFY_REMIND_DAYS . ' days')->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i:s');

    $refreshToken = (string)$env['SPOTIFY_REFRESH_TOKEN'];
    $preview = spotify_token_preview($refreshToken);
    $hash = hash('sha256', $refreshToken);
    $appKey = SPOTIFY_APP_KEY;

    $stmt = $checkconn->prepare("
        UPDATE spotify_token_state
        SET
            refresh_token_present = 1,
            refresh_token_preview = ?,
            refresh_token_sha256 = ?,
            authorized_at = ?,
            refresh_token_expires_at = ?,
            remind_from = ?,
            reauth_required = 0
        WHERE app_key = ?
    ");
    $stmt->bind_param('ssssss', $preview, $hash, $authorizedAt, $expiresAt, $remindFrom, $appKey);
    $stmt->execute();
}

function spotify_tail_file_reversed(string $path, int $lines = 120): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $content = file($path, FILE_IGNORE_NEW_LINES);
    if ($content === false) {
        return '';
    }

    $tail = array_slice($content, -$lines);
    $tail = array_reverse($tail);

    return implode("\n", $tail);
}

function spotify_handle_callback(mysqli $checkconn): void
{
    spotify_ensure_state_row($checkconn);

    $state = (string)($_GET['state'] ?? '');
    $code = (string)($_GET['code'] ?? '');
    $spotifyError = (string)($_GET['error'] ?? '');

    if ($state === '') {
        spotify_set_last_error($checkconn, 'callback_state_missing', 'Spotify Callback ohne state erhalten.', true);
        spotify_redirect_self(['spotify' => 'error']);
    }

    $appKey = SPOTIFY_APP_KEY;
    $stmt = $checkconn->prepare("
        SELECT *
        FROM spotify_token_state
        WHERE app_key = ?
          AND auth_state = ?
          AND auth_state_expires_at IS NOT NULL
          AND auth_state_expires_at > NOW()
        LIMIT 1
    ");
    $stmt->bind_param('ss', $appKey, $state);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        spotify_set_last_error($checkconn, 'callback_state_invalid', 'Spotify Callback state ist ungültig oder abgelaufen.', true);
        spotify_redirect_self(['spotify' => 'error']);
    }

    if ($spotifyError !== '') {
        spotify_set_last_error($checkconn, 'spotify_callback_error', 'Spotify Callback Fehler: ' . $spotifyError, true);
        spotify_redirect_self(['spotify' => 'error']);
    }

    if ($code === '') {
        spotify_set_last_error($checkconn, 'callback_code_missing', 'Spotify Callback ohne code erhalten.', true);
        spotify_redirect_self(['spotify' => 'error']);
    }

    try {
        $env = spotify_read_env();
        $clientId = spotify_env_require($env, 'SPOTIFY_CLIENT_ID');
        $clientSecret = spotify_env_require($env, 'SPOTIFY_CLIENT_SECRET');
        $redirectUri = spotify_env_require($env, 'SPOTIFY_REDIRECT_URI');
        $scopes = trim((string)($row['scopes'] ?? '')) ?: SPOTIFY_DEFAULT_SCOPES;

        $tokenResponse = spotify_http_post_form(
            SPOTIFY_ACCOUNTS_URL . '/api/token',
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ],
            [
                spotify_basic_auth_header($clientId, $clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ]
        );

        if ($tokenResponse['status'] !== 200 || !is_array($tokenResponse['json'])) {
            $msg = 'Token Exchange fehlgeschlagen (' . $tokenResponse['status'] . '): ' . $tokenResponse['body'];
            spotify_set_last_error($checkconn, 'token_exchange_failed', $msg, true);
            spotify_redirect_self(['spotify' => 'error']);
        }

        $payload = $tokenResponse['json'];
        $refreshToken = trim((string)($payload['refresh_token'] ?? ''));
        $accessToken = trim((string)($payload['access_token'] ?? ''));

        if ($refreshToken === '') {
            spotify_set_last_error(
                $checkconn,
                'refresh_token_missing',
                'Spotify hat keinen refresh_token zurückgegeben. Auth erneut starten.',
                true
            );
            spotify_redirect_self(['spotify' => 'error']);
        }

        spotify_write_env_values([
            'SPOTIFY_REFRESH_TOKEN' => $refreshToken,
            'SPOTIFY_REFRESH_TOKEN_CREATED_AT' => spotify_now_iso(),
        ]);

        $me = null;
        if ($accessToken !== '') {
            $meResponse = spotify_http_get_json(
                SPOTIFY_API_URL . '/me',
                ['Authorization: Bearer ' . $accessToken]
            );

            if ($meResponse['status'] === 200 && is_array($meResponse['json'])) {
                $me = $meResponse['json'];
            }
        }

        spotify_update_after_successful_auth($checkconn, $refreshToken, $clientId, $redirectUri, $scopes, $me);
        spotify_redirect_self(['spotify' => 'ok']);
    } catch (Throwable $e) {
        spotify_set_last_error($checkconn, 'callback_exception', $e->getMessage(), true);
        spotify_redirect_self(['spotify' => 'error']);
    }
}

function spotify_action_status(mysqli $checkconn): void
{
    $row = spotify_get_state_row($checkconn);

    try {
        $env = spotify_read_env();
        spotify_sync_db_from_env_created_at($checkconn, $env, $row);
        $row = spotify_get_state_row($checkconn);

        $envOk = true;
        $envError = '';
    } catch (Throwable $e) {
        $env = [];
        $envOk = false;
        $envError = $e->getMessage();
    }

    $refreshToken = trim((string)($env['SPOTIFY_REFRESH_TOKEN'] ?? ''));
    $clientId = trim((string)($env['SPOTIFY_CLIENT_ID'] ?? ''));
    $redirectUri = trim((string)($env['SPOTIFY_REDIRECT_URI'] ?? ''));

    $hasRefresh = $refreshToken !== '';
    $expiresAt = $row['refresh_token_expires_at'] ?? null;
    $remindFrom = $row['remind_from'] ?? null;

    $secondsUntilExpiry = null;
    $daysUntilExpiry = null;

    if ($expiresAt) {
        $secondsUntilExpiry = strtotime($expiresAt) - time();
        $daysUntilExpiry = (int)floor($secondsUntilExpiry / 86400);
    }

    $status = 'unknown';
    $statusLabel = 'Unbekannt';
    $statusText = 'Token existiert, aber die Seite kennt noch kein Ausstellungsdatum. Einmal erneuern, dann kann die Seite korrekt zählen.';

    if (!$envOk) {
        $status = 'error';
        $statusLabel = 'Fehler';
        $statusText = $envError;
    } elseif (!$hasRefresh) {
        $status = 'missing';
        $statusLabel = 'Fehlt';
        $statusText = 'Kein Refresh Token in der .env gefunden. Reauth erforderlich.';
    } elseif (($row['last_error_code'] ?? '') === 'invalid_grant') {
        $status = 'reauth';
        $statusLabel = 'Reauth nötig';
        $statusText = 'Spotify hat invalid_grant geliefert. Der gespeicherte Refresh Token ist abgelaufen oder widerrufen.';
    } elseif (!$expiresAt) {
        $status = 'unknown';
        $statusLabel = 'Unbekannt';
        $statusText = 'Token existiert, aber Ablaufdatum ist noch unbekannt. Jetzt einmal über diese Seite erneuern.';
    } elseif ($secondsUntilExpiry <= 0) {
        $status = 'expired';
        $statusLabel = 'Abgelaufen';
        $statusText = 'Der gespeicherte Refresh Token sollte erneuert werden.';
    } elseif ($remindFrom && strtotime($remindFrom) <= time()) {
        $status = 'soon';
        $statusLabel = 'Bald erneuern';
        $statusText = 'Der Token ist noch gültig, sollte aber bald erneuert werden.';
    } else {
        $status = 'ok';
        $statusLabel = 'OK';
        $statusText = 'Der Token ist laut Datenbank noch im gültigen Fenster.';
    }

    spotify_json([
        'ok' => true,
        'status' => $status,
        'status_label' => $statusLabel,
        'status_text' => $statusText,
        'csrf' => $_SESSION['spotify_token_csrf'],

        'env' => [
            'ok' => $envOk,
            'error' => $envError,
            'path' => SPOTIFY_ENV_PATH,
            'readable' => is_readable(SPOTIFY_ENV_PATH),
            'writable' => is_writable(SPOTIFY_ENV_PATH),
            'has_refresh_token' => $hasRefresh,
            'refresh_token_preview' => $hasRefresh ? spotify_token_preview($refreshToken) : '',
            'has_created_at' => !empty($env['SPOTIFY_REFRESH_TOKEN_CREATED_AT']),
            'created_at' => $env['SPOTIFY_REFRESH_TOKEN_CREATED_AT'] ?? '',
            'client_id_preview' => $clientId ? spotify_token_preview($clientId) : '',
            'redirect_uri' => $redirectUri,
        ],

        'db' => [
            'app_name' => $row['app_name'] ?? SPOTIFY_APP_NAME,
            'spotify_user_id' => $row['spotify_user_id'] ?? '',
            'spotify_display_name' => $row['spotify_display_name'] ?? '',
            'scopes' => $row['scopes'] ?: SPOTIFY_DEFAULT_SCOPES,
            'authorized_at' => $row['authorized_at'] ?? '',
            'refresh_token_expires_at' => $expiresAt ?: '',
            'remind_from' => $remindFrom ?: '',
            'seconds_until_expiry' => $secondsUntilExpiry,
            'days_until_expiry' => $daysUntilExpiry,
            'last_refresh_ok_at' => $row['last_refresh_ok_at'] ?? '',
            'last_cron_run_at' => $row['last_cron_run_at'] ?? '',
            'last_cron_exit_code' => $row['last_cron_exit_code'] ?? '',
            'last_playlist_name' => $row['last_playlist_name'] ?? '',
            'last_playlist_id' => $row['last_playlist_id'] ?? '',
            'last_tracks_count' => $row['last_tracks_count'] ?? '',
            'last_error_at' => $row['last_error_at'] ?? '',
            'last_error_code' => $row['last_error_code'] ?? '',
            'last_error_message' => $row['last_error_message'] ?? '',
        ],

        'paths' => [
            'script' => SPOTIFY_SCRIPT_PATH,
            'log' => SPOTIFY_LOG_PATH,
        ],

        'log_tail' => spotify_tail_file_reversed(SPOTIFY_LOG_PATH),
    ]);
}

function spotify_action_start_auth(mysqli $checkconn): void
{
    spotify_require_csrf();

    try {
        $row = spotify_get_state_row($checkconn);
        $env = spotify_read_env();

        $clientId = spotify_env_require($env, 'SPOTIFY_CLIENT_ID');
        spotify_env_require($env, 'SPOTIFY_CLIENT_SECRET');
        $redirectUri = spotify_env_require($env, 'SPOTIFY_REDIRECT_URI');

        $scopes = trim((string)($row['scopes'] ?? '')) ?: SPOTIFY_DEFAULT_SCOPES;
        $state = bin2hex(random_bytes(32));
        $appKey = SPOTIFY_APP_KEY;

        $stmt = $checkconn->prepare("
            UPDATE spotify_token_state
            SET
                client_id = ?,
                redirect_uri = ?,
                scopes = ?,
                auth_state = ?,
                auth_state_created_at = NOW(),
                auth_state_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                auth_started_at = NOW(),
                last_error_at = NULL,
                last_error_code = NULL,
                last_error_message = NULL
            WHERE app_key = ?
        ");
        $stmt->bind_param('sssss', $clientId, $redirectUri, $scopes, $state, $appKey);
        $stmt->execute();

        $authUrl = SPOTIFY_ACCOUNTS_URL . '/authorize?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => $scopes,
            'state' => $state,
            'show_dialog' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);

        spotify_json([
            'ok' => true,
            'auth_url' => $authUrl,
        ]);
    } catch (Throwable $e) {
        spotify_set_last_error($checkconn, 'start_auth_failed', $e->getMessage(), false);

        spotify_json([
            'ok' => false,
            'error' => 'start_auth_failed',
            'message' => $e->getMessage(),
        ], 500);
    }
}

function spotify_action_test_refresh(mysqli $checkconn): void
{
    spotify_require_csrf();

    try {
        $env = spotify_read_env();

        $clientId = spotify_env_require($env, 'SPOTIFY_CLIENT_ID');
        $clientSecret = spotify_env_require($env, 'SPOTIFY_CLIENT_SECRET');
        $refreshToken = spotify_env_require($env, 'SPOTIFY_REFRESH_TOKEN');

        $response = spotify_http_post_form(
            SPOTIFY_ACCOUNTS_URL . '/api/token',
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
            [
                spotify_basic_auth_header($clientId, $clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ]
        );

        if ($response['status'] !== 200 || !is_array($response['json'])) {
            $payload = $response['json'] ?: [];
            $errorCode = (string)($payload['error'] ?? 'refresh_failed');
            $message = 'Refresh Test fehlgeschlagen (' . $response['status'] . '): ' . $response['body'];
            $reauth = $errorCode === 'invalid_grant';

            if ($reauth) {
                $appKey = SPOTIFY_APP_KEY;
                $stmt = $checkconn->prepare("
                    UPDATE spotify_token_state
                    SET
                        reauth_required = 1,
                        refresh_token_present = 0,
                        last_error_at = NOW(),
                        last_error_code = 'invalid_grant',
                        last_error_message = ?
                    WHERE app_key = ?
                ");
                $stmt->bind_param('ss', $message, $appKey);
                $stmt->execute();
            } else {
                spotify_set_last_error($checkconn, $errorCode, $message, false);
            }

            spotify_json([
                'ok' => false,
                'error' => $errorCode,
                'message' => $message,
                'reauth_required' => $reauth,
            ], 400);
        }

        $payload = $response['json'];
        $accessToken = trim((string)($payload['access_token'] ?? ''));

        if ($accessToken === '') {
            throw new RuntimeException('Refresh erfolgreich, aber access_token fehlt in der Antwort.');
        }

        if (!empty($payload['refresh_token'])) {
            $newRefreshToken = trim((string)$payload['refresh_token']);
            spotify_write_env_values([
                'SPOTIFY_REFRESH_TOKEN' => $newRefreshToken,
                'SPOTIFY_REFRESH_TOKEN_CREATED_AT' => spotify_now_iso(),
            ]);

            $meResponse = spotify_http_get_json(
                SPOTIFY_API_URL . '/me',
                ['Authorization: Bearer ' . $accessToken]
            );

            $me = null;
            if ($meResponse['status'] === 200 && is_array($meResponse['json'])) {
                $me = $meResponse['json'];
            }

            $row = spotify_get_state_row($checkconn);
            $scopes = trim((string)($row['scopes'] ?? '')) ?: SPOTIFY_DEFAULT_SCOPES;
            $redirectUri = spotify_env_require($env, 'SPOTIFY_REDIRECT_URI');

            spotify_update_after_successful_auth($checkconn, $newRefreshToken, $clientId, $redirectUri, $scopes, $me);
        } else {
            $meResponse = spotify_http_get_json(
                SPOTIFY_API_URL . '/me',
                ['Authorization: Bearer ' . $accessToken]
            );

            $me = null;
            if ($meResponse['status'] === 200 && is_array($meResponse['json'])) {
                $me = $meResponse['json'];
            }

            $userId = $me['id'] ?? null;
            $displayName = $me['display_name'] ?? null;
            $appKey = SPOTIFY_APP_KEY;

            $stmt = $checkconn->prepare("
                UPDATE spotify_token_state
                SET
                    spotify_user_id = COALESCE(?, spotify_user_id),
                    spotify_display_name = COALESCE(?, spotify_display_name),
                    refresh_token_present = 1,
                    refresh_token_preview = ?,
                    refresh_token_sha256 = ?,
                    reauth_required = 0,
                    last_refresh_ok_at = NOW(),
                    last_error_at = NULL,
                    last_error_code = NULL,
                    last_error_message = NULL
                WHERE app_key = ?
            ");

            $preview = spotify_token_preview($refreshToken);
            $hash = hash('sha256', $refreshToken);
            $stmt->bind_param('sssss', $userId, $displayName, $preview, $hash, $appKey);
            $stmt->execute();
        }

        spotify_json([
            'ok' => true,
            'message' => 'Refresh Token funktioniert. Spotify hat einen Access Token ausgegeben.',
        ]);
    } catch (Throwable $e) {
        spotify_set_last_error($checkconn, 'test_refresh_exception', $e->getMessage(), false);

        spotify_json([
            'ok' => false,
            'error' => 'test_refresh_exception',
            'message' => $e->getMessage(),
        ], 500);
    }
}

function spotify_action_clear_error(mysqli $checkconn): void
{
    spotify_require_csrf();

    spotify_clear_error($checkconn);

    spotify_json([
        'ok' => true,
        'message' => 'Fehlerstatus gelöscht.',
    ]);
}

spotify_ensure_state_row($checkconn);

if (isset($_GET['code']) || isset($_GET['error'])) {
    spotify_handle_callback($checkconn);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'status') {
            spotify_require_csrf();
            spotify_action_status($checkconn);
        }

        if ($action === 'start_auth') {
            spotify_action_start_auth($checkconn);
        }

        if ($action === 'test_refresh') {
            spotify_action_test_refresh($checkconn);
        }

        if ($action === 'clear_error') {
            spotify_action_clear_error($checkconn);
        }

        spotify_json([
            'ok' => false,
            'error' => 'unknown_action',
            'message' => 'Unbekannte Aktion.'
        ], 400);
    } catch (Throwable $e) {
        spotify_json([
            'ok' => false,
            'error' => 'exception',
            'message' => $e->getMessage(),
        ], 500);
    }
}

require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';

$initialNotice = '';

if (($_GET['spotify'] ?? '') === 'ok') {
    $initialNotice = 'Spotify Token wurde erneuert.';
} elseif (($_GET['spotify'] ?? '') === 'error') {
    $initialNotice = 'Spotify Reauth fehlgeschlagen.';
}
?>

<div class="spotify-token-page">
    <div class="container spotify-token-panel">
        <?php if ($initialNotice !== ''): ?>
            <div id="initialNotice" class="spotify-token-notice">
                <?= spotify_h($initialNotice) ?>
            </div>
        <?php endif; ?>

        <div id="countdownText" class="spotify-token-countdown">–</div>
        <div class="spotify-token-expiry">
            läuft ab am <strong id="expiresAt">–</strong>
        </div>

        <div class="modal-actions spotify-token-actions">
            <button type="button" id="startAuthBtn">Token erneuern</button>
            <button type="button" class="btn-secondary" id="testRefreshBtn">Token testen</button>
        </div>

        <div id="errorBox" class="spotify-token-error hidden">
            <div id="lastErrorCode" class="spotify-token-error-code">–</div>
            <div id="lastErrorMessage" class="spotify-token-error-message">–</div>
            <button type="button" class="btn-secondary" id="clearErrorBtn">Fehler löschen</button>
        </div>
    </div>

    <div class="container spotify-token-log-panel">
        <textarea id="cronLog" readonly class="spotify-token-log"></textarea>
    </div>
</div>

<script>
const SPOTIFY_CSRF = <?= json_encode($_SESSION['spotify_token_csrf'], JSON_UNESCAPED_SLASHES) ?>;

let lastStatus = null;

function qs(id) {
    return document.getElementById(id);
}

function setText(id, value) {
    const el = qs(id);
    if (el) el.textContent = value || '–';
}

function formatCountdown(seconds) {
    if (seconds === null || seconds === undefined) {
        return 'unbekannt';
    }

    seconds = Number(seconds);

    if (Number.isNaN(seconds)) {
        return 'unbekannt';
    }

    if (seconds <= 0) {
        return 'abgelaufen';
    }

    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);

    if (d >= 2) return `${d} Tage, ${h} Stunden`;
    if (d === 1) return `1 Tag, ${h} Stunden`;
    return `${h} Stunden`;
}

function formatGermanDateTime(value) {
    if (!value) return '–';

    const normalized = String(value).replace(' ', 'T');
    const date = new Date(normalized);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
}

function statusColor(status) {
    if (status === 'ok') return 'var(--primary)';
    if (status === 'soon') return '#d97706';
    if (status === 'unknown') return '#d97706';
    if (status === 'expired') return '#c0392b';
    if (status === 'missing') return '#c0392b';
    if (status === 'reauth') return '#c0392b';
    if (status === 'error') return '#c0392b';
    return 'var(--primary)';
}

async function api(action, extra = {}) {
    const body = new URLSearchParams();
    body.set('ajax', '1');
    body.set('action', action);
    body.set('csrf', SPOTIFY_CSRF);

    Object.entries(extra).forEach(([key, value]) => {
        body.set(key, value);
    });

    const res = await fetch(window.location.pathname, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        body,
    });

    let payload = null;

    try {
        payload = await res.json();
    } catch (e) {
        throw new Error('Server hat keine JSON-Antwort geliefert.');
    }

    if (!res.ok || !payload.ok) {
        throw new Error(payload.message || payload.error || 'Aktion fehlgeschlagen.');
    }

    return payload;
}

function renderStatus(data) {
    lastStatus = data;

    const color = statusColor(data.status);

    qs('countdownText').textContent = formatCountdown(data.db.seconds_until_expiry);
    qs('countdownText').style.color = color;

    setText('expiresAt', formatGermanDateTime(data.db.refresh_token_expires_at));

    qs('cronLog').value = data.log_tail || '';

    const errorBox = qs('errorBox');
    if (data.db.last_error_code || data.db.last_error_message) {
        errorBox.classList.remove('hidden');
        setText('lastErrorCode', data.db.last_error_code || 'Fehler');
        setText('lastErrorMessage', data.db.last_error_message || '–');
    } else {
        errorBox.classList.add('hidden');
    }
}

async function loadStatus() {
    try {
        const data = await api('status');
        renderStatus(data);
    } catch (e) {
        qs('countdownText').textContent = 'Fehler';
        qs('countdownText').style.color = '#c0392b';
        qs('expiresAt').textContent = '–';

        const errorBox = qs('errorBox');
        errorBox.classList.remove('hidden');
        setText('lastErrorCode', 'status_failed');
        setText('lastErrorMessage', e.message);
    }
}

async function startAuth() {
    const btn = qs('startAuthBtn');
    btn.disabled = true;
    btn.textContent = 'Spotify wird geöffnet…';

    try {
        const data = await api('start_auth');
        window.location.href = data.auth_url;
    } catch (e) {
        alert(e.message);
        btn.disabled = false;
        btn.textContent = 'Token erneuern';
    }
}

async function testRefresh() {
    const btn = qs('testRefreshBtn');
    btn.disabled = true;
    btn.textContent = 'Teste…';

    try {
        const data = await api('test_refresh');
        alert(data.message || 'Token funktioniert.');
        await loadStatus();
    } catch (e) {
        alert(e.message);
        await loadStatus();
    } finally {
        btn.disabled = false;
        btn.textContent = 'Token testen';
    }
}

async function clearError() {
    const btn = qs('clearErrorBtn');
    btn.disabled = true;

    try {
        await api('clear_error');
        await loadStatus();
    } catch (e) {
        alert(e.message);
    } finally {
        btn.disabled = false;
    }
}

qs('startAuthBtn').addEventListener('click', startAuth);
qs('testRefreshBtn').addEventListener('click', testRefresh);
qs('clearErrorBtn').addEventListener('click', clearError);

loadStatus();

setInterval(() => {
    if (lastStatus) {
        loadStatus();
    }
}, 60000);

setTimeout(() => {
    const notice = qs('initialNotice');
    if (notice) notice.remove();
}, 5000);
</script>