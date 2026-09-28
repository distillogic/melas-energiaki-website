<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user=require_login();
ensure_customer_workspace_schema();
if(!in_array($user['role'],['admin','manager'],true)){http_response_code(403);exit('Η πρόσβαση επιτρέπεται μόνο στη διοίκηση.');}
$id=trim((string)($_GET['id']??''));
$statement=db()->prepare("SELECT d.* FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=? AND d.document_type='intake' AND c.deleted_at IS NULL LIMIT 1");
$statement->execute([$id]);$intake=$statement->fetch();
if(!$intake){http_response_code(404);exit('Το Project Intake δεν βρέθηκε.');}
$name=preg_replace('/[^A-Za-z0-9._-]/','-',(string)($intake['file_name']?:$intake['document_reference'].'.doc'));
header('Content-Type: application/msword; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$name.'"');echo $intake['content'];
