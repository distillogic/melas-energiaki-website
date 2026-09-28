<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';personnel_schema();
$user=onboarding_session_user();if(!$user){if(current_user())redirect_to('');redirect_to('login.php');}
if(personnel_access_allowed($user)){
    session_regenerate_id(true);$_SESSION=['user_id'=>$user['id'],'access_generation'=>account_session_generation($user['id']),'csrf'=>bin2hex(random_bytes(32))];
    activity_start($user,'login');redirect_to('');
}
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        enforce_rate_limit('onboarding-submit:'.$user['id'],20);
        $pdo=db();$pdo->beginTransaction();
        $q=$pdo->prepare('SELECT id,active,password_hash FROM users WHERE id=? FOR UPDATE');$q->execute([$user['id']]);$fresh=$q->fetch();
        if(!$fresh||!$fresh['active']||!onboarding_session_user())throw new InvalidArgumentException('Η πρόσβαση άλλαξε. Συνδεθείτε ξανά.');
        $doc=onboarding_own_document($user,(string)($_POST['document_id']??''),true);
        onboarding_assert_documents_stage($user['id'],$doc['kind']);
        if(!personnel_document_current($doc))throw new InvalidArgumentException('Αυτή η έκδοση αντικαταστάθηκε. Ανανεώστε τη σελίδα.');
        $action=(string)($_POST['action']??'');
        if($action==='accept_policy'){
            if(($_POST['accepted']??'')!=='1')throw new InvalidArgumentException('Πρώτα διαβάστε και αποδεχθείτε το κείμενο.');
            onboarding_accept_policy($user,$doc,(string)($_POST['snapshot']??''));
        }elseif($action==='upload_worker'){
            if(!in_array($doc['kind'],['services','employment','confidentiality'],true)||$doc['worker_at']||$doc['approved_at']||$doc['finalized_at'])throw new InvalidArgumentException('Το έγγραφο δεν δέχεται νέο αρχείο. Ζητήστε νέα έκδοση για διόρθωση.');
            if(($_POST['signed']??'')!=='1'||!password_verify((string)($_POST['password']??''),$fresh['password_hash']))throw new InvalidArgumentException('Επιβεβαιώστε την υπογραφή και τον προσωπικό σας κωδικό.');
            $bytes=personnel_pdf_upload('worker_pdf');$hash=hash('sha256',$bytes);
            $pdo->prepare('UPDATE personnel_documents SET worker_pdf=?,worker_hash=?,worker_at=NOW() WHERE id=?')->execute([$bytes,$hash,$doc['id']]);
            personnel_audit($user,$user['id'],$doc['id'],'worker_self_upload','Ανέβασμα από το πρόσωπο· εκκρεμεί έλεγχος διοίκησης. SHA-256 '.$hash);
        }else throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
        $pdo->commit();redirect_to('onboarding.php');
    }catch(InvalidArgumentException $e){if(db()->inTransaction())db()->rollBack();$error=$e->getMessage();}
    catch(Throwable $e){if(db()->inTransaction())db()->rollBack();error_log('Onboarding submission: '.get_class($e));$error='Δεν αποθηκεύτηκε η ενέργεια. Δοκιμάστε ξανά ή ενημερώστε τον διαχειριστή.';}
}
$record=personnel_record($user['id']);$rule=melas_onboarding_rule(db(),$user['id']);$ready=melas_onboarding_documents_ready(db(),$user['id']);
$training=onboarding_training_status($user['id']);
?><!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Η πρόσβασή μου | Melas Energiaki</title><link rel="stylesheet" href="<?= e(crm_url('assets/onboarding-v28.css')) ?>"></head><body><main class="onboarding">
<header><div><span class="eyebrow">MELAS ENERGIAKI · ΥΠΟΔΟΧΗ ΣΥΝΕΡΓΑΤΗ</span><h1>Καλώς ήρθες, <?= e($user['name']) ?>.</h1><p>Ξεκινάς με την εκπαίδευση. Εδώ παρακολουθείς τα επόμενα βήματα και, όταν ολοκληρώσεις την Academy, τα προσωπικά σου έγγραφα. Δεν έχεις ακόμη πρόσβαση σε πραγματικά εταιρικά δεδομένα.</p></div><a href="<?= e(crm_url('logout.php')) ?>">Αποσύνδεση</a></header>
<?php if(($record['relationship']??'')==='employee'): ?><p class="notice">Για μισθωτούς τα συμβατικά έγγραφα εκδίδονται και υπογράφονται ανεξάρτητα από την εκπαιδευτική ολοκλήρωση. Η εκπαίδευση παραμένει προϋπόθεση πρόσβασης σε πραγματικά δεδομένα CRM, όχι καθυστέρησης της πρόσληψης ή του μισθού.</p><?php endif; ?><ol class="steps"><li>1. Μαθήματα Academy</li><li>2. Εξετάσεις &amp; εικονική πρακτική</li><li>3. Έγγραφα &amp; υπογραφές</li><li>4. Τελική έγκριση CRM</li></ol>
<?php if($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif; ?>
<section class="card"><h2><?= !$training['ready']?'Ξεκίνα από την Academy':($ready?'Αναμονή τελικής έγκρισης CRM':'Η εκπαίδευση ολοκληρώθηκε · σειρά έχουν τα έγγραφα') ?></h2>
<?php if(!$training['available']): ?><p class="notice error">Δεν μπορέσαμε προσωρινά να ελέγξουμε την εκπαιδευτική σου πρόοδο. Η πρόσβαση στην Academy δεν εξαρτάται από τα έγγραφα. Ενημέρωσε τον διαχειριστή αν το μήνυμα επιμένει.</p>
<?php elseif(!$training['ready']): ?><p>Μπες στην Academy με το ίδιο email και τον ίδιο κωδικό του Melas CRM. Ολοκλήρωσε τα μαθήματα, τα quizzes, τα βαθμολογούμενα CRM Labs, την τελική εξέταση και την εικονική πρακτική. Μετά την εκπαιδευτική αξιολόγηση ξεκλειδώνει εδώ το βήμα των υπογραφών. Δεν χρειάζονται έγγραφα για να ξεκινήσεις να μαθαίνεις.</p>
<?php elseif($ready): ?><p>Η εκπαίδευση και τα έγγραφά σου έχουν ολοκληρωθεί. Η διοίκηση θα ελέγξει τα στοιχεία και θα εγκρίνει χωριστά την πρόσβαση στο πραγματικό CRM. Δεν χρειάζεται να υποβάλεις ξανά όσα έχουν ήδη αποθηκευτεί.</p>
<?php else: ?><p>Ο διαχειριστής επιβεβαιώνει την αξιολόγηση και εκδίδει τα έγγραφά σου. Ακολουθούν υπογεγραμμένη σύμβαση και συμφωνία εμπιστευτικότητας, έγκριση CEO (διευθύνοντος συμβούλου), τελικά PDF και αποδοχή των δύο πολιτικών. Η απλή μεταφόρτωση PDF δεν αποτελεί αυτόματη επαλήθευση υπογραφής ούτε ενεργοποίηση CRM.</p><?php endif; ?>
<a class="button" href="/academy/login.php">Μετάβαση στην Academy →</a></section>
<?php if($record&&onboarding_documents_stage_ready($user['id'])): foreach([$record['relationship']==='employee'?'employment':'services','confidentiality','commission_policy','crm_rules'] as $kind): $latest=personnel_latest($user['id'],(int)$record['onboarding_cycle'],$kind); ?>
<section class="card"><h2><?= e(['employment'=>'Σύμβαση εργασίας','services'=>'Σύμβαση συνεργασίας','confidentiality'=>'Συμφωνία εμπιστευτικότητας (NDA)','commission_policy'=>'Πολιτική προμηθειών','crm_rules'=>'Κανόνες CRM και κατοχύρωσης leads'][$kind]) ?></h2>
<?php if(!$latest): ?><p>Αναμονή έκδοσης από τον διαχειριστή.</p><?php else: $doc=onboarding_own_document($user,$latest['id']);$data=json_decode($doc['snapshot'],true,512,JSON_THROW_ON_ERROR); ?>
<p><?= e($doc['reference']) ?> · Έκδοση <?= (int)$doc['revision'] ?></p><a href="<?= e(crm_url('personnel-document.php?id='.urlencode($doc['id']))) ?>" target="_blank" rel="noopener">Άνοιγμα / εκτύπωση πλήρους εγγράφου</a>
<?php if(in_array($kind,['commission_policy','crm_rules'],true)): $q=db()->prepare('SELECT accepted_at FROM personnel_policy_receipts WHERE user_id=? AND document_id=?');$q->execute([$user['id'],$doc['id']]);$accepted=$q->fetchColumn(); ?>
<details><summary>Διάβασε το κείμενο αυτής της έκδοσης</summary><div class="document-text"><?= e($data['terms']."\n\n".$data['body']) ?></div></details>
<?php if($accepted): ?><p class="notice">Αποδοχή καταγεγραμμένη: <?= e($accepted) ?>. Δεν υποβάλλεται νέα προσπάθεια για την ίδια έκδοση.</p><?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="accept_policy"><input type="hidden" name="document_id" value="<?= e($doc['id']) ?>"><input type="hidden" name="snapshot" value="<?= e(hash('sha256',$doc['snapshot'])) ?>"><label class="check"><input type="checkbox" name="accepted" value="1" required><span>Διάβασα και αποδέχομαι ολόκληρη τη συγκεκριμένη έκδοση.</span></label><button>Καταγραφή αποδοχής</button></form><?php endif; ?>
<?php elseif(!$doc['worker_at']): ?><p>Διάβασε όλες τις σελίδες, υπέγραψε και ανέβασε το πλήρες PDF. Για διόρθωση μετά την υποβολή, ζήτησε νέα έκδοση από τον διαχειριστή.</p><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="upload_worker"><input type="hidden" name="document_id" value="<?= e($doc['id']) ?>"><label>Υπογεγραμμένο PDF · έως 10 MB<input type="file" name="worker_pdf" accept="application/pdf,.pdf" required></label><label class="check"><input type="checkbox" name="signed" value="1" required><span>Ανεβάζω την πλήρη συγκεκριμένη έκδοση που υπέγραψα προσωπικά.</span></label><label>Ο προσωπικός μου κωδικός<input type="password" name="password" autocomplete="current-password" required></label><button>Υποβολή για έλεγχο</button></form>
<?php else: ?><p class="notice"><?= $doc['finalized_at']?'Ολοκληρώθηκε και αποθηκεύτηκε το τελικό έγγραφο.':($doc['approved_at']?'Εγκρίθηκε από CEO · εκκρεμεί τελικό PDF.':'Το αρχείο σου αποθηκεύτηκε · εκκρεμεί έλεγχος και έγκριση CEO.') ?></p><?php foreach(['worker'=>'Το υπογεγραμμένο αρχείο μου','final'=>'Τελικό έγγραφο και των δύο μερών'] as $type=>$label): if($doc[$type.'_pdf']): ?><p><a href="<?= e(crm_url('personnel-download.php?id='.urlencode($doc['id']).'&file='.$type)) ?>"><?= e($label) ?></a></p><?php endif; endforeach; endif; endif; ?></section>
<?php endforeach; endif; ?><footer>Αν χρειάζεσαι διόρθωση ή διευκρίνιση, επικοινώνησε με τον διαχειριστή. Δεν χρειάζεται να κοινοποιήσεις τον κωδικό σου.</footer>
</main></body></html>
