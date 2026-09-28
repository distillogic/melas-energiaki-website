<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';academy_session();if(!academy_installed())redirect_to('setup.php');require __DIR__.'/sso-workflow.php';$error=null;
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new InvalidArgumentException('Ξεκίνα την εταιρική σύνδεση από την Academy.');
    if(!academy_sso_enabled())throw new InvalidArgumentException('Η σύνδεση μέσω CRM δεν έχει ενεργοποιηθεί.');
    if(academy_input('action')==='start'){
        verify_csrf();academy_rate_limit('sso-start','',30);
        $url=academy_sso_url();
        $user=academy_current_user();$state=bin2hex(random_bytes(32));
        $_SESSION['sso_pending']=['state'=>$state,'created_at'=>time(),'link_user_id'=>$user['id']??null];
        header('Location: '.$url.'?state='.$state,true,303);exit;
    }
    $pending=$_SESSION['sso_pending']??null;
    if(!$pending||time()-(int)$pending['created_at']>600)throw new InvalidArgumentException('Η σύνδεση έληξε. Ξεκίνα ξανά από την Academy.');
    $claims=academy_sso_claims(academy_input('payload'),academy_input('signature'),$pending['state'],time());
    $linkUser=null;if($pending['link_user_id']){$linkUser=academy_current_user();if(!$linkUser||$linkUser['id']!==$pending['link_user_id'])throw new InvalidArgumentException('Η προσωπική συνεδρία έληξε. Συνδέσου ξανά πριν συνδέσεις λογαριασμούς.');}
    $user=academy_sso_accept($claims,$linkUser);unset($_SESSION['sso_pending']);academy_sign_in($user,'sso');redirect_to('');
}catch(InvalidArgumentException $e){http_response_code(400);$error=$e->getMessage();}
academy_auth_header('Σύνδεση μέσω CRM'); ?><div class="school-error" role="alert"><?= e($error) ?></div><a class="school-button" href="<?= e(academy_url('login.php')) ?>">Επιστροφή στη σύνδεση</a><?php academy_auth_footer(); ?>
