<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) {
    http_response_code(404);
    exit('Η επικοινωνία δεν βρέθηκε.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'update');
    if ($action === 'archive') {
        $statement = db()->prepare("UPDATE communications SET status = 'archived', archived_at = NOW() WHERE id = ?");
        $statement->execute([$id]);
        flash('success', 'Η επικοινωνία αρχειοθετήθηκε.');
        redirect_to('communications.php');
    }
    if ($action === 'delete') {
        $statement = db()->prepare('UPDATE communications SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        $statement->execute([$id]);
        flash('success', 'Η επικοινωνία διαγράφηκε.');
        redirect_to('communications.php');
    }
    if ($action === 'update') {
        $interest = ($_POST['interest_level'] ?? '') === '' ? null : max(1, min(5, (int)$_POST['interest_level']));
        $nextAt = trim((string)($_POST['next_action_at'] ?? ''));
        $statement = db()->prepare(
            'UPDATE communications SET status = ?, outcome = ?, interest_level = ?, contact_name = ?, contact_role = ?, next_action = ?, next_action_at = ?, notes = ?, assigned_user_id = ? WHERE id = ?'
        );
        $statement->execute([
            (string)($_POST['status'] ?? 'new'), (string)($_POST['outcome'] ?? '') ?: null, $interest,
            trim((string)($_POST['contact_name'] ?? '')) ?: null, trim((string)($_POST['contact_role'] ?? '')) ?: null,
            trim((string)($_POST['next_action'] ?? '')) ?: null,
            $nextAt ? str_replace('T', ' ', $nextAt) . (strlen($nextAt) === 16 ? ':00' : '') : null,
            trim((string)($_POST['notes'] ?? '')) ?: null,
            (string)($_POST['assigned_user_id'] ?? '') ?: null, $id,
        ]);
        flash('success', 'Οι αλλαγές αποθηκεύτηκαν.');
        redirect_to('communication.php?id=' . urlencode($id));
    }
}

$statement = db()->prepare(
    "SELECT c.*, co.name AS company_name, co.email AS company_email, co.phone AS company_phone,
            COALESCE(author.name, 'Αυτόματη καταχώριση') AS author_name,
            COALESCE(assigned.name, 'Μη ανατεθειμένο') AS assigned_name,
            w.reference, w.business_email, w.language, w.source_page, w.submitted_at,
            p.country, p.service, p.project_title, p.requirement, p.technologies,
            p.project_stage, p.engagement, p.timeline, p.request_nda
       FROM communications c
       JOIN companies co ON co.id = c.company_id
       LEFT JOIN users author ON author.id = c.user_id
       LEFT JOIN users assigned ON assigned.id = c.assigned_user_id
       LEFT JOIN website_enquiries w ON w.communication_id = c.id
       LEFT JOIN communication_project_briefs p ON p.communication_id = c.id
      WHERE c.id = ? AND c.deleted_at IS NULL LIMIT 1"
);
$statement->execute([$id]);
$item = $statement->fetch();
if (!$item) {
    http_response_code(404);
    exit('Η επικοινωνία δεν βρέθηκε.');
}
$users = db()->query('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll();
$documents = [];
if ($item['reference']) {
    $docs = db()->prepare('SELECT d.id, d.file_name, d.mime_type, d.size_bytes FROM website_enquiry_documents d JOIN website_enquiries w ON w.id = d.website_enquiry_id WHERE w.communication_id = ? ORDER BY d.created_at');
    $docs->execute([$id]);
    $documents = $docs->fetchAll();
}
render_header('Προβολή επικοινωνίας', $user);
require_once __DIR__.'/contact-operations.php';contact_ops_banner($user,'company',(string)$item['company_id']);
?>
<div class="page-heading"><div><h1><?= e($item['company_name']) ?></h1><p><?= e($item['contact_name'] ?: 'Χωρίς όνομα επαφής') ?> · <?= $item['source'] === 'website' ? 'Από ιστοσελίδα' : 'Τηλεφωνική επικοινωνία' ?></p></div><div class="actions"><a class="button" href="<?= e(crm_url('communications.php')) ?>">Πίσω</a><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="archive"><button class="button" data-confirm="Να αρχειοθετηθεί η επικοινωνία;">Αρχειοθέτηση</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="button danger" data-confirm="Να διαγραφεί η επικοινωνία; Θα αφαιρεθεί από όλες τις λίστες.">Διαγραφή</button></form></div></div>
<section class="grid split">
  <div class="stack">
    <form method="post" class="card form-section"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update">
      <header><h2>Στοιχεία και εξέλιξη</h2><p>Ενημερώστε την κατάσταση και την επόμενη ενέργεια.</p></header><div class="form-grid">
        <label>Κατάσταση<select name="status"><?php foreach (['new'=>'Νέο','contacted'=>'Έγινε επικοινωνία','qualified'=>'Αξιολογημένο','proposal'=>'Στάλθηκε πρόταση','won'=>'Κερδήθηκε','lost'=>'Δεν προχώρησε','archived'=>'Αρχειοθετημένο'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $item['status']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Αποτέλεσμα<select name="outcome"><option value="">Δεν έχει οριστεί</option><?php foreach (['interested'=>'Ενδιαφέρεται','callback'=>'Επανάκληση','no_answer'=>'Δεν απάντησε','not_interested'=>'Δεν ενδιαφέρεται'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $item['outcome']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Όνομα επαφής<input name="contact_name" value="<?= e($item['contact_name']) ?>"></label><label>Ρόλος επαφής<input name="contact_role" value="<?= e($item['contact_role']) ?>"></label>
        <label>Επίπεδο ενδιαφέροντος<select name="interest_level"><option value="">Δεν έχει οριστεί</option><?php for($i=1;$i<=5;$i++): ?><option value="<?= $i ?>" <?= (int)$item['interest_level']===$i?'selected':'' ?>><?= $i ?>/5</option><?php endfor; ?></select></label>
        <label>Ανάθεση σε<select name="assigned_user_id"><option value="">Μη ανατεθειμένο</option><?php foreach($users as $account): ?><option value="<?= e($account['id']) ?>" <?= $item['assigned_user_id']===$account['id']?'selected':'' ?>><?= e($account['name']) ?></option><?php endforeach; ?></select></label>
        <label class="field-full">Επόμενη ενέργεια<input name="next_action" value="<?= e($item['next_action']) ?>"></label>
        <label>Υπενθύμιση<input type="datetime-local" name="next_action_at" value="<?= $item['next_action_at'] ? e(str_replace(' ', 'T', substr($item['next_action_at'],0,16))) : '' ?>"></label>
        <label class="field-full">Σημειώσεις<textarea name="notes"><?= e($item['notes']) ?></textarea></label>
      </div><button class="button primary" type="submit">Αποθήκευση αλλαγών</button>
    </form>
    <?php if ($item['reference']): ?><article class="card"><h2>Αίτημα έργου από την ιστοσελίδα</h2><dl class="detail-list">
      <div><dt>Κωδικός</dt><dd><?= e($item['reference']) ?></dd></div><div><dt>Υποβλήθηκε</dt><dd><?= e(format_datetime($item['submitted_at'])) ?></dd></div>
      <div><dt>Τίτλος έργου</dt><dd><?= e($item['project_title']) ?></dd></div><div><dt>Χώρα</dt><dd><?= e($item['country']) ?></dd></div>
      <div><dt>Υπηρεσία</dt><dd><?= e($item['service']) ?></dd></div><div><dt>Στάδιο</dt><dd><?= e($item['project_stage']) ?></dd></div>
      <div><dt>Μοντέλο συνεργασίας</dt><dd><?= e($item['engagement']) ?></dd></div><div><dt>Χρονοδιάγραμμα</dt><dd><?= e($item['timeline']) ?></dd></div>
      <div class="field-full"><dt>Απαίτηση</dt><dd><?= nl2br(e($item['requirement'])) ?></dd></div><div class="field-full"><dt>Τεχνολογίες</dt><dd><?= e($item['technologies'] ?: '—') ?></dd></div>
      <div><dt>NDA</dt><dd><?= $item['request_nda'] ? 'Ζητήθηκε' : 'Όχι' ?></dd></div>
    </dl><?php if ($documents): ?><div class="document-section"><header><h2>Συνημμένα αρχεία</h2><span><?= count($documents) ?></span></header><ul class="document-list"><?php foreach($documents as $document): $extension = strtoupper(pathinfo($document['file_name'], PATHINFO_EXTENSION) ?: 'FILE'); ?><li><span class="document-icon"><?= e(mb_substr($extension, 0, 4)) ?></span><span><strong><?= e($document['file_name']) ?></strong><small><?= e(format_bytes((int)$document['size_bytes'])) ?> · <?= e($document['mime_type']) ?></small></span><a class="button compact" href="<?= e(crm_url('download.php?id=' . urlencode($document['id']))) ?>">Λήψη</a></li><?php endforeach; ?></ul></div><?php else: ?><div class="document-section"><header><h2>Συνημμένα αρχεία</h2><span>0</span></header><p class="muted">Δεν υποβλήθηκαν αρχεία με αυτό το αίτημα.</p></div><?php endif; ?></article><?php endif; ?>
  </div>
  <aside class="card"><h2>Στοιχεία εταιρείας</h2><dl class="detail-list"><div><dt>Email</dt><dd><?= e($item['company_email'] ?: '—') ?></dd></div><div><dt>Τηλέφωνο</dt><dd><?= e($item['company_phone'] ?: '—') ?></dd></div><div><dt>Καταχώριση από</dt><dd><?= e($item['author_name']) ?></dd></div><div><dt>Ανατέθηκε σε</dt><dd><?= e($item['assigned_name']) ?></dd></div><div><dt>Δημιουργήθηκε</dt><dd><?= e(format_datetime($item['created_at'])) ?></dd></div><div><dt>Ενημερώθηκε</dt><dd><?= e(format_datetime($item['updated_at'])) ?></dd></div></dl></aside>
</section>
<?php render_footer(); ?>
