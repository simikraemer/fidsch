<?php
// db.php

$config_path = '/work/credentials.json';

if (!file_exists($config_path)) {
    die('Konfigurationsdatei nicht gefunden.');
}

$config_data = json_decode(file_get_contents($config_path), true);

if (!isset($config_data['fidschphp'])) {
    die('fidschphp-Konfiguration nicht gefunden.');
}

$dbconf = $config_data['fidschphp'];

$mysqli = new mysqli($dbconf['host'], $dbconf['user'], $dbconf['password'], $dbconf['database']);

if ($mysqli->connect_error) {
    die('Datenbankverbindung fehlgeschlagen: ' . $mysqli->connect_error);
}
