<?php
// biz/get_transfer.php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $bizconn->prepare("
    SELECT id, kategorie_id, auftragskonto, buchungstag, valutadatum,
           buchungstext, verwendungszweck, zahlungspartner,
           iban, bic, betrag, waehrung, info
    FROM transfers
    WHERE id = ?
");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($row, JSON_UNESCAPED_UNICODE);
