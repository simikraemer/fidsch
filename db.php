<?php
// db.php

$config_path = '/etc/credentials/config.json';

if (!file_exists($config_path)) {
    die('Konfigurationsdatei nicht gefunden.');
}

$config_data = json_decode(file_get_contents($config_path), true);

if (!isset($config_data['fitphp'])) {
    die('fitphp-Konfiguration nicht gefunden.');
}

$dbconf = $config_data['fitphp'];

$mysqli = new mysqli($dbconf['host'], $dbconf['user'], $dbconf['password'], $dbconf['database']);

if ($mysqli->connect_error) {
    die('Datenbankverbindung fehlgeschlagen: ' . $mysqli->connect_error);
}
