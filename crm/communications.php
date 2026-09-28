<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $recordId = (string)($_POST['id'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if (preg_match('/^[0-9a-f-]{36}$/i', $recordId) && in_array($action, ['archive', 'delete'], true)) {
        if ($action === 'archive') {
            $change = db()->prepare("UPDATE communications SET status = 'archived', archived_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $message = 'Η επικοινωνία αρχειοθετήθηκε.';
        } else {
            $change = db()->prepare('UPDATE communications SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
            $message = 'Η επικοινωνία διαγράφηκε.';
        }
        $change->execute([$recordId]);
        flash('success', $message);
    }
    $returnSource = (string)($_POST['return_source'] ?? 'all');
    redirect_to('communications.php' . ($returnSource !== 'all' ? '?source=' . urlencode($returnSource) : ''));
}

$source = (string)($_GET['source'] ?? 'all');
$search = trim((string)($_GET['q'] ?? ''));
$allowedSources = ['all', 'telephone'];
if (!in_array($source, $allowedSources, true)) {
    $source = 'all';
}

$conditions = ['c.deleted_at IS NULL'];
$parameters = [];
if ($source !== 'all') {
    $conditions[] = 'c.source = ?';
    $parameters[] = $source;
}
if ($search !== '') {
    $conditions[] = '(co.name LIKE ? OR c.contact_name LIKE ? OR co.email LIKE ? OR co.phone LIKE ? OR EXISTS (SELECT 1 FROM website_enquiries we WHERE we.communication_id = c.id AND we.reference = ?))';
    $term = '%' . mb_substr($search, 0, 120) . '%';
    array_push($parameters, $term, $term, $term, $term, strtoupper(trim($search)));
}
$statement = db()->prepare(
    "SELECT c.id, c.source, c.status, c.contact_name, c.outcome, c.interest_level,
            c.next_action, c.next_action_at, c.created_at, co.name AS company_name,
            COALESCE(u.name, 'Μη ανατεθειμένο') AS assigned_name,
            (SELECT COUNT(*) FROM website_enquiries we JOIN website_enquiry_documents wd ON wd.website_enquiry_id = we.id WHERE we.communication_id = c.id) AS document_count
       FROM communications c
       JOIN companies co ON co.id = c.company_id
       LEFT JOIN users u ON u.id = c.assigned_user_id
      WHERE " . implode(' AND ', $conditions) . "
      ORDER BY c.created_at DESC LIMIT 200"
);
$statement->execute($parameters);
$items = $statement->fetchAll();

render_header('Επικοινωνίες', $user);
?>
<div class="page-heading"><div><h1>Επικοινωνίες</h1><p>Αιτήματα από την ιστοσελίδα και τηλεφωνικές καταχωρίσεις.</p></div><a class="button primary" href="<?= e(crm_url('new-communication.php')) ?>">Νέα τηλεφωνική επικοινωνία</a></div>
<div class="filters">
  <a class="<?= $source === 'all' ? 'active' : '' ?>" href="<?= e(crm_url('communications.php')) ?>">Όλες</a>
  <a class="<?= $source === 'telephone' ? 'active' : '' ?>" href="<?= e(crm_url('communications.php?source=telephone')) ?>">Χειροκίνητες</a>
</div>
<form method="get" class="card filters"><input type="search" name="q" value="<?= e($search) ?>" placeholder="Εταιρεία, κωδικός DL-…, επαφή, email ή τηλέφωνο"><input type="hidden" name="source" value="<?= e($source) ?>"><button class="button" type="submit">Αναζήτηση</button></form>
<div class="table-wrap communication-table"><table><thead><tr><th>Εταιρεία / επαφή</th><th>Πηγή</th><th>Υπεύθυνος</th><th>Κατάσταση</th><th>Επόμενη ενέργεια</th><th>Ημερομηνία</th><th class="align-right">Ενέργειες</th></tr></thead><tbody>
<?php if (!$items): ?><tr><td colspan="7" class="empty">Δεν βρέθηκαν επικοινωνίες.</td></tr><?php endif; ?>
<?php foreach ($items as $item): ?><tr>
  <td><a href="<?= e(crm_url('communication.php?id=' . urlencode($item['id']))) ?>"><strong><?= e($item['company_name']) ?></strong></a><br><span class="muted"><?= e($item['contact_name']) ?> · <?= e(format_datetime($item['created_at'])) ?><?php if ((int)$item['document_count'] > 0): ?> · <?= (int)$item['document_count'] ?> <?= (int)$item['document_count'] === 1 ? 'αρχείο' : 'αρχεία' ?><?php endif; ?></span></td>
  <td><span class="badge <?= e($item['source']) ?>"><?= $item['source'] === 'website' ? 'Ιστοσελίδα' : 'Τηλέφωνο' ?></span></td>
  <td class="compact-copy"><?= e($item['assigned_name']) ?></td>
  <td><span class="status-dot status-<?= e($item['status']) ?>"></span><?= e(status_label($item['status'])) ?></td>
  <td class="compact-copy"><?= e($item['next_action'] ?: '—') ?><?php if ($item['next_action_at']): ?><br><span class="muted"><?= e(format_datetime($item['next_action_at'])) ?></span><?php endif; ?></td>
  <td class="compact-copy"><?= e(format_datetime($item['created_at'])) ?></td>
  <td class="align-right"><button class="row-menu-trigger" type="button" aria-label="Ενέργειες για <?= e($item['company_name']) ?>" aria-expanded="false" data-row-menu-trigger>⋯</button><div class="row-menu-panel" hidden data-row-menu-panel><a href="<?= e(crm_url('communication.php?id=' . urlencode($item['id']))) ?>">Προβολή</a><a href="<?= e(crm_url('communication.php?id=' . urlencode($item['id']))) ?>">Επεξεργασία</a><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($item['id']) ?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="return_source" value="<?= e($source) ?>"><button data-confirm="Να αρχειοθετηθεί η επικοινωνία;">Αρχειοθέτηση</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($item['id']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="return_source" value="<?= e($source) ?>"><button class="danger-action" data-confirm="Να διαγραφεί η επικοινωνία;">Διαγραφή</button></form></div></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php render_footer(); ?>
