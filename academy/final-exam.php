<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=academy_require_login();require_once __DIR__.'/courses.php';
ensure_academy_schema();academy_final_schema();$error=null;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    verify_csrf();
    try{
        if(in_array(academy_input('action'),['start','test_restart'],true))$id=academy_start_final($user,academy_input('action')==='test_restart');
        elseif(academy_input('action')==='submit'&&is_array($_POST['answers']??null)){$id=academy_input('exam_id');academy_submit_final($user,$id,$_POST['answers']);}
        else throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
        redirect_to('final-exam.php?id='.rawurlencode($id));
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
}
$id=is_string($_GET['id']??null)?$_GET['id']:'';$exam=null;
if($id!==''){$q=db()->prepare('SELECT * FROM '.academy_table('final_exams').' WHERE id=? AND user_id=?');$q->execute([$id,$user['id']]);$exam=$q->fetch();if(!$exam){http_response_code(404);exit('Η εξέταση δεν βρέθηκε.');}}
if(!$exam&&!academy_can_test_retake($user))$exam=academy_final_current($user);
render_header('Τελική εξέταση',$user);
?>
<section class="ac-panel ac-lesson"><span class="ac-kicker">ΤΕΣΣΕΡΙΣ ΠΥΛΩΝΕΣ · ΜΙΑ ΟΛΟΚΛΗΡΩΜΕΝΗ ΕΙΚΟΝΑ</span><h1>Τελική εξέταση Sales Academy</h1><p>32 ερωτήσεις με τυχαία σειρά: 8 ανά πυλώνα, οι 4 από ξεχωριστά νέα περιστατικά και οι 4 από διαφορετικά μαθήματα. Συνολικά 16 περιστατικά δεν έχουν χρησιμοποιηθεί στα quizzes των μαθημάτων. Χρόνος 90 λεπτά, βάση 65/100. Μία επίσημη προσπάθεια για την τρέχουσα ύλη — η δεύτερη ευκαιρία των quiz δεν ισχύει εδώ. Λανθασμένη απάντηση σε κρίσιμο θέμα κατοχύρωσης, εξουσιοδότησης ή προστασίας επικοινωνίας εμποδίζει την επιτυχία ανεξάρτητα από το σύνολο. Ακολουθούν τουλάχιστον 3 διαφορετικά επιτυχή σενάρια εικονικών κλήσεων, με περιστατικά και από τις δύο εταιρείες, και τελική ανασκόπηση της διοίκησης.</p>
<?php if($error): ?><p class="school-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if(academy_can_test_retake($user)): ?><p class="school-info">ΠΡΟΣΩΠΙΚΗ ΔΟΚΙΜΗ SOPHIANOS: άμεση πρόσβαση χωρίς προαπαιτούμενα. Οι δοκιμές δεν μετρούν στη βαθμολογία, στην πιστοποίηση ή στην ενεργοποίηση πρόσβασης CRM.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_restart"><button class="school-button">Νέα δοκιμή / συνέχεια ενεργής</button></form><?php endif; ?>
<?php if(!$exam): ?>
<?php if(academy_final_access($user)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="start"><button class="school-button">Έναρξη / συνέχεια εξέτασης</button></form><?php else: ?><p class="school-info">Η εξέταση ξεκλειδώνει όταν ολοκληρώσεις όλα τα θεωρητικά μαθήματα και τα CRM Labs.</p><a class="school-button" href="<?= e(academy_url()) ?>">Συνέχεια μαθημάτων</a><?php endif; ?>
<?php elseif($exam['status']==='in_progress'&&strtotime($exam['expires_at'])>=time()&&hash_equals($exam['content_hash'],academy_final_run_hash($user))): ?>
<p>Ολοκλήρωση έως <?= e(format_datetime($exam['expires_at'])) ?>. Μπορείς να επιστρέψεις στην ίδια προσπάθεια μέχρι τότε.</p>
<form method="post" class="ac-quiz-form"><?= csrf_field() ?><input type="hidden" name="action" value="submit"><input type="hidden" name="exam_id" value="<?= e($exam['id']) ?>">
<?php foreach(json_decode($exam['questions_json'],true) as $i=>$question): ?><fieldset><legend><?= ($i+1).'. '.e($question['prompt']) ?><?= $question['critical']?' · Κρίσιμο σημείο':'' ?></legend><?php foreach($question['options'] as $j=>$option): ?><label class="ac-choice"><input type="radio" name="answers[<?= $i ?>]" value="<?= $j ?>" required><span><?= e($option) ?></span></label><?php endforeach; ?></fieldset><?php endforeach; ?><button class="school-button">Οριστική υποβολή</button></form>
<?php elseif($exam['status']==='in_progress'): ?><p class="school-info">Η προσπάθεια έληξε ή ενημερώθηκε η ύλη. Η βαθμολογία δεν καταχωρίστηκε. Η λήξη καταναλώνει την επίσημη προσπάθεια· δεν ξεκινά αυτόματα δεύτερη.</p><a href="<?= e(academy_url('final-exam.php')) ?>">Επιστροφή στην εξέταση</a>
<?php else: ?><h2><?= $exam['status']==='passed'?'Επιτυχία':'Μη επιτυχής προσπάθεια' ?> · <?= (int)$exam['score'] ?>/100</h2><p>Κρίσιμο λάθος: <?= $exam['critical_failure']?'Ναι':'Όχι' ?>. Η ολοκλήρωση θεωρίας δεν υποκαθιστά την αυτόματη εικονική πρακτική.</p>
<?php $answers=json_decode($exam['answers_json'],true);foreach(json_decode($exam['questions_json'],true) as $i=>$question): ?><article class="ac-chapter"><h3><?= e($question['prompt']) ?></h3><p>Η απάντησή σου: <?= e($question['options'][$answers[$i]] ?? '—') ?></p><p class="quiz-answer"><strong>Σωστή απάντηση:</strong> <?= e($question['options'][$question['correct']]) ?></p><p><?= $answers[$i]===$question['correct']?'Σωστά.':'Χρειάζεται προσοχή.' ?> <?= e($question['why']) ?></p><a href="<?= e(academy_url('index.php?lesson='.$question['lesson'])) ?>">Επανάληψη μαθήματος</a></article><?php endforeach; ?><a class="school-button" href="<?= e(academy_url('supervised-calls.php')) ?>">Επόμενο βήμα: εικονικές κλήσεις</a><?php endif; ?>
<h2>Οι προηγούμενες προσπάθειές μου</h2><?php $q=db()->prepare('SELECT id,status,score,started_at,content_hash FROM '.academy_table('final_exams').' WHERE user_id=? ORDER BY started_at DESC LIMIT 20');$q->execute([$user['id']]);foreach($q->fetchAll() as $row): ?><p><a href="<?= e(academy_url('final-exam.php?id='.$row['id'])) ?>"><?= e(format_datetime($row['started_at'])) ?></a> · <?= e($row['status']) ?><?= hash_equals($row['content_hash'],hash('sha256',academy_exam_fingerprint().':personal-qa-v34'))?' · ΔΟΚΙΜΗ':'' ?><?= $row['score']!==null?' · '.(int)$row['score'].'/100':'' ?></p><?php endforeach; ?></section>
<?php render_footer(); ?>
