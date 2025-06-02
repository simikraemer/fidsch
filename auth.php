<?php
// auth.php

$config_path = '/etc/credentials/config.json';
$credentials = json_decode(file_get_contents($config_path), true)['fitpw'];

$valid_user = $credentials['username'];
$valid_pass = $credentials['password'];

$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW'] ?? '';

if ($user !== $valid_user || $pass !== $valid_pass) {
    header('WWW-Authenticate: Basic realm="FitnessTracker Login"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Zugriff verweigert.';
    exit;
}
