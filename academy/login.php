<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';academy_session();if(!academy_installed())redirect_to('setup.php');if(academy_current_user())redirect_to('');$error=null;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(!academy_crm_enabled()&&!academy_local_login_enabled()){http_response_code(403);exit('Χρησιμοποίησε την εταιρική σύνδεση.');}
    verify_csrf();$email=strtolower(academy_input('email'));$password=is_string($_POST['password']??null)?$_POST['password']:'';
    try {
        academy_rate_limit('login',$email,10);
        if(academy_crm_enabled()){
            $account=academy_crm_login($email,$password);
            if($account){academy_sign_in($account,'crm');redirect_to('');}
        }else{
        $q=db()->prepare('SELECT * FROM '.academy_table('users').' WHERE email=?');$q->execute([$email]);$account=$q->fetch();
        // Fixed dummy hash avoids a fast unknown-account path.
        $hash=$account['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $verified=strlen($password)<=72 && !str_contains($password,"\0") && password_verify($password,$hash);
        if($verified&&$account&&$account['active']&&$account['password_hash']){academy_sign_in($account);redirect_to($account['must_change_password']?'password.php':'');}
        }
        $error='Δεν έγινε σύνδεση. Έλεγξε τα στοιχεία ή επικοινώνησε με τον διαχειριστή.';
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Academy identity login failed: '.get_class($e));http_response_code(503);$error='Η σύνδεση με το Energiaki CRM δεν είναι προσωρινά διαθέσιμη. Επικοινώνησε με τον διαχειριστή. Δεν χρειάζεται νέος λογαριασμός ή επαναφορά κωδικού Academy.';}
}
academy_auth_header('Καλώς ήρθες.'); ?>
<p>Συνδέσου για να συνεχίσεις τη δική σου εκπαιδευτική διαδρομή.</p><?php if($error): ?><div class="school-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if(academy_crm_enabled()): ?><form method="post" class="school-form"><?= csrf_field() ?><label>Email Energiaki CRM<input type="email" name="email" required autocomplete="username" value="<?= e(academy_input('email')) ?>"></label><label>Κωδικός Energiaki CRM<input type="password" name="password" required maxlength="72" autocomplete="current-password"></label><button class="school-button" type="submit">Σύνδεση στην Academy</button></form><p class="auth-note">Χρησιμοποίησε τα ίδια στοιχεία με τον ενεργό λογαριασμό σου στο Melas Energiaki CRM, χωρίς δεύτερη εγγραφή. Οι νέοι συνεργάτες ξεκινούν εδώ: πρώτα μαθήματα, εξετάσεις, εικονική πρακτική και εκπαιδευτική αξιολόγηση· μετά έγγραφα και υπογραφές· τέλος έγκριση για πρόσβαση στο πραγματικό CRM. Δεν χρειάζονται υπογραφές για την εκπαίδευση. Οι υπάρχουσες εξαιρέσεις πρόσβασης διατηρούνται. Για αλλαγή κωδικού επικοινώνησε με τον διαχειριστή του CRM.</p><?php endif; ?>
<p class="auth-note"><a href="/crm/login.php">Προσωπική διαδρομή πρόσβασης και έγγραφα μετά την Academy →</a></p><?php if(academy_sso_enabled()): ?><form method="post" action="<?= e(academy_url('sso.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><button class="school-button school-sso" type="submit">Σύνδεση μέσω <?= e(academy_sso_label()) ?> →</button></form><?php if(academy_local_login_enabled()): ?><p class="auth-divider">ή με ξεχωριστό λογαριασμό Academy</p><?php endif; ?><?php endif; ?>
<?php if(academy_local_login_enabled()): ?><form method="post" class="school-form"><?= csrf_field() ?><label>Email<input type="email" name="email" required autocomplete="username" value="<?= e(academy_input('email')) ?>"></label><label>Κωδικός Academy<input type="password" name="password" required maxlength="72" autocomplete="current-password"></label><button class="school-button" type="submit">Σύνδεση</button></form><p class="auth-note">Οι λογαριασμοί δημιουργούνται από τον διαχειριστή. Για νέο κωδικό ζήτησε επαναφορά από εκείνον. Κωδικός CRM χρησιμοποιείται μόνο στο CRM, μέσω του αντίστοιχου κουμπιού όταν έχει ενεργοποιηθεί.</p><?php endif; academy_auth_footer(); ?>
