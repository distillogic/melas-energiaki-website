<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$user=academy_require_login();
require_once __DIR__.'/practice-workflow.php';
require_once __DIR__.'/sales-terms.php';$readingTerms=[];
function lab_reading(string $text):string {global $mode,$readingTerms;return $mode==='guided'?sales_terms_html($text,$readingTerms):e($text);}
function lab_query(string $key,string $default=''): string {
    $value=$_GET[$key]??$default;
    if(!is_string($value)){http_response_code(400);exit('Μη έγκυρο αίτημα.');}
    return $value;
}
$lessonId=lab_query('lesson');$mode=lab_query('mode','guided');$resultId=lab_query('result');$view=lab_query('view');
$catalog=academy_lab_catalog();$meta=$catalog[$lessonId]??null;$team=$view==='team';
if(($lessonId!==''&&!$meta)||!in_array($mode,['guided','assessment'],true)||!in_array($view,['','team'],true)) {http_response_code(404);exit('Η άσκηση δεν βρέθηκε.');}
if($team&&!academy_is_admin($user)){http_response_code(403);exit('Δεν έχεις πρόσβαση στις προσπάθειες άλλων μαθητών.');}
academy_lab_schema();
$error=null;$draft=null;$case=null;$result=null;
$ajax=($_SERVER['HTTP_X_LAB_SAVE']??'')==='1';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    verify_csrf();
    try {
        $action=academy_input('action');
        if($team)throw new InvalidArgumentException('Η ανασκόπηση είναι μόνο για ανάγνωση.');
        if($action==='unlock') {
            academy_rate_limit('lab-unlock',$user['id'],20);
            academy_lab_unlock($user,academy_input('demo_email'),academy_input('demo_password'));
            redirect_to('practice.php'.($lessonId?'?lesson='.rawurlencode($lessonId).'&mode='.$mode:''));
        }
        if($action==='lock') {unset($_SESSION['academy_lab_user'],$_SESSION['academy_lab_until']);redirect_to('practice.php');}
        if(!academy_lab_unlocked($user))throw new InvalidArgumentException('Η demo συνεδρία έληξε. Άνοιξέ την ξανά. Το αποθηκευμένο πρόχειρο παραμένει.');
        if(!$meta)throw new InvalidArgumentException('Διάλεξε άσκηση.');
        if($action==='test_retake'){
            academy_test_retake($user,$lessonId);
            $existing=academy_lab_draft($user,$lessonId,'assessment');
            academy_lab_start($user,$lessonId,'assessment',!empty($existing['attempt_id']));
            redirect_to('practice.php?lesson='.rawurlencode($lessonId).'&mode=assessment#lab-record');
        }
        if($action==='start') {
            academy_lab_start($user,$lessonId,$mode,academy_input('restart')==='1');
            redirect_to('practice.php?lesson='.rawurlencode($lessonId).'&mode='.$mode);
        }
        if(!in_array($action,['save','submit'],true)||!is_array($_POST['answers']??null))throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
        $id=academy_lab_write($user,$lessonId,$mode,academy_input('submission_key'),$_POST['answers'],$action==='submit');
        if($ajax) {header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]);exit;}
        if($id)redirect_to('practice.php?result='.rawurlencode($id));
        $_SESSION['notice']='Το προσωπικό σου πρόχειρο αποθηκεύτηκε.';
        redirect_to('practice.php?lesson='.rawurlencode($lessonId).'&mode='.$mode);
    } catch(InvalidArgumentException $e) {http_response_code(422);$error=$e->getMessage();}
      catch(Throwable $e) {error_log('Academy lab failed: '.$e->getMessage());http_response_code(503);$error='Δεν ολοκληρώθηκε η αποθήκευση. Κράτησε τις απαντήσεις και δοκίμασε ξανά. Μην διαγράψεις δεδομένα.';}
    if($ajax){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$error]);exit;}
}
$unlocked=academy_lab_unlocked($user);$progress=academy_my_progress($user);
if($resultId!=='') {
    $result=academy_lab_result($user,$resultId,$team);
    if(!$result){http_response_code(404);exit('Το αποτέλεσμα δεν βρέθηκε για τον λογαριασμό σου.');}
    $meta=$catalog[$result['lesson_id']]??null;
} elseif($meta&&$unlocked) {
    $draft=academy_lab_draft($user,$lessonId,$mode);
    if($draft&&$draft['attempt_id']){
        if(!$error)redirect_to('practice.php?result='.rawurlencode($draft['attempt_id']));
        // Keep a rejected POST and its message visible, instead of masking it with a redirect.
        $result=academy_lab_result($user,$draft['attempt_id']);$draft=null;
    }
    if($draft)$case=academy_lab_case($lessonId,(int)$draft['variant']);
}
$attemptState=$meta?academy_attempt_state($user,$result['lesson_id']??$lessonId):null;
$history=(!$case&&!$result)?academy_lab_history($user,$team):[];
$done=count(array_filter(array_keys($catalog),static fn(string $id):bool=>!empty($progress[$id]['completed_at'])));
render_header('CRM Lab · Μάθηση στην πράξη',$user);
?>
<div class="crm-lab" data-lab-root>
  <nav class="lab-breadcrumb" aria-label="Διαδρομή"><a href="<?= e(academy_url('index.php')) ?>">Academy</a><span>/</span><?php if (($meta['company'] ?? '') === 'distillogic'): ?><a href="<?= e(academy_url('index.php?pillar=crm-distillogic')) ?>">CRM Distillogic</a><?php elseif (($meta['company'] ?? '') === 'melas'): ?><a href="<?= e(academy_url('index.php?pillar=crm')) ?>">CRM Melas Energiaki</a><?php else: ?><span>Κοινή πρακτική των δύο CRM</span><?php endif; ?><span>/</span><a href="<?= e(academy_url('practice.php')) ?>">CRM Lab</a></nav>
  <div class="lab-safety"><span class="lab-dot"></span><strong>DEMO · Χωρίς πραγματικούς πελάτες</strong><span>Δεν στέλνονται email, δεν εκτελούνται πληρωμές ή εγκρίσεις.</span></div>
  <div class="lab-persona"><span>Μαθητής: <strong><?= e($user['name']) ?></strong> · η πρόοδος είναι προσωπική.</span><?php if($unlocked): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="lock"><span>Demo persona: demo@distillogic.gr</span><button type="submit">Κλείσιμο demo</button></form><?php endif; ?></div>
  <?php if($meta&&!$team)require __DIR__.'/lab-navigation.php'; ?>
  <?php if($error): ?><p class="lab-alert" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?php if($result): $feedback=json_decode($result['feedback_json'],true,512,JSON_THROW_ON_ERROR);$answers=json_decode($result['answers_json'],true,512,JSON_THROW_ON_ERROR);$isTest=$result['mode']==='assessment'; ?>
    <section class="lab-hero lab-result-hero">
      <div><span class="lab-eyebrow"><?= $isTest?'ΑΞΙΟΛΟΓΗΣΗ':'ΚΑΘΟΔΗΓΟΥΜΕΝΗ ΕΞΑΣΚΗΣΗ' ?></span><h1><?= $result['passed']?'Καλή δουλειά. Δες τι έκανες σωστά.':'Κάθε διόρθωση είναι ένα βήμα μπροστά.' ?></h1><p><?= e($meta['title']??$result['lesson_id']) ?> · <?= e($result['learner_name']) ?></p><p><?= $isTest?($result['passed']?'Η άσκηση προστέθηκε στην προσωπική σου πρόοδο.':'Δεν ολοκληρώθηκε ακόμη. Χρειάζεσαι ≥85% και σωστές όλες τις κρίσιμες αποφάσεις.'):'Η εξάσκηση δεν ολοκληρώνει το μάθημα. Συνέχισε στο τεστ χωρίς υποδείξεις.' ?></p></div><div class="lab-score"><strong><?= (int)$result['score'] ?><small>/100</small></strong><span><?= $result['passed']?'Επιτυχής προσπάθεια':'Χρειάζεται επανάληψη' ?></span></div>
    </section>
    <div class="lab-actions">
      <?php if(!$team&&$isTest&&academy_can_test_retake($user)): ?><form method="post" action="<?= e(academy_url('practice.php?lesson='.$result['lesson_id'].'&mode=assessment')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="test_retake"><button class="lab-button" type="submit">Νέα επανεξέταση δοκιμής</button></form><p class="lab-small">Μόνο για Sophianos · ο πρώτος βαθμός και όλες οι προσπάθειες διατηρούνται.</p><?php endif; ?>
      <?php if(!$team&&(!$isTest||$attemptState['allowed'])): ?><form method="post" action="<?= e(academy_url('practice.php?lesson='.$result['lesson_id'].'&mode='.$result['mode'])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><input type="hidden" name="restart" value="1"><button class="lab-button" type="submit"><?= $isTest?'Εγκεκριμένη επανεξέταση ↗':'Νέα παραλλαγή εξάσκησης ↗' ?></button></form><?php if(!$isTest): ?><form method="post" action="<?= e(academy_url('practice.php?lesson='.$result['lesson_id'].'&mode=assessment')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><button class="lab-button lab-outline" type="submit">Πέρασε στο τεστ →</button></form><?php endif; ?><?php endif; ?>
      <a class="lab-button lab-outline" href="<?= e(academy_url('practice.php'.($team?'?view=team':''))) ?>">Πίσω στη διαδρομή</a>
      <?php if(!$team&&$navNext): ?><a class="lab-button" href="<?= e(academy_url('practice.php?lesson='.$navNext.'&mode='.$navMode)) ?>">Συνέχεια: <?= e($catalog[$navNext]['title']) ?> →</a><?php endif; ?>
    </div>
    <section class="lab-panel"><div class="lab-section-heading"><div><span class="lab-eyebrow">ΑΝΑΣΚΟΠΗΣΗ ΚΑΤΑΧΩΡΙΣΗΣ</span><h2>Η απάντησή σου, πεδίο προς πεδίο</h2></div><span>Παραλλαγή <?= (int)$result['variant']+1 ?> · <?= e(format_datetime($result['created_at'])) ?></span></div>
      <div class="lab-feedback-grid"><?php foreach($feedback as $key=>$item): ?><article class="lab-feedback <?= $item['correct']?'is-correct':'is-incorrect' ?>"><div><span><?= $item['correct']?'✓ Σωστό':'↻ Διόρθωσε' ?></span><?php if($item['critical']): ?><b>Κρίσιμη απόφαση</b><?php endif; ?></div><h3><?= e($item['label']) ?></h3><dl><dt>Η απάντησή σου</dt><dd><?= e($item['entered_display']) ?></dd><dt>Σωστή απάντηση</dt><dd><?= e($item['expected_display']) ?></dd></dl><p><?= e($item['why']) ?></p></article><?php endforeach; ?></div>
      <details class="lab-reflection"><summary>Η σύνοψή σου · για ανασκόπηση από εκπαιδευτή, όχι αυτόματη βαθμολόγηση</summary><p><?= nl2br(e($answers['reflection']??'')) ?></p></details>
    </section>
    <?php if($isTest&&!$team): ?><p class="lab-alert"><?= e(academy_attempt_notice($attemptState)) ?></p><?php endif; ?>
  <?php elseif($case&&$draft): $values=json_decode($draft['answers_json'],true)?:[]; if($error&&is_array($_POST['answers']??null)){foreach($case['f'] as $key=>$spec)if(is_string($_POST['answers'][$key]??null))$values[$key]=mb_substr($_POST['answers'][$key],0,2500);} $groups=[];foreach($case['f'] as $key=>$spec)$groups[$spec['group']][$key]=$spec; ?>
    <header class="lab-case-heading"><div><span class="lab-eyebrow"><?= e(strtoupper($case['company'])) ?> · ΑΣΚΗΣΗ <?= e($case['number']) ?> · ΠΑΡΑΛΛΑΓΗ <?= (int)$draft['variant']+1 ?></span><h1><?= lab_reading($case['title']) ?></h1><p><?= lab_reading($case['role']) ?></p></div><span class="lab-mode"><?= $mode==='guided'?'Με καθοδήγηση':'Τεστ χωρίς υποδείξεις' ?></span></header>
    <div class="lab-workspace lab-company-<?= e($case['company']) ?>">
      <nav class="lab-mobile-nav" aria-label="Γρήγορη εναλλαγή περιστατικού και καρτέλας"><a href="#lab-transcript">01 · Συνομιλία ↑</a><a href="#lab-record">02 · Καρτέλα ↓</a></nav>
      <div class="lab-source-slot" data-lab-source-slot><aside class="lab-inbox" id="lab-transcript"><div class="lab-inbox-heading"><span>01 / ΤΟ ΠΕΡΙΣΤΑΤΙΚΟ</span><h2>Διάβασε τη συνομιλία</h2><p><?= lab_reading($case['brief']) ?></p><div class="lab-search" data-lab-search-box hidden><label for="lab-search">Αναζήτηση στη συνομιλία</label><input id="lab-search" type="search" data-lab-search placeholder="π.χ. ποσότητα, email, ημερομηνία" autocomplete="off"><span data-lab-search-status role="status"></span></div></div><div class="lab-messages"><?php foreach($case['dialogue'] as [$speaker,$line]): ?><article class="lab-message"><strong><?= e($speaker) ?></strong><p><?= lab_reading($line) ?></p></article><?php endforeach; ?></div>
      <?php if($mode==='guided'): ?><details open class="lab-coach"><summary>Οδηγίες εκπαιδευτή</summary><ul><?php foreach($case['tips'] as $tip): ?><li><?= lab_reading($tip) ?></li><?php endforeach; ?></ul></details><?php endif; ?></aside></div>
      <section class="lab-record" id="lab-record"><div class="lab-record-top"><div><span>02 / ΤΟ ΔΙΚΟ ΣΟΥ CRM</span><h2>Συμπλήρωσε την καρτέλα</h2></div><span class="lab-owner">Ιδιοκτήτης demo: εσύ<br><small>Δεν υπάρχει ανάθεση σε άλλον.</small></span></div><p class="lab-form-help">Προσομοίωση πεδίων και αποφάσεων, όχι σύνδεση στο live CRM. Τα πεδία με <b>◆</b> είναι κρίσιμα. Αριθμοί χωρίς διαχωριστικό χιλιάδων: π.χ. 1800,50.</p>
        <form method="post" class="lab-form" data-lab-form action="<?= e(academy_url('practice.php?lesson='.$lessonId.'&mode='.$mode)) ?>">
          <?= csrf_field() ?><input type="hidden" name="submission_key" value="<?= e($draft['submission_key']) ?>">
          <div class="lab-toolbox" data-lab-toolbox hidden><button type="button" class="lab-button lab-outline" data-lab-reference>Συνομιλία & στοιχεία</button><button type="button" class="lab-button lab-outline" data-lab-view aria-pressed="true">Όλα τα πεδία</button><span data-lab-filled role="status"></span></div>
          <nav class="lab-steps" aria-label="Βήματα καρτέλας" data-lab-steps hidden><?php $step=0;foreach($groups as $name=>$fields): ?><button type="button" data-lab-step="<?= $step ?>"><?= ++$step ?>. <?= e($name) ?></button><?php endforeach; ?></nav>
          <?php $step=0;foreach($groups as $name=>$fields): ?><fieldset class="lab-fields" data-lab-panel="<?= $step++ ?>"><legend><?= e($name) ?></legend><?php foreach($fields as $key=>$spec): $value=$values[$key]??''; ?><div class="lab-field <?= $spec['type']==='textarea'?'lab-field-wide':'' ?>"><label for="lab-<?= e($key) ?>"><?= lab_reading($spec['label']) ?><?= $spec['critical']?' ◆':'' ?></label>
            <?php if($spec['type']==='select'): ?><select id="lab-<?= e($key) ?>" name="answers[<?= e($key) ?>]"><option value="">Επίλεξε…</option><?php foreach($spec['options'] as $option=>$label): ?><option value="<?= e($option) ?>" <?= $value===(string)$option?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select>
            <?php elseif($spec['type']==='textarea'): ?><textarea id="lab-<?= e($key) ?>" name="answers[<?= e($key) ?>]" rows="5" maxlength="2500" placeholder="Γνωστά στοιχεία · εκκρεμότητες · επόμενο βήμα"><?= e($value) ?></textarea>
            <?php else: ?><input id="lab-<?= e($key) ?>" name="answers[<?= e($key) ?>]" type="<?= $spec['type']==='number'?'text':e($spec['type']) ?>" <?= $spec['type']==='number'?'inputmode="decimal"':'' ?> value="<?= e($value) ?>" maxlength="300" autocomplete="off"><?php endif; ?>
            <?php if($mode==='guided'||$spec['type']==='textarea'): ?><small><?= lab_reading($spec['why']) ?></small><?php endif; ?></div><?php endforeach; ?></fieldset><?php endforeach; ?>
          <div class="lab-step-controls" data-lab-controls hidden><button type="button" class="lab-button lab-outline" data-lab-prev>← Προηγούμενο</button><span data-lab-position aria-live="polite"></span><button type="button" class="lab-button lab-outline" data-lab-next>Επόμενο →</button></div>
          <div class="lab-savebar"><span data-lab-save-status role="status">Προσωπικό πρόχειρο · <?= e(format_datetime($draft['updated_at'])) ?></span><div><button type="submit" name="action" value="save" class="lab-button lab-outline">Αποθήκευση</button><button type="submit" name="action" value="submit" class="lab-button" data-lab-submit><?= $mode==='guided'?'Έλεγχος εξάσκησης':'Υποβολή τεστ' ?></button></div></div>
          <p class="lab-small">Το τεστ απαιτεί ≥85% και όλες τις κρίσιμες αποφάσεις σωστές. Υπάρχει μία βαθμολογούμενη υποβολή· η αποθήκευση προχείρου δεν καταναλώνει την προσπάθεια. Τα κενά θεωρούνται απαντήσεις: αφήνεις κενό μόνο ό,τι είναι πραγματικά άγνωστο. Η σύνοψη δεν βαθμολογείται αυτόματα.</p>
        </form>
      </section>
    </div>
    <dialog class="lab-reference-dialog" data-lab-dialog aria-labelledby="lab-reference-title"><header><h2 id="lab-reference-title">Η συνομιλία δίπλα σου</h2><button type="button" class="lab-button lab-outline" data-lab-close>Επιστροφή στην καρτέλα</button></header><div data-lab-dialog-body></div></dialog>
  <?php elseif($meta&&!$team): $missing=academy_lab_missing($user,$lessonId); ?>
    <?php if($unlocked&&academy_can_test_retake($user)&&$attemptState['count']): ?><form class="lab-actions" method="post" action="<?= e(academy_url('practice.php?lesson='.$lessonId.'&mode=assessment')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="test_retake"><button class="lab-button" type="submit">Νέα επανεξέταση δοκιμής</button><span class="lab-small">Προσωπικός έλεγχος Sophianos · διατήρηση ιστορικού.</span></form><?php endif; ?>
    <section class="lab-hero"><div><span class="lab-eyebrow">ΠΥΛΩΝΑΣ 03 · <?= e(strtoupper($meta['company'])) ?></span><h1><?= e($meta['title']) ?></h1><p><?= e($meta['goal']) ?></p><p>≈ <?= (int)$meta['minutes'] ?>′ · 3 παραλλαγές εξάσκησης · μία βαθμολογούμενη προσπάθεια</p></div></section>
    <?php if($unlocked): ?><div class="lab-launch-grid"><section class="lab-panel"><span class="lab-eyebrow">ΠΡΩΤΑ ΜΑΘΑΙΝΩ</span><h2>Καθοδηγούμενη εξάσκηση</h2><p>Συνομιλία πελάτη, υποδείξεις ανά πεδίο, έλεγχος και εξήγηση κάθε απάντησης. Δεν μετρά ως ολοκλήρωση.</p><form method="post" action="<?= e(academy_url('practice.php?lesson='.$lessonId.'&mode=guided')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><button class="lab-button" type="submit">Άνοιγμα εξάσκησης →</button></form></section><section class="lab-panel"><span class="lab-eyebrow">ΜΕΤΑ ΕΦΑΡΜΟΖΩ</span><h2>Πρακτικό τεστ</h2><p>Συμπληρώνεις μόνος σου την καρτέλα. Η επιτυχία αποθηκεύεται στην προσωπική σου πρόοδο.</p><?php if($missing): ?><p><strong>Πρώτα ολοκλήρωσε:</strong></p><ul><?php foreach($missing as $id): ?><li><a href="<?= e(academy_url((isset($catalog[$id])?'practice.php':'index.php').'?lesson='.$id)) ?>"><?= e(academy_lesson($id)['title']) ?></a></li><?php endforeach; ?></ul><?php elseif($attemptState['allowed']): ?><form method="post" action="<?= e(academy_url('practice.php?lesson='.$lessonId.'&mode=assessment')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><button class="lab-button lab-outline" type="submit">Έναρξη τεστ →</button></form><?php endif; ?><p><?= e(academy_attempt_notice($attemptState)) ?></p><?php if($attemptState['first']): ?><a href="<?= e(academy_url('practice.php?result='.$attemptState['first']['id'])) ?>">Δες την πρώτη προσπάθεια</a><?php endif; ?></section></div><?php endif; ?>
  <?php else: ?>
    <section class="lab-hero"><div><span class="lab-eyebrow">ΠΥΛΩΝΑΣ 03 / LEARN BY DOING</span><h1>Η επόμενη κλήση σου.<br>Χωρίς το άγχος της πρώτης.</h1><p>Δύο εταιρείες. Πραγματικοί τρόποι εργασίας. Ένας ασφαλής χώρος για να δοκιμάσεις, να κάνεις λάθη και να βελτιωθείς.</p><a class="lab-button lab-light" href="#lab-route">Βρες την επόμενη άσκηση ↗</a></div><div class="lab-hero-stats"><strong><?= $done ?><small>/14</small></strong><span>πρακτικά τεστ ολοκληρωμένα</span><progress max="14" value="<?= $done ?>" aria-label="Πρόοδος CRM Lab"></progress><p>12 ασκήσεις δεξιοτήτων<br>2 τελικές αποστολές<br>42 παραλλαγές περιστατικών</p></div></section>
    <div class="lab-journey"><span><b>1</b> Διαβάζω τη συνομιλία</span><span><b>2</b> Συμπληρώνω την καρτέλα</span><span><b>3</b> Διορθώνω με ανατροφοδότηση</span><span><b>4</b> Περνάω το πρακτικό τεστ</span></div>
  <?php endif; ?>
  <?php if(!$unlocked&&!$team): ?>
    <section class="lab-unlock lab-panel"><div><span class="lab-eyebrow">ΤΟ DEMO ΠΡΟΦΙΛ ΣΟΥ</span><h2>Μπες στο εκπαιδευτικό γραφείο</h2><p>Πρώτα συνδέεσαι στην Academy με τον δικό σου λογαριασμό CRM. Εδώ ενεργοποιείς μόνο την προσομοίωση για 2 ώρες.</p><dl><dt>Demo email</dt><dd><code>demo@distillogic.gr</code></dd><dt>Demo password · μόνο για εκπαίδευση</dt><dd><code>Demo-CRM-Lab-2026!</code></dd></dl><p class="lab-small">Δεν δημιουργείται κοινός χρήστης σε κανένα παραγωγικό CRM. Οι βαθμοί σου δεν μοιράζονται με τους άλλους μαθητές.</p></div><form method="post" class="lab-unlock-form" action="<?= e(academy_url('practice.php'.(($navLesson??$lessonId)?'?lesson='.rawurlencode($navLesson??$lessonId).'&mode='.($navMode??$mode):''))) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="unlock"><label for="demo-email">Demo email</label><input id="demo-email" type="email" name="demo_email" value="demo@distillogic.gr" autocomplete="off" required><label for="demo-password">Demo password</label><input id="demo-password" type="password" name="demo_password" autocomplete="off" required><button class="lab-button" type="submit">Άνοιγμα CRM Lab →</button></form></section>
  <?php endif; ?>
  <?php if(!$meta&&!$result): ?>
    <section id="lab-route" class="lab-route"><div class="lab-section-heading"><div><span class="lab-eyebrow">ΜΙΑ ΚΑΘΑΡΗ ΔΙΑΔΡΟΜΗ</span><h2><?= $team?'Ασκήσεις και ανασκόπηση ομάδας':'Το εκπαιδευτικό σου γραφείο' ?></h2></div><div class="lab-switch" aria-label="Εταιρεία εξάσκησης" data-lab-filter hidden><button type="button" data-company="all" aria-pressed="true">Όλα</button><button type="button" data-company="melas" aria-pressed="false">Melas Energiaki</button><button type="button" data-company="distillogic" aria-pressed="false">Distillogic</button></div></div>
      <div class="lab-card-grid"><?php foreach($catalog as $id=>$item): $complete=!empty($progress[$id]['completed_at']); ?><a class="lab-card lab-company-<?= e($item['company']) ?>" href="<?= e(academy_url('practice.php?lesson='.$id)) ?>" data-lab-company="<?= e($item['company']) ?>"><div class="lab-card-meta"><span><?= e($item['number']) ?> / <?= $item['company']==='both'?'ΚΟΙΝΕΣ ΔΕΞΙΟΤΗΤΕΣ':e(strtoupper($item['company'])) ?></span><span><?= $complete?'✓ Ολοκληρώθηκε':($item['requires']?'Τελική αποστολή':'Άσκηση') ?></span></div><h3><?= e($item['title']) ?></h3><p><?= e($item['goal']) ?></p><footer><span>≈ <?= (int)$item['minutes'] ?>′ · 3 παραλλαγές</span><b aria-hidden="true">↗</b></footer></a><?php endforeach; ?></div>
    </section>
  <?php endif; ?>
  <?php if(!$case&&!$result): ?>
    <section class="lab-panel lab-history"><div class="lab-section-heading"><div><span class="lab-eyebrow">ΠΡΟΣΩΠΙΚΗ ΠΡΟΟΔΟΣ, ΟΧΙ ΚΟΙΝΟ DEMO SCORE</span><h2><?= $team?'Προσπάθειες μαθητών':'Οι τελευταίες προσπάθειές σου' ?></h2></div><?php if(academy_is_admin($user)): ?><a class="lab-button lab-outline" href="<?= e(academy_url('practice.php'.($team?'':'?view=team'))) ?>"><?= $team?'Η δική μου διαδρομή':'Ανασκόπηση ομάδας' ?></a><?php endif; ?></div>
      <?php if(!$history): ?><p>Δεν υπάρχουν ακόμη προσπάθειες. Ξεκίνα μια άσκηση και δες εδώ τις απαντήσεις και την εξέλιξή σου.</p><?php else: ?><div class="lab-table-scroll"><table><caption>Έως 100 πιο πρόσφατες προσπάθειες · οι παλιότερες διατηρούνται στη βάση</caption><thead><tr><?php if($team): ?><th>Μαθητής</th><?php endif; ?><th>Άσκηση</th><th>Τρόπος</th><th>Βαθμός</th><th>Αποτέλεσμα</th><th>Ημερομηνία</th></tr></thead><tbody><?php foreach($history as $row): ?><tr><?php if($team): ?><td><?= e($row['learner_name']) ?></td><?php endif; ?><th><a href="<?= e(academy_url('practice.php?result='.$row['id'].($team?'&view=team':''))) ?>"><?= e($catalog[$row['lesson_id']]['title']??$row['lesson_id']) ?></a></th><td><?= $row['mode']==='assessment'?'Τεστ':'Εξάσκηση' ?></td><td><?= (int)$row['score'] ?>/100</td><td><?= $row['passed']?'✓ Επιτυχία':'Επανάληψη' ?></td><td><?= e(format_datetime($row['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
  <?php endif; ?>
  <p class="lab-disclaimer">Το Lab προσομοιώνει τις βασικές ροές και επιλεγμένα πεδία των δύο CRM. Δεν εκτελεί live ενέργειες, δεν αλλάζει εταιρικά δικαιώματα και δεν υποκαθιστά την πρακτική αξιολόγηση από υπεύθυνο. Μετά τις τελικές αποστολές, κάνε <a href="<?= e(academy_url('assessment.php')) ?>">ανασκόπηση με τον εκπαιδευτή</a> πριν ανεξάρτητη εργασία.</p>
</div>
<?php render_footer(); ?>
