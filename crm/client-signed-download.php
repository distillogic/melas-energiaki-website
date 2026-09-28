<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login();
ensure_customer_workspace_schema();
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) { http_response_code(404); exit('Το αρχείο δεν βρέθηκε.'); }
$statement = db()->prepare('SELECT d.client_signed_file_name, d.client_signed_pdf FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=? AND c.deleted_at IS NULL LIMIT 1');
$statement->execute([$id]);
$document = $statement->fetch();
if (!$document || !$document['client_signed_pdf']) { http_response_code(404); exit('Δεν έχει αποθηκευτεί υπογεγραμμένο PDF πελάτη.'); }
$fileName = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$document['client_signed_file_name']) ?: 'client-signed-nda.pdf';
header('Content-Type: application/pdf');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($fileName));
header('Content-Length: ' . strlen((string)$document['client_signed_pdf']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $document['client_signed_pdf'];

