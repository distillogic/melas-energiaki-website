<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$user=require_login();ensure_customer_workspace_schema();
$query=trim((string)($_GET['q']??''));$status=trim((string)($_GET['status']??''));
$labels=['draft'=>'Draft','client_signed'=>'Client signed','approval_pending'=>'Pending CEO approval','approved_pdf'=>'CEO-approved PDF','sent'=>'Sent','active'=>'Active','terminated'=>'Terminated','expired'=>'Expired'];
$where=["d.document_type='msa'",'c.deleted_at IS NULL'];$params=[];
if($query!==''){$where[]='(d.document_reference LIKE ? OR d.title LIKE ? OR c.name LIKE ? OR c.legal_name LIKE ?)';$needle='%'.$query.'%';array_push($params,$needle,$needle,$needle,$needle);}
if(isset($labels[$status])){$where[]='d.document_status=?';$params[]=$status;}
$stmt=db()->prepare('SELECT d.*,c.name AS company_name FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE '.implode(' AND ',$where).' ORDER BY d.updated_at DESC');$stmt->execute($params);$rows=$stmt->fetchAll();
render_header('Master Services Agreements',$user);
?>
<div class="page-heading"><div><p class="eyebrow">MASTER SERVICES AGREEMENTS</p><h1>Master Services Agreements</h1><p>Το βασικό συμβόλαιο-ομπρέλα ανά πελάτη, πάνω στο οποίο συνδέονται τα επιμέρους SOW.</p></div><a class="button primary" href="<?= e(crm_url('msa.php')) ?>">+ Νέο MSA</a></div>
<section class="card toolbar-card"><form method="get" class="filters"><label>Αναζήτηση<input name="q" value="<?= e($query) ?>" placeholder="Πελάτης ή reference"></label><label>Κατάσταση<select name="status"><option value="">Όλες</option><?php foreach($labels as $value=>$label): ?><option value="<?= e($value) ?>" <?= $status===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><button class="button primary">Εφαρμογή</button><a class="button" href="<?= e(crm_url('msas.php')) ?>">Καθαρισμός</a></form></section>
<section class="card table-card"><?php if(!$rows): ?><div class="empty-state"><h2>Δεν υπάρχουν MSA</h2><p>Δημιουργήστε το πρώτο client-specific Master Services Agreement.</p><a class="button primary" href="<?= e(crm_url('msa.php')) ?>">Νέο MSA</a></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Reference</th><th>Πελάτης</th><th>Κατάσταση</th><th>Ενημέρωση</th><th></th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><strong><?= e($row['document_reference']) ?></strong><small><?= e($row['title']) ?></small></td><td><?= e($row['company_name']) ?></td><td><span class="proposal-status proposal-status-<?= e($row['document_status']) ?>"><?= e($labels[$row['document_status']]??$row['document_status']) ?></span></td><td><?= e(format_datetime($row['updated_at'])) ?></td><td class="table-actions"><a class="button small" href="<?= e(crm_url('msa.php?id='.urlencode((string)$row['id']))) ?>">Άνοιγμα</a><a class="button small" target="_blank" href="<?= e(crm_url('msa-view.php?id='.urlencode((string)$row['id']))) ?>">Προβολή</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php render_footer(); ?>
