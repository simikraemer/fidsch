<?php
// auth.php

$config_path = '/work/credentials.json';
$credentials = json_decode(file_get_contents($config_path), true)['webpw'];

$valid_user = $credentials['username'];
$valid_pass = $credentials['password'];
$allowed_ips = $credentials['allowed_ips'] ?? [];

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

// IP innerhalb von 10.2.10.0/24 erlauben
function ip_in_subnet($ip, $subnet) {
    list($subnet_ip, $mask_bits) = explode('/', $subnet);
    $ip_dec = ip2long($ip);
    $subnet_dec = ip2long($subnet_ip);
    $mask = -1 << (32 - $mask_bits);
    return ($ip_dec & $mask) === ($subnet_dec & $mask);
}

if (
    !in_array($client_ip, $allowed_ips) &&
    !ip_in_subnet($client_ip, '10.2.10.0/24')
) {
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

    // Mit fail2ban Logüberwachung wird BruteForcing verhindert
    if ($user !== $valid_user || $pass !== $valid_pass) {
        header('WWW-Authenticate: Basic realm="FitnessTracker Login"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Zugriff verweigert.';
        exit;
    }
}
