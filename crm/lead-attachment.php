<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=require_login();ensure_lead_schema();$id=trim((string)($_GET['id']??''));
$q=db()->prepare('SELECT a.*,l.submitted_by,l.contacts_released_at FROM lead_attachments a JOIN sales_leads l ON l.id=a.lead_id WHERE a.id=? LIMIT 1');$q->execute([$id]);$file=$q->fetch();
if(!$file){http_response_code(404);exit('Το αρχείο δεν βρέθηκε.');}if(!can_access_lead($user,$file)||!can_view_lead_identity($user,$file)){http_response_code(403);exit('Δεν έχετε πρόσβαση.');}
header('Cache-Control: private, no-store');header('Content-Type: '.$file['mime_type']);header('Content-Length: '.(string)$file['size_bytes']);header('Content-Disposition: attachment; filename*=UTF-8\'\''.rawurlencode($file['file_name']));header('X-Content-Type-Options: nosniff');echo $file['file_content'];
