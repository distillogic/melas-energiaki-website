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
$toolbar='<div class="crm-intake-toolbar"><a href="'.e(crm_url('intake.php?id='.urlencode($id))).'">← Επιστροφή στο CRM</a><a href="'.e(crm_url('intake-download.php?id='.urlencode($id))).'">Λήψη Word</a><button type="button" onclick="window.print()">Εκτύπωση / Αποθήκευση PDF</button></div><style>.crm-intake-toolbar{position:sticky;top:0;z-index:9999;display:flex;gap:10px;justify-content:center;padding:12px;background:#071a33;box-shadow:0 5px 20px #0003}.crm-intake-toolbar a,.crm-intake-toolbar button{appearance:none;border:1px solid #4c7fbf;border-radius:8px;background:#fff;color:#071a33;padding:10px 16px;text-decoration:none;font:700 14px Arial;cursor:pointer}.crm-intake-toolbar button{background:#176afc;color:#fff;border-color:#176afc}@media print{.crm-intake-toolbar{display:none!important}}</style>';
$html=preg_replace('/<body([^>]*)>/i','<body$1>'.$toolbar,(string)$intake['content'],1)?:$toolbar.(string)$intake['content'];
header("Content-Security-Policy: default-src 'self' data:; img-src 'self' data: https:; style-src 'unsafe-inline'; script-src 'unsafe-inline'");
header('Content-Type: text/html; charset=UTF-8');echo $html;
