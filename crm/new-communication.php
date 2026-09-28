<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
$prefillCompany = null;
$prefillCompanyId = filter_var($_GET['company_id'] ?? null, FILTER_VALIDATE_INT);
if ($prefillCompanyId) {
    $prefillStatement = db()->prepare('SELECT name, email, phone FROM companies WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $prefillStatement->execute([$prefillCompanyId]);
    $prefillCompany = $prefillStatement->fetch() ?: null;
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $companyName = trim((string)($_POST['company_name'] ?? ''));
    $countryCode = preg_replace('/[^+0-9]/', '', (string)($_POST['country_code'] ?? '+30')) ?: '+30';
    $phoneNumber = preg_replace('/\D+/', '', (string)($_POST['phone_number'] ?? '')) ?: '';
    $phone = $phoneNumber === '' ? null : $countryCode . $phoneNumber;
    $email = trim((string)($_POST['email'] ?? ''));
    if ($companyName === '') $errors[] = 'Η επωνυμία είναι υποχρεωτική.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Το email δεν είναι έγκυρο.';
    $interest = ($_POST['interest_level'] ?? '') === '' ? null : (int)$_POST['interest_level'];
    if ($interest !== null && ($interest < 1 || $interest > 5)) $errors[] = 'Το επίπεδο ενδιαφέροντος δεν είναι έγκυρο.';

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $nameKey = mb_strtolower($companyName);
            $companyQuery = $pdo->prepare('SELECT id FROM companies WHERE name_key = ? AND deleted_at IS NULL LIMIT 1');
            $companyQuery->execute([$nameKey]);
            $companyId = $companyQuery->fetchColumn();
            if (!$companyId) {
                $insertCompany = $pdo->prepare('INSERT INTO companies (name, name_key, email, phone) VALUES (?, ?, ?, ?)');
                $insertCompany->execute([$companyName, $nameKey, $email ?: null, $phone]);
                $companyId = $pdo->lastInsertId();
            } else {
                $updateCompany = $pdo->prepare('UPDATE companies SET email = COALESCE(NULLIF(?, \'\'), email), phone = COALESCE(?, phone) WHERE id = ?');
                $updateCompany->execute([$email, $phone, $companyId]);
            }
            $id = uuid_v4();
            $insert = $pdo->prepare(
                'INSERT INTO communications (id, company_id, user_id, assigned_user_id, source, status, contact_name, contact_role, outcome, interest_level, next_action, next_action_at, notes)
                 VALUES (?, ?, ?, ?, \'telephone\', ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $nextAt = trim((string)($_POST['next_action_at'] ?? '')) ?: null;
            $insert->execute([
                $id, $companyId, $user['id'], $user['id'], (string)($_POST['status'] ?? 'contacted'),
                trim((string)($_POST['contact_name'] ?? '')) ?: null,
                trim((string)($_POST['contact_role'] ?? '')) ?: null,
                (string)($_POST['outcome'] ?? '') ?: null, $interest,
                trim((string)($_POST['next_action'] ?? '')) ?: null,
                $nextAt ? str_replace('T', ' ', $nextAt) . ':00' : null,
                trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            $pdo->commit();
            flash('success', 'Η τηλεφωνική επικοινωνία καταχωρίστηκε.');
            redirect_to('communication.php?id=' . urlencode($id));
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Communication create failed: ' . $exception->getMessage());
            $errors[] = 'Η καταχώριση δεν ολοκληρώθηκε. Δοκιμάστε ξανά.';
        }
    }
}

render_header('Νέα καταχώριση', $user);
?>
<div class="page-heading"><div><h1>Νέα τηλεφωνική επικοινωνία</h1><p>Ο καταχωρίζων συμπληρώνεται αυτόματα από τον συνδεδεμένο λογαριασμό.</p></div></div>
<?php if ($errors): ?><div class="alert error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <section class="card form-section"><header><h2>Εταιρεία</h2><p>Καταχωρίστε τα βασικά στοιχεία του πελάτη ή της επαφής.</p></header><div class="form-grid">
    <label class="field-full">Επωνυμία *<input name="company_name" required value="<?= e($_POST['company_name'] ?? ($prefillCompany['name'] ?? '')) ?>" placeholder="π.χ. ΑΒΓ ΕΠΕ"></label>
    <label>Email<input type="email" name="email" value="<?= e($_POST['email'] ?? ($prefillCompany['email'] ?? '')) ?>"></label>
    <label>Τηλέφωνο<div class="phone-row"><select name="country_code" aria-label="Κωδικός χώρας"><option value="+30" selected>🇬🇷 +30</option><option value="+357">🇨🇾 +357</option><option value="+44">🇬🇧 +44</option><option value="+49">🇩🇪 +49</option><option value="+1">🇺🇸 +1</option></select><input name="phone_number" inputmode="numeric" value="<?= e($_POST['phone_number'] ?? '69') ?>" aria-label="Αριθμός τηλεφώνου"></div></label>
  </div></section>
  <section class="card form-section"><header><h2>Επικοινωνία</h2><p>Καταχωρίστηκε από: <strong><?= e($user['name']) ?></strong></p></header><div class="form-grid">
    <label>Αποτέλεσμα<select name="outcome"><option value="">Δεν έχει οριστεί</option><option value="interested">Ενδιαφέρεται</option><option value="callback">Επανάκληση</option><option value="no_answer">Δεν απάντησε</option><option value="not_interested">Δεν ενδιαφέρεται</option></select></label>
    <label>Κατάσταση<select name="status"><option value="contacted">Έγινε επικοινωνία</option><option value="new">Νέο</option><option value="qualified">Αξιολογημένο</option><option value="proposal">Στάλθηκε πρόταση</option><option value="won">Κερδήθηκε</option><option value="lost">Δεν προχώρησε</option></select></label>
    <label>Όνομα επαφής<input name="contact_name" value="<?= e($_POST['contact_name'] ?? '') ?>"></label><label>Ρόλος επαφής<input name="contact_role" value="<?= e($_POST['contact_role'] ?? '') ?>"></label>
    <label>Επίπεδο ενδιαφέροντος<select name="interest_level"><option value="">Δεν έχει οριστεί</option><?php for ($i=1;$i<=5;$i++): ?><option value="<?= $i ?>"><?= $i ?>/5</option><?php endfor; ?></select></label>
    <label>Υπενθύμιση για<input type="datetime-local" name="next_action_at"></label>
    <label class="field-full">Επόμενη ενέργεια<input name="next_action" placeholder="π.χ. Αποστολή καταλόγου τιμών"></label>
    <label class="field-full">Σημειώσεις<textarea name="notes"><?= e($_POST['notes'] ?? '') ?></textarea></label>
  </div></section>
  <div class="actions"><button class="button primary" type="submit">Αποθήκευση επικοινωνίας</button><a class="button" href="<?= e(crm_url('communications.php')) ?>">Άκυρο</a></div>
</form>
<?php render_footer(); ?>
