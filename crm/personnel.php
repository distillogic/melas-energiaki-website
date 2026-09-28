<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$user=require_login();if(!can_manage_accounts($user)){http_response_code(403);exit('Δεν έχετε πρόσβαση.');}
require __DIR__.'/account-management.php';ensure_account_management_schema();
$uid=(string)($_GET['user']??'');$templates=isset($_GET['templates']);$errors=[];$target=null;
if(!$templates){$q=db()->prepare('SELECT u.id,u.name,u.email,u.active,p.deleted_at FROM users u LEFT JOIN user_work_profiles p ON p.user_id=u.id WHERE u.id=?');$q->execute([$uid]);$target=$q->fetch();if(!$target){http_response_code(404);exit('Η καρτέλα δεν βρέθηκε.');}}
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        personnel_reauth($user,$_POST);$action=(string)($_POST['action']??'');
        $pdo=db();$pdo->beginTransaction();
        if($templates){
            if($action==='new_template'){
                $kind=(string)($_POST['kind']??'');$title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));
                if(!in_array($kind,['employment','services','confidentiality','offboarding','commission_policy','crm_rules'],true)||mb_strlen($title)<3||mb_strlen($title)>200||mb_strlen($body)<100||mb_strlen($body)>60000)throw new InvalidArgumentException('Ελέγξτε είδος, τίτλο και κείμενο προτύπου.');
                $tid=uuid_v4();$pdo->prepare('INSERT INTO personnel_templates(id,kind,title,body) VALUES(?,?,?,?)')->execute([$tid,$kind,$title,$body]);
                personnel_audit($user,null,null,'template_draft',$tid);
            }elseif($action==='publish_template'){
                if(!personnel_ceo($user)||($_POST['reviewed']??'')!=='1')throw new InvalidArgumentException('Μόνο ο melas@distillogic.gr εγκρίνει το περιεχόμενο προτύπου για χρήση, μετά από έλεγχο.');
                $tid=(string)($_POST['template_id']??'');$q=$pdo->prepare('SELECT id FROM personnel_templates WHERE id=? AND approved_at IS NULL FOR UPDATE');$q->execute([$tid]);if(!$q->fetch())throw new InvalidArgumentException('Το πρότυπο δεν είναι διαθέσιμο προς έγκριση.');
                $pdo->prepare('UPDATE personnel_templates SET approved_at=NOW(),approved_by=? WHERE id=?')->execute([$user['id'],$tid]);personnel_audit($user,null,null,'template_published',$tid);
            }else throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
        }else{
            $lock=$pdo->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$lock->execute([$uid]);$target=$lock->fetch();
            if(personnel_owner($target))throw new InvalidArgumentException('Οι λογαριασμοί διοίκησης εξαιρούνται από τη διαδικασία.');
            $record=personnel_record($uid);
            if($action==='preapprove'){
                onboarding_preapprove($user,$uid,$_POST);
            }elseif($action==='relationship'){
                personnel_relationship_change($user,$uid,(string)($_POST['relationship']??''));
            }elseif($action==='enroll'){
                $relationship=(string)($_POST['relationship']??'');
                if($record||!in_array($relationship,['employee','contractor'],true))throw new InvalidArgumentException('Η σχέση έχει ήδη οριστεί ή δεν είναι έγκυρη.');
                $pdo->prepare('INSERT INTO personnel_onboarding(user_id,relationship) VALUES(?,?)')->execute([$uid,$relationship]);
                $pdo->prepare("INSERT IGNORE INTO personnel_access_rules(user_id,policy) VALUES(?,'required')")->execute([$uid]);
                if(onboarding_required($uid)){
                    $pdo->prepare('INSERT INTO user_access_state(user_id,generation) VALUES(?,1) ON DUPLICATE KEY UPDATE generation=generation+1')->execute([$uid]);
                }
                personnel_audit($user,$uid,null,'enrolled',$relationship);
            }elseif($action==='create_document'){
                if(!$record)throw new InvalidArgumentException('Ορίστε πρώτα τη σχέση συνεργασίας.');
                $q=$pdo->prepare('SELECT * FROM personnel_templates WHERE id=?');$q->execute([(string)($_POST['template_id']??'')]);$t=$q->fetch();
                if(!$t||!$t['approved_at'])throw new InvalidArgumentException('Απαιτείται πρότυπο εγκεκριμένο για χρήση. Τα προσχέδια προβάλλονται μόνο για έλεγχο.');
                onboarding_assert_documents_stage($uid,$t['kind']);
                $expected=$record['relationship']==='employee'?'employment':'services';
                if(!in_array($t['kind'],[$expected,'confidentiality','offboarding','commission_policy','crm_rules'],true))throw new InvalidArgumentException('Το πρότυπο δεν αντιστοιχεί στη σχέση συνεργασίας.');
                personnel_assert_melas_template($t);
                $terms=trim((string)($_POST['terms']??''));
                if(mb_strlen($terms)<80||mb_strlen($terms)>30000||($_POST['terms_reviewed']??'')!=='1'||preg_match('/\[[^\]]+\]/u',$terms))throw new InvalidArgumentException('Συμπληρώστε και ελέγξτε τους ειδικούς όρους χωρίς εκκρεμή placeholders (80–30000 χαρακτήρες).');
                $individual=personnel_structured_terms($_POST,$record['relationship'],$t['kind']);
                if($individual){$terms="ΑΤΟΜΙΚΑ ΣΤΟΙΧΕΙΑ ΣΥΜΒΑΣΗΣ\n".implode("\n",array_map(static fn($key)=>personnel_terms_fields($record['relationship'])[$key].': '.$individual[$key],array_keys($individual)))."\n\nΕΙΔΙΚΟΙ ΟΡΟΙ\n".$terms;}
                $last=personnel_latest($uid,(int)$record['onboarding_cycle'],$t['kind']);$revision=$last?(int)$last['revision']+1:1;
                $id=uuid_v4();$reference='ME-HR-'.strtoupper(substr($t['kind'],0,3)).'-'.date('Ymd').'-'.strtoupper(substr($id,0,8)).'-R'.$revision;
                $snapshot=['brand'=>'MELAS ENERGEIAKI','title'=>$t['title'],'body'=>$t['body'],'template_id'=>$t['id'],'template_approved_at'=>$t['approved_at'],'name'=>$target['name'],'email'=>$target['email'],'relationship'=>$record['relationship'],'terms'=>$terms,'individual_terms'=>$individual];
                $pdo->prepare('INSERT INTO personnel_documents(id,user_id,onboarding_cycle,kind,revision,reference,snapshot) VALUES(?,?,?,?,?,?,?)')->execute([$id,$uid,$record['onboarding_cycle'],$t['kind'],$revision,$reference,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                if($t['kind']!=='offboarding'){
                    $pdo->prepare('UPDATE personnel_onboarding SET released_at=NULL,released_by=NULL WHERE user_id=?')->execute([$uid]);
                    if(onboarding_required($uid)){
                        $pdo->prepare('INSERT INTO user_access_state(user_id,generation) VALUES(?,1) ON DUPLICATE KEY UPDATE generation=generation+1')->execute([$uid]);
                    }
                }
                personnel_audit($user,$uid,$id,'document_issued',$reference.' template '.$t['id']);
            }elseif($action==='departure_note'){
                $reason=trim((string)($_POST['reason']??''));if(mb_strlen($reason)<5||mb_strlen($reason)>2000)throw new InvalidArgumentException('Συμπληρώστε παρατήρηση 5–2000 χαρακτήρων.');
                personnel_audit($user,$uid,null,'departure_pending',$reason);
            }else throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
        }
        $pdo->commit();flash('success','Η ενέργεια αποθηκεύτηκε.');redirect_to('personnel.php?'.($templates?'templates=1':'user='.urlencode($uid)));
    }catch(InvalidArgumentException $error){if(db()->inTransaction())db()->rollBack();$errors[]=$error->getMessage();}
    catch(Throwable $error){if(db()->inTransaction())db()->rollBack();error_log('Personnel workflow: '.get_class($error));$errors[]='Η ενέργεια δεν αποθηκεύτηκε. Ανανεώστε και δοκιμάστε ξανά.';}
}
render_header('Έγγραφα προσωπικού',$user);
?>
<div class="page-heading"><div><h1><?= $templates?'Πρότυπα προσωπικού':'Συμβάσεις &amp; έγγραφα' ?></h1><p><?= $target?e($target['name']):'Κάθε αλλαγή περιεχομένου δημιουργεί νέα έκδοση. Δεν αλλάζει παλιά έγγραφα.' ?></p></div><div class="actions"><a class="button" href="<?= e(crm_url('accounts.php'.($uid?'?id='.urlencode($uid):''))) ?>">Καρτέλα προσωπικού</a><a class="button" href="<?= e(crm_url('personnel.php?templates=1')) ?>">Πρότυπα</a></div></div>
<div class="alert info">Νέοι χρήστες: Academy → μαθήματα, εξετάσεις, εικονική πρακτική και εκπαιδευτική αξιολόγηση → έγγραφα, υπογραφές και έγκριση CEO (διευθύνοντος συμβούλου) → τελική πρόσβαση CRM. Οι υπάρχοντες χρήστες και οι προηγούμενες εξαιρέσεις διατηρούν την πρόσβασή τους. Τα πρότυπα του Word παραμένουν προσχέδια μέχρι τον έλεγχο και την έγκριση του CEO.</div>
<?php if($errors): ?><div class="alert error"><?= e(implode(' ',$errors)) ?></div><?php endif; ?>
<?php if($templates): ?>
<?php if(personnel_ceo($user)): ?><p><a class="button" href="<?= e(crm_url('signature-assets.php')) ?>">Έλεγχος εταιρικής υπογραφής / σφραγίδας</a></p><?php endif; ?><div class="alert info">Τα αρχικά κείμενα είναι προσχέδια για νομικό και διοικητικό έλεγχο. Δεν υποκαθιστούν τις νόμιμες διαδικασίες πρόσληψης/αποχώρησης. Μόνο ο CEO μπορεί να εγκρίνει συγκεκριμένη έκδοση για πραγματική χρήση.</div>
<?php $rows=db()->query('SELECT * FROM personnel_templates ORDER BY kind,created_at DESC,id')->fetchAll();foreach($rows as $t): ?>
<section class="card form-section"><h2><?= e($t['title']) ?></h2><p><?= $t['approved_at']?'Εγκεκριμένο για χρήση: '.e($t['approved_at']):'ΠΡΟΣΧΕΔΙΟ — όχι για υπογραφή' ?> · <?= e($t['id']) ?></p>
<a class="button" target="_blank" rel="noopener" href="<?= e(crm_url('personnel-document.php?template='.urlencode($t['id']))) ?>">Προεπισκόπηση / εκτύπωση PDF</a>
<details><summary>Επεξεργασία ως νέα έκδοση</summary><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="new_template"><input type="hidden" name="kind" value="<?= e($t['kind']) ?>"><label>Τίτλος<input name="title" maxlength="200" required value="<?= e($t['title']) ?>"></label><label>Πλήρες κείμενο<textarea name="body" rows="22" maxlength="60000" required><?= e($t['body']) ?></textarea></label><label>Ο κωδικός σας<input type="password" name="actor_password" autocomplete="current-password" required></label><button class="button">Αποθήκευση νέου προσχεδίου</button></form></details>
<?php if(!$t['approved_at']&&personnel_ceo($user)): ?><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="publish_template"><input type="hidden" name="template_id" value="<?= e($t['id']) ?>"><label class="checkbox-row"><input type="checkbox" name="reviewed" value="1" required> Έλεγξα το περιεχόμενο και εγκρίνω αυτή την έκδοση για χρήση με ευθύνη της διοίκησης.</label><label>Κωδικός CEO<input type="password" name="actor_password" autocomplete="current-password" required></label><button class="button">Έγκριση προτύπου για χρήση</button></form><?php endif; ?></section>
<?php endforeach; ?>
<?php else: $record=personnel_record($uid); require __DIR__.'/onboarding-admin-panel.php'; ?>
<?php if(personnel_owner($target)): ?><section class="card form-section"><h2>Εξαίρεση διοίκησης</h2><p>Οι melas@distillogic.gr και sophianos@distillogic.gr δεν χρειάζονται συμβάσεις σε αυτή τη ροή για πρόσβαση στο CRM.</p></section>
<?php elseif(!$record): ?><section class="card form-section"><p>Δεν έχει οριστεί ροή εγγράφων για αυτή την καρτέλα. Μπορείτε προαιρετικά να την ξεκινήσετε παρακάτω, χωρίς αλλαγή της πρόσβασης στο CRM.</p><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="enroll"><label>Σχέση<select name="relationship"><option value="employee">Μισθωτός</option><option value="contractor">Εξωτερικός συνεργάτης</option></select></label><label>Ο κωδικός σας<input type="password" name="actor_password" required autocomplete="current-password"></label><button class="button">Ένταξη στη ροή εγγράφων</button></form></section>
<?php else: ?>
<section class="card form-section"><h2>Νομική σχέση — πριν από έκδοση εγγράφων</h2><p>Δεν ταυτίζεται με τον ρόλο χρήστη του CRM. Διόρθωση επιτρέπεται μόνο πριν εκδοθεί έγγραφο στον τρέχοντα κύκλο. Δεν επηρεάζει την πρόσβαση παλαιών χρηστών.</p><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="relationship"><label>Σχέση<select name="relationship"><option value="employee" <?= $record['relationship']==='employee'?'selected':'' ?>>Μισθωτός</option><option value="contractor" <?= $record['relationship']==='contractor'?'selected':'' ?>>Εξωτερικός συνεργάτης</option></select></label><label>Ο κωδικός μου<input type="password" name="actor_password" required autocomplete="current-password"></label><button class="button">Διόρθωση σχέσης</button></form></section><?php if(!empty($target['deleted_at'])): $departure=personnel_latest($uid,(int)$record['onboarding_cycle'],'offboarding'); ?><div class="alert info"><strong>Αποχωρήσας — χωρίς πρόσβαση.</strong> <?= $departure&&$departure['finalized_at']?'Το πρωτόκολλο αποχώρησης έχει ολοκληρωθεί.':'Εκκρεμεί η έκδοση ή ολοκλήρωση του πρωτοκόλλου αποχώρησης. Επιλέξτε το αντίστοιχο πρότυπο παρακάτω ή καταγράψτε άρνηση/εκκρεμότητα.' ?></div><?php endif; ?>
<section class="card form-section"><h2><?= $record['relationship']==='employee'?'Μισθωτός':'Εξωτερικός συνεργάτης' ?> · Κύκλος <?= (int)$record['onboarding_cycle'] ?></h2><p><?= personnel_complete($record)?'Τα έγγραφα ολοκληρώθηκαν.':'Εκκρεμεί η ολοκλήρωση των εγγράφων. Η Academy παραμένει διαθέσιμη ανεξάρτητα από αυτά.' ?></p><p>Μετά την Academy: σύμβαση και NDA με υπογραφή προσώπου → PDF → έλεγχος και κωδικός CEO → τελικό PDF. Πολιτική προμηθειών και κανόνες CRM: ανάγνωση και προσωπική αποδοχή συγκεκριμένης έκδοσης. Ακολουθεί ξεχωριστή τελική έγκριση πρόσβασης CRM.</p></section>
<section class="card form-section"><h2>Έκδοση νέου εγγράφου</h2><p>Για εξωτερικούς συνεργάτες προηγείται η Academy. Για μισθωτούς τα εργασιακά έγγραφα εκδίδονται ανεξάρτητα από την εκπαίδευση, εγκαίρως για την πρόσληψη. Νέα έκδοση επαναφέρει την απαίτηση ολοκλήρωσης εγγράφων και τελικής έγκρισης CRM, όχι την εκπαιδευτική πρόοδο. Οι παλιοί χρήστες παραμένουν εξαιρούμενοι. Τα προηγούμενα έγγραφα και οι υπογραφές τους παραμένουν στο ιστορικό. Το έγγραφο αποχώρησης εξαιρείται από τον εκπαιδευτικό έλεγχο και δεν καθυστερεί την απενεργοποίηση.</p>
<form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="create_document"><label>Εγκεκριμένη έκδοση προτύπου<select name="template_id" required><option value="">Επιλέξτε</option><?php $q=db()->prepare('SELECT id,title,created_at FROM personnel_templates WHERE approved_at IS NOT NULL AND kind IN (?,\'confidentiality\',\'offboarding\',\'commission_policy\',\'crm_rules\') ORDER BY created_at DESC');$q->execute([$record['relationship']==='employee'?'employment':'services']);foreach($q->fetchAll() as $t): ?><option value="<?= e($t['id']) ?>"><?= e($t['title'].' · '.$t['created_at'].' · '.substr($t['id'],0,8)) ?></option><?php endforeach; ?></select></label>
<?php personnel_render_terms_fields($record['relationship']); ?><label>Ειδικά στοιχεία και όροι *<textarea name="terms" rows="14" minlength="80" maxlength="30000" required placeholder="Συμπληρώστε τα στοιχεία μερών, διεύθυνση, θέση/αντικείμενο, έναρξη/διάρκεια, ωράριο ή παραδοτέα, αποδοχές/αμοιβή, άδειες/πληρωμές και όλους τους ειδικούς όρους που ζητά το επιλεγμένο πρότυπο. Για αποχώρηση: ημερομηνία, εξοπλισμός, παραδοτέα και εκκρεμότητες."></textarea></label>
<label class="checkbox-row"><input type="checkbox" name="terms_reviewed" value="1" required> Έλεγξα ότι οι ειδικοί όροι είναι πλήρεις, ακριβείς και χωρίς εκκρεμή πεδία.</label><label>Ο κωδικός σας<input type="password" name="actor_password" required autocomplete="current-password"></label><button class="button primary">Έκδοση για εκτύπωση και υπογραφή</button></form></section>
<?php endif; ?>
<section class="card form-section"><h2>Όλα τα έγγραφα και οι εκδόσεις</h2><?php $q=db()->prepare('SELECT d.id,d.reference,d.kind,d.onboarding_cycle,d.revision,d.worker_at,d.approved_at,d.finalized_at,(SELECT accepted_at FROM personnel_policy_receipts r WHERE r.document_id=d.id AND r.user_id=d.user_id) AS accepted_at FROM personnel_documents d WHERE d.user_id=? ORDER BY onboarding_cycle DESC,created_at DESC,revision DESC');$q->execute([$uid]);foreach($q->fetchAll() as $doc): ?><div class="card"><strong><?= e($doc['reference']) ?></strong><p>Κύκλος <?= (int)$doc['onboarding_cycle'] ?> · <?= in_array($doc['kind'],['commission_policy','crm_rules'],true)?($doc['accepted_at']?'Αποδοχή καταγεγραμμένη: '.e($doc['accepted_at']):'Αναμονή προσωπικής αποδοχής πολιτικής'):($doc['finalized_at']?'Τελικό PDF αποθηκευμένο':($doc['approved_at']?'CEO εγκρίθηκε — εκκρεμεί τελικό PDF':($doc['worker_at']?'Υπογραφή προσώπου αποθηκευμένη':'Αναμονή υπογραφής προσώπου'))) ?></p><a class="button" href="<?= e(crm_url((in_array($doc['kind'],['commission_policy','crm_rules'],true)?'personnel-document.php':'personnel-signing.php').'?id='.urlencode($doc['id']))) ?>">Έγγραφο / αρχεία / υπογραφές</a></div><?php endforeach; ?></section>
<?php if(!personnel_owner($target)): ?><section class="card form-section"><h2>Εκκρεμότητα αποχώρησης</h2><p>Καταγράψτε καθυστέρηση ή άρνηση υπογραφής. Η καταγραφή δεν ισοδυναμεί με υπογραφή και δεν εμποδίζει την απενεργοποίηση.</p><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="departure_note"><textarea name="reason" minlength="5" maxlength="2000" required></textarea><label>Ο κωδικός σας<input type="password" name="actor_password" autocomplete="current-password" required></label><button class="button">Καταγραφή</button></form></section><?php endif; ?>
<section class="card form-section"><h2>Ιστορικό εγγράφων</h2><?php $q=db()->prepare('SELECT a.*,u.name AS actor_name FROM personnel_audit a LEFT JOIN users u ON u.id=a.actor_id WHERE a.user_id=? ORDER BY a.created_at DESC');$q->execute([$uid]);foreach($q->fetchAll() as $event): ?><p><strong><?= e($event['action']) ?></strong> · <?= e($event['created_at'].' · '.$event['actor_name']) ?><br><?= e($event['detail']) ?></p><?php endforeach; ?></section>
<?php endif; render_footer(); ?>
