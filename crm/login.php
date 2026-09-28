<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
start_crm_session();
personnel_schema();
if(onboarding_session_user())redirect_to('onboarding.php');
if (current_user()) {
    redirect_to('');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_login_form_token((string)($_POST['login_token'] ?? ''))) {
        $error = 'Η φόρμα σύνδεσης έληξε. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.';
    }
    try {
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
        enforce_rate_limit('login', 10);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    if ($error === null) {
        // Read the revocation generation in the same snapshot as the credentials.
        account_session_generation('');
        $statement = db()->prepare('SELECT u.id,u.email,u.password_hash,u.active,COALESCE(s.generation,0) AS generation FROM users u LEFT JOIN user_access_state s ON s.user_id=u.id WHERE u.email = ? LIMIT 1');
        $statement->execute([$email]);
        $account = $statement->fetch();
        if ($account && (bool)$account['active'] && password_verify($password, $account['password_hash'])) {
            clear_rate_limit('login');
            if(!personnel_access_allowed($account)){
                onboarding_begin_session($account);redirect_to('onboarding.php');
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = $account['id'];
            $_SESSION['access_generation'] = (int)$account['generation'];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            activity_start($account, 'login');
            redirect_to('');
        }
        usleep(250000);
        $error = 'Λανθασμένο email ή κωδικός πρόσβασης.';
    }
}
?>
<!doctype html>
<html lang="el">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow"><title>Σύνδεση | MELAS ENERGIAKI CRM</title>
  <link rel="stylesheet" href="<?= e(crm_url('assets/crm.css')) ?>">
  <link rel="stylesheet" href="<?= e(crm_url('assets/workspace-ui.css?v=20260916-4')) ?>">
  <link rel="stylesheet" href="<?= e(crm_url('assets/workspace-studio.css?v=20260916-2')) ?>">
  <link rel="stylesheet" href="<?= e(crm_url('assets/workspace-font.css?v=20260916')) ?>">
</head>
<body class="login-page"><main class="login-layout">
  <section class="login-visual">
    <div class="login-grid"></div>
    <div class="login-visual-content">
      <div class="brand login-brand">
        <span class="brand-mark brand-mark-image"><img src="<?= e(crm_url('assets/melas-energiaki-logo.png')) ?>" alt=""></span>
        <span>Melas Energiaki CRM<small>Energy Operations</small></span>
      </div>
      <div class="login-statement"><span>Εταιρική οργάνωση</span><h1>Εταιρείες, επικοινωνίες και προτάσεις σε έναν ασφαλή χώρο.</h1><p>Διαχειριστείτε τις συνεργασίες και τα επόμενα βήματα της Melas Energiaki με καθαρή εικόνα σε κάθε στάδιο.</p></div>
      <div class="login-journey" aria-hidden="true"><span>01 <b>Επικοινωνία</b></span><span>02 <b>Πρόταση</b></span><span>03 <b>Συνεργασία</b></span></div>
    </div>
  </section>
  <section class="login-form-side"><div class="login-form-wrap">
    <div class="login-heading"><span>MELAS ENERGIAKI · Ασφαλής πρόσβαση</span><h2>Καλώς ήρθατε.</h2><p>Συνδεθείτε για να συνεχίσετε στο εταιρικό CRM.</p></div>
    <div class="auth-card">
      <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= e(crm_url('login.php')) ?>" class="stack">
        <input type="hidden" name="login_token" value="<?= e(login_form_token()) ?>">
        <label>Email<input type="email" name="email" required autofocus autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="email"></label>
        <label>Κωδικός πρόσβασης<input type="password" name="password" required autocomplete="current-password" autocapitalize="none" autocorrect="off" spellcheck="false"></label>
        <button class="button primary" type="submit">Σύνδεση</button>
      </form>
    </div>
    <p class="access-note">Η πρόσβαση παρέχεται αποκλειστικά από τον διαχειριστή του συστήματος.</p>
    <p class="access-note"><a href="https://distillogic.gr/crm/">Μετάβαση στο Distillogic CRM ↗</a></p>
  </div></section>
</main><script src="<?= e(crm_url('assets/workspace-ui.js?v=20260916')) ?>" defer></script></body></html>
