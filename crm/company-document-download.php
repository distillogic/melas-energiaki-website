<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login();
ensure_customer_workspace_schema();
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) { http_response_code(404); exit('Το έγγραφο δεν βρέθηκε.'); }
$statement = db()->prepare('SELECT d.file_name, d.mime_type, d.content FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=? AND c.deleted_at IS NULL LIMIT 1');
$statement->execute([$id]);
$document = $statement->fetch();
if (!$document) { http_response_code(404); exit('Το έγγραφο δεν βρέθηκε.'); }
$fileName = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$document['file_name']) ?: 'Distillogic-NDA.doc';
header('Content-Type: application/msword; charset=UTF-8');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($fileName));
$content = normalize_document_brand_html(embed_nda_logo((string)$document['content']));
header('Content-Length: ' . strlen($content));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $content;
