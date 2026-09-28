<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();

$companyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$companyId) { http_response_code(404); exit('Η εταιρεία δεν βρέθηκε.'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'update_company') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($name === '') $errors[] = 'Η επωνυμία είναι υποχρεωτική.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Το email δεν είναι έγκυρο.';
        if (!$errors) {
            $statement = db()->prepare('UPDATE companies SET name=?, legal_name=?, trading_name=?, legal_form=?, name_key=?, email=?, phone=?, industry=?, website=?, address=?, city=?, country=?, postal_code=?, registration_number=?, vat_number=?, contact_name=?, contact_title=?, notes=? WHERE id=? AND deleted_at IS NULL');
            $statement->execute([$name, trim((string)($_POST['legal_name'] ?? '')) ?: null, trim((string)($_POST['trading_name'] ?? '')) ?: null, trim((string)($_POST['legal_form'] ?? '')) ?: null, mb_strtolower($name), $email ?: null, trim((string)($_POST['phone'] ?? '')) ?: null, trim((string)($_POST['industry'] ?? '')) ?: null, trim((string)($_POST['website'] ?? '')) ?: null, trim((string)($_POST['address'] ?? '')) ?: null, trim((string)($_POST['city'] ?? '')) ?: null, trim((string)($_POST['country'] ?? '')) ?: null, trim((string)($_POST['postal_code'] ?? '')) ?: null, trim((string)($_POST['registration_number'] ?? '')) ?: null, trim((string)($_POST['vat_number'] ?? '')) ?: null, trim((string)($_POST['contact_name'] ?? '')) ?: null, trim((string)($_POST['contact_title'] ?? '')) ?: null, trim((string)($_POST['notes'] ?? '')) ?: null, $companyId]);
            flash('success', 'Τα στοιχεία της εταιρείας αποθηκεύτηκαν.');
            redirect_to('customer.php?id=' . $companyId);
        }
    }
}

$companyStatement = db()->prepare('SELECT * FROM companies WHERE id=? AND deleted_at IS NULL LIMIT 1');
$companyStatement->execute([$companyId]);
$company = $companyStatement->fetch();
if (!$company) { http_response_code(404); exit('Η εταιρεία δεν βρέθηκε.'); }

$communicationsStatement = db()->prepare('SELECT id, contact_name, source, status, created_at FROM communications WHERE company_id=? ORDER BY created_at DESC LIMIT 100');
$communicationsStatement->execute([$companyId]);
$communications = $communicationsStatement->fetchAll();
$proposalsStatement = db()->prepare("SELECT id, document_reference, document_status, title, data_json, final_signed_pdf, updated_at FROM company_documents WHERE company_id=? AND document_type='proposal' ORDER BY updated_at DESC");
$proposalsStatement->execute([$companyId]);
$proposals = $proposalsStatement->fetchAll();
$statusLabels = ['draft'=>'Προσχέδιο','pdf_ready'=>'PDF προς έγκριση','approval_pending'=>'Αναμονή CEO','ceo_approved'=>'Εγκρίθηκε από CEO','approved_pdf'=>'Εγκεκριμένο PDF','sent'=>'Στάλθηκε','accepted'=>'Έγινε αποδεκτή','rejected'=>'Απορρίφθηκε','expired'=>'Έληξε'];

render_header('Καρτέλα εταιρείας', $user);
require_once __DIR__.'/contact-operations.php';contact_ops_banner($user,'company',(string)$companyId);
?>
<div class="page-heading"><div><p class="eyebrow">ΕΤΑΙΡΕΙΑ</p><h1><?= e($company['legal_name'] ?: $company['name']) ?></h1><p>Στοιχεία, επικοινωνίες και επαγγελματικές προτάσεις.</p></div><div class="actions"><a class="button" href="<?= e(crm_url('customers.php')) ?>">Όλες οι εταιρείες</a><a class="button primary" href="<?= e(crm_url('proposal.php?company_id=' . $companyId)) ?>">+ Νέα πρόταση</a></div></div>
<?php if ($errors): ?><div class="alert error"><?= e(implode(' ', array_unique($errors))) ?></div><?php endif; ?>

<section class="card customer-overview"><div class="customer-monogram"><?= e(mb_strtoupper(mb_substr((string)$company['name'], 0, 1))) ?></div><div><h2><?= e($company['legal_name'] ?: $company['name']) ?></h2><p><?= e(implode(' · ', array_filter([$company['industry'], $company['city'], $company['country']]))) ?: 'Δεν έχουν συμπληρωθεί ακόμη στοιχεία.' ?></p></div><div class="customer-kpis"><span><strong><?= count($communications) ?></strong> Επικοινωνίες</span><span><strong><?= count($proposals) ?></strong> Προτάσεις</span></div></section>

<details id="company-data" class="card workspace-section" open><summary><span><strong>Στοιχεία εταιρείας</strong><small>Τα στοιχεία χρησιμοποιούνται στις προτάσεις.</small></span></summary><form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$companyId ?>"><input type="hidden" name="action" value="update_company"><div class="form-grid">
<label>Σύντομη επωνυμία *<input name="name" required value="<?= e($company['name']) ?>"></label><label>Πλήρης νομική επωνυμία<input name="legal_name" value="<?= e($company['legal_name']) ?>"></label><label>Εμπορική ονομασία<input name="trading_name" value="<?= e($company['trading_name']) ?>"></label><label>Νομική μορφή<input name="legal_form" value="<?= e($company['legal_form']) ?>"></label><label>Αριθμός μητρώου<input name="registration_number" value="<?= e($company['registration_number']) ?>"></label><label>ΑΦΜ / VAT<input name="vat_number" value="<?= e($company['vat_number']) ?>"></label><label>Email<input type="email" name="email" value="<?= e($company['email']) ?>"></label><label>Τηλέφωνο<input name="phone" value="<?= e($company['phone']) ?>"></label><label>Website<input type="url" name="website" value="<?= e($company['website']) ?>"></label><label>Κλάδος<input name="industry" value="<?= e($company['industry']) ?>"></label><label>Κύρια επαφή<input name="contact_name" value="<?= e($company['contact_name']) ?>"></label><label>Τίτλος / ρόλος<input name="contact_title" value="<?= e($company['contact_title']) ?>"></label><label class="field-full">Διεύθυνση<input name="address" value="<?= e($company['address']) ?>"></label><label>Πόλη<input name="city" value="<?= e($company['city']) ?>"></label><label>Τ.Κ.<input name="postal_code" value="<?= e($company['postal_code']) ?>"></label><label>Χώρα<input name="country" value="<?= e($company['country']) ?>"></label><label class="field-full">Εσωτερικές σημειώσεις<textarea name="notes"><?= e($company['notes']) ?></textarea></label></div><div class="actions"><button class="button primary" type="submit">Αποθήκευση στοιχείων</button></div></form></details>

<details class="card workspace-section" open><summary><span><strong>Επαγγελματικές προτάσεις</strong><small>Δημιουργία, CEO Approval και αποστολή.</small></span></summary><div class="workspace-body"><div class="document-grid"><?php if (!$proposals): ?><p class="empty-state">Δεν έχει δημιουργηθεί ακόμη πρόταση.</p><?php endif; ?><?php foreach ($proposals as $row): $data=json_decode((string)$row['data_json'], true) ?: []; ?><article class="saved-document"><div class="document-mark">PRO</div><div><strong><?= e($data['project_name'] ?? $row['title']) ?></strong><small><?= e($row['document_reference']) ?> · <?= e(format_datetime($row['updated_at'])) ?></small><span class="proposal-status proposal-status-<?= e($row['document_status']) ?>"><?= e($statusLabels[$row['document_status']] ?? $row['document_status']) ?></span></div><div class="actions"><a class="button compact" href="<?= e(crm_url('proposal.php?id=' . urlencode((string)$row['id']))) ?>">Άνοιγμα</a><a class="button compact" target="_blank" href="<?= e(crm_url('proposal-view.php?id=' . urlencode((string)$row['id']))) ?>">Προβολή</a><?php if ($row['final_signed_pdf']): ?><a class="button compact primary" href="<?= e(crm_url('proposal-download.php?id=' . urlencode((string)$row['id']) . '&pdf=1')) ?>">PDF</a><?php endif; ?></div></article><?php endforeach; ?></div><div class="actions"><a class="button primary" href="<?= e(crm_url('proposal.php?company_id=' . $companyId)) ?>">+ Νέα πρόταση</a></div></div></details>

<details class="card workspace-section" open><summary><span><strong>Ιστορικό επικοινωνιών</strong><small>Όλες οι επαφές με τη συγκεκριμένη εταιρεία.</small></span></summary><div class="workspace-body"><div class="record-list"><?php if (!$communications): ?><p class="empty-state">Δεν υπάρχει ακόμη επικοινωνία.</p><?php endif; ?><?php foreach ($communications as $item): ?><a href="<?= e(crm_url('communication.php?id=' . urlencode((string)$item['id']))) ?>"><span class="status-dot status-<?= e($item['status']) ?>"></span><span><strong><?= e($item['contact_name'] ?: 'Επικοινωνία') ?></strong><small><?= $item['source'] === 'website' ? 'Ιστοσελίδα' : 'Τηλέφωνο' ?> · <?= e(status_label($item['status'])) ?></small></span><time><?= e(format_datetime($item['created_at'])) ?></time></a><?php endforeach; ?></div><div class="actions"><a class="button" href="<?= e(crm_url('new-communication.php?company_id=' . $companyId)) ?>">+ Νέα επικοινωνία</a></div></div></details>
<?php render_footer(); ?>
