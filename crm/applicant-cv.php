<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=require_login();
if(!can_view_all_sales_financials($user)){http_response_code(403);exit('Δεν έχετε πρόσβαση.');}
$id=is_string($_GET['id']??null)?$_GET['id']:'';
$q=db()->prepare('SELECT cv_pdf FROM sales_applicants WHERE id=?');$q->execute([$id]);$pdf=$q->fetchColumn();
if(!$pdf){http_response_code(404);exit('Δεν βρέθηκε αρχείο.');}
header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="candidate-cv.pdf"');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');echo $pdf;
