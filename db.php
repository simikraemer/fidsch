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
