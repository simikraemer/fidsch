<?php
// db.php

$config_path = '/work/credentials.json';

if (!file_exists($config_path)) {
    die('Konfigurationsdatei nicht gefunden.');
}

$config_data = json_decode(file_get_contents($config_path), true);

if (!is_array($config_data)) {
    die('Konfigurationsdatei ist ungültig.');
}


/**
 * Liefert den Host für eine persistente mysqli-Verbindung.
 *
 * In credentials.json steht nur der eigentliche Host,
 * z. B. "127.0.0.1".
 *
 * Das PHP-/mysqli-spezifische "p:" wird ausschließlich
 * hier ergänzt.
 */
function mysqli_persistent_host(array $config): string
{
    if (!isset($config['host']) || $config['host'] === '') {
        die('Datenbank-Host fehlt in der Konfiguration.');
    }

    return 'p:' . $config['host'];
}


// Verbindung zur fit-Datenbank
if (!isset($config_data['fitphp'])) {
    die('fitphp-Konfiguration nicht gefunden.');
}

$fitconf = $config_data['fitphp'];

$fitconn = new mysqli(
    mysqli_persistent_host($fitconf),
    $fitconf['user'],
    $fitconf['password'],
    $fitconf['database']
);

if ($fitconn->connect_error) {
    die('Verbindung zur FIT-Datenbank fehlgeschlagen: ' . $fitconn->connect_error);
}


// Verbindung zur biz-Datenbank
if (!isset($config_data['bizphp'])) {
    die('bizphp-Konfiguration nicht gefunden.');
}

$bizconf = $config_data['bizphp'];

$bizconn = new mysqli(
    mysqli_persistent_host($bizconf),
    $bizconf['user'],
    $bizconf['password'],
    $bizconf['database']
);

if ($bizconn->connect_error) {
    die('Verbindung zur BIZ-Datenbank fehlgeschlagen: ' . $bizconn->connect_error);
}


// Verbindung zur sci-Datenbank
if (!isset($config_data['sciphp'])) {
    die('sciphp-Konfiguration nicht gefunden.');
}

$sciconf = $config_data['sciphp'];

$sciconn = new mysqli(
    mysqli_persistent_host($sciconf),
    $sciconf['user'],
    $sciconf['password'],
    $sciconf['database']
);

if ($sciconn->connect_error) {
    die('Verbindung zur sci-Datenbank fehlgeschlagen: ' . $sciconn->connect_error);
}


// Verbindung zur check-Datenbank
if (!isset($config_data['checkphp'])) {
    die('checkphp-Konfiguration nicht gefunden.');
}

$checkconf = $config_data['checkphp'];

$checkconn = new mysqli(
    mysqli_persistent_host($checkconf),
    $checkconf['user'],
    $checkconf['password'],
    $checkconf['database']
);

if ($checkconn->connect_error) {
    die('Verbindung zur check-Datenbank fehlgeschlagen: ' . $checkconn->connect_error);
}


// Verbindung zur login_audit-Datenbank
if (!isset($config_data['loginphp'])) {
    die('loginphp-Konfiguration nicht gefunden.');
}

$loginconf = $config_data['loginphp'];

$loginconn = new mysqli(
    mysqli_persistent_host($loginconf),
    $loginconf['user'],
    $loginconf['password'],
    $loginconf['database']
);

if ($loginconn->connect_error) {
    die('Verbindung zur Login-DB fehlgeschlagen: ' . $loginconn->connect_error);
}

$loginconn->set_charset('utf8mb4');


// Verbindung zur phan-Datenbank
if (!isset($config_data['phanphp'])) {
    die('phanphp-Konfiguration nicht gefunden.');
}

$phanconf = $config_data['phanphp'];

$phanconn = new mysqli(
    mysqli_persistent_host($phanconf),
    $phanconf['user'],
    $phanconf['password'],
    $phanconf['database']
);

if ($phanconn->connect_error) {
    die('Verbindung zur PHAN-Datenbank fehlgeschlagen: ' . $phanconn->connect_error);
}

$phanconn->set_charset('utf8mb4');


// Verbindung zur blog-Datenbank
if (!isset($config_data['blogphp'])) {
    die('blogphp-Konfiguration nicht gefunden.');
}

$blogconf = $config_data['blogphp'];

$blogconn = new mysqli(
    mysqli_persistent_host($blogconf),
    $blogconf['user'],
    $blogconf['password'],
    $blogconf['database']
);

if ($blogconn->connect_error) {
    die('Verbindung zur blog-DB fehlgeschlagen: ' . $blogconn->connect_error);
}

$blogconn->set_charset('utf8mb4');