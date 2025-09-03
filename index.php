<?php
// Pfad ermitteln (ohne Query-String)
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// --- Routing-Tabellen ---
$routesFit = [
    'start'         => 'Start.php',          // /fit/ → Start.php
    'stats'    => 'Start.php',
    'kalorien' => 'Kalorien_New.php',
    'gewicht'  => 'Gewicht_New.php',
    'pizza'    => 'Pizza.php',
    'training' => 'Training_New.php',
];

$routesBiz = [
    'start'         => 'Start.php',
    'stats'    => 'Start.php',
    'data'     => 'Data.php',
    'insert'   => 'Insert.php',
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

} elseif ($path === '' || $path === 'index.php') {
    // --- Startseite ---
    require_once 'template.php';
    ?>
    <main class="container">
        <div style="text-align: center;">
            <h1 class="ueberschrift">Willkommen auf meiner Webseite</h1>

            <p>
                Diese Seite dient ausschließlich privaten Zwecken. Alle weiteren Inhalte sind zugangsbeschränkt 
                und nur für den persönlichen Gebrauch vorgesehen.
            </p>

            <p>
                Falls du dich hierher verirrt hast: keine Sorge, hier gibt's nichts zu sehen :^)
            </p>
        </div>
    </main>

    <footer style="text-align: center; font-size: 0.8rem; margin-top: 2rem; color: #777;">
        <p>Dies ist eine rein private, nicht-kommerzielle Webseite.</p>
        <p>Verantwortlich gemäß § 55 Abs. 1 RStV:<br>Simon Fiji Krämer<br>Aachen</p>
    </footer>
    <?php

}
