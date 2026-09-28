<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';$user=academy_require_login();
if(!academy_can_review_training($user)){http_response_code(403);exit('Δεν έχετε εκπαιδευτική αρμοδιότητα.');}
render_header('Εκπαιδευτική εποπτεία',$user);
?><section class="school-panel"><h1>Εκπαιδευτική εποπτεία</h1><p>Πρόοδος και βαθμοί για καθοδήγηση. Η αρμοδιότητα αυτή δεν δίνει πρόσβαση σε οικονομικά, κωδικούς, ιστορικό συνδέσεων ή αλλαγή εταιρικών ρόλων. Μόνο ο Examiner (εξεταστής) ή η διοίκηση μπορούν να καταχωρίζουν τελική αξιολόγηση, ποτέ για τον εαυτό τους.</p>
<?php foreach(db()->query('SELECT id,name FROM '.academy_table('users').' WHERE active=1 ORDER BY name,id')->fetchAll() as $learner): ?><article class="school-panel"><h2><?= e($learner['name']) ?></h2><a href="<?= e(academy_url('grades.php?user_id='.rawurlencode($learner['id']))) ?>">Πρόοδος / βαθμοί</a><?php if(academy_can_assess_training($user)&&$learner['id']!==$user['id']): ?> · <a href="<?= e(academy_url('assessment.php?user_id='.rawurlencode($learner['id']))) ?>">Αξιολόγηση</a><?php endif; ?></article><?php endforeach; ?></section><?php render_footer(); ?>
