<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=require_login();ensure_customer_workspace_schema();$id=trim((string)($_GET['id']??''));
$stmt=db()->prepare("SELECT d.* FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=? AND d.document_type='msa' AND c.deleted_at IS NULL LIMIT 1");$stmt->execute([$id]);$row=$stmt->fetch();if(!$row){http_response_code(404);exit('Το MSA δεν βρέθηκε.');}
if(($_GET['pdf']??'')==='1'){if(!$row['final_signed_pdf']){http_response_code(404);exit('Δεν υπάρχει τελικό PDF.');}$name=preg_replace('/[^A-Za-z0-9._-]/','-',(string)($row['final_signed_file_name']?:$row['document_reference'].'.pdf'));header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.strlen((string)$row['final_signed_pdf']));echo $row['final_signed_pdf'];exit;}
$name=preg_replace('/[^A-Za-z0-9._-]/','-',(string)($row['file_name']?:$row['document_reference'].'.doc'));header('Content-Type: application/msword; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$name.'"');echo $row['content'];
