# FitPHP

Ein privates PHP-Tool zur Erfassung von Gewicht, Kalorienaufnahme und Training. Visualisierung über Chart.js. Keine Benutzerverwaltung. Nur für lokalen Eigengebrauch.

## Dateien

- Gewicht_New.php
- Kalorien_New.php
- Training_New.php
- Start.php
- header.php
- template.php
- db.php
- auth.php
- FIT.css

## Setup

1. MySQL-Datenbank anlegen:

    CREATE DATABASE fit;

2. Tabellen erstellen:

    USE fit;

    CREATE TABLE gewicht (
        id INT AUTO_INCREMENT PRIMARY KEY,
        gewicht DECIMAL(5,2) NOT NULL,
        tstamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE kalorien (
        id INT AUTO_INCREMENT PRIMARY KEY,
        beschreibung VARCHAR(255),
        kalorien INT NOT NULL,
        tstamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE training (
        id INT AUTO_INCREMENT PRIMARY KEY,
        beschreibung VARCHAR(255),
        kalorien INT NOT NULL,
        tstamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

3. Zugangsdaten in /etc/credentials/config.json eintragen:

    {
        "fitphp": {
            "host": "p:127.0.0.1",
            "user": "DEIN_USER",
            "password": "DEIN_PASSWORT",
            "database": "fit"
        }
    }

4. In auth.php IP-Whitelist eintragen

## Nutzung

- Start.php zeigt Diagramme für Netto-/Brutto-Kalorien und Gewicht
- Die anderen Seiten dienen zum Erfassen der jeweiligen Datenpunkte
