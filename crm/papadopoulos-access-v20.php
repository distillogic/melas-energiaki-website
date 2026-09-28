<?php
declare(strict_types=1);
// Upload beside the existing CRM bootstrap.php. Never run as public setup.
require __DIR__.'/bootstrap.php';
require __DIR__.'/papadopoulos-access-workflow-v20.php';
$https=(!empty($_SERVER['HTTPS']) && !in_array(strtolower((string)$_SERVER['HTTPS']),['off','0'],true)) || (int)($_SERVER['SERVER_PORT'] ?? 0)===443;
// Unlike legacy request_is_https(), never trust arbitrary forwarded headers.
$trusted=crm_config()['app']['trusted_proxy_ips'] ?? [];
if (!$https && is_array($trusted) && in_array($_SERVER['REMOTE_ADDR'] ?? '',$trusted,true)
    && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')==='https') $https=true;
if (!$https) { http_response_code(400); exit('Χρειάζεται ασφαλής σύνδεση HTTPS. Μην πληκτρολογήσεις κωδικούς σε HTTP.'); }
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$actor=current_user();
if (!$actor) { redirect_to('login.php'); }
if (!papadopoulos_v20_admin($actor)) { http_response_code(403); exit('Δεν επιτρέπεται διαχείριση πρόσβασης.'); }
$error=null;$created=false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
    verify_csrf();
    try { papadopoulos_v20_create($actor,$_POST);$created=true; }
    catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { error_log('Approved user v20: operation failed ('.get_class($e).')');$error='Η δημιουργία δεν ολοκληρώθηκε. Έλεγξε τη βάση και τα Logs. Δεν χρειάζεται διαγραφή ή επανεγκατάσταση CRM.'; }
}
$existing=papadopoulos_v20_account();
?><!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Έγκριση χρήστη · v20</title>
<style>body{margin:0;background:#eff5f1;color:#163a31;font:16px/1.6 system-ui,sans-serif}main{box-sizing:border-box;max-width:650px;margin:32px auto;padding:28px;background:white;border:1px solid #d4e0d9;border-radius:18px}h1{font-size:26px;line-height:1.3}label{display:grid;gap:5px;margin:15px 0}input,textarea,button{box-sizing:border-box;width:100%;padding:12px;border:1px solid #bccfc4;border-radius:8px;font:inherit}textarea{min-height:110px}.check{display:flex;align-items:flex-start;gap:10px}.check input{width:20px;height:20px;flex:0 0 20px;margin-top:3px}button{background:#164d3c;color:white;cursor:pointer}.notice{padding:14px;background:#edf5ee;border-radius:8px}.error{background:#fff0f0;color:#782d2d;padding:12px}a{color:#16543e}small{color:#50685d;overflow-wrap:anywhere}@media(max-width:680px){main{margin:12px;padding:20px}}</style></head><body><main>
<small>ΕΓΚΕΚΡΙΜΕΝΗ ΠΡΟΣΒΑΣΗ · V20</small><h1>Προσθήκη απλού χρήστη</h1>
<p><strong><?= e(papadopoulos_v20_email()) ?></strong><br>CRM: <?= e((string)(crm_config()['app']['origin'] ?? 'τρέχουσα εγκατάσταση')) ?></p>
<?php if($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if($existing): ?>
<p class="notice"><?= $created?'Ο λογαριασμός δημιουργήθηκε σε αυτό το CRM. Η εξαίρεση υπογραφών καταγράφηκε στο ιστορικό.':'Υπάρχει ήδη λογαριασμός με αυτό το email. Δεν αλλάξαμε κωδικό, ρόλο ή ενεργοποίηση.' ?></p>
<p>Ρόλος: <strong><?= e(role_label($existing['role'])) ?></strong> · <?= $existing['active']?'Ενεργός':'Ανενεργός' ?></p>
<p>Επανάλαβε τη δημιουργία στο άλλο CRM μόνο αν δεν υπάρχει εκεί. Η Academy δημιουργεί το εκπαιδευτικό προφίλ στην πρώτη «Σύνδεση μέσω Distillogic», αφού ρυθμιστεί ο connector.</p>
<p>Μετά τον έλεγχο, αφαίρεσε από αυτόν τον φάκελο μόνο τα δύο πρόσθετα αρχεία papadopoulos-access-v20.php και papadopoulos-access-workflow-v20.php. Ο λογαριασμός και το ιστορικό παραμένουν.</p>
<?php else: ?>
<p class="notice">Μόνο ο συγκεκριμένος χρήστης θα λάβει ρόλο <strong>Χρήστης (employee)</strong>, χωρίς διαχείριση ή εγκρίσεις CEO. Η έγκριση εργοδότη παρακάμπτει την προϋπόθεση εγγράφων μόνο για τη νέα αυτή εγγραφή. Δεν δημιουργούνται υπογραφές.</p>
<form method="post"><?= csrf_field() ?>
<label>Όνομα χρήστη<input name="name" value="Παπαδόπουλος" required maxlength="150" autocomplete="off"></label>
<label>Νέος κωδικός χρήστη<input type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
<label>Επανάληψη νέου κωδικού<input type="password" name="password_confirm" required minlength="12" maxlength="72" autocomplete="new-password"></label>
<small>Βάλε τον ίδιο νέο κωδικό και στο άλλο CRM. Οι μελλοντικές αλλαγές κωδικού δεν συγχρονίζονται αυτόματα μεταξύ των δύο CRM.</small>
<label>Αιτιολογία έγκρισης<textarea name="reason" required minlength="10" maxlength="1500">Πρόσβαση εγκεκριμένη από τον εργοδότη χωρίς προϋπόθεση υπογραφής εγγράφων onboarding για τον συγκεκριμένο χρήστη.</textarea></label>
<label class="check"><input type="checkbox" name="boss_approved" value="1" required><span>Επιβεβαιώνω ότι ο εργοδότης ενέκρινε αυτή την εξαίρεση.</span></label>
<label>Ο δικός σου κωδικός διαχειριστή<input type="password" name="actor_password" required autocomplete="current-password"></label>
<button type="submit">Δημιουργία απλού χρήστη σε αυτό το CRM</button></form>
<?php endif; ?><p><a href="<?= e(crm_url('')) ?>">Επιστροφή στο CRM</a></p></main></body></html>
