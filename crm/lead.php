<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$user=require_login();ensure_lead_schema();$id=trim((string)($_GET['id']??''));$lead=load_lead($id);
if(!$lead){http_response_code(404);exit('Το lead δεν βρέθηκε.');}if(!can_access_lead($user,$lead)){http_response_code(403);exit('Δεν έχετε πρόσβαση σε αυτό το lead.');}
$errors=[];$reviewer=can_review_leads($user);$financeManager=can_view_all_sales_financials($user);$statuses=lead_status_labels();
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=(string)($_POST['action']??'');
 try{
  if(!$reviewer&&$action!=='append_note')throw new RuntimeException('Μόνο ιδιοκτήτης ή διαχειριστής μπορεί να αξιολογήσει leads.');
  if(in_array($action,['quick_approve','approve_fee','set_purchase_terms','panel_payment','service_component','service_receipt','commission_status'],true)&&!$financeManager)throw new RuntimeException('Η οικονομική διαχείριση επιτρέπεται μόνο στον ιδιοκτήτη και στον εξουσιοδοτημένο διαχειριστή.');
  if((string)$lead['submitted_by']===(string)$user['id']&&in_array($action,['status','quick_approve','approve_fee','set_purchase_terms','panel_payment','service_component','service_receipt','commission_status'],true))throw new RuntimeException('Δεν μπορείτε να εγκρίνετε ή να εξοφλήσετε δικό σας lead.');
  if(in_array($action,['set_purchase_terms','panel_payment','service_component','service_receipt'],true)&&empty($lead['contacts_released_at']))throw new RuntimeException('Προηγείται αποδοχή κατοχύρωσης και αμοιβής €80.');
  if($action==='append_note'){
   $note=trim((string)($_POST['protection_note']??''));
   if(mb_strlen($note)<10||mb_strlen($note)>3000)throw new RuntimeException('Συμπληρώστε σημείωση 10–3000 χαρακτήρων.');
   db()->beginTransaction();$q=db()->prepare('SELECT id FROM sales_leads WHERE id=? FOR UPDATE');$q->execute([$id]);
   $fresh=load_lead($id);if(!can_access_lead($user,$fresh))throw new RuntimeException('Δεν έχετε πρόσβαση.');
   lead_record_event($fresh,$user,lead_is_owner($user,$fresh)?'partner_message':'review_message',$note);
   db()->commit();flash('success','Η σημείωση προστέθηκε στο ιστορικό.');
  }elseif(in_array($action,['quick_approve','approve_fee'],true)){
   lead_accept_and_release($user,$id,$_POST);
   flash('success','Καταγράφηκε η αποδοχή στον αρχικό δικαιούχο. Τα €80 είναι πληρωτέα και τα στοιχεία επικοινωνίας αποκαλύφθηκαν.');
  }elseif($action==='status'){
   lead_review_status($user,$id,$_POST);
   flash('success','Η απόφαση καταγράφηκε στο ιστορικό του συνεργάτη.');
  }elseif($action==='set_purchase_terms'){
   panel_set_purchase_quantity($user,$id,$_POST['agreed_panel_quantity']??'');
   flash('success','Η τελική συμφωνημένη ποσότητα αποθηκεύτηκε.');
  }elseif($action==='panel_payment'){
   panel_record_payment($user,$id,$_POST);
   flash('success','Η πληρωμή πωλητή καταγράφηκε και τα πληρωμένα πάνελ δημιούργησαν προμήθεια.');
  }elseif($action==='service_component'){
   service_approve_component($user,$id,$_POST);
   flash('success','Η βάση και το επίσημο ποσοστό αποθηκεύτηκαν ως σταθερό στιγμιότυπο.');
  }elseif($action==='service_receipt'){
   service_record_receipt($user,$id,$_POST);
   flash('success','Η πραγματική είσπραξη και η προμήθεια του επιλέξιμου μέρους αποθηκεύτηκαν.');
  }elseif($action==='commission_status'){
   lead_set_commission_status($user,$id,(string)($_POST['commission_id']??''),(string)($_POST['commission_status']??''),$_POST);
   flash('success','Η κατάσταση της προμήθειας και το ιστορικό ενημερώθηκαν.');
  }else throw new RuntimeException('Μη έγκυρη ενέργεια.');
  redirect_to('lead.php?id='.$id);
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$errors[]=$e->getMessage();}
}
$lead=load_lead($id);$identityVisible=can_view_lead_identity($user,$lead);$canEdit=can_edit_protected_lead($user,$lead);$protectedLead=$lead;$lead=protected_lead_view($user,$lead);$data=json_decode((string)$lead['opportunity_data_json'],true)?:[];$qualification=json_decode((string)($lead['qualification_check_json']??''),true)?:[];
$a=db()->prepare('SELECT id,file_name,mime_type,size_bytes,created_at FROM lead_attachments WHERE lead_id=? ORDER BY created_at');$a->execute([$id]);$attachments=$identityVisible?$a->fetchAll():[];
$commissionSql='SELECT c.*,u.name beneficiary_name,a.name approver_name FROM lead_commissions c JOIN users u ON u.id=c.beneficiary_user_id JOIN users a ON a.id=c.approved_by WHERE c.lead_id=?'.($financeManager?'':' AND c.beneficiary_user_id=?').' ORDER BY c.created_at DESC';$c=db()->prepare($commissionSql);$c->execute($financeManager?[$id]:[$id,$user['id']]);$commissions=$c->fetchAll();if(!$identityVisible)foreach($commissions as &$row){$row['payment_reference']='';$row['cancellation_reason']='';}unset($row);
$panelPayments=[];$serviceReceipts=[];if($financeManager&&$identityVisible){$p=db()->prepare('SELECT p.*,u.name confirmed_by_name FROM panel_purchase_payments p JOIN users u ON u.id=p.confirmed_by WHERE p.lead_id=? ORDER BY p.seller_paid_at DESC,p.created_at DESC');$p->execute([$id]);$panelPayments=$p->fetchAll();$r=db()->prepare('SELECT r.*,u.name confirmed_by_name FROM service_revenue_receipts r JOIN users u ON u.id=r.confirmed_by WHERE r.lead_id=? ORDER BY r.received_at DESC,r.created_at DESC');$r->execute([$id]);$serviceReceipts=$r->fetchAll();}
$paidPanelQuantity=array_sum(array_map(static fn(array $row): float => (float)$row['panel_quantity'],$panelPayments));
$agreedPanelQuantity=(float)($lead['agreed_panel_quantity']??0);$remainingPanelQuantity=max(0,$agreedPanelQuantity-$paidPanelQuantity);
$panelCommissionEarned=$paidPanelQuantity*.60;
$specialServiceCommission=(string)($data['service_category']??'')==='10';
$h=db()->prepare('SELECT h.*,u.name changed_by_name FROM lead_status_history h JOIN users u ON u.id=h.changed_by WHERE h.lead_id=? ORDER BY h.created_at DESC');$h->execute([$id]);$history=$h->fetchAll();if(!$identityVisible)foreach($history as &$event){$event['note']='';}unset($event);
$eventsQuery=db()->prepare('SELECT p.id,p.event_type,p.note,p.created_at,u.name actor_name FROM lead_protection_events p JOIN users u ON u.id=p.actor_id WHERE p.lead_id=? ORDER BY p.id DESC');$eventsQuery->execute([$id]);$protectionEvents=$eventsQuery->fetchAll();if(!$financeManager&&!lead_is_owner($user,$protectedLead))foreach($protectionEvents as &$event){if(str_starts_with($event['event_type'],'commission_'))$event['note']='Καταγράφηκε ενημέρωση προμήθειας. Τα οικονομικά είναι ορατά μόνο στον δικαιούχο και στους εξουσιοδοτημένους διαχειριστές.';}unset($event);

render_header('Lead '.$lead['lead_reference'],$user);
if ($identityVisible) {
    require_once __DIR__.'/contact-operations.php';
    contact_ops_banner($user,'lead',$id);
}
?>
<div class="page-heading"><div><span class="eyebrow"><?= e($lead['lead_reference']) ?></span><h1><?= e($lead['company_name']) ?></h1><p><?= e(opportunity_type_labels()[$lead['opportunity_type']]??$lead['opportunity_type']) ?></p></div><div class="actions"><span class="lead-status status-<?= e($lead['status']) ?>"><?= e($statuses[$lead['status']]??$lead['status']) ?></span><?php if($canEdit): ?><a class="button" href="<?= e(crm_url('edit-lead.php?id='.$lead['id'])) ?>">Επεξεργασία</a><?php endif; ?><?php if($identityVisible&&($financeManager||lead_is_owner($user,$protectedLead))): ?><a class="button" href="<?= e(crm_url('lead-receipt.php?id='.$lead['id'])) ?>">Αποδεικτικό κατοχύρωσης</a><?php endif; ?><a class="button" href="<?= e(crm_url('leads.php')) ?>">Πίσω</a></div></div>
<?php if($errors): ?><div class="alert error"><?= e(implode(' ',$errors)) ?></div><?php endif; ?>
<?php require __DIR__.'/lead-protection-panel.php'; ?>
<div class="lead-layout"><div class="stack"><section class="card form-section"><header><h2><?= $identityVisible?'Στοιχεία εταιρείας και επαφής':'Ανώνυμη αξιολόγηση' ?></h2></header><dl class="detail-grid"><div><dt>Εταιρεία</dt><dd><?= e($lead['company_name']) ?></dd></div><div><dt>Περιοχή</dt><dd><?= e($lead['country_region']) ?></dd></div><div><dt>Επαφή</dt><dd><?= e($lead['contact_name']) ?> · <?= e($lead['contact_title']) ?></dd></div><div><dt>Τηλέφωνο</dt><dd><?= e($lead['contact_phone']) ?></dd></div><div><dt>Email</dt><dd><a href="mailto:<?= e($lead['contact_email']) ?>"><?= e($lead['contact_email']) ?></a></dd></div><div><dt>Website</dt><dd><?= $lead['website']?'<a href="'.e($lead['website']).'" target="_blank" rel="noopener">'.e($lead['website']).'</a>':'—' ?></dd></div><div><dt>Ιδιοκτήτης / δικαιούχος lead</dt><dd><?= e($lead['submitter_name']) ?></dd></div><div><dt>Υποβολή</dt><dd><?= e(format_datetime($lead['created_at'])) ?></dd></div></dl></section>
<section class="card form-section"><header><h2>Περιγραφή ευκαιρίας</h2></header><?php if($lead['company_id']): ?><div class="alert">Η εταιρεία βρέθηκε ήδη στις καρτέλες CRM. Ο αξιολογητής πρέπει να ελέγξει αν πρόκειται για πραγματικά νέα ευκαιρία.</div><?php endif; ?><dl class="detail-grid"><?php foreach($data as $key=>$value): ?><?php if($value!==''): ?><div><dt><?= e(lead_field_label($key)) ?></dt><dd><?= e($key==='public_region'?(lead_public_regions()[$value]??'—'):(string)$value) ?></dd></div><?php endif; ?><?php endforeach; ?></dl><h3>Σύνοψη συνομιλίας</h3><p><?= $lead['conversation_summary']?nl2br(e($lead['conversation_summary'])):'—' ?></p><h3>Επόμενο βήμα</h3><p><?= e($lead['next_step']?:'Δεν έχει οριστεί') ?><?= $lead['next_step_other']?' — '.e($lead['next_step_other']):'' ?></p><p><strong>Follow-up:</strong> <?= e(format_datetime($lead['follow_up_at'])) ?><?= $lead['follow_up_note']?' — '.e($lead['follow_up_note']):'' ?></p><p class="muted">Πηγή: <?= e($lead['lead_source']) ?><?= $lead['source_other']?' — '.e($lead['source_other']):'' ?> · Συγκατάθεση: <?= $lead['contact_consent']?'Ναι':'Όχι' ?></p></section>
<section class="card form-section"><header><h2>Συνημμένα</h2></header><?php if(!$attachments): ?><p class="muted">Δεν υπάρχουν συνημμένα.</p><?php endif; ?><div class="attachment-list"><?php foreach($attachments as $file): ?><a class="button" href="<?= e(crm_url('lead-attachment.php?id='.$file['id'])) ?>"><?= e($file['file_name']) ?> · <?= e(format_bytes((int)$file['size_bytes'])) ?></a><?php endforeach; ?></div></section><section class="card form-section"><header><h2>Ιστορικό pipeline</h2></header><div class="lead-history"><?php foreach($history as $event): ?><div><strong><?= e(($statuses[$event['old_status']]??'Νέα καταχώριση').' → '.($statuses[$event['new_status']]??$event['new_status'])) ?></strong><span><?= e($event['changed_by_name']) ?> · <?= e(format_datetime($event['created_at'])) ?></span><?php if($event['note']): ?><p><?= e($event['note']) ?></p><?php endif; ?></div><?php endforeach; ?></div></section></div>
<aside class="stack"><?php require __DIR__.'/lead-review-panel.php'; ?>
<?php $canManageFinance=$financeManager&&(string)$lead['submitted_by']!==(string)$user['id']; ?>
<?php if($financeManager&&is_panel_opportunity((string)$lead['opportunity_type'])): ?>
<section class="card form-section finance-card">
 <header><h2>Αγορά και πληρωμές πάνελ</h2><p>Η προμήθεια €0,60 γεννιέται μόνο για πάνελ που η MELAS ENERGIAKI έχει πραγματικά αγοράσει και πληρώσει στον πωλητή.</p></header>
 <div class="financial-summary">
  <div><span>Αρχική εκτίμηση lead</span><strong><?= e(number_format((float)($data['panel_quantity']??0),0,',','.')) ?></strong></div>
  <div><span>Τελική συμφωνημένη ποσότητα</span><strong><?= $agreedPanelQuantity>0?e(number_format($agreedPanelQuantity,0,',','.')):'—' ?></strong></div>
  <div><span>Πληρωμένα στον πωλητή</span><strong><?= e(number_format($paidPanelQuantity,0,',','.')) ?></strong></div>
  <div><span>Υπόλοιπο συμφωνίας</span><strong><?= $agreedPanelQuantity>0?e(number_format($remainingPanelQuantity,0,',','.')):'—' ?></strong></div>
  <div><span>Προμήθεια €0,60 που δημιουργήθηκε</span><strong>€<?= e(number_format($panelCommissionEarned,2,',','.')) ?></strong></div>
 </div>
 <?php if($canManageFinance): ?>
 <details class="commission-create" <?= $agreedPanelQuantity<=0?'open':'' ?>><summary>Ορισμός τελικής συμφωνημένης ποσότητας</summary>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="set_purchase_terms"><label>Πάνελ που συμφωνήθηκε τελικά να αγοραστούν<input type="number" name="agreed_panel_quantity" min="1" step="1" value="<?= $agreedPanelQuantity>0?e((string)$agreedPanelQuantity):'' ?>" required></label><p class="muted">Η αρχική ποσότητα του lead είναι εκτίμηση. Η προμήθεια δεν βασίζεται σε αυτή, αλλά στις πραγματικές πληρωμές που θα καταχωριστούν παρακάτω.</p><button class="button">Αποθήκευση ποσότητας</button></form>
 </details>
 <?php if($agreedPanelQuantity>0&&$remainingPanelQuantity>0&&in_array($lead['status'],['qualified','fee_approved','commercial_discussion','won'],true)): ?>
 <details class="commission-create"><summary>+ Καταγραφή πληρωμής προς πωλητή</summary>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="panel_payment"><label>Πάνελ που πληρώθηκαν σε αυτή τη δόση<input type="number" name="panel_quantity" min="1" max="<?= e((string)$remainingPanelQuantity) ?>" step="1" required></label><label>Ποσό που πληρώθηκε στον πωλητή (€)<input type="number" name="seller_payment_amount" min="0.01" step="0.01" required></label><label>Ημερομηνία πληρωμής πωλητή<input type="date" name="seller_paid_at" max="<?= e(date('Y-m-d')) ?>" required></label><label>Αναφορά πληρωμής / παραστατικού<input name="payment_reference" maxlength="255" required></label><label>Σημειώσεις<textarea name="notes"></textarea></label><label class="check-label"><input type="checkbox" name="seller_payment_confirmed" value="1" required><span>Επιβεβαιώνω ότι η MELAS ENERGIAKI έχει ήδη πληρώσει τον πωλητή για αυτή την ποσότητα. Δεν αρκεί συμφωνία ή υπογεγραμμένη προσφορά.</span></label><p class="finance-preview">Η δόση δημιουργεί προμήθεια <strong>€0,60 ανά πραγματικά πληρωμένο πάνελ</strong>. Δεν απαιτείται να έχουν μεταπωληθεί τα πάνελ.</p><button class="button primary">Καταχώριση πληρωμής και προμήθειας</button></form>
 </details>
 <?php elseif($agreedPanelQuantity>0&&$remainingPanelQuantity<=0): ?><div class="alert success">Έχει καταγραφεί πληρωμή για ολόκληρη τη συμφωνημένη ποσότητα.</div><?php endif; ?>
 <?php endif; ?>
 <?php if($panelPayments): ?><div class="payment-history"><h3>Ιστορικό πληρωμών πωλητή</h3><?php foreach($panelPayments as $payment): ?><article><div><strong><?= e(number_format((float)$payment['panel_quantity'],0,',','.')) ?> πάνελ</strong><span><?= e(format_datetime($payment['seller_paid_at'])) ?> · <?= e($payment['payment_reference']) ?></span></div><strong>€<?= e(number_format((float)$payment['seller_payment_amount'],2,',','.')) ?></strong></article><?php endforeach; ?></div><?php endif; ?>
</section>

<?php endif; ?>
<?php if($financeManager && $identityVisible): require __DIR__.'/service-compensation-panel.php'; endif; ?>
<section class="card form-section"><header><h2>Προμήθειες</h2><p>Τα €80 του εγκεκριμένου qualified lead παραμένουν πληρωτέα ακόμη κι αν η αγορά τελικά δεν ολοκληρωθεί.</p></header>
 <?php foreach($commissions as $row): ?><article class="commission-row commission-<?= e($row['status']) ?>"><div><strong>€<?= e(number_format((float)$row['commission_amount'],2,',','.')) ?></strong><span><?= e(commission_type_label($row['commission_type'])) ?></span><?php if($row['payment_reference']): ?><small><?= e($row['payment_reference']) ?></small><?php endif; ?></div><span class="commission-status status-<?= e($row['status']) ?>"><?= e(commission_status_label((string)$row['status'])) ?></span><?php if($canManageFinance&&$row['status']==='earned'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="commission_status"><input type="hidden" name="commission_id" value="<?= e($row['id']) ?>"><input type="hidden" name="commission_status" value="payable"><button class="button compact">Κάνε την πληρωτέα</button></form><?php elseif($canManageFinance&&$row['status']==='payable'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="commission_status"><input type="hidden" name="commission_id" value="<?= e($row['id']) ?>"><input type="hidden" name="commission_status" value="paid"><label>Ημερομηνία πληρωμής συνεργάτη<input type="date" name="payout_date" max="<?= e(date('Y-m-d')) ?>" required></label><label>Αναφορά πληρωμής συνεργάτη<input name="payout_reference" maxlength="255" required></label><button class="button compact">Σήμανση πληρωμένης</button></form><?php endif; ?><?php if($row['status']==='cancelled'&&$row['cancellation_reason']): ?><p class="commission-reason">Αιτιολογία: <?= e($row['cancellation_reason']) ?></p><?php endif; ?></article><?php endforeach; ?>
 <?php if(!$commissions): ?><p class="muted">Δεν υπάρχουν ακόμη προμήθειες.</p><?php endif; ?>
</section></aside></div>
<script>document.querySelectorAll('[data-check-all]').forEach(button=>button.addEventListener('click',()=>{const form=button.closest('[data-review-form]');if(!form)return;form.querySelectorAll('.qualification-checklist input[type="checkbox"]').forEach(box=>box.checked=true);button.textContent='✓ Όλα επιβεβαιώθηκαν';button.classList.add('is-complete')}));</script>
<?php render_footer(); ?>
