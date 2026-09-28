<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';academy_session();
$installed=academy_installed();$error=null;
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && !$installed) {
    verify_csrf();
    try {
        // Session throttle works even before the database tables exist.
        $window=$_SESSION['setup_window']??['at'=>time(),'count'=>0];if(time()-$window['at']>900)$window=['at'=>time(),'count'=>0];
        $window['count']++;$_SESSION['setup_window']=$window;if($window['count']>10)throw new InvalidArgumentException('Πολλές προσπάθειες εγκατάστασης. Δοκίμασε ξανά σε 15 λεπτά.');
        $expected=(string)(academy_config()['app']['setup_token']??'');
        if (strlen($expected)<32||str_starts_with($expected,'REPLACE_')||!hash_equals($expected,academy_input('setup_token'))) throw new InvalidArgumentException('Το setup token δεν είναι σωστό ή δεν έχει οριστεί στο config.php.');
        $name=academy_input('name');$email=strtolower(academy_input('email'));$password=is_string($_POST['password']??null)?$_POST['password']:'';
        if ($name===''||mb_strlen($name)>150||!academy_privileged_email($email)) throw new InvalidArgumentException('Δώσε όνομα και το email του Μελά ή του εξουσιοδοτημένου διαχειριστή.');
        if (!academy_password_valid($password)) throw new InvalidArgumentException('Χρησιμοποίησε νέο κωδικό 12–72 bytes.');
        academy_install($name,$email,$password);$installed=true;
    } catch (InvalidArgumentException $e) {$error=$e->getMessage();}
    catch (Throwable $e) {error_log('Academy setup: '.$e->getMessage());$error='Η εγκατάσταση δεν ολοκληρώθηκε. Έλεγξε τα στοιχεία βάσης, το database.mode, τα δικαιώματα και τα Logs. Μη διαγράψεις κανέναν πίνακα του CRM.';}
}
academy_auth_header($installed?'Η Academy είναι έτοιμη':'Αρχική εγκατάσταση');
if($installed): ?><p>Η εγκατάσταση έχει ολοκληρωθεί. Δεν χρειάζεται να ξανατρέξει και δεν αλλάζει το CRM.</p><a class="school-button" href="<?= e(academy_url('login.php')) ?>">Μετάβαση στη σύνδεση</a><?php else: ?>
<p>Δημιούργησε τον πρώτο λογαριασμό διαχείρισης Academy. Η εγκατάσταση εκτελείται μία φορά.</p>
<?php if(academy_database_mode()==='shared'): ?><p class="school-info">Κοινή βάση: θα δημιουργηθούν μόνο οι νέοι πίνακες <strong>academy_school_*</strong>. Οι πίνακες και οι χρήστες CRM δεν εισάγονται ή τροποποιούνται. Κράτησε πρώτα πλήρες αντίγραφο της βάσης.</p><?php else: ?><p class="school-info">Ξεχωριστή βάση Academy: χρησιμοποιούνται οι πίνακες academy_*.</p><?php endif; ?>
<?php if($error): ?><div class="school-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="school-form"><?= csrf_field() ?><label>Setup token<input type="password" name="setup_token" required autocomplete="off"></label><label>Όνομα<input name="name" required maxlength="150" autocomplete="name"></label><label>Email διαχειριστή<select name="email"><option value="sophianos@distillogic.gr">sophianos@distillogic.gr</option><option value="melas@distillogic.gr">melas@distillogic.gr</option></select></label><label>Νέος κωδικός Academy<input type="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password"></label><button class="school-button" type="submit">Εγκατάσταση Academy</button></form><?php endif; academy_auth_footer(); ?>
