<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 255));
    $email = trim(mb_substr((string)($_POST['email'] ?? ''), 0, 255));
    if ($name === '') $errors[] = 'Η σύντομη επωνυμία είναι υποχρεωτική.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Το email δεν είναι έγκυρο.';

    if (!$errors) {
        try {
            $statement = db()->prepare(
                'INSERT INTO companies (name, legal_name, trading_name, legal_form, name_key, email, phone, industry, website, address, city, country, postal_code, registration_number, vat_number, contact_name, contact_title, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $name,
                trim((string)($_POST['legal_name'] ?? '')) ?: null,
                trim((string)($_POST['trading_name'] ?? '')) ?: null,
                trim((string)($_POST['legal_form'] ?? '')) ?: null,
                mb_strtolower($name),
                $email ?: null,
                trim((string)($_POST['phone'] ?? '')) ?: null,
                trim((string)($_POST['industry'] ?? '')) ?: null,
                trim((string)($_POST['website'] ?? '')) ?: null,
                trim((string)($_POST['address'] ?? '')) ?: null,
                trim((string)($_POST['city'] ?? '')) ?: null,
                trim((string)($_POST['country'] ?? '')) ?: null,
                trim((string)($_POST['postal_code'] ?? '')) ?: null,
                trim((string)($_POST['registration_number'] ?? '')) ?: null,
                trim((string)($_POST['vat_number'] ?? '')) ?: null,
                trim((string)($_POST['contact_name'] ?? '')) ?: null,
                trim((string)($_POST['contact_title'] ?? '')) ?: null,
                trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            $companyId = (int)db()->lastInsertId();
            flash('success', 'Η καρτέλα πελάτη δημιουργήθηκε.');
            redirect_to('customer.php?id=' . $companyId);
        } catch (PDOException $exception) {
            error_log('Customer create failed: ' . $exception->getMessage());
            $errors[] = $exception->getCode() === '23000' ? 'Υπάρχει ήδη πελάτης με αυτή την επωνυμία.' : 'Η καρτέλα δεν δημιουργήθηκε. Δοκιμάστε ξανά.';
        }
    }
}

render_header('Νέος πελάτης', $user);
?>
<div class="page-heading"><div><h1>Νέα καρτέλα πελάτη</h1><p>Η καρτέλα μπορεί να δημιουργηθεί χειροκίνητα, χωρίς να απαιτείται προηγούμενη επικοινωνία.</p></div><a class="button" href="<?= e(crm_url('customers.php')) ?>">Πίσω</a></div>
<?php if ($errors): ?><div class="alert error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<form method="post" class="stack"><?= csrf_field() ?>
  <section class="card form-section"><header><h2>Ταυτότητα εταιρείας</h2><p>Στοιχεία που χρησιμοποιούνται και στα εταιρικά έγγραφα.</p></header><div class="form-grid">
    <label>Σύντομη επωνυμία *<input name="name" required value="<?= e($_POST['name'] ?? '') ?>" placeholder="π.χ. Example Group"></label>
    <label>Πλήρης νομική επωνυμία<input name="legal_name" value="<?= e($_POST['legal_name'] ?? '') ?>"></label>
    <label>Εμπορική ονομασία<input name="trading_name" value="<?= e($_POST['trading_name'] ?? '') ?>"></label>
    <label>Νομική μορφή<input name="legal_form" value="<?= e($_POST['legal_form'] ?? '') ?>" placeholder="π.χ. S.A., Ltd, ΙΚΕ"></label>
    <label>Αριθμός μητρώου<input name="registration_number" value="<?= e($_POST['registration_number'] ?? '') ?>"></label>
    <label>ΑΦΜ / VAT<input name="vat_number" value="<?= e($_POST['vat_number'] ?? '') ?>"></label>
  </div></section>
  <section class="card form-section"><header><h2>Επικοινωνία και έδρα</h2></header><div class="form-grid">
    <label>Email<input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
    <label>Τηλέφωνο<input name="phone" value="<?= e($_POST['phone'] ?? '') ?>"></label>
    <label>Website<input type="url" name="website" value="<?= e($_POST['website'] ?? '') ?>" placeholder="https://"></label>
    <label>Κλάδος<input name="industry" value="<?= e($_POST['industry'] ?? '') ?>"></label>
    <label>Κύρια επαφή<input name="contact_name" value="<?= e($_POST['contact_name'] ?? '') ?>"></label>
    <label>Τίτλος / ρόλος<input name="contact_title" value="<?= e($_POST['contact_title'] ?? '') ?>"></label>
    <label class="field-full">Διεύθυνση<input name="address" value="<?= e($_POST['address'] ?? '') ?>"></label>
    <label>Πόλη<input name="city" value="<?= e($_POST['city'] ?? '') ?>"></label>
    <label>Τ.Κ.<input name="postal_code" value="<?= e($_POST['postal_code'] ?? '') ?>"></label>
    <label>Χώρα<input name="country" value="<?= e($_POST['country'] ?? '') ?>"></label>
    <label class="field-full">Εσωτερικές σημειώσεις<textarea name="notes"><?= e($_POST['notes'] ?? '') ?></textarea></label>
  </div></section>
  <div class="actions"><button class="button primary" type="submit">Δημιουργία καρτέλας</button><a class="button" href="<?= e(crm_url('customers.php')) ?>">Άκυρο</a></div>
</form>
<?php render_footer(); ?>
