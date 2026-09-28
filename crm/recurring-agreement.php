<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';$user=require_login();ensure_lead_schema();
$id=is_string($_GET['id']??null)?$_GET['id']:'';
$q=db()->prepare('SELECT a.document_pdf,a.document_hash,c.beneficiary_user_id FROM sales_recurring_agreements a JOIN sales_recurring_contracts c ON c.id=a.contract_id WHERE c.id=?');$q->execute([$id]);$row=$q->fetch();
if(!$row||(!can_view_all_sales_financials($user)&&$row['beneficiary_user_id']!==$user['id'])){http_response_code(404);exit('Δεν βρέθηκε διαθέσιμη συμφωνία.');}
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="recurring-agreement.pdf"');echo $row['document_pdf'];
