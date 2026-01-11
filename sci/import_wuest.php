<?php
// sci/import_wuest.php
// CLI Import: Wärme- und Stoffübertragung (Einheit + Dauer), sort_key in Eingabereihenfolge

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript ist nur für CLI gedacht.\n");
    exit(1);
}

require_once __DIR__ . '/../db.php'; // muss $sciconn bereitstellen
if (!isset($sciconn) || !($sciconn instanceof mysqli)) {
    fwrite(STDERR, "DB-Handle \$sciconn nicht gefunden. Prüfe ../db.php\n");
    exit(1);
}
$sciconn->set_charset('utf8mb4');

$FACH = 'Wärme- und Stoffübertragung';

// Deine Daten (Reihenfolge bleibt exakt so)
$raw = <<<TXT
L01	0:16:44
L02	0:30:09
L03	0:09:43
L04	0:13:12
L05	0:14:31
L06	0:16:18
L07	0:11:53
L08	0:09:59
VÜ01	0:23:26
L09	0:16:40
VÜ02	0:17:41
L10	0:14:58
L11	0:14:26
L12	0:20:37
L13	0:16:33
VÜ03	0:26:50
L14	0:23:00
L15	0:10:40
L16	0:36:56
L17	0:26:02
VÜ04	0:25:56
VÜ05	0:22:27
L01	0:33:46
L02	0:12:39
L03	0:20:06
L04	0:09:40
L05	0:15:43
L06	0:25:50
L07	0:31:09
VÜ01	0:26:51
VÜ02	0:27:55
L08	0:11:40
L09	0:06:49
VÜ03_1	0:07:34
VÜ03_2	0:06:46
L01	00:24:34
L02	00:21:48
L03	00:12:43
VÜ01	00:15:55
VÜ01_1	00:23:53
L04	00:17:42
L05	00:12:56
L06	00:13:05
L07	00:11:28
L08	00:13:00
VÜ02	00:11:45
L09	00:07:15
L10	00:08:07
L11	00:16:49
VÜ03	tba
L12	00:12:43
L01	00:25:45
L02	00:10:11
L03	00:09:32
L04	00:13:06
L05	00:21:10
VÜ01	tba
L06	00:16:26
L07	00:12:23
L08	00:17:10
VÜ02	tba
VÜ03	tba
TXT;

function parseDurationToSeconds(string $s): int {
    $s = trim($s);
    if ($s === '') return 0;

    if (strtolower($s) === 'tba') {
        return 0; // 0h pauschal
    }

    // akzeptiert "H:MM:SS" oder "HH:MM:SS" oder "MM:SS"
    $parts = explode(':', $s);
    if (count($parts) === 3) {
        $h = (int)$parts[0];
        $m = (int)$parts[1];
        $sec = (int)$parts[2];
        return max(0, ($h * 3600) + ($m * 60) + $sec);
    }
    if (count($parts) === 2) {
        $m = (int)$parts[0];
        $sec = (int)$parts[1];
        return max(0, ($m * 60) + $sec);
    }

    // Fallback: nur Sekunden
    return max(0, (int)$s);
}

// max(sort_key) für Fach holen, damit wir sauber hinten anhängen
$maxSort = 0.0;
$stmtMax = $sciconn->prepare("SELECT COALESCE(MAX(sort_key), 0) AS m FROM lerntime WHERE fach = ?");
$stmtMax->bind_param('s', $FACH);
$stmtMax->execute();
$resMax = $stmtMax->get_result();
if ($resMax && ($r = $resMax->fetch_assoc())) {
    $maxSort = (float)$r['m'];
}
$stmtMax->close();

$startSort = $maxSort + 1000.0;

// Insert vorbereiten
$stmtIns = $sciconn->prepare("
    INSERT INTO lerntime (fach, einheit, titel, notiz, dauer_sekunden, erledigt_am, sort_key)
    VALUES (?, ?, ?, NULL, ?, NULL, ?)
");
if (!$stmtIns) {
    fwrite(STDERR, "Prepare failed: " . $sciconn->error . "\n");
    exit(1);
}

$lines = preg_split('/\R+/', trim($raw));
$rows = [];

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;

    // split by whitespace/tab
    $parts = preg_split('/\s+/', $line);
    if (!$parts || count($parts) < 2) {
        fwrite(STDERR, "Überspringe ungültige Zeile: {$line}\n");
        continue;
    }

    $einheit = trim($parts[0]);
    $timeStr = trim($parts[1]);

    $dur = parseDurationToSeconds($timeStr);

    // titel erstmal placeholder (hier: Einheitskennung), damit NOT NULL erfüllt ist
    $titel = "";

    $rows[] = [$einheit, $titel, $dur];
}

if (count($rows) === 0) {
    fwrite(STDERR, "Keine gültigen Zeilen gefunden.\n");
    exit(1);
}

// Transaktion
$sciconn->begin_transaction();

try {
    $sort = $startSort;

    foreach ($rows as $i => [$einheit, $titel, $dur]) {
        $sortStr = number_format($sort, 10, '.', ''); // DECIMAL stabil

        // fach(s), einheit(s), titel(s), dauer(i), sort_key(s)
        $stmtIns->bind_param('sssis', $FACH, $einheit, $titel, $dur, $sortStr);

        if (!$stmtIns->execute()) {
            throw new RuntimeException("Insert failed (Zeile " . ($i+1) . " / {$einheit}): " . $stmtIns->error);
        }

        $sort += 1000.0;
    }

    $sciconn->commit();
    $stmtIns->close();

    fwrite(STDOUT, "OK: " . count($rows) . " Einträge für '{$FACH}' eingefügt.\n");
    fwrite(STDOUT, "sort_key Start: " . number_format($startSort, 10, '.', '') . "\n");
} catch (Throwable $e) {
    $sciconn->rollback();
    $stmtIns->close();
    fwrite(STDERR, "ROLLBACK: " . $e->getMessage() . "\n");
    exit(1);
}
