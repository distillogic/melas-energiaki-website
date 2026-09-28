<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=academy_require_login();require_once __DIR__.'/call-workflow.php';require_once __DIR__.'/call-conversation-v38.php';academy_call_schema();
$id=is_string($_GET['id']??null)?$_GET['id']:'';$error=null;
try{$run=academy_call_get($user,$id);}catch(InvalidArgumentException){http_response_code(404);exit('Η προσομοίωση δεν βρέθηκε.');}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    verify_csrf();
    try{
        $action=academy_input('action');
        if($action==='choose')academy_call_choose($user,$id,academy_input('node'),academy_input('choice'));
        elseif(in_array($action,['save','submit'],true)&&is_array($_POST['answers']??null))academy_call_submit($user,$id,$_POST['answers'],$action==='submit');
        else throw new InvalidArgumentException('Μη έγκυρη υποβολή.');
        redirect_to('call-run.php?id='.rawurlencode($id).($action==='choose'?'#call-next':($action==='save'?'#call-next':'#call-result')));
    }catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
    $run=academy_call_get($user,$id);
}
$case=academy_call_case($run['scenario_id'],(int)$run['variant'],(int)$run['scenario_version']);$choices=json_decode($run['choices_json'],true,512,JSON_THROW_ON_ERROR);
$trace=academy_call_trace($case,$choices);$own=$run['user_id']===$user['id'];$guided=$run['mode']==='guided';$test=$run['mode']==='test';
$draft=json_decode($run['draft_json'],true,512,JSON_THROW_ON_ERROR);
if($error&&is_array($_POST['answers']??null))foreach($case['fields'] as $key=>$field)if(is_string($_POST['answers'][$key]??null))$draft[$key]=mb_substr($_POST['answers'][$key],0,250);
render_header('Εικονική κλήση · '.$case['title'],$user);
?>
<div class="sim-shell">
<nav class="sim-breadcrumb"><a href="<?= e(academy_url('supervised-calls.php')) ?>">← Εικονικές κλήσεις</a><span><?= $test?'Προσωπική δοκιμή — χωρίς βαθμολογικό βάρος':($guided?'Ελεύθερη εξάσκηση':'Βαθμολογούμενη προσπάθεια '.(int)$run['attempt_number']) ?></span></nav>
<header class="sim-heading"><span class="ac-kicker"><?= $case['brand']==='melas'?'MELAS ENERGIAKI':'DISTILLOGIC' ?> · ΠΡΟΣΟΜΟΙΩΣΗ</span><h1><?= e($case['title']) ?></h1><p><?= e($case['company']) ?> · Όλα τα στοιχεία είναι φανταστικά. Δεν πραγματοποιείται πραγματική κλήση και δεν αλλάζει κανένα σύστημα διαχείρισης πελατειακών σχέσεων (CRM).</p></header>
<?php if($error): ?><p class="school-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if($run['status']==='submitted'): $result=json_decode($run['result_json'],true,512,JSON_THROW_ON_ERROR); ?>
<section id="call-result" class="sim-result <?= $result['passed']?'is-pass':'is-review' ?>"><div><span class="ac-kicker"><?= $test?'ΑΠΟΤΕΛΕΣΜΑ ΔΟΚΙΜΗΣ':($guided?'ΑΠΟΤΕΛΕΣΜΑ ΕΞΑΣΚΗΣΗΣ':'ΟΡΙΣΤΙΚΟ ΑΠΟΤΕΛΕΣΜΑ') ?></span><h2><?= $result['passed']?'Ολοκλήρωσες με επιτυχία':'Δες τι χρειάζεται βελτίωση' ?></h2><p>Συνομιλία: <?= (int)$result['dialogue_score'] ?>/50 · Καρτέλα CRM: <?= (int)$result['crm_score'] ?>/50</p><p>Βάση: 65/100. Κρίσιμο λάθος: <?= $result['critical_failure']?'Ναι — εμποδίζει επιτυχία ανεξάρτητα από τον βαθμό.':'Όχι.' ?></p></div><strong class="sim-score"><?= (int)$result['score'] ?><small>/100</small></strong></section>
<p class="school-info"><?= ($guided||$test)?'Η εξάσκηση / δοκιμή δεν μετρά στην πιστοποίηση. Μπορείς να την επαναλάβεις ελεύθερα.':'Η προσπάθεια διατηρείται στο ιστορικό. Δεν αντικαθίσταται με επαναλαμβανόμενες υποβολές. Για επανεξέταση μετά από αποτυχία χρειάζεται άδεια διαχειριστή.' ?></p>
<h2>Ανασκόπηση συνομιλίας</h2><div class="sim-message-list"><?php foreach(academy_conversation_opening($case,$run['scenario_id']) as [$side,$speaker,$text])academy_conversation_message($side,$speaker,$text); ?></div>
<?php if(!empty($result['missing_required'])): ?><p class="school-error">Η διερεύνηση έμεινε ελλιπής: <?= e(implode(' · ',$result['missing_required'])) ?>. Η ορθή καταχώριση «δεν γνωρίζω» είναι έντιμη, αλλά δεν υποκαθιστά τη διερεύνηση των αναγκαίων στοιχείων.</p><?php endif; ?>
<?php foreach($result['turns'] as $turn): ?><article class="sim-feedback"><p class="sim-speaker">Πελάτης</p><p><?= e($turn['prompt']) ?></p><p><strong>Η απάντησή σου:</strong> <?= e($turn['choice']) ?></p><p class="sim-customer-reply"><?= e($turn['reply']) ?></p><p class="quiz-answer"><strong>Προτεινόμενη σωστή απάντηση:</strong> <?= e($turn['correct']) ?></p><p><?= $turn['critical']?'Κρίσιμο λάθος: μη εξουσιοδοτημένη υπόσχεση, ανακριβής καταγραφή ή παραβίαση ορίων επικοινωνίας.':((int)$turn['points']===10?'Σωστή διερεύνηση / επαγγελματικός χειρισμός.':'Η απάντηση άφησε εκκρεμότητα ή χρειάστηκε αποκατάσταση της συζήτησης.') ?></p></article><?php endforeach; ?>
<div class="sim-message-list"><?php academy_conversation_farewell(); ?></div>
<h2>Η εκπαιδευτική καρτέλα CRM</h2>
<?php foreach($result['fields'] as $key=>$field): $spec=$case['fields'][$key];$given=$field['answer']===''||$field['answer']==='__unknown'?'Κενό / δεν γνωρίζω':($spec['options'][$field['answer']]??$field['answer']); ?><article class="sim-feedback"><h3><?= e($field['label']) ?> · <?= $field['correct']?'Σωστά':'Χρειάζεται διόρθωση' ?></h3><p>Η απάντησή σου: <?= e($given) ?></p><p class="quiz-answer"><strong>Σωστή καταχώριση:</strong> <?= e($field['expected']) ?></p><?php if($field['critical']): ?><p class="school-error">Κρίσιμο στοιχείο: δεν δηλώνουμε συναίνεση, έγκριση ή δέσμευση που δεν τεκμηριώνεται.</p><?php endif; ?></article><?php endforeach; ?>
<a class="school-button" href="<?= e(academy_url('supervised-calls.php'.($own?'':'?user_id='.rawurlencode($run['user_id'])))) ?>">Επιστροφή στις προσομοιώσεις</a>
<?php elseif(!$own): ?>
<p class="school-info">Η προσπάθεια βρίσκεται σε εξέλιξη. Ο εκπαιδευόμενος την ολοκληρώνει από τον δικό του λογαριασμό.</p>
<?php elseif((int)$run['scenario_version']!==$case['version']): ?><p class="school-info">Το σενάριο ενημερώθηκε. Άνοιξε την τρέχουσα έκδοση από τη λίστα προσομοιώσεων.</p>
<?php else: ?>
<nav class="sim-wayfinding" aria-label="Μετάβαση στην προσομοίωση"><a href="#call-conversation">Συνομιλία</a><a href="#call-next"><?= $trace['node']==='crm'?'Καρτέλα CRM':'Η απάντησή μου' ?> ↓</a></nav>
<section class="sim-call-card sim-conversation" id="call-conversation">
<div class="sim-call-bar"><span class="sim-avatar" aria-hidden="true"><?= $case['brand']==='melas'?'M':'D' ?></span><div><strong>Η συνομιλία σας</strong><small>Γραπτή προσομοίωση · από τον χαιρετισμό στο επόμενο βήμα</small></div><span class="sim-live">Χωρίς ήχο</span></div>
<p class="sim-conversation-note">Ο χαιρετισμός και ο αποχαιρετισμός είναι η εισαγωγή και το κλείσιμο του σεναρίου. Εσύ επιλέγεις τις ουσιαστικές απαντήσεις· αυτές καθορίζουν τη συνέχεια και τη βαθμολογία.</p>
<div class="sim-message-list" data-sim-thread data-turn-count="<?= count($trace['turns']) ?>" tabindex="0" role="region" aria-label="Πλήρης συνομιλία από την αρχή">
<?php foreach(academy_conversation_opening($case,$run['scenario_id']) as [$side,$speaker,$text])academy_conversation_message($side,$speaker,$text); ?>
<?php foreach($trace['turns'] as $turn): ?>
<?php academy_conversation_message('customer','Πελάτης',$turn['prompt']);academy_conversation_message('learner','Η απάντησή σου',$turn['choice']);academy_conversation_message('customer','Πελάτης',$turn['reply']); ?>
<?php if($guided): ?><p class="sim-coach quiz-answer"><strong>Σημείωση εκπαιδευτή:</strong> <?= e($turn['correct']) ?></p><?php endif; ?>
<?php endforeach; ?>
<?php if($trace['node']==='crm'): academy_conversation_farewell(); else: academy_conversation_message('customer','Πελάτης',$case['nodes'][$trace['node']]['prompt']); endif; ?>
</div>
<div class="sim-conversation-progress"><span><?= count($trace['turns']) ?> / <?= count($case['nodes']) ?> αποφάσεις</span><progress value="<?= count($trace['turns']) ?>" max="<?= count($case['nodes']) ?>" aria-label="Πρόοδος συζήτησης, όχι βαθμολογία"></progress></div>
</section>
<?php if($trace['node']!=='crm'): $options=$case['nodes'][$trace['node']]['options'];uksort($options,fn($a,$b)=>strcmp(academy_call_token($run,$trace['node'],$a),academy_call_token($run,$trace['node'],$b))); ?>
<section class="sim-choices" id="call-next"><h2>Πώς συνεχίζεις τη συζήτηση;</h2><p>Διάβασε το τελευταίο μήνυμα του πελάτη και διάλεξε μία απάντηση. Η επιλογή σου καταγράφεται και δεν αλλάζει μετά.</p>
<div class="sim-current-message"><span>ΤΩΡΑ Ο ΠΕΛΑΤΗΣ ΛΕΕΙ</span><p><?= e($case['nodes'][$trace['node']]['prompt']) ?></p></div>
<?php foreach($options as $key=>$option): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="choose"><input type="hidden" name="node" value="<?= e($trace['node']) ?>"><input type="hidden" name="choice" value="<?= e(academy_call_token($run,$trace['node'],$key)) ?>"><button class="sim-choice" type="submit"><span><?= e($option['text']) ?></span><span aria-hidden="true">↗</span></button></form><?php endforeach; ?></section>
<?php else: ?>
<section class="sim-crm" id="call-next"><span class="ac-kicker">ΑΠΟ ΤΗ ΣΥΝΟΜΙΛΙΑ ΣΤΗΝ ΚΑΤΑΧΩΡΙΣΗ</span><h2>Η δική σου καρτέλα CRM</h2><p>CRM σημαίνει σύστημα διαχείρισης πελατειακών σχέσεων. Εδώ συμπληρώνεις μόνο εκπαιδευτικά δεδομένα. Όσα δεν ειπώθηκαν μένουν άγνωστα· μην τα μαντεύεις. Οι τιμές γράφονται χωρίς διαχωριστικό χιλιάδων, π.χ. 27200 ή 8,50.</p>
<form method="post" class="school-form sim-crm-form"><?= csrf_field() ?>
<?php foreach($case['fields'] as $key=>$field): ?><label><?= e($field['label']) ?><?php if($field['type']==='select'): ?><select name="answers[<?= e($key) ?>]"><option value="__unknown">Δεν γνωρίζω / δεν αναφέρθηκε</option><?php foreach($field['options'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= ($draft[$key]??'')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select><?php else: ?><input type="text" name="answers[<?= e($key) ?>]" value="<?= e($draft[$key]??'') ?>" maxlength="250" <?= $field['type']==='number'?'inputmode="decimal"':'' ?> autocomplete="off" placeholder="Κενό αν δεν επιβεβαιώθηκε"><?php endif; ?></label><?php endforeach; ?>
<div class="sim-form-actions"><button type="submit" name="action" value="save" class="sim-secondary">Αποθήκευση προχείρου</button><button type="submit" name="action" value="submit" class="school-button">Οριστική υποβολή & αποτέλεσμα</button></div><p class="sim-fine">Η υποβολή βαθμολογείται αυτόματα: 50 μονάδες συνομιλία + 50 μονάδες καρτέλα. Βάση 65/100 και κανένα κρίσιμο λάθος. Τα κενά λαμβάνονται υπόψη.</p>
</form></section>
<?php endif; endif; ?>
</div><script src="<?= e(academy_url('conversation-v38.js?v=38')) ?>" defer></script><?php render_footer(); ?>
