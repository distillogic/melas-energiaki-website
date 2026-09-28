<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$user=academy_require_login();
if (!academy_can_view_access_history($user)) {http_response_code(403);exit('Δεν έχετε πρόσβαση στο ιστορικό συνδέσεων.');}
$target=is_string($_GET['user']??null)?trim($_GET['user']):'';
$report=academy_access_rows($user,$target,max(1,(int)($_GET['page']??1)));
$people=db()->query('SELECT id,name,email FROM '.academy_table('users').' ORDER BY name,id')->fetchAll();
render_header('Ιστορικό συνδέσεων',$user);
?>
<section class="school-hero"><span class="eyebrow">ΠΡΟΣΒΑΣΗ · ΤΕΛΕΥΤΑΙΕΣ 30 ΗΜΕΡΕΣ</span><h1>Είσοδοι &amp; έξοδοι</h1><p>Ώρες Ελλάδας. Μόνο ο δικός σου διαχειριστικός λογαριασμός έχει πρόσβαση σε αυτή την προβολή.</p></section>
<section class="school-panel">
<p class="school-info">Το κλείσιμο καρτέλας δεν καταγράφεται ως έξοδος. Η «τελευταία δραστηριότητα» είναι το τελευταίο επιβεβαιωμένο αίτημα στη σελίδα — όχι χρόνος εργασίας ή απόδειξη συνεχούς παρουσίας. Η λήξη υπολογίζεται μετά από 30 λεπτά αδράνειας ή το ανώτατο όριο συνεδρίας.</p>
<form method="get" class="access-filter"><label>Εκπαιδευόμενος <select name="user"><option value="">Όλοι</option><?php foreach($people as $person): ?><option value="<?= e($person['id']) ?>" <?= $target===$person['id']?'selected':'' ?>><?= e($person['name'].' · '.$person['email']) ?></option><?php endforeach; ?></select></label><button class="school-button" type="submit">Προβολή</button></form>
<p><?= $report['count'] ?> εγγραφές · Σελίδα <?= $report['page'] ?> / <?= $report['pages'] ?></p>
<div class="access-table-scroll"><table class="access-table"><thead><tr><th>Χρήστης</th><th>Είσοδος</th><th>Τελευταία δραστηριότητα</th><th>Έξοδος / λήξη</th><th>Τι καταγράφηκε</th></tr></thead><tbody>
<?php foreach($report['rows'] as $row): ?><tr><td><strong><?= e($row['name']??'Λογαριασμός που δεν είναι πλέον διαθέσιμος') ?></strong><br><?= e($row['email']??'') ?></td><td><?= $row['login_at']===null?'Ήδη συνδεδεμένος πριν αρχίσει η καταγραφή':e(academy_access_time((int)$row['login_at'])) ?></td><td><?= e(academy_access_time((int)$row['last_seen_at'])) ?></td><td><?= e(academy_access_time($row['ended_at']===null?null:(int)$row['ended_at'])) ?></td><td><?= e(academy_access_reason($row['end_reason'])) ?></td></tr><?php endforeach; ?>
<?php if(!$report['rows']): ?><tr><td colspan="5">Δεν υπάρχουν καταγεγραμμένες συνεδρίες για αυτό το φίλτρο. Δεν ανακατασκευάζονται παλιές είσοδοι.</td></tr><?php endif; ?></tbody></table></div>
<nav aria-label="Σελίδες ιστορικού"><?php foreach(['Προηγούμενη'=>$report['page']-1,'Επόμενη'=>$report['page']+1] as $label=>$page): if($page<1||$page>$report['pages'])continue; ?><a class="school-button secondary" href="<?= e(academy_url('access-log.php?'.http_build_query(['user'=>$target,'page'=>$page]))) ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
<p>Οι εγγραφές διαγράφονται αυτόματα μετά από 30 ημέρες κατά τη χρήση της Academy και από την προγραμματισμένη εργασία συντήρησης. <a href="<?= e(academy_url('privacy.php')) ?>">Ενημέρωση απορρήτου</a>.</p>
</section><?php render_footer(); ?>
