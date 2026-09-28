<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=academy_require_admin();
require_once __DIR__.'/courses.php';ensure_academy_schema();$error=null;$notice=null;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    verify_csrf();
    try{academy_grant_retake($user,academy_input('user_id'),academy_input('lesson_id'),academy_input('reason'));$_SESSION['notice']='Εγκρίθηκε μία επανεξέταση. Ο πρώτος βαθμός παραμένει αμετάβλητος.';redirect_to('retakes.php');}
    catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
}
$members=academy_team($user);
$history=db()->query('SELECT g.*,u.name learner_name,a.name actor_name FROM '.academy_table('retake_grants').' g JOIN '.academy_table('users').' u ON u.id=g.user_id JOIN '.academy_table('users').' a ON a.id=g.actor_id ORDER BY g.created_at DESC,g.id DESC LIMIT 100')->fetchAll();
render_header('Επανεξετάσεις',$user);
?>
<section class="ac-panel"><h1>Επανεξετάσεις μαθημάτων</h1><p>Μόνο για μαθητή που δεν έχει ολοκληρώσει το μάθημα και έχει εξαντλήσει τις κανονικές προσπάθειες (δύο στο quiz, μία στο CRM Lab). Κάθε έγκριση επιτρέπει μία επιπλέον υποβολή. Δεν αλλάζει τον επίσημο πρώτο βαθμό· μια επιτυχής επανεξέταση ξεκλειδώνει τη συνέχεια. Δεν επιτρέπεται αυτοέγκριση.</p>
<?php if($error): ?><p class="school-alert" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php foreach($members as $member): if($member['id']===$user['id'])continue; $eligible=array_filter($member['progress'],fn($p)=>empty($p['completed_at'])); if(!$eligible)continue; ?>
<details><summary><?= e($member['name'].' · '.$member['email']) ?></summary>
<?php foreach($eligible as $id=>$p): $state=academy_attempt_state($member,$id); ?>
<section class="ac-panel"><h2><?= e(academy_lesson($id)['title']) ?></h2><p><?= e(academy_attempt_notice($state)) ?></p>
<?php if(!$state['allowed']&&$state['count']): ?><form method="post" class="school-form"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= e($member['id']) ?>"><input type="hidden" name="lesson_id" value="<?= e($id) ?>"><label>Αιτιολογία και οδηγίες επανάληψης<textarea name="reason" required minlength="15" maxlength="2000" rows="3"></textarea></label><button class="school-button" type="submit">Έγκριση μίας επανεξέτασης</button></form><?php endif; ?></section>
<?php endforeach; ?></details><?php endforeach; ?></section>
<section class="ac-panel"><h2>Ιστορικό εγκρίσεων</h2><p>Οι 100 πιο πρόσφατες· όλες διατηρούνται στη βάση.</p>
<?php if(!$history): ?><p>Δεν υπάρχουν εγκρίσεις.</p><?php endif; ?>
<?php foreach($history as $row): ?><details><summary><?= e($row['learner_name'].' · '.academy_lesson($row['lesson_id'])['title']) ?> · <?= $row['attempt_id']?'Χρησιμοποιήθηκε':'Διαθέσιμη' ?></summary><p><?= e($row['reason']) ?></p><p><?= e($row['actor_name'].' · '.$row['created_at']) ?></p></details><?php endforeach; ?></section>
<?php render_footer(); ?>
