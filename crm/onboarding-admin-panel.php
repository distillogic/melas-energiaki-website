<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')||!isset($user,$target)||!can_manage_accounts($user)){http_response_code(403);exit;}
$rule=melas_onboarding_rule(db(),$uid);
$training=onboarding_training_status($uid);$employee=($record['relationship']??'')==='employee';
?>
<section class="card form-section"><?php if($employee): ?><p class="alert info">Μισθωτός: εκδώστε εγκαίρως τα εργασιακά έγγραφα χωρίς αναμονή για την Academy. Η παρακάτω εκπαιδευτική σειρά αφορά την πρόσβαση στο CRM, όχι τον χρόνο πρόσληψης ή μισθοδοσίας.</p><?php endif; ?><h2>Διαδρομή πρόσβασης · Academy πρώτα</h2><p><strong><?= e(onboarding_label($target)) ?></strong></p>
<?php if($rule&&$rule['policy']==='legacy'): ?><p>Ο χρήστης υπήρχε πριν από την ενεργοποίηση της νέας διαδικασίας. Η πρόσβασή του διατηρείται χωρίς νέα απαίτηση υπογραφών. Η εξαίρεση δεν δηλώνει ότι υπέγραψε έγγραφα.</p>
<?php else: ?><p>Academy → μαθήματα, εξετάσεις, τουλάχιστον 3 διαφορετικές επιτυχείς εικονικές κλήσεις από τις δύο εταιρείες και εκπαιδευτική αξιολόγηση → σύμβαση και συμφωνία εμπιστευτικότητας (NDA) με υπογραφές και αποδοχή των δύο πολιτικών → τελική έγκριση CRM από το Sales Partner Portal.</p><p>Σύνδεσμος νέου συνεργάτη: <a href="/academy/login.php">Σύνδεση Academy</a>, με τα ίδια στοιχεία του Melas CRM. Η <a href="<?= e(crm_url('login.php')) ?>">σύνδεση Melas CRM</a> οδηγεί μόνο στην προσωπική σελίδα υποδοχής μέχρι την τελική έγκριση. Δεν του στέλνετε κωδικούς CEO.</p>
<p>Εκπαιδευτική ολοκλήρωση: <?= !$training['available']?'Ο έλεγχος δεν είναι προσωρινά διαθέσιμος — ελέγξτε την εγκατάσταση Academy.':($training['ready']?'Επιβεβαιώθηκε. Μπορούν να εκδοθούν και να υπογραφούν τα έγγραφα.':'Εκκρεμεί. Οι υπογραφές παραμένουν κλειδωμένες· η Academy είναι το πρώτο βήμα.') ?></p>
<?php if(empty($rule['preapproved_at'])): ?><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="preapprove">
<?php foreach(['application'=>'Ελέγχθηκε η αίτηση και τα στοιχεία υποψηφίου','screening'=>'Ολοκληρώθηκε ο αρχικός έλεγχος (screening)','interview'=>'Ολοκληρώθηκε η συνέντευξη','research'=>'Ολοκληρώθηκε το τεστ έρευνας αγοράς','roleplay'=>'Ολοκληρώθηκε το αρχικό σενάριο συνομιλίας','commission_terms'=>'Εξηγήθηκαν η εξωτερική συνεργασία, η απουσία σταθερού μισθού όπου ισχύει και οι αμοιβές (€80 ανά εγκεκριμένο lead)'] as $key=>$label): ?><label class="checkbox-row"><input type="checkbox" name="<?= e($key) ?>" value="1" required> <?= e($label) ?></label><?php endforeach; ?>
<label>Τεκμηρίωση αξιολόγησης<textarea name="evidence" minlength="20" maxlength="3000" required></textarea></label><label>Ο κωδικός σας<input name="actor_password" type="password" autocomplete="current-password" required></label><button class="button primary">Καταγραφή προέγκρισης εγγράφων</button></form>
<?php else: ?><p>Προέγκριση: <?= e($rule['preapproved_at']) ?>. Η προέγκριση δεν υπογράφει έγγραφα και δεν ενεργοποιεί πωλήσεις.</p><?php endif; ?>
<p>Η προέγκριση δεν είναι προϋπόθεση εισόδου στην Academy. Academy: <?= melas_onboarding_allowed(db(),$target,'academy')?'Επιτρέπεται η εκπαίδευση με τα στοιχεία CRM.':'Δεν επιτρέπεται· ελέγξτε ενεργοποίηση και καρτέλα λογαριασμού.' ?></p>
<a class="button" href="<?= e(crm_url('sales-portal.php?tab=development')) ?>">Στάδιο εκπαίδευσης / τελική ενεργοποίηση</a>
<?php endif; ?></section>
