<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$user=require_login();$manage=can_manage_accounts($user);
if(!$manage&&!in_array($user['role'],['admin','technical'],true)){http_response_code(403);exit('Δεν έχετε πρόσβαση.');}
require __DIR__.'/account-management.php';ensure_account_management_schema();
$errors=[];$id=trim((string)($_GET['id']??''));$isNew=isset($_GET['new']);$fields=account_work_fields();$account=null;$profile=[];
if($isNew&&$id!==''){http_response_code(400);exit('Επιλέξτε νέα εγγραφή ή υπάρχουσα καρτέλα.');}
if($id!==''||$isNew){
    if(!$manage){http_response_code(403);exit('Η καρτέλα προσωπικού είναι διαθέσιμη μόνο στους διαχειριστές χρηστών.');}
    if($id!==''){
        $statement=db()->prepare('SELECT u.id,u.name,u.email,u.role,u.active,p.details,p.deleted_at FROM users u LEFT JOIN user_work_profiles p ON p.user_id=u.id WHERE u.id=?');
        $statement->execute([$id]);$account=$statement->fetch();
        if(!$account){http_response_code(404);exit('Ο λογαριασμός δεν βρέθηκε.');}
        $profile=json_decode((string)($account['details']??''),true)?:[];
    }
}
$protected=$account&&account_is_owner_email((string)$account['email']);
$applicantId=$isNew&&is_string($_GET['applicant']??null)?$_GET['applicant']:'';
if($applicantId!==''){
    if(!can_view_all_sales_financials($user)){http_response_code(403);exit('Δεν έχετε πρόσβαση στις αιτήσεις.');}
    ensure_lead_schema();$q=db()->prepare("SELECT name,email FROM sales_applicants WHERE id=? AND status='training_approved'");$q->execute([$applicantId]);$candidate=$q->fetch();
    if(!$candidate){http_response_code(404);exit('Η αίτηση δεν είναι διαθέσιμη για δημιουργία λογαριασμού.');}$account=$candidate;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!$manage||(!$account&&!$isNew)){http_response_code(403);exit('Δεν επιτρέπεται αυτή η ενέργεια.');}
    verify_csrf();$action=(string)($_POST['action']??'save');
    try{
        account_apply_change($user,$account,$isNew,$action,$_POST);
        flash('success',$isNew?'Ο νέος χρήστης δημιουργήθηκε με πρόσβαση στην Academy. Ορίστε τη νομική σχέση από τα Έγγραφα προσωπικού. Δεν έχει ακόμη πρόσβαση σε πραγματικό CRM.':($action==='save'?'Τα στοιχεία αποθηκεύτηκαν.':($action==='reactivate'?'Η πρόσβαση ενεργοποιήθηκε. Απαιτείται νέα σύνδεση.':'Η ενέργεια καταγράφηκε. Η πρόσβαση είναι ανενεργή και το ιστορικό διατηρήθηκε.')));redirect_to($isNew?'accounts.php?id='.urlencode($GLOBALS['activity_account_change']['id']):'accounts.php');
    }catch(InvalidArgumentException $error){$errors[]=$error->getMessage();}
    catch(Throwable $error){error_log('Account management failure: '.get_class($error).' '.$error->getCode());$errors[]='Η αποθήκευση απέτυχε. Ελέγξτε μήπως το email χρησιμοποιείται ήδη.';}
    if($action==='save'){
        $profile=array_intersect_key($_POST,array_fill_keys(array_keys($fields),true));
        $account=array_merge($account?:[],array_intersect_key($_POST,array_fill_keys($protected?['name']:['name','email','role'],true)));
        if($isNew){$account['active']=1;$account['role']='employee';}
    }
}
render_header('Λογαριασμοί',$user);
?>
<div class="page-heading"><div><h1>Λογαριασμοί CRM</h1><p>Διαχείριση πρόσβασης για ιδιοκτήτη, διαχειριστή και τεχνική υποστήριξη.</p></div><div class="actions"><a class="button" href="<?= e(crm_url('accounts.php')) ?>">Όλοι οι λογαριασμοί</a><?php if($manage): ?><a class="button primary" href="<?= e(crm_url('accounts.php?new=1')) ?>">+ Νέος χρήστης</a><?php endif; ?></div></div>
<?php if($manage): ?><nav class="actions" aria-label="Λογαριασμοί" style="margin-bottom:20px"><a class="button primary" aria-current="page" href="<?= e(crm_url('accounts.php')) ?>">Λογαριασμοί</a><a class="button" href="<?= e(crm_url('activity.php')) ?>">Ιστορικό ενεργειών CRM</a></nav><?php endif; ?>
<?php if($errors): ?><div class="alert error" role="alert"><?= e(implode(' ',$errors)) ?></div><?php endif; ?>
<?php if($id!==''||$isNew): ?>
<form method="post" class="stack">
<?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if($applicantId!==''): ?><input type="hidden" name="applicant_id" value="<?= e($applicantId) ?>"><p>Δημιουργία από εγκεκριμένη αίτηση. Η ενέργεια απαιτεί επιλογή νέου κωδικού και δική σας επιβεβαίωση· δεν αλλάζει στοιχεία σε υπάρχοντα λογαριασμό.</p><?php endif; ?>
<section class="card form-section"><header><h2>Πρόσβαση CRM</h2></header><div class="form-grid">
<label>Ονοματεπώνυμο *<input name="name" maxlength="150" required value="<?= e($account['name']??'') ?>"></label>
<label>Email *<input type="email" name="email" maxlength="320" required <?= $protected?'readonly':'' ?> value="<?= e($account['email']??'') ?>"></label>
<?php if($isNew): ?>
<label>Κωδικός πρόσβασης *<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
</div><p>Η δημιουργία λογαριασμού επιτρέπει είσοδο στην Academy με το ίδιο email και τον ίδιο κωδικό. Πρώτα ολοκληρώνονται εκπαίδευση, εξετάσεις και εικονική πρακτική. Μετά ακολουθούν τα έγγραφα, οι υπογραφές και η τελική έγκριση για πραγματική εργασία στο CRM. Η προσωπική σελίδα υποδοχής είναι διαθέσιμη από την αρχή.</p><p>Ο κωδικός πρέπει να έχει τουλάχιστον 12 χαρακτήρες (έως 72 bytes). Ρόλος και επαγγελματικά στοιχεία συμπληρώνονται αργότερα στην καρτέλα.</p><div class="actions"><button class="button primary">Δημιουργία χρήστη</button></div></section>
<?php else: ?>
<label>Ρόλος CRM<select name="role" <?= $protected?'disabled':'' ?>><?php foreach(['employee','partner','manager','technical','admin'] as $role): ?><option value="<?= e($role) ?>" <?= ($account['role']??'employee')===$role?'selected':'' ?>><?= e(role_label($role)) ?></option><?php endforeach; ?></select></label>
<p>Κατάσταση: <strong><?= !empty($account['deleted_at'])?'Αποχωρήσας / αρχειοθετημένος':(!empty($account['active'])?'Ενεργός':'Ανενεργός') ?></strong>. Η ενεργοποίηση κωδικών δεν παρακάμπτει τα έγγραφα και την εκπαίδευση νέων χρηστών.</p>
<?php if(!$protected||($account['id']??'')===$user['id']): ?>
<label>Νέος κωδικός (προαιρετικό)<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password"></label>
<label>Επιβεβαίωση νέου κωδικού<input type="password" name="password_confirm" autocomplete="new-password"></label>
<?php endif; ?>
<label class="field-full">Ο δικός σας τρέχων κωδικός για επιβεβαίωση *<input type="password" name="actor_password" autocomplete="current-password" required></label>
</div><p>Ο λογαριασμός ιδιοκτήτη προστατεύεται από αλλαγή email/ρόλου, διαγραφή και απενεργοποίηση.</p></section>
<section class="card form-section"><header><h2>Επαγγελματική καρτέλα</h2><p>Ορατή μόνο στον ιδιοκτήτη και στον διαχειριστή. Μην καταχωρίζετε τραπεζικά, ιατρικά στοιχεία ή κωδικούς πρόσβασης.</p></header><div class="form-grid">
<?php foreach($fields as $key=>[$label,$type,$limit]): ?><label class="<?= $type==='textarea'?'field-full':'' ?>"><?= e($label) ?><?php if($type==='textarea'): ?><textarea name="<?= e($key) ?>" maxlength="<?= $limit ?>"><?= e($profile[$key]??'') ?></textarea><?php else: ?><input name="<?= e($key) ?>" type="<?= e($type) ?>" maxlength="<?= $limit ?>" value="<?= e($profile[$key]??'') ?>"><?php endif; ?></label><?php endforeach; ?>
</div><div class="actions"><button class="button primary">Αποθήκευση καρτέλας</button></div></section>
<?php endif; ?>
</form>
<?php if(!$isNew): ?><section class="card form-section"><h2>Εκπαίδευση, έγγραφα &amp; πρόσβαση</h2><p>Οι νέοι χρήστες ολοκληρώνουν πρώτα την Academy, μετά υπογράφουν τα έγγραφα και τέλος λαμβάνουν έγκριση CRM. Οι υπάρχοντες διατηρούν την προηγούμενη πρόσβασή τους.</p><a class="button" href="<?= e(crm_url('personnel.php?user='.urlencode($id))) ?>">Διαδρομή πρόσβασης / έγγραφα προσωπικού</a></section><?php endif; ?>
<?php if(!$isNew&&!$protected&&$id!==$user['id']): ?>
<section class="card form-section"><h2>Πρόσβαση &amp; αποχώρηση</h2>
<p>Η απενεργοποίηση διακόπτει την πρόσβαση από το επόμενο αίτημα στο CRM. Η αποχώρηση αρχειοθετεί επιπλέον την καρτέλα. Έγγραφα και ιστορικό δεν διαγράφονται. Δεν απαιτείται κωδικός του εργαζομένου.</p>
<form method="post" class="form-grid"><?= csrf_field() ?>
<label>Ενέργεια<select name="action"><?php if(!empty($account['deleted_at'])): ?>
<option value="restore">Επαναφορά καρτέλας — παραμένει ανενεργή</option>
<?php else: ?>
<?php if($account['active']): ?><option value="deactivate">Απενεργοποίηση πρόσβασης</option><?php else: ?><option value="reactivate">Επανενεργοποίηση πρόσβασης</option><?php endif; ?>
<option value="archive">Αποχώρηση &amp; αρχειοθέτηση</option><?php endif; ?></select></label>
<label>Πληκτρολογήστε το email του χρήστη<input name="confirm_email" type="email" required autocomplete="off"></label>
<label class="field-full">Αιτιολογία *<textarea name="reason" minlength="5" maxlength="2000" required><?= e($_POST['reason']??'') ?></textarea></label>
<label>Ο δικός σας τρέχων κωδικός *<input name="actor_password" type="password" required autocomplete="current-password"></label>
<div class="actions"><button class="button">Επιβεβαίωση ενέργειας</button></div></form></section>
<?php endif; ?>
<?php if(!$isNew): ?>
<section class="card form-section"><h2>Ιστορικό πρόσβασης &amp; αποχώρησης</h2>
<?php $events=db()->prepare('SELECT ev.action,ev.reason,ev.created_at,u.name AS actor_name FROM user_lifecycle_events ev LEFT JOIN users u ON u.id=ev.actor_id WHERE ev.subject_id=? ORDER BY ev.created_at DESC');$events->execute([$id]);$eventRows=$events->fetchAll(); ?>
<?php if(!$eventRows): ?><p>Δεν υπάρχουν νέες καταγεγραμμένες ενέργειες αποχώρησης.</p><?php endif; ?>
<?php foreach($eventRows as $event): ?><div class="card"><strong><?= e(['create_pending'=>'Δημιουργία για onboarding','create_active'=>'Δημιουργία με άμεση πρόσβαση','deactivate'=>'Απενεργοποίηση','archive'=>'Αποχώρηση / αρχειοθέτηση','restore'=>'Επαναφορά καρτέλας','reactivate'=>'Επανενεργοποίηση'][$event['action']]??$event['action']) ?></strong>
<p><?= e($event['created_at']) ?> · <?= e($event['actor_name']??'Διαχειριστής') ?></p><p><?= nl2br(e($event['reason'])) ?></p></div><?php endforeach; ?>
<p>Οριστική διαγραφή: μη διαθέσιμη. Απαιτεί ξεχωριστή διαδικασία και καθορισμένους κανόνες διατήρησης στοιχείων.</p></section>
<?php endif; ?>
<?php else:
$showDeleted=$manage&&isset($_GET['deleted']);
$accounts=db()->query("SELECT u.id,u.name,u.email,u.role,u.active,u.created_at,p.deleted_at FROM users u LEFT JOIN user_work_profiles p ON p.user_id=u.id WHERE LOWER(u.email) NOT IN ('sophianos@melasenenrgiaki.gr','sophianos@melasenergiaki.gr') AND p.deleted_at IS ".($showDeleted?'NOT NULL':'NULL').' ORDER BY u.name')->fetchAll();
?>
<?php if($manage): ?><div class="actions"><a class="button" href="<?= e(crm_url('accounts.php')) ?>">Τρέχοντες</a><a class="button" href="<?= e(crm_url('accounts.php?deleted=1')) ?>">Αποχωρήσαντες / αρχείο</a></div><?php endif; ?>
<section class="card table-card"><div class="table-wrap"><table><thead><tr><th>Χρήστης</th><th>Ρόλος CRM</th><th>Κατάσταση</th><?php if($manage): ?><th>Καρτέλα</th><?php endif; ?></tr></thead><tbody>
<?php foreach($accounts as $row): ?><tr><td><strong><?= e($row['name']) ?></strong><br><?= e($row['email']) ?></td><td><?= e(role_label($row['role'])) ?></td><td><?= $row['deleted_at']?'Αποχωρήσας':e(onboarding_label($row)) ?></td><?php if($manage): ?><td><a class="button small" href="<?= e(crm_url('accounts.php?id='.urlencode($row['id']))) ?>">Προβολή / επεξεργασία</a></td><?php endif; ?></tr><?php endforeach; ?>
</tbody></table></div><?php if(!$accounts): ?><p class="empty-state">Δεν υπάρχουν λογαριασμοί σε αυτή την κατηγορία.</p><?php endif; ?></section>
<?php endif; render_footer(); ?>
