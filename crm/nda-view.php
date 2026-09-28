<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login();
ensure_customer_workspace_schema();
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) { http_response_code(404); exit('Το NDA δεν βρέθηκε.'); }
$statement = db()->prepare('SELECT d.*, c.name AS company_name FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=? AND d.document_type=\'nda\' AND c.deleted_at IS NULL LIMIT 1');
$statement->execute([$id]);
$document = $statement->fetch();
if (!$document) { http_response_code(404); exit('Το NDA δεν βρέθηκε.'); }

if ($document['final_signed_pdf']) {
    $content = (string)$document['final_signed_pdf'];
    $fileName = (string)($document['final_signed_file_name'] ?: 'final-signed-nda.pdf');
} elseif ($document['client_signed_pdf']) {
    $content = (string)$document['client_signed_pdf'];
    $fileName = (string)($document['client_signed_file_name'] ?: 'client-signed-nda.pdf');
} else {
    redirect_to('company-document.php?id=' . urlencode($id));
}
$fileName = preg_replace('/[\x00-\x1F\x7F]/u', '', $fileName) ?: 'nda.pdf';
header('Content-Type: application/pdf');
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($fileName));
header('Content-Length: ' . strlen($content));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $content;

