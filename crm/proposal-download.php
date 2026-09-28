<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();
$id = trim((string)($_GET['id'] ?? ''));
$statement = db()->prepare("SELECT d.* FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=? AND d.document_type='proposal' AND c.deleted_at IS NULL LIMIT 1");
$statement->execute([$id]);
$proposal = $statement->fetch();
if (!$proposal) { http_response_code(404); exit('Η πρόταση δεν βρέθηκε.'); }
$wantPdf = ($_GET['pdf'] ?? '') === '1';
if ($wantPdf) {
    if (!$proposal['final_signed_pdf']) { http_response_code(404); exit('Δεν έχει αποθηκευτεί τελικό PDF.'); }
    $fileName = (string)($proposal['final_signed_file_name'] ?: $proposal['document_reference'] . '.pdf');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $fileName) . '"');
    header('Content-Length: ' . strlen((string)$proposal['final_signed_pdf']));
    echo $proposal['final_signed_pdf'];
    exit;
}
$fileName = preg_replace('/[^A-Za-z0-9._-]/', '-', (string)($proposal['file_name'] ?: $proposal['document_reference'] . '.doc'));
header('Content-Type: application/msword; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
echo $proposal['content'];
