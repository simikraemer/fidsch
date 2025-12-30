<?php
// biz/upload_transfer_document.php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltige Transfer-ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['document'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Kein Dokument hochgeladen'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file       = $_FILES['document'];
$tmpPath    = $file['tmp_name'] ?? '';
$fileSize   = (int)($file['size'] ?? 0);
$maxSize    = 8 * 1024 * 1024; // 8 MB
$allowedMap = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp'
];

$uploadErr  = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
$uploadErrMap = [
    UPLOAD_ERR_OK         => null,
    UPLOAD_ERR_INI_SIZE   => 'Datei ueberschreitet upload_max_filesize.',
    UPLOAD_ERR_FORM_SIZE  => 'Datei ueberschreitet MAX_FILE_SIZE im Formular.',
    UPLOAD_ERR_PARTIAL    => 'Upload wurde nur teilweise uebertragen.',
    UPLOAD_ERR_NO_FILE    => 'Keine Datei uebertragen.',
    UPLOAD_ERR_NO_TMP_DIR => 'Kein temporaeres Verzeichnis auf dem Server.',
    UPLOAD_ERR_CANT_WRITE => 'Server konnte die Datei nicht schreiben.',
    UPLOAD_ERR_EXTENSION  => 'Upload durch PHP-Erweiterung abgebrochen.'
];

if ($uploadErr !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => $uploadErrMap[$uploadErr] ?? 'Upload fehlgeschlagen'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$tmpPath || !is_uploaded_file($tmpPath)) {
    http_response_code(400);
    echo json_encode(['error' => 'Kein Dokument hochgeladen'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($fileSize <= 0 || $fileSize > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'Datei zu gross (max. 8 MB)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? finfo_file($finfo, $tmpPath) : null;
if ($finfo) finfo_close($finfo);

if (!$mime || !isset($allowedMap[$mime])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nur PDF oder Bilddateien erlaubt'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ext       = $allowedMap[$mime];
$uploadDir = __DIR__ . '/../uploads/transfers';

if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'Upload-Verzeichnis nicht schreibbar'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadWritable = is_writable($uploadDir) || @chmod($uploadDir, 0775);
if (!$uploadWritable) {
    http_response_code(500);
    echo json_encode(['error' => 'Upload-Verzeichnis nicht schreibbar', 'path' => $uploadDir], JSON_UNESCAPED_UNICODE);
    exit;
}

// Vorhandene Datei fuer diesen Transfer entfernen (alle erlaubten Extensions)
foreach (array_unique(array_values($allowedMap)) as $candidateExt) {
    $candidate = $uploadDir . "/transfer_{$id}.{$candidateExt}";
    if (is_file($candidate)) {
        @unlink($candidate);
    }
}

$targetPath = $uploadDir . "/transfer_{$id}.{$ext}";

$moved = move_uploaded_file($tmpPath, $targetPath);
if (!$moved) {
    // Fallback wenn move_uploaded_file blockiert (z. B. Rechte oder open_basedir)
    $moved = (@rename($tmpPath, $targetPath) || (@copy($tmpPath, $targetPath) && @unlink($tmpPath)));
}

if (!$moved) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Speichern fehlgeschlagen',
        'hint'  => "Bitte Schreibrechte pruefen: {$uploadDir} (Ziel: {$targetPath})",
        'upload_dir_writable' => is_writable($uploadDir),
        'tmp_readable'        => is_readable($tmpPath),
        'target_exists'       => file_exists($targetPath),
        'last_error'          => error_get_last()['message'] ?? null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'url'     => "transfer_document.php?id={$id}"
], JSON_UNESCAPED_UNICODE);
?>
