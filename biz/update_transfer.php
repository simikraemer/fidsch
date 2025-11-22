<?php
// biz/update_transfer.php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$id  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$kat = (isset($_POST['kategorie_id']) && $_POST['kategorie_id'] !== '') ? (int)$_POST['kategorie_id'] : null;

if ($id <= 0) {
    http_response_code(400);
    exit;
}

$stmt = $bizconn->prepare("UPDATE transfers SET kategorie_id = ? WHERE id = ?");
$stmt->bind_param('ii', $kat, $id);
$stmt->execute();

http_response_code(204);
