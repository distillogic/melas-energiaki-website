<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=require_login();
if(!can_manage_accounts($user)){http_response_code(403);exit('Δεν έχετε πρόσβαση.');}
personnel_schema();$id=(string)($_GET['id']??'');
try{$document=personnel_document($id);}catch(InvalidArgumentException $error){http_response_code(404);exit('Το έγγραφο δεν βρέθηκε.');}
if(in_array($document['kind'],['commission_policy','crm_rules'],true))redirect_to('personnel-document.php?id='.urlencode($id));
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        personnel_reauth($user,$_POST);$pdo=db();$pdo->beginTransaction();
        // All document mutations share the subject lock with account/lifecycle changes.
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$document['user_id']]);
        $document=personnel_document($id,true);
        onboarding_assert_documents_stage($document['user_id'],$document['kind']);
        if(!personnel_document_current($document)||$document['finalized_at'])throw new InvalidArgumentException('Η έκδοση είναι κλειδωμένη ή έχει αντικατασταθεί.');
        $action=(string)($_POST['action']??'');
        if($action==='upload_worker'){
            if($document['worker_at']||$document['approved_at'])throw new InvalidArgumentException('Το αρχείο αυτής της έκδοσης έχει κλειδωθεί. Εκδώστε νέα έκδοση για διορθώσεις.');
            if(($_POST['worker_reviewed']??'')!=='1')throw new InvalidArgumentException('Επιβεβαιώστε τον έλεγχο υπογραφής και όλων των σελίδων.');
            $bytes=personnel_pdf_upload('worker_pdf');$hash=hash('sha256',$bytes);
            $pdo->prepare('UPDATE personnel_documents SET worker_pdf=?,worker_hash=?,worker_at=NOW() WHERE id=?')->execute([$bytes,$hash,$id]);
            personnel_audit($user,$document['user_id'],$id,'worker_pdf_uploaded','Reviewed paper signature; SHA-256 '.$hash);
        }elseif($action==='request_approval'){
            if(($_POST['worker_reviewed']??'')!=='1')throw new InvalidArgumentException('Ελέγξτε όλες τις σελίδες και την υπογραφή πριν ζητήσετε έγκριση CEO.');
            if(!$document['worker_at']||!$document['worker_pdf']||$document['approved_at'])throw new InvalidArgumentException('Προηγείται το PDF υπογεγραμμένο από το πρόσωπο.');
            if($document['code_requested']&&strtotime($document['code_requested'])>time()-60)throw new InvalidArgumentException('Περιμένετε ένα λεπτό πριν ζητήσετε νέο κωδικό.');
            $code=(string)random_int(100000,999999);$binding=personnel_binding($document);
            $pdo->prepare('UPDATE personnel_documents SET code_hash=?,code_expires=?,code_requested=?,code_attempts=0,code_binding=? WHERE id=?')->execute([password_hash($code,PASSWORD_DEFAULT),date('Y-m-d H:i:s',time()+600),date('Y-m-d H:i:s'),$binding,$id]);
            $data=json_decode($document['snapshot'],true,512,JSON_THROW_ON_ERROR);
            $body='<h2>MELAS ENERGEIAKI — έγκριση εγγράφου προσωπικού</h2><p>'.e($document['reference']).' · '.e($data['name']).'</p><p>Κωδικός: <strong>'.e($code).'</strong></p><p>Ισχύει για 10 λεπτά και 5 προσπάθειες, μόνο για αυτό το έγγραφο. Μην κοινοποιήσετε τον κωδικό αν δεν εγκρίνετε την υπογραφή.</p><p>Αίτημα από '.e($user['email']).'<br>SHA-256: '.e($document['worker_hash']).'</p>';
            if(!send_crm_email('melas@distillogic.gr','MELAS ENERGEIAKI HR approval — '.$document['reference'],$body))throw new InvalidArgumentException('Η αποστολή απέτυχε. Ελέγξτε τις ρυθμίσεις email.');
            personnel_audit($user,$document['user_id'],$id,'ceo_code_requested',$binding);
        }elseif($action==='verify_approval'){
            $code=(string)($_POST['approval_code']??'');
            if($document['approved_at']||!$document['code_hash']||!$document['code_expires']||strtotime($document['code_expires'])<=time()||(int)$document['code_attempts']>=5||!hash_equals((string)$document['code_binding'],personnel_binding($document)))throw new InvalidArgumentException('Ο κωδικός έληξε, εξαντλήθηκε ή δεν αντιστοιχεί στην έκδοση. Ζητήστε νέο.');
            if(!preg_match('/^[0-9]{6}$/D',$code)||!password_verify($code,$document['code_hash'])){
                $pdo->prepare('UPDATE personnel_documents SET code_attempts=code_attempts+1 WHERE id=?')->execute([$id]);personnel_audit($user,$document['user_id'],$id,'ceo_code_failed','Invalid code');$pdo->commit();throw new InvalidArgumentException('Ο κωδικός δεν είναι σωστός.');
            }
            $pdo->prepare('UPDATE personnel_documents SET approved_at=?,approved_by=?,code_hash=NULL,code_expires=NULL WHERE id=?')->execute([date('Y-m-d H:i:s'),$user['id'],$id]);
            personnel_audit($user,$document['user_id'],$id,'ceo_approved',personnel_binding($document));
        }else throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
        $pdo->commit();flash('success','Η ενέργεια ολοκληρώθηκε.');redirect_to('personnel-signing.php?id='.urlencode($id));
    }catch(InvalidArgumentException $error){if(db()->inTransaction())db()->rollBack();$errors[]=$error->getMessage();}
    catch(Throwable $error){if(db()->inTransaction())db()->rollBack();error_log('Personnel signing: '.get_class($error));$errors[]='Η ενέργεια δεν αποθηκεύτηκε. Ανανεώστε και δοκιμάστε ξανά.';}
    $document=personnel_document($id);
}
$current=personnel_document_current($document);$data=json_decode($document['snapshot'],true,512,JSON_THROW_ON_ERROR);
$signingReady=onboarding_documents_stage_ready($document['user_id'],$document['kind']);
render_header('Υπογραφές προσωπικού',$user);
?>
<div class="page-heading"><div><h1><?= e($data['title']) ?></h1><p><?= e($data['name'].' · '.$document['reference']) ?></p></div><a class="button" href="<?= e(crm_url('personnel.php?user='.urlencode($document['user_id']))) ?>">Πίσω στην καρτέλα εγγράφων</a></div>
<?php if($errors): ?><div class="alert error"><?= e(implode(' ',$errors)) ?></div><?php endif; ?>
<section class="card form-section"><h2>Αρχεία της συγκεκριμένης έκδοσης</h2><div class="actions"><a class="button" target="_blank" rel="noopener" href="<?= e(crm_url('personnel-document.php?id='.urlencode($id))) ?>">Αρχικό έγγραφο / εκτύπωση PDF</a><?php foreach(['worker'=>'PDF υπογεγραμμένο από το πρόσωπο','final'=>'Τελικό PDF και των δύο μερών'] as $file=>$label): if($document[$file.'_pdf']): ?><a class="button" href="<?= e(crm_url('personnel-download.php?id='.urlencode($id).'&file='.$file)) ?>"><?= e($label) ?></a><?php endif; endforeach; ?></div><p>Έλεγχος γνησιότητας χειρόγραφης υπογραφής γίνεται από τη διοίκηση. Η μεταφόρτωση PDF δεν αποτελεί αυτόματη επαλήθευση ταυτότητας ούτε πιστοποιημένη ηλεκτρονική υπογραφή.</p></section>
<?php if(!$current): ?><div class="alert info">Ιστορική έκδοση — διαθέσιμη μόνο για ανάγνωση.</div><?php elseif($document['finalized_at']): ?><div class="alert success">Το τελικό αρχείο έχει αποθηκευτεί και κλειδωθεί. SHA-256: <?= e($document['final_hash']) ?></div>
<?php elseif(!$signingReady): ?><div class="alert info">Οι υπογραφές ξεκλειδώνουν μετά την ολοκλήρωση της Academy και την εκπαιδευτική αξιολόγηση. Τα υπάρχοντα αρχεία παραμένουν διαθέσιμα για ανάγνωση.</div>
<?php else: ?>
<?php if(!$document['worker_at']): ?><section class="card form-section"><h2>1. Ανέβασμα υπογεγραμμένου PDF</h2><form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="upload_worker"><input type="file" name="worker_pdf" accept="application/pdf,.pdf" required><label class="checkbox-row"><input type="checkbox" name="worker_reviewed" value="1" required> Έλεγξα ότι είναι η συγκεκριμένη έκδοση, περιλαμβάνει όλες τις σελίδες και την υπογραφή του προσώπου.</label><label>Ο κωδικός σας<input name="actor_password" type="password" autocomplete="current-password" required></label><button class="button primary">Αποθήκευση και κλείδωμα αρχείου</button></form></section>
<?php elseif(!$document['approved_at']): ?>
<section class="card form-section"><h2>2. Έγκριση CEO</h2><p>Ο κωδικός αποστέλλεται μόνο στο melas@distillogic.gr. Λήγει σε 10 λεπτά, με έως 5 προσπάθειες.</p><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="request_approval"><label class="checkbox-row"><input type="checkbox" name="worker_reviewed" value="1" required> Έλεγξα την πλήρη συγκεκριμένη έκδοση, την ταυτότητα και την υπογραφή του προσώπου.</label><label>Ο κωδικός σας<input type="password" name="actor_password" autocomplete="current-password" required></label><button class="button">Αποστολή κωδικού CEO</button></form></section>
<section class="card form-section"><h2>3. Επιβεβαίωση CEO</h2><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="verify_approval"><label>Εξαψήφιος κωδικός<input name="approval_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><label>Ο δικός σας κωδικός πρόσβασης<input type="password" name="actor_password" autocomplete="current-password" required></label><button class="button primary">Επιβεβαίωση</button></form></section>
<?php else: ?><section class="card form-section"><h2>4. Τοποθέτηση υπογραφής, ημερομηνίας και σφραγίδας</h2><p>Ημερομηνία υπογραφής: <?= e(format_datetime($document['approved_at'])) ?> (έγκριση CEO).</p><a class="button primary" href="<?= e(crm_url('personnel-finalize.php?id='.urlencode($id))) ?>">Δημιουργία τελικού PDF</a></section><?php endif; ?>
<?php endif; render_footer(); ?>
