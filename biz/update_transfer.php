<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id']);
    $kat = is_numeric($_POST['kategorie_id']) ? intval($_POST['kategorie_id']) : null;

    $stmt = $bizconn->prepare("UPDATE transfers SET kategorie_id = ? WHERE id = ?");
    $stmt->bind_param('ii', $kat, $id);
    $stmt->execute();
    http_response_code(200);
    exit;
}
http_response_code(400);
