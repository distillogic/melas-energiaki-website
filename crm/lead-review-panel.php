<?php
declare(strict_types=1);
if (!defined('CRM_ROOT') || !isset($protectedLead,$user)) { http_response_code(403); exit; }
if (!$reviewer) return;
?>
<section class="card form-section"><header><h2>Αξιολόγηση</h2><p>Δικαιούχος: <?= e($protectedLead['submitter_name']) ?>. Δεν υπάρχει ανάθεση σε άλλο χρήστη.</p></header>
<?php if(lead_is_owner($user,$protectedLead)): ?><div class="alert">Η κατοχύρωση είναι δική σας. Η αποδοχή απαιτεί άλλον εξουσιοδοτημένο διαχειριστή.</div>
<?php elseif(in_array($protectedLead['status'],['prospect','contacted','decision_maker_found','need_identified','qualification_in_progress'],true)): ?><p>Ο συνεργάτης προετοιμάζει την ευκαιρία. Αναμένεται υποβολή για αξιολόγηση.</p>
<?php else: ?>
 <?php if(empty($protectedLead['contacts_released_at']) && $financeManager && in_array($protectedLead['status'],['submitted','under_review','more_information','qualified','commercial_discussion','won'],true)): ?>
 <form method="post" class="stack" data-review-form><?= csrf_field() ?><input type="hidden" name="action" value="quick_approve">
 <fieldset class="qualification-checklist"><legend>Έλεγχος με τον συνεργάτη</legend><div class="qualification-tools"><button class="button compact" type="button" data-check-all>Επιβεβαίωση όλων</button></div>
 <?php foreach(qualification_labels_for((string)$protectedLead['opportunity_type']) as $key=>$label): ?><label><input type="checkbox" name="qualification[<?= e($key) ?>]" value="1" required><span><?= e($label) ?></span></label><?php endforeach; ?></fieldset>
 <label class="check-label"><input type="checkbox" name="accept_commitment" value="1" required><span>Αποδέχομαι την ευκαιρία στον παραπάνω δικαιούχο και την αμοιβή €80 ως πληρωτέα. Ζητώ να αποκαλυφθούν τα στοιχεία. Η αμοιβή διατηρείται ακόμη κι αν δεν γίνει η αγορά.</span></label>
 <button class="button primary" type="submit">Αποδοχή + €80 και αποκάλυψη</button></form>
 <?php endif; ?>
 <form method="post" class="stack review-decision-form"><?= csrf_field() ?><input type="hidden" name="action" value="status">
 <label>Ενημέρωση κατάστασης<select name="status">
 <?php $choices=empty($protectedLead['contacts_released_at'])?['under_review','more_information','rejected']:['fee_approved','commercial_discussion','won','lost']; foreach($choices as $key): ?><option value="<?= e($key) ?>" <?= $protectedLead['status']===$key?'selected':'' ?>><?= e($statuses[$key]) ?></option><?php endforeach; ?></select></label>
 <label>Αιτιολογία / διευκρινίσεις<textarea name="review_note" rows="3" placeholder="Ορατό στον συνεργάτη. Υποχρεωτικό για συμπληρώσεις, απόρριψη ή Lost."></textarea></label>
 <button class="button" type="submit">Καταγραφή απόφασης</button>
 </form>
<?php endif; ?></section>
