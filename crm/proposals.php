<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();

$query = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$allowedStatuses = ['draft','pdf_ready','approval_pending','ceo_approved','approved_pdf','sent','accepted','rejected','expired'];
$where = ["d.document_type='proposal'", 'c.deleted_at IS NULL'];
$params = [];
if ($query !== '') {
    $where[] = '(d.document_reference LIKE ? OR d.title LIKE ? OR c.name LIKE ? OR c.legal_name LIKE ?)';
    $needle = '%' . $query . '%';
    array_push($params, $needle, $needle, $needle, $needle);
}
if (in_array($status, $allowedStatuses, true)) {
    $where[] = 'd.document_status=?';
    $params[] = $status;
}
$statement = db()->prepare('SELECT d.*, c.name AS company_name FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE ' . implode(' AND ', $where) . ' ORDER BY d.updated_at DESC');
$statement->execute($params);
$proposals = $statement->fetchAll();
$statusLabels = ['draft'=>'Draft','pdf_ready'=>'PDF ready for approval','approval_pending'=>'Pending CEO approval','ceo_approved'=>'CEO approved','approved_pdf'=>'CEO-approved PDF','sent'=>'Sent','accepted'=>'Accepted','rejected'=>'Rejected','expired'=>'Expired'];

render_header('Προσφορές', $user);
?>
<div class="page-heading">
  <div><p class="eyebrow">COMMERCIAL PROPOSALS</p><h1>Εμπορικές & τεχνικές προτάσεις</h1><p>Κάθε πρόταση συνδέεται με την καρτέλα του πελάτη και δημιουργείται από το εγκεκριμένο master template.</p></div>
  <div class="actions"><a class="button primary" href="<?= e(crm_url('proposal.php')) ?>">+ Νέα πρόταση</a></div>
</div>
<section class="card toolbar-card">
  <form method="get" class="filters">
    <label>Αναζήτηση<input name="q" value="<?= e($query) ?>" placeholder="Πελάτης, project ή reference"></label>
    <label>Κατάσταση<select name="status"><option value="">Όλες</option><?php foreach ($statusLabels as $value=>$label): ?><option value="<?= e($value) ?>" <?= $status===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <button class="button primary">Εφαρμογή</button><a class="button" href="<?= e(crm_url('proposals.php')) ?>">Καθαρισμός</a>
  </form>
</section>
<section class="card table-card">
<?php if (!$proposals): ?><div class="empty-state"><h2>Δεν υπάρχουν προτάσεις</h2><p>Δημιουργήστε την πρώτη client-specific πρόταση.</p><a class="button primary" href="<?= e(crm_url('proposal.php')) ?>">Νέα πρόταση</a></div>
<?php else: ?><div class="table-wrap"><table><thead><tr><th>Reference / Project</th><th>Πελάτης</th><th>Κατάσταση</th><th>Ενημέρωση</th><th></th></tr></thead><tbody>
<?php foreach ($proposals as $row): $proposalData=json_decode((string)$row['data_json'], true) ?: []; ?><tr>
  <td><strong><?= e($row['document_reference']) ?></strong><small><?= e($proposalData['project_name'] ?? $row['title']) ?></small></td>
  <td><?= e($row['company_name']) ?><small><?= e($proposalData['client_email'] ?? '') ?></small></td>
  <td><span class="proposal-status proposal-status-<?= e($row['document_status']) ?>"><?= e($statusLabels[$row['document_status']] ?? $row['document_status']) ?></span></td>
  <td><?= e(format_datetime($row['updated_at'])) ?></td>
  <td class="table-actions"><a class="button small" href="<?= e(crm_url('proposal.php?id='.urlencode((string)$row['id']))) ?>">Άνοιγμα</a><a class="button small" target="_blank" href="<?= e(crm_url('proposal-view.php?id='.urlencode((string)$row['id']))) ?>">Προβολή</a></td>
</tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
