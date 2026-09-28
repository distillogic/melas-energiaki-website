<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require_once __DIR__.'/contact-operations.php';
$user=require_login();$error=null;
if($_SERVER['REQUEST_METHOD']==='POST') {verify_csrf();try{contact_ops_reminder_action($user,$_POST);flash('success','Η προσωπική υπενθύμιση ενημερώθηκε. Δεν στάλθηκε επικοινωνία σε πελάτη.');redirect_to('reminders.php');}catch(Throwable $e){$error=$e->getMessage();}}
$items=contact_ops_reminders($user);$overdue=count(array_filter($items,fn($r)=>strtotime($r['display_at'])<time()));
render_header('Οι υπενθυμίσεις μου',$user);
?>
<link rel="stylesheet" href="<?= e(crm_url('operations-v33.css')) ?>">
<div class="page-heading"><div><span class="eyebrow">Προσωπική ουρά εργασίας</span><h1>Οι υπενθυμίσεις μου</h1><p><?= $overdue ?> ληξιπρόθεσμες · <?= count($items) ?> ανοικτές. Δημιουργούνται αυτόματα από τα επόμενα βήματα των δικών σου leads και των επικοινωνιών που σου έχουν ανατεθεί.</p></div></div>
<p class="card">Μόνο εσωτερικές υπενθυμίσεις, που ενημερώνονται όταν ανοίγεις τη σελίδα. Δεν αποστέλλονται email, SMS ή κλήσεις και δεν υπάρχουν ειδοποιήσεις με κλειστό browser. Η ολοκλήρωση εδώ κλείνει μόνο την υπενθύμιση, όχι το lead ούτε την προμήθεια.</p>
<?php if($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if(!$items): ?><section class="card"><h2>Δεν υπάρχουν ανοικτές υπενθυμίσεις</h2><p>Πρόσθεσε επόμενο βήμα μέσα στην καρτέλα μιας ευκαιρίας.</p></section><?php endif; ?>
<div class="ops-grid"><?php foreach($items as $item): $state=$item['contact_state']; ?><article class="card ops-reminder"><span class="eyebrow"><?= $item['snoozed']?'Σε αναβολή':(strtotime($item['display_at'])<time()?'Εκπρόθεσμη':'Προγραμματισμένη') ?></span><h2><a href="<?= e(crm_url($item['url'])) ?>"><?= e($item['title']) ?></a></h2><p><?= e($item['note']?:'Επιβεβαίωσε το επόμενο βήμα στην καρτέλα.') ?></p><p><strong><?= e(format_datetime($item['display_at'])) ?></strong></p><p class="<?= $state['blocked']?'ops-warning':'' ?>"><?= $state['blocked']?'DNC: μόνο εσωτερικός έλεγχος — όχι επικοινωνία.':($state['cleared']?'Υπάρχει καταχωρισμένος έλεγχος για κλήση.':'Πριν από κλήση χρειάζεται έλεγχος επικοινωνίας.') ?></p><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="key" value="<?= e($item['key']) ?>"><button class="button primary" name="action" value="done">Ολοκλήρωση υπενθύμισης</button><label>Αναβολή έως<input type="datetime-local" name="snoozed_until"></label><button class="button" name="action" value="snoozed">Αναβολή</button></form></article><?php endforeach; ?></div>
<?php render_footer(); ?>
