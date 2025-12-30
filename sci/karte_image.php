<?php
// NEUE DATEI: sci/karte_image.php

require_once __DIR__ . '/../auth.php';

$pfad = $_GET['pfad'] ?? '';
if ($pfad === '') {
    http_response_code(400);
    exit('no path');
}

$relative = ltrim(str_replace('\\', '/', $pfad), '/');

// nur uploads/lernkarten zulassen
if (strpos($relative, 'uploads/lernkarten/') !== 0) {
    http_response_code(403);
    exit('forbidden');
}

$fullPath = __DIR__ . '/../' . $relative;
if (!is_file($fullPath)) {
    http_response_code(404);
    exit('not found');
}

$mime = mime_content_type($fullPath);
if ($mime === false) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;
