<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();
if (!in_array($user['role'], ['admin', 'manager'], true)) { http_response_code(403); exit('Η πρόσβαση στο Project Intake επιτρέπεται μόνο στη διοίκηση.'); }

$query = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 120));
$status = trim((string)($_GET['status'] ?? ''));
$statusLabels = ['draft'=>'Draft','qualified'=>'BID','hold'=>'HOLD','no_bid'=>'NO-BID'];
$where = ["d.document_type='intake'", 'c.deleted_at IS NULL'];
$params = [];
if ($query !== '') { $where[]='(d.document_reference LIKE ? OR d.title LIKE ? OR c.name LIKE ? OR c.legal_name LIKE ?)'; $needle='%'.$query.'%'; array_push($params,$needle,$needle,$needle,$needle); }
if (array_key_exists($status,$statusLabels)) { $where[]='d.document_status=?'; $params[]=$status; }
$statement=db()->prepare('SELECT d.*,c.name AS company_name FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE '.implode(' AND ',$where).' ORDER BY d.updated_at DESC');
$statement->execute($params);
$intakes=$statement->fetchAll();

render_header('Project Intake', $user);
?>
<div class="page-heading"><div><p class="eyebrow">INTERNAL / MANAGEMENT ONLY</p><h1>Project Intake &amp; RFP Qualification</h1><p>Qualification πριν από proposal: requirement, budget, authority, procurement, exposure και bid / no-bid.</p></div><div class="actions"><a class="button primary" href="<?= e(crm_url('intake.php')) ?>">+ Νέο Project Intake</a></div></div>
<section class="card toolbar-card"><form method="get" class="filters"><label>Αναζήτηση<input name="q" value="<?= e($query) ?>" placeholder="Πελάτης, project ή reference"></label><label>Απόφαση<select name="status"><option value="">Όλες</option><?php foreach($statusLabels as $value=>$label): ?><option value="<?= e($value) ?>" <?= $status===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><button class="button primary">Εφαρμογή</button><a class="button" href="<?= e(crm_url('intakes.php')) ?>">Καθαρισμός</a></form></section>
<section class="card table-card"><?php if(!$intakes): ?><div class="empty-state"><h2>Δεν υπάρχουν Project Intakes</h2><p>Δημιουργήστε το πρώτο qualification πριν από την επόμενη πρόταση.</p><a class="button primary" href="<?= e(crm_url('intake.php')) ?>">Νέο Project Intake</a></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Reference / Opportunity</th><th>Πελάτης</th><th>Απόφαση</th><th>Win probability</th><th>Ενημέρωση</th><th></th></tr></thead><tbody><?php foreach($intakes as $row): $item=json_decode((string)$row['data_json'],true)?:[]; ?><tr><td><strong><?= e($row['document_reference']) ?></strong><small><?= e($item['project_name']??$row['title']) ?></small></td><td><?= e($row['company_name']) ?></td><td><span class="proposal-status proposal-status-<?= e($row['document_status']) ?>"><?= e($statusLabels[$row['document_status']]??$row['document_status']) ?></span></td><td><?= e($item['win_probability']??'—') ?></td><td><?= e(format_datetime($row['updated_at'])) ?></td><td class="table-actions"><a class="button small" href="<?= e(crm_url('intake.php?id='.urlencode((string)$row['id']))) ?>">Άνοιγμα</a><a class="button small" target="_blank" href="<?= e(crm_url('intake-view.php?id='.urlencode((string)$row['id']))) ?>">Προβολή</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php render_footer(); ?>
