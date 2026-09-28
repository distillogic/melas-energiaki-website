<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=require_login();ensure_lead_schema();
$rows=sales_accounting_rows($user);
header('Content-Type: text/csv; charset=UTF-8');header('Cache-Control: private, no-store');
header('Content-Disposition: attachment; filename="melas-accounting-'.date('Y-m-d').'.csv"');
$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,['ID','Lead','Δικαιούχος','Email δικαιούχου','Είδος','Ποσό EUR','Κατάσταση','Ημερομηνία δικαιώματος','Εξόφληση','Αναφορά εξόφλησης','Πηγή'],',','"','');
foreach($rows as $row)fputcsv($out,array_map('sales_csv_cell',[$row['id'],$row['lead_reference'],$row['name'],$row['email'],$row['commission_type'],$row['amount'],$row['status'],$row['earned_at'],$row['payout_date'],$row['payout_reference'],$row['source_record_id']]),',','"','');
fclose($out);exit;
