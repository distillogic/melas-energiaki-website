<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=academy_require_login();require_once __DIR__.'/grades-workflow.php';
$target=is_string($_GET['user_id']??null)?$_GET['user_id']:$user['id'];
try{$report=academy_grade_report($user,$target);}catch(InvalidArgumentException){http_response_code(403);exit('Δεν έχεις πρόσβαση στη βαθμολογία αυτού του λογαριασμού.');}
$q=db()->prepare('SELECT name FROM '.academy_table('users').' WHERE id=?');$q->execute([$target]);$name=$q->fetchColumn();if(!$name){http_response_code(404);exit('Ο λογαριασμός δεν βρέθηκε.');}
$num=fn($v):string=>$v===null?'—':number_format((float)$v,2,',','.');
$pct=fn($r)=>$r?(100*(float)$r['score']/max(1,(int)$r['question_count'])):null;
render_header('Βαθμολογία',$user);
?>
<section class="ac-panel ac-lesson"><span class="ac-kicker">ΑΝΑΛΥΤΙΚΗ ΚΑΡΤΕΛΑ ΒΑΘΜΟΛΟΓΙΑΣ</span><h1><?= e($name) ?></h1>
<?php if(academy_can_review_training($user)): ?><form method="get" class="school-form"><label>Εκπαιδευόμενος<select name="user_id"><?php foreach(db()->query('SELECT id,name FROM '.academy_table('users').' WHERE active=1 ORDER BY name')->fetchAll() as $person): ?><option value="<?= e($person['id']) ?>" <?= $person['id']===$target?'selected':'' ?>><?= e($person['name']) ?></option><?php endforeach; ?></select></label><button class="school-button">Προβολή βαθμών</button></form><?php endif; ?>
<h2>Συνολικός βαθμός: <?= e($num($report['total'])) ?> / 100</h2><p><?= $report['complete']?'Οι απαιτήσεις αξιολόγησης ολοκληρώθηκαν. Η έγκριση της διοίκησης και τα απαιτούμενα έγγραφα παραμένουν ξεχωριστά βήματα.':($report['total']===null?'Δεν έχει οριστικοποιηθεί: λείπουν αποτελέσματα μαθημάτων, τελικής εξέτασης ή τουλάχιστον τριών διαφορετικών κλήσεων.':'Ο αριθμητικός βαθμός έχει υπολογιστεί, αλλά εκκρεμούν απαιτήσεις επιτυχίας. Δεν ισοδυναμεί με πιστοποίηση.'); ?></p>
<p><strong>Quiz μαθημάτων × 10% + τελική εξέταση × 70% + εικονικές κλήσεις × 20%.</strong></p>
<ul><li>Quiz: <?= e($num($report['quizMean'])) ?>/100 · <?= (int)$report['settled'] ?>/<?= (int)$report['quizzesTotal'] ?> οριστικοποιημένοι βαθμοί.</li><li>Τελική εξέταση: <?= e($num($report['examGrade'])) ?>/100 · μία επίσημη προσπάθεια.</li><li>Κλήσεις: <?= e($num($report['callMean'])) ?>/100 · μέσος όρος <?= count($report['calls']['graded']) ?> βαθμολογημένων κλήσεων, μαζί με αποτυχημένες και εγκεκριμένες επανεξετάσεις.</li></ul>
<p>Τα CRM Labs παραμένουν προαπαιτούμενα με μία βαθμολογούμενη προσπάθεια, αλλά δεν προστίθενται ως quiz στο 10%. Ελεύθερη εξάσκηση και προσωπικές δοκιμές δεν μετρούν σε κανένα από τα βάρη. Οι παλιές έγκυρες βαθμολογημένες κλήσεις διατηρούνται.</p>
<h2>Τελικός βαθμός ανά μάθημα</h2><p>Αν περάσεις με την πρώτη, ο βαθμός κλειδώνει. Αν περάσεις μόνο με τη δεύτερη: max(μέσος όρος των δύο ποσοστών, 80%). Αν αποτύχεις και στις δύο, εμφανίζεται ο μέσος όρος χωρίς προβιβασμό. Εγκεκριμένη πρόσθετη επανεξέταση μπορεί να ολοκληρώσει το μάθημα, χωρίς να ξαναγράψει αυτούς τους δύο βαθμούς.</p>
<?php foreach($report['lessons'] as $id=>$item):$s=$item['state']; ?>
<article class="ac-chapter"><h3><?= e($item['title']) ?> <?= $item['lab']?'· CRM Lab':'' ?><?= !empty($item['optional'])?' · Επιπλέον εκπαίδευση, εκτός υπάρχοντος συνολικού βαθμού':'' ?></h3><p>Πρώτη: <?= e($num($pct($s['first']))) ?>%<?php if(!$item['lab']): ?> · Δεύτερη: <?= e($num($pct($s['second']))) ?>%<?php endif; ?> · <strong><?= $item['settled']?'Τελικός':'Προσωρινός' ?> βαθμός: <?= e($num($item['grade'])) ?>/100</strong></p><p><?= e($s['grade_rule']) ?> · <?= $s['completed_at']?'Ολοκληρώθηκε':'Δεν έχει ολοκληρωθεί' ?></p><?php if($target===$user['id']): ?><a href="<?= e(academy_url('index.php?lesson='.rawurlencode($id))) ?>">Άνοιγμα μαθήματος</a><?php endif; ?></article>
<?php endforeach; ?>
<h2>Κλήσεις που συνυπολογίζονται</h2><?php foreach($report['calls']['graded'] as $run): ?><p><a href="<?= e(academy_url('call-run.php?id='.$run['id'])) ?>"><?= e(academy_call_catalog()[$run['scenario_id']]['title']) ?></a> · <?= (int)$run['score'] ?>/100 · <?= $run['passed']?'Επιτυχής':'Μη επιτυχής' ?> · <?= e(format_datetime($run['submitted_at'])) ?></p><?php endforeach; ?>
</section><?php render_footer(); ?>
