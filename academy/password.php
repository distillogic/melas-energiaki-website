<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=academy_require_login();$error=null;
if(academy_crm_enabled()){
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){verify_csrf();http_response_code(403);exit('Ο κωδικός αλλάζει μόνο στο Energiaki CRM.');}
    render_header('Κωδικός Energiaki CRM',$user);
    echo '<section class="school-panel school-narrow"><h1>Ένας κωδικός για CRM και Academy</h1><p>Για αλλαγή κωδικού επικοινώνησε με τον διαχειριστή του Energiaki CRM. Η αλλαγή εκεί ισχύει αμέσως και εδώ.</p><a class="school-button" href="/crm/">Energiaki CRM →</a></section>';
    render_footer();exit;
}
if (!$user['password_hash']) {http_response_code(403);exit('Αυτός ο λογαριασμός συνδέεται μέσω CRM. Ο κωδικός αλλάζει μόνο εκεί.');}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    verify_csrf();
    try{
        academy_rate_limit('password',$user['id'],10);
        $old=is_string($_POST['current_password']??null)?$_POST['current_password']:'';$new=is_string($_POST['new_password']??null)?$_POST['new_password']:'';
        if(!password_verify($old,$user['password_hash']))throw new InvalidArgumentException('Ο τρέχων κωδικός δεν είναι σωστός.');
        if(!academy_password_valid($new)||$new!==($_POST['confirm_password']??null)||password_verify($new,$user['password_hash']))throw new InvalidArgumentException('Δώσε ίδιο νέο κωδικό στα δύο πεδία, 12–72 bytes, διαφορετικό από τον παλιό.');
        $q=db()->prepare('UPDATE '.academy_table('users').' SET password_hash=?,must_change_password=0,session_version=session_version+1 WHERE id=? AND session_version=? AND active=1');$q->execute([password_hash($new,PASSWORD_DEFAULT),$user['id'],$user['session_version']]);
        if($q->rowCount()!==1)throw new InvalidArgumentException('Ο λογαριασμός άλλαξε. Συνδέσου ξανά.');
        $user['session_version']++;academy_sign_in($user);$_SESSION['notice']='Ο κωδικός Academy ενημερώθηκε και οι προηγούμενες συνεδρίες τερματίστηκαν.';redirect_to('');
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
}
render_header('Αλλαγή κωδικού',$user); ?><section class="school-panel school-narrow"><h1><?= $user['must_change_password']?'Όρισε προσωπικό κωδικό':'Αλλαγή κωδικού Academy' ?></h1><p>Η αλλαγή αφορά μόνο την Academy, όχι τους κωδικούς των CRM.</p><?php if($error): ?><div class="school-error" role="alert"><?= e($error) ?></div><?php endif; ?><form method="post" class="school-form"><?= csrf_field() ?><label>Τρέχων / προσωρινός κωδικός<input type="password" name="current_password" required autocomplete="current-password"></label><label>Νέος κωδικός<input type="password" name="new_password" minlength="12" maxlength="72" required autocomplete="new-password"></label><label>Επανάληψη νέου κωδικού<input type="password" name="confirm_password" minlength="12" maxlength="72" required autocomplete="new-password"></label><button class="school-button" type="submit">Αποθήκευση νέου κωδικού</button></form></section><?php render_footer(); ?>
