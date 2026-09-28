<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();
if (!in_array($user['role'], ['admin', 'manager'], true)) {http_response_code(403);exit('Δεν έχετε πρόσβαση.');}
$id=(string)($_GET['id']??'');
if(!preg_match('/^[a-f0-9-]{36}$/i',$id)){http_response_code(404);exit('Το αρχείο δεν βρέθηκε.');}
$statement=db()->prepare("SELECT file_name,mime_type,content FROM company_documents WHERE id=? AND document_type IN ('vendor_response','security_questionnaire','client_information_pack') LIMIT 1");
$statement->execute([$id]);$document=$statement->fetch();
if(!$document){http_response_code(404);exit('Το αρχείο δεν βρέθηκε.');}
$fileName=preg_replace('/[\x00-\x1F\x7F]/u','',(string)$document['file_name'])?:'client-pack-file';
header('Content-Type: '.((string)$document['mime_type']?:'application/octet-stream'));
header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($fileName));
header('Content-Length: '.strlen((string)$document['content']));
header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');
echo $document['content'];
