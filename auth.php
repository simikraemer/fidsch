<?php
// auth.php

require_once __DIR__ . '/db.php'; // stellt $loginconn bereit

$config_path = '/work/credentials.json';

$config_raw = @file_get_contents($config_path);
if ($config_raw === false) {
    error_log("auth: credentials.json konnte nicht gelesen werden");
    http_response_code(500);
    exit('Authentifizierung nicht verfügbar.');
}

$config_all = json_decode($config_raw, true);
if (!is_array($config_all)) {
    error_log("auth: credentials.json enthält kein gültiges JSON");
    http_response_code(500);
    exit('Authentifizierung nicht verfügbar.');
}

$credentials = $config_all['webpw'] ?? [];

if (!is_array($credentials)) {
    error_log("auth: webpw-Konfiguration fehlt oder ist ungültig");
    http_response_code(500);
    exit('Authentifizierung nicht verfügbar.');
}

$valid_user = (string)($credentials['username'] ?? '');
$valid_pass = (string)($credentials['password'] ?? '');

$allowed_ips = $credentials['allowed_ips'] ?? [];
$allowed_subnets = $credentials['allowed_subnet'] ?? [];

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

/*
 * Sicherheitsrelevant:
 * Wenn Benutzername oder Passwort in der Konfiguration fehlen,
 * niemals mit leeren Credentials "fail open" authentifizieren.
 */
if ($valid_user === '' || $valid_pass === '') {
    error_log("auth: username/password in webpw fehlen");
    http_response_code(500);
    exit('Authentifizierung nicht verfügbar.');
}

if (!filter_var($client_ip, FILTER_VALIDATE_IP)) {
    error_log("auth: ungültige REMOTE_ADDR");
    http_response_code(403);
    exit('Zugriff verweigert.');
}

/*
 * Konfiguration normalisieren.
 */
if (!is_array($allowed_ips)) {
    $allowed_ips = [];
}

$allowed_ips = array_values(array_filter(
    $allowed_ips,
    static fn($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)
));

/*
 * allowed_subnet darf zur Abwärtskompatibilität entweder
 * ein einzelner String oder ein Array von Subnetzen sein.
 */
if (is_string($allowed_subnets) && $allowed_subnets !== '') {
    $allowed_subnets = [$allowed_subnets];
} elseif (!is_array($allowed_subnets)) {
    $allowed_subnets = [];
}


/* --------------------------------------------------------------------------
 * Helpers
 * -------------------------------------------------------------------------- */

function ip_in_subnet(string $ip, string $subnet): bool
{
    /*
     * Die bestehende Subnet-Logik ist bewusst IPv4-only.
     */
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }

    if (strpos($subnet, '/') === false) {
        return false;
    }

    [$subnet_ip, $mask_bits_raw] = explode('/', $subnet, 2);

    if (!filter_var($subnet_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }

    if ($mask_bits_raw === '' || !ctype_digit($mask_bits_raw)) {
        return false;
    }

    $mask_bits = (int)$mask_bits_raw;

    if ($mask_bits < 0 || $mask_bits > 32) {
        return false;
    }

    $ip_dec = ip2long($ip);
    $subnet_dec = ip2long($subnet_ip);

    if ($ip_dec === false || $subnet_dec === false) {
        return false;
    }

    if ($mask_bits === 0) {
        return true;
    }

    $mask = -1 << (32 - $mask_bits);

    return (($ip_dec & $mask) === ($subnet_dec & $mask));
}


function client_ip_is_whitelisted(
    string $client_ip,
    array $allowed_ips,
    array $allowed_subnets
): bool {
    if (in_array($client_ip, $allowed_ips, true)) {
        return true;
    }

    foreach ($allowed_subnets as $subnet) {
        if (!is_string($subnet) || $subnet === '') {
            continue;
        }

        if (ip_in_subnet($client_ip, $subnet)) {
            return true;
        }
    }

    return false;
}


function log_login_event($loginconn, array $e): void
{
    if (!($loginconn instanceof mysqli)) {
        return;
    }

    $sql = "
        INSERT INTO login_events
        (
            username,
            auth_mode,
            success,
            http_status,
            client_ip,
            x_forwarded_for,
            session_id,
            host,
            request_uri,
            referer,
            user_agent
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            INET6_ATON(?),
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ";

    $stmt = $loginconn->prepare($sql);

    if (!$stmt) {
        error_log("login logger: prepare failed: " . $loginconn->error);
        return;
    }

    $username = $e['username'] ?? null;
    $auth_mode = $e['auth_mode'] ?? 'pw';
    $success = (int)($e['success'] ?? 0);
    $http_status = $e['http_status'] ?? null;

    $event_client_ip = $e['client_ip'] ?? '0.0.0.0';
    $xff = $e['x_forwarded_for'] ?? null;
    $session_id = $e['session_id'] ?? null;

    $host = $e['host'] ?? null;
    $request_uri = $e['request_uri'] ?? null;
    $referer = $e['referer'] ?? null;
    $user_agent = $e['user_agent'] ?? null;

    $stmt->bind_param(
        "ssiisssssss",
        $username,
        $auth_mode,
        $success,
        $http_status,
        $event_client_ip,
        $xff,
        $session_id,
        $host,
        $request_uri,
        $referer,
        $user_agent
    );

    if (!$stmt->execute()) {
        error_log("login logger: execute failed: " . $stmt->error);
    }

    $stmt->close();
}


/*
 * Brute-Force-Schutz für NICHT-whitelistete IPs.
 *
 * Regel:
 *   - maximal 5 fehlgeschlagene Passwort-Logins
 *   - innerhalb von 10 Minuten
 *   - danach HTTP 429
 *
 * Die IP-Whitelist durchläuft diese Funktion überhaupt nicht.
 *
 * Rückgabe:
 *   [
 *       'ok'          => bool,   // DB-Abfrage erfolgreich
 *       'limited'     => bool,
 *       'fail_count'  => int,
 *       'retry_after' => int
 *   ]
 */
function login_rate_limit_status(mysqli $conn, string $client_ip): array
{
    $sql = "
        SELECT
            COUNT(*) AS fail_count,
            MIN(event_time) AS first_failure
        FROM login_events
        WHERE client_ip = INET6_ATON(?)
          AND auth_mode = 'pw'
          AND success = 0
          AND event_time >= (NOW(6) - INTERVAL 10 MINUTE)
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("login rate limiter: prepare failed: " . $conn->error);

        return [
            'ok' => false,
            'limited' => true,
            'fail_count' => 0,
            'retry_after' => 60,
        ];
    }

    $stmt->bind_param("s", $client_ip);

    if (!$stmt->execute()) {
        error_log("login rate limiter: execute failed: " . $stmt->error);
        $stmt->close();

        return [
            'ok' => false,
            'limited' => true,
            'fail_count' => 0,
            'retry_after' => 60,
        ];
    }

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;

    $stmt->close();

    if (!$row) {
        error_log("login rate limiter: kein Ergebnis");

        return [
            'ok' => false,
            'limited' => true,
            'fail_count' => 0,
            'retry_after' => 60,
        ];
    }

    $fail_count = (int)($row['fail_count'] ?? 0);

    $retry_after = 1;

    if (
        $fail_count >= 5 &&
        !empty($row['first_failure'])
    ) {
        $first_failure = strtotime((string)$row['first_failure']);

        if ($first_failure !== false) {
            $elapsed = time() - $first_failure;
            $retry_after = max(1, 600 - $elapsed);
        }
    }

    return [
        'ok' => true,
        'limited' => ($fail_count >= 5),
        'fail_count' => $fail_count,
        'retry_after' => $retry_after,
    ];
}


/* --------------------------------------------------------------------------
 * Authentifizierung
 *
 * 1. Whitelist:
 *      direkt erlauben
 *
 * 2. Andere IPs:
 *      Brute-Force-Limit prüfen
 *
 * 3. Basic Auth:
 *      Credentials prüfen
 *
 * 4. Erfolg / Fehler:
 *      in login_events protokollieren
 * -------------------------------------------------------------------------- */


/*
 * 1) IP-Whitelist
 *
 * Bewusst VOR dem Rate-Limiter:
 * vertraute IPs werden weder durch Fehlversuche noch durch einen
 * möglicherweise ausgefallenen Login-Log-DB-Zugriff ausgesperrt.
 */
$is_whitelisted = client_ip_is_whitelisted(
    $client_ip,
    $allowed_ips,
    $allowed_subnets
);

if ($is_whitelisted) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['is_authed'] = true;
    $_SESSION['auth_mode'] = 'ip';

    /*
     * Nur einmal pro Session loggen.
     */
    if (empty($_SESSION['login_logged'])) {
        log_login_event($loginconn, [
            'username' => $valid_user ?: null,
            'auth_mode' => 'ip',
            'success' => 1,
            'http_status' => 200,
            'client_ip' => $client_ip,
            'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            'session_id' => session_id(),
            'host' => $_SERVER['HTTP_HOST'] ?? null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        $_SESSION['login_logged'] = true;
    }

    return;
}


/*
 * 2) Rate-Limit für alle NICHT-whitelisteten IPs
 *
 * REMOTE_ADDR ist absichtlich die maßgebliche IP.
 * X-Forwarded-For wird NICHT für Sicherheitsentscheidungen verwendet.
 */
if (!($loginconn instanceof mysqli)) {
    /*
     * High-Security-Verhalten:
     * Wenn der Rate-Limiter seine DB nicht prüfen kann, wird bei
     * nicht-whitelisteten IPs nicht einfach unlimitiert weitergemacht.
     */
    error_log("auth: loginconn nicht verfügbar; externe Auth wird fail-closed abgewiesen");

    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    echo 'Authentifizierung vorübergehend nicht verfügbar.';
    exit;
}

$rate_limit = login_rate_limit_status($loginconn, $client_ip);

if (!$rate_limit['ok']) {
    http_response_code(503);
    header('Retry-After: 60');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    echo 'Authentifizierung vorübergehend nicht verfügbar.';
    exit;
}

if ($rate_limit['limited']) {
    http_response_code(429);
    header('Retry-After: ' . $rate_limit['retry_after']);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    echo 'Zu viele fehlgeschlagene Anmeldeversuche. Bitte später erneut versuchen.';
    exit;
}


/*
 * 3) Basic Auth
 */
$user = isset($_SERVER['PHP_AUTH_USER'])
    ? (string)$_SERVER['PHP_AUTH_USER']
    : '';

$pass = isset($_SERVER['PHP_AUTH_PW'])
    ? (string)$_SERVER['PHP_AUTH_PW']
    : '';

$credentials_supplied =
    array_key_exists('PHP_AUTH_USER', $_SERVER) ||
    array_key_exists('PHP_AUTH_PW', $_SERVER);

$user_ok = hash_equals($valid_user, $user);
$pass_ok = hash_equals($valid_pass, $pass);

if (!$user_ok || !$pass_ok) {
    /*
     * Die initiale Browser-Challenge ohne Credentials wird nicht als
     * Fehlversuch gewertet.
     *
     * Erst wenn der Client tatsächlich Benutzername/Passwort sendet,
     * entsteht ein login_events-Fehler und zählt damit zum Rate-Limit.
     */
    if ($credentials_supplied) {
        log_login_event($loginconn, [
            'username' => $user !== '' ? $user : null,
            'auth_mode' => 'pw',
            'success' => 0,
            'http_status' => 401,
            'client_ip' => $client_ip,
            'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            'session_id' => null,
            'host' => $_SERVER['HTTP_HOST'] ?? null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    header('WWW-Authenticate: Basic realm="FitnessTracker Login"');
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    echo 'Zugriff verweigert.';
    exit;
}


/*
 * 4) Erfolgreicher Passwort-Login
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['is_authed'] = true;
$_SESSION['auth_mode'] = 'pw';

/*
 * Nur einmal pro Session loggen.
 */
if (empty($_SESSION['login_logged'])) {
    log_login_event($loginconn, [
        'username' => $user !== '' ? $user : ($valid_user ?: null),
        'auth_mode' => 'pw',
        'success' => 1,
        'http_status' => 200,
        'client_ip' => $client_ip,
        'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        'session_id' => session_id(),
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        'referer' => $_SERVER['HTTP_REFERER'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);

    $_SESSION['login_logged'] = true;
}