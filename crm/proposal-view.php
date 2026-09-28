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
if(!empty($proposal['approved_at'])&&!empty($proposal['final_signed_pdf'])&&!isset($_GET['draft'])){header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="approved-proposal.pdf"');echo $proposal['final_signed_pdf'];exit;}
$html = (string)$proposal['content'];
$toolbar = '<div class="crm-proposal-toolbar"><a href="' . e(crm_url('proposal.php?id='.urlencode($id))) . '">← Επιστροφή στο CRM</a><a href="' . e(crm_url('proposal-download.php?id='.urlencode($id))) . '">Λήψη Word</a><button type="button" onclick="window.print()">Εκτύπωση / Αποθήκευση PDF</button></div><style>.crm-proposal-toolbar{position:sticky;top:0;z-index:9999;display:flex;gap:10px;justify-content:center;padding:12px;background:#071a33;box-shadow:0 5px 20px #0003}.crm-proposal-toolbar a,.crm-proposal-toolbar button{appearance:none;border:1px solid #4c7fbf;border-radius:8px;background:#fff;color:#071a33;padding:10px 16px;text-decoration:none;font:700 14px Arial;cursor:pointer}.crm-proposal-toolbar button{background:#176afc;color:#fff;border-color:#176afc}@media print{.crm-proposal-toolbar{display:none!important}}</style>';
$html = preg_replace('/<body([^>]*)>/i', '<body$1>' . $toolbar, $html, 1) ?: $toolbar . $html;
header("Content-Security-Policy: default-src 'self' data:; img-src 'self' data: https:; style-src 'unsafe-inline'; script-src 'unsafe-inline'");
header('Content-Type: text/html; charset=UTF-8');
echo $html;
