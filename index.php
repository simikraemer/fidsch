<?php
// index.php
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// --- Routing-Tabellen ---
$routesFit = [
    'start'    => 'Start.php',          // /fit/ → Start.php
    'stats'    => 'Start.php',
    'kalorien' => 'Kalorien_New.php',
    'kalorien_data' => 'Kalorien_Data.php',
    'gewicht'  => 'Gewicht_New.php',
    'pizza'    => 'Pizza.php',
    'training' => 'Training_New.php',
];

$routesBiz = [
    'start'    => 'Start.php',
    'stats'    => 'Start.php',
    'data'     => 'Data.php',
    'insert'   => 'Insert.php',
];

$routesSci = [
    'start'    => 'Lerntime.php',
    'fragen'   => 'Fragen.php',
    'data'     => 'Data.php',
    'insert'   => 'Insert.php',
    'lerntime' => 'Lerntime.php',
];

$routesCheck = [
    'start'    => 'ToDo.php',
    'todo'     => 'ToDo.php',
];

$routestool = [
    'mac'         => 'MAC.php',
    'path'    => 'PATH.php',
    'bit'     => 'BYTES.php',
    'ips'     => 'IPS.php',
    'unixtime'     => 'UNIXTIME.php',
    'log'     => 'LoginLogs.php',
    'timer'   => 'TIMER.php'
];

// --- Routing-Logik ---
if (str_starts_with($path, 'fit')) {
    $slug = trim(preg_replace('#^fit/?#', '', $path), '/');
    if (array_key_exists($slug, $routesFit)) {
        require __DIR__ . '/fit/' . $routesFit[$slug];
    } else {
        http_response_code(404);
        echo "Seite nicht gefunden.";
    }

} elseif (str_starts_with($path, 'biz')) {
    $slug = trim(preg_replace('#^biz/?#', '', $path), '/');
    if (array_key_exists($slug, $routesBiz)) {
        require __DIR__ . '/biz/' . $routesBiz[$slug];
    } else {
        http_response_code(404);
        echo "Seite nicht gefunden.";
    }

} elseif (str_starts_with($path, 'sci')) {
    $slug = trim(preg_replace('#^sci/?#', '', $path), '/');
    if (array_key_exists($slug, $routesSci)) {
        require __DIR__ . '/sci/' . $routesSci[$slug];
    } else {
        http_response_code(404);
        echo "Seite nicht gefunden.";
    }

} elseif (str_starts_with($path, 'check')) {
    $slug = trim(preg_replace('#^check/?#', '', $path), '/');
    if (array_key_exists($slug, $routesCheck)) {
        require __DIR__ . '/check/' . $routesCheck[$slug];
    } else {
        http_response_code(404);
        echo "Seite nicht gefunden.";
    }

} elseif (str_starts_with($path, 'tools')) {
    $slug = trim(preg_replace('#^tools/?#', '', $path), '/');
    if (array_key_exists($slug, $routestool)) {
        require __DIR__ . '/tools/' . $routestool[$slug];
    } else {
        http_response_code(404);
        echo "Seite nicht gefunden.";
    }

} elseif ($path === '' || $path === 'index.php') {
    // Rendering starten
    $page_title = 'Fidsch';
    require_once __DIR__ . '/head.php';      // <!DOCTYPE html> … <body>
    require_once __DIR__ . '/navbar.php';    // nur die Navbar
    ?>
    <main class="container">
        <div style="text-align: center;">
            <h1 class="ueberschrift">Private Webseite</h1>

            <p>
                Diese Seite ist eine private, nicht-öffentliche Webanwendung und richtet sich ausschließlich an
                autorisierte Nutzer. Inhalte, Funktionen und Daten sind nicht für die allgemeine Nutzung bestimmt.
            </p>

            <p>
                Falls du hier gelandet bist, ohne Zugriff zu haben, kannst du diese Seite einfach schließen.
                Ein Login bzw. Zugriff ist nur für berechtigte Personen vorgesehen.
            </p>

            <p style="opacity: .8; font-size: .95rem; margin-top: 1rem;">
                Hinweis: Unautorisierte Zugriffsversuche werden protokolliert.
            </p>
        </div>
    </main>
    </body>
    </html>
    <?php
}
