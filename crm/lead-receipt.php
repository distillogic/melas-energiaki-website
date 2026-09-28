<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$user=require_login();$lead=load_lead(trim((string)($_GET['id']??'')));
if (!$lead) { http_response_code(404); exit('Δεν βρέθηκε.'); }
if (!can_access_lead($user,$lead) || !can_view_lead_identity($user,$lead) || (!lead_is_owner($user,$lead) && !can_view_all_sales_financials($user))) { http_response_code(403); exit('Δεν έχετε πρόσβαση στο αποδεικτικό.'); }
$q=db()->prepare('SELECT p.*,u.name actor_name FROM lead_protection_events p JOIN users u ON u.id=p.actor_id WHERE lead_id=? ORDER BY p.id');$q->execute([$lead['id']]);$events=$q->fetchAll();
$q=db()->prepare('SELECT commission_type,commission_amount,status,earned_at,paid_at FROM lead_commissions WHERE lead_id=? AND beneficiary_user_id=? ORDER BY created_at');$q->execute([$lead['id'],$lead['submitted_by']]);$commissions=$q->fetchAll();
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="lead-claim-'.preg_replace('/[^A-Za-z0-9-]/','',$lead['lead_reference']).'.html"');
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
?>
<!doctype html><html lang="el"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Αποδεικτικό κατοχύρωσης <?= e($lead['lead_reference']) ?></title>
<style>body{font:16px/1.6 system-ui,sans-serif;color:#133d3b;max-width:920px;margin:40px auto;padding:0 24px}h1{font-size:28px}table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:10px;border:1px solid #d6e1df;text-align:left}article{border-top:1px solid #d6e1df;padding:14px 0}small{overflow-wrap:anywhere}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.5 monospace}dt{font-weight:bold}dd{margin:0 0 12px}@media print{body{margin:0}article{break-inside:avoid}}</style>
<h1>MELAS ENERGIAKI · Αποδεικτικό κατοχύρωσης lead</h1>
<dl><dt>Κωδικός</dt><dd><?= e($lead['lead_reference']) ?></dd><dt>Καταχωρητής / σταθερός δικαιούχος</dt><dd><?= e($lead['submitter_name'].' · '.$lead['submitter_email']) ?></dd><dt>Αρχική καταχώριση CRM</dt><dd><?= e($lead['created_at']) ?></dd><dt>Εταιρεία</dt><dd><?= e($lead['company_name']) ?></dd><dt>Κατάσταση</dt><dd><?= e(lead_status_labels()[$lead['status']]??$lead['status']) ?></dd><dt>Αποδοχή / αποκάλυψη</dt><dd><?= e($lead['contacts_released_at']??'Δεν έχει γίνει') ?></dd></dl>
<p>Η καταχώριση κατοχυρώνει την προέλευση του lead, όχι αυτομάτως αμοιβή. Η τρέχουσα αμοιβή είναι €80 και γίνεται πληρωτέα στην αποδοχή. Ιστορικές πληρωμένες αμοιβές διατηρούν το πραγματικό ποσό τους στον πίνακα. Η μη ολοκλήρωση αγοράς δεν ακυρώνει ήδη αποδεκτή αμοιβή. Η πρόσθετη προμήθεια πάνελ είναι €0,60 ανά πραγματικά αγορασμένο και πληρωμένο πάνελ.</p>
<h2>Προμήθειες δικαιούχου</h2><table><tr><th>Τύπος</th><th>Ποσό €</th><th>Κατάσταση</th><th>Πληρωμή</th></tr><?php foreach($commissions as $item): ?><tr><td><?= e(commission_type_label($item['commission_type'])) ?></td><td><?= e($item['commission_amount']) ?></td><td><?= e(commission_status_label($item['status'])) ?></td><td><?= e($item['paid_at']??'—') ?></td></tr><?php endforeach; ?></table>
<h2>Καταγεγραμμένα γεγονότα</h2>
<?php if(!$events): ?><p>Παλαιά καταχώριση: δεν υπάρχει γεγονός αρχικής κατοχύρωσης της νέας έκδοσης. Η αρχική ημερομηνία προέρχεται από το υφιστάμενο CRM.</p><?php endif; ?>
<?php foreach($events as $event): ?><article><strong>#<?= e((string)$event['id']) ?> · <?= e($event['event_type']) ?></strong><p><?= e($event['created_at'].' · '.$event['actor_name']) ?><br><?= nl2br(e($event['note'])) ?></p><small>SHA-256 στιγμιοτύπου: <?= e($event['snapshot_sha256']) ?></small><details><summary>Αποθηκευμένο στιγμιότυπο</summary><pre><?= e($event['snapshot_json']) ?></pre></details></article><?php endforeach; ?>
<p>Έκδοση αντιγράφου: <?= e(date('Y-m-d H:i:s T')) ?>. Φυλάξτε το αρχείο προσωπικά. Είναι αντίγραφο των εγγραφών του CRM, όχι ανεξάρτητη χρονοσήμανση ή ψηφιακή υπογραφή. Το ιστορικό δεν αλλάζει από τις οθόνες της εφαρμογής· ο διαχειριστής της βάσης/φιλοξενίας έχει τεχνικά δυνατότητα επέμβασης.</p></html>
