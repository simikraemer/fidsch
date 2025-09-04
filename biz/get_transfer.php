<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);

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
echo json_encode($res->fetch_assoc());
