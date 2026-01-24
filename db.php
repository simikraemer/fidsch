<?php
// db.php

$config_path = '/work/credentials.json';

if (!file_exists($config_path)) {
    die('Konfigurationsdatei nicht gefunden.');
}

$config_data = json_decode(file_get_contents($config_path), true);


// Verbindung zur fit-Datenbank
if (!isset($config_data['fitphp'])) {
    die('fitphp-Konfiguration nicht gefunden.');
}
$fitconf = $config_data['fitphp'];
$fitconn = new mysqli($fitconf['host'], $fitconf['user'], $fitconf['password'], $fitconf['database']);
if ($fitconn->connect_error) {
    die('Verbindung zur FIT-Datenbank fehlgeschlagen: ' . $fitconn->connect_error);
}


// Verbindung zur biz-Datenbank
if (!isset($config_data['bizphp'])) {
    die('bizphp-Konfiguration nicht gefunden.');
}
$bizconf = $config_data['bizphp'];
$bizconn = new mysqli($bizconf['host'], $bizconf['user'], $bizconf['password'], $bizconf['database']);
if ($bizconn->connect_error) {
    die('Verbindung zur BIZ-Datenbank fehlgeschlagen: ' . $bizconn->connect_error);
}


// Verbindung zur sci-Datenbank
if (!isset($config_data['sciphp'])) {
    die('sciphp-Konfiguration nicht gefunden.');
}
$sciconf = $config_data['sciphp'];
$sciconn = new mysqli($sciconf['host'], $sciconf['user'], $sciconf['password'], $sciconf['database']);
if ($sciconn->connect_error) {
    die('Verbindung zur sci-Datenbank fehlgeschlagen: ' . $sciconn->connect_error);
}


// Verbindung zur check-Datenbank
if (!isset($config_data['checkphp'])) {
    die('checkphp-Konfiguration nicht gefunden.');
}
$checkconf = $config_data['checkphp'];
$checkconn = new mysqli($checkconf['host'], $checkconf['user'], $checkconf['password'], $checkconf['database']);
if ($checkconn->connect_error) {
    die('Verbindung zur check-Datenbank fehlgeschlagen: ' . $checkconn->connect_error);
}


// Verbindung zur login_audit-Datenbank (Login-Logging)
if (!isset($config_data['loginphp'])) {
    die('loginphp-Konfiguration nicht gefunden.');
}
$loginconf = $config_data['loginphp'];
$loginconn = new mysqli($loginconf['host'], $loginconf['user'], $loginconf['password'], $loginconf['database']);
if ($loginconn->connect_error) {
    die('Verbindung zur Login-DB fehlgeschlagen: ' . $loginconn->connect_error);
}
$loginconn->set_charset('utf8mb4');
