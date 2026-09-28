<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$user=require_login();
if(!can_manage_accounts($user)){http_response_code(403);exit('Δεν έχετε πρόσβαση στο ιστορικό ενεργειών.');}
header('Cache-Control: no-store');
$mode=($_GET['mode']??'events')==='sessions'?'sessions':'events';
$actor=is_string($_GET['actor']??null)?$_GET['actor']:'';
$from=is_string($_GET['from']??null)?$_GET['from']:'';
$to=is_string($_GET['to']??null)?$_GET['to']:'';
$page=max(1,min(100000,(int)($_GET['page']??1)));
$rows=[];$actors=[];$error=null;$more=false;$where=[];$params=[];
foreach(['from'=>$from,'to'=>$to] as $key=>$value){
    if($value==='')continue;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$date||$date->format('Y-m-d')!==$value){$error='Ελέγξτε τις ημερομηνίες.';break;}
    $column=$mode==='sessions'?($key==='from'?'COALESCE(ended_at,last_seen_at)':'started_at'):'created_at';
    $where[]=$column.($key==='from'?' >= ?':' < ?');
    $params[]=$key==='from'?$date->format('Y-m-d'):$date->modify('+1 day')->format('Y-m-d');
}
if($from!==''&&$to!==''&&$from>$to)$error='Ελέγξτε τη σειρά των ημερομηνιών.';
if($actor!==''){$where[]='actor_id=?';$params[]=$actor;}
try{
    ensure_activity_schema();
    $actors=db()->query('SELECT actor_id,MAX(actor_email) AS email FROM (SELECT actor_id,actor_email FROM crm_business_activity UNION ALL SELECT actor_id,actor_email FROM crm_activity_sessions) a GROUP BY actor_id ORDER BY email')->fetchAll();
    if(!$error){
        $sql=$mode==='sessions'?'SELECT *,last_seen_at >= DATE_SUB(NOW(),INTERVAL 30 MINUTE) AS recent FROM crm_activity_sessions':'SELECT * FROM crm_business_activity';
        $q=db()->prepare($sql.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY '.($mode==='sessions'?'started_at':'created_at').' DESC,id DESC LIMIT 51 OFFSET '.(($page-1)*50));
        $q->execute($params);$rows=$q->fetchAll();$more=count($rows)>50;if($more)array_pop($rows);
    }
}catch(Throwable $exception){$error='Το ιστορικό δεν είναι διαθέσιμο. Ελέγξτε τη σύνδεση και τα δικαιώματα της βάσης.';}
render_header('Ιστορικό ενεργειών',$user);
?>
<div class="page-heading"><div><h1>Συνδέσεις &amp; σημαντικές ενέργειες</h1><p>Χωρίς καταγραφή επισκέψεων σε καρτέλες.</p></div><a class="button" href="<?= e(crm_url('accounts.php')) ?>">Λογαριασμοί</a></div>
<nav class="actions" aria-label="Ιστορικό"><?php foreach(['events'=>'Σημαντικές ενέργειες','sessions'=>'Περίοδοι σύνδεσης'] as $key=>$label): ?><a class="button <?= $mode===$key?'primary':'' ?>" href="<?= e(crm_url('activity.php?'.http_build_query(['mode'=>$key,'actor'=>$actor,'from'=>$from,'to'=>$to]))) ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
<section class="card form-section" style="margin-top:20px">
<form method="get" class="form-grid"><input type="hidden" name="mode" value="<?= e($mode) ?>"><label>Χρήστης<select name="actor"><option value="">Όλοι</option><?php foreach($actors as $a): ?><option value="<?= e($a['actor_id']) ?>" <?= $actor===$a['actor_id']?'selected':'' ?>><?= e($a['email']) ?></option><?php endforeach; ?></select></label><label>Από<input type="date" name="from" value="<?= e($from) ?>"></label><label>Έως<input type="date" name="to" value="<?= e($to) ?>"></label><div class="actions"><button class="button primary">Εφαρμογή</button><a class="button" href="<?= e(crm_url('activity.php?mode='.$mode)) ?>">Καθαρισμός</a></div></form>
<?php if($mode==='sessions'): ?><p>Η περίοδος μετρά από τη σύνδεση ή την πρώτη δραστηριότητα. Μετά από 30 λεπτά αδράνειας θεωρείται ανενεργή, έως την τελευταία δραστηριότητα — δεν είναι μέτρηση χρόνου εργασίας. Το κλείσιμο του browser δεν αποτελεί επιβεβαιωμένη αποσύνδεση.</p><?php else: ?><p>Εμφανίζονται ολοκληρωμένες ενέργειες από την εγκατάσταση της νέας καταγραφής και μετά.</p><?php endif; ?>
</section>
<?php if($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<section class="catalog-card"><div class="table-wrap"><table><thead><tr><th><?= $mode==='sessions'?'Έναρξη':'Ημερομηνία' ?></th><th>Χρήστης</th><?php if($mode==='sessions'): ?><th>Τελευταία δραστηριότητα / λήξη</th><th>Κατάσταση</th><?php else: ?><th>Ενέργεια</th><?php endif; ?></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?= e(format_datetime($row[$mode==='sessions'?'started_at':'created_at'])) ?></td><td><?= e($row['actor_email']) ?></td><?php if($mode==='sessions'): ?><td><?= e(format_datetime($row['ended_at']?:$row['last_seen_at'])) ?></td><td><?= $row['end_reason']==='logout'?'Αποσυνδέθηκε':(!$row['ended_at']&&$row['recent']?'Πρόσφατα ενεργός':'Ανενεργός — τελευταία δραστηριότητα') ?></td><?php else: ?><td><?= e($row['description']) ?></td><?php endif; ?></tr><?php endforeach; ?>
<?php if(!$rows&&!$error): ?><tr><td colspan="<?= $mode==='sessions'?4:3 ?>">Δεν υπάρχουν καταγραφές για τα επιλεγμένα φίλτρα.</td></tr><?php endif; ?></tbody></table></div></section>
<div class="actions" style="margin-top:20px"><?php foreach([$page-1=>'Προηγούμενη',$page+1=>'Επόμενη'] as $p=>$label): if($p<1||($p>$page&&!$more))continue; ?><a class="button" href="<?= e(crm_url('activity.php?'.http_build_query(['mode'=>$mode,'actor'=>$actor,'from'=>$from,'to'=>$to,'page'=>$p]))) ?>"><?= e($label) ?></a><?php endforeach; ?></div>
<?php render_footer(); ?>
