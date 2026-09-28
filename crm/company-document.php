<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) { http_response_code(404); exit('Το έγγραφο δεν βρέθηκε.'); }
$statement = db()->prepare('SELECT d.*, c.name AS company_name FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=? AND c.deleted_at IS NULL LIMIT 1');
$statement->execute([$id]);
$document = $statement->fetch();
if (!$document) { http_response_code(404); exit('Το έγγραφο δεν βρέθηκε.'); }
$toolbar = '<div class="preview-toolbar" style="position:fixed;z-index:9999;left:16px;right:16px;top:12px;display:flex;gap:8px;justify-content:center;padding:10px;background:#07182e;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.22)"><a href="' . e(crm_url('customer.php?id=' . (int)$document['company_id'])) . '" style="color:white;text-decoration:none;padding:9px 14px">← Καρτέλα πελάτη</a><a href="' . e(crm_url('company-document-download.php?id=' . urlencode($id))) . '" style="background:white;color:#07182e;text-decoration:none;padding:9px 14px;border-radius:8px;font-weight:700">Download Word</a><button onclick="window.print()" style="border:0;background:#1468ff;color:white;padding:9px 14px;border-radius:8px;font-weight:700;cursor:pointer">Εκτύπωση / Αποθήκευση PDF</button></div><style>body{padding-top:78px!important}.signature-page{min-height:0!important}.signatures,.signature-card{break-inside:avoid!important;page-break-inside:avoid!important}.signature-card{min-height:150mm!important}.stamp-space{margin-top:18px!important}.signature-intro{margin-bottom:16px!important}.doc-footer{margin-top:14px!important}@media print{.preview-toolbar{display:none!important}body{padding-top:0!important}}</style>';
$html = embed_nda_logo((string)$document['content']);
$html = preg_replace('/<body([^>]*)>/i', '<body$1>' . $toolbar, $html, 1) ?? $html;
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' https://www.distillogic.gr https://distillogic.gr data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'");
echo $html;
