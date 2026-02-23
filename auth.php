<?php
// auth.php

require_once __DIR__ . '/db.php'; // stellt $loginconn bereit

$config_path  = '/work/credentials.json';
$config_all   = json_decode(file_get_contents($config_path), true);
$credentials  = $config_all['webpw'] ?? [];

$valid_user   = $credentials['username'] ?? '';
$valid_pass   = $credentials['password'] ?? '';
$allowed_ips  = $credentials['allowed_ips'] ?? [];
$allowed_subnet = $credentials['allowed_subnet'] ?? [];
$client_ip    = $_SERVER['REMOTE_ADDR'] ?? '';

function ip_in_subnet(string $ip, string $subnet): bool
{
    // defensiv: nur IPv4/Subnet IPv4 (deine Subnet-Logik ist IPv4-only)
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    if (strpos($subnet, '/') === false) return false;

    [$subnet_ip, $mask_bits] = explode('/', $subnet, 2);
    if (!filter_var($subnet_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;

    $ip_dec     = ip2long($ip);
    $subnet_dec = ip2long($subnet_ip);
    $mask_bits  = (int)$mask_bits;
    if ($mask_bits < 0 || $mask_bits > 32) return false;

    $mask = -1 << (32 - $mask_bits);
    return (($ip_dec & $mask) === ($subnet_dec & $mask));
}

function log_login_event($loginconn, array $e): void
{
    if (!($loginconn instanceof mysqli)) {
        return;
    }

    $sql = "INSERT INTO login_events
            (username, auth_mode, success, http_status, client_ip, x_forwarded_for, session_id, host, request_uri, referer, user_agent)
            VALUES
            (?, ?, ?, ?, INET6_ATON(?), ?, ?, ?, ?, ?, ?)";

    $stmt = $loginconn->prepare($sql);
    if (!$stmt) {
        error_log("login logger: prepare failed: " . $loginconn->error);
        return;
    }

    $username     = $e['username'] ?? null;
    $auth_mode    = $e['auth_mode'] ?? 'pw';
    $success      = (int)($e['success'] ?? 0);
    $http_status  = $e['http_status'] ?? null;

    $client_ip    = $e['client_ip'] ?? '0.0.0.0';
    $xff          = $e['x_forwarded_for'] ?? null;
    $session_id   = $e['session_id'] ?? null;

    $host         = $e['host'] ?? null;
    $request_uri  = $e['request_uri'] ?? null;
    $referer      = $e['referer'] ?? null;
    $user_agent   = $e['user_agent'] ?? null;

    // 11 params total, types:
    // username(s) auth_mode(s) success(i) http_status(i) client_ip(s) xff(s) session_id(s) host(s) request_uri(s) referer(s) user_agent(s)
    $stmt->bind_param(
        "ssiisssssss",
        $username,
        $auth_mode,
        $success,
        $http_status,
        $client_ip,
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
 * 1) IP-Whitelist: direkt erlauben
 * 2) sonst Basic Auth (401 challengen)
 * 3) Erfolg/Fehler loggen (einmal pro Session)
 */

// IP-Whitelist: direkt durchlassen und Modus merken
if (in_array($client_ip, $allowed_ips, true) || ($allowed_subnet && ip_in_subnet($client_ip, $allowed_subnet))) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    $_SESSION['is_authed'] = true;
    $_SESSION['auth_mode'] = 'ip';

    // nur einmal pro Session loggen
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

// Basic-Auth prüfen
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW'] ?? '';

if ($user !== $valid_user || $pass !== $valid_pass) {

    // nur loggen, wenn überhaupt Credentials geliefert wurden (sonst Erst-Challenge nicht spammen)
    if (isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW'])) {
        log_login_event($loginconn, [
            'username' => $user ?: null,
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
    header('HTTP/1.0 401 Unauthorized');
    echo 'Zugriff verweigert.';
    exit;
}

// Erfolg: Passwort-Login in Session vermerken
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$_SESSION['is_authed'] = true;
$_SESSION['auth_mode'] = 'pw';

// nur einmal pro Session loggen
if (empty($_SESSION['login_logged'])) {
    log_login_event($loginconn, [
        'username' => $user ?: ($valid_user ?: null),
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
