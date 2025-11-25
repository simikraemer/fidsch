<?php
// biz/transfer_document.php
require_once __DIR__ . '/../auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid request');
}

$uploadDir = __DIR__ . '/../uploads/transfers';
$allowed   = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
$filePath  = null;
$foundExt  = null;

foreach ($allowed as $ext) {
    $candidate = $uploadDir . "/transfer_{$id}.{$ext}";
    if (is_file($candidate)) {
        $filePath = $candidate;
        $foundExt = $ext;
        break;
    }
}

if (!$filePath) {
    http_response_code(404);
    exit('Not found');
}

$mimeMap = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp'
];
$mime = $mimeMap[strtolower($foundExt)] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="transfer-' . $id . '.' . $foundExt . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=0, no-cache');

readfile($filePath);
exit;
