<?php
// Mapping: schöne URL => echte Datei
$routes = [
    ''         => 'Start.php',          // /fit/ → Start.php
    'stats'    => 'Start.php',
    'kalorien' => 'Kalorien_New.php',
    'gewicht'  => 'Gewicht_New.php',
    'pizza'    => 'Pizza.php',
    'training' => 'Training_New.php',
];

// aktuellen Pfad ermitteln (ohne Query-String)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// alles vor /fit/ abschneiden
$path = preg_replace('#^/fit/?#', '', $path);

// jetzt nur den slug (z.B. "gewicht") übrig
$route = trim($path, '/');

// passendes File laden
if (array_key_exists($route, $routes)) {
    require __DIR__ . '/' . $routes[$route];
} else {
    http_response_code(404);
    echo "Seite nicht gefunden.";
}
