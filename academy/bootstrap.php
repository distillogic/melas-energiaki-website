<?php
declare(strict_types=1);
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(403); exit; }
const ACADEMY_ROOT = __DIR__;
require_once __DIR__.'/database.php';
require_once __DIR__.'/roles.php';
require_once __DIR__.'/crm-identity.php';
require_once __DIR__.'/access-history.php';
require_once __DIR__.'/training-permissions.php';

function academy_config(): array {
    static $config;
    if (is_array($config)) return $config;
    if (!is_file(__DIR__.'/config.php')) {
        http_response_code(503);
        exit('Η Academy χρειάζεται ρύθμιση. Δημιουργήστε academy/config.php από το config.example.php. Για κοινή βάση ορίστε database.mode=shared και αντιγράψτε μόνο τα στοιχεία σύνδεσης της βάσης, όχι ολόκληρο το config του CRM.');
    }
    $config = require __DIR__.'/config.php';
    if (!is_array($config)) throw new RuntimeException('Invalid Academy configuration');
    $origin = (string)($config['app']['origin'] ?? '');
    if (!preg_match('~^https://[a-z0-9.-]+(?::[0-9]+)?$~iD', $origin)
        && !(PHP_SAPI === 'cli-server' && preg_match('~^http://127\.0\.0\.1:[0-9]+$~D', $origin))) {
        throw new RuntimeException('Academy origin must be an HTTPS origin');
    }
    if (($config['app']['base_path'] ?? '') !== '/academy') throw new RuntimeException('Academy base_path must be /academy');
    date_default_timezone_set((string)($config['app']['timezone'] ?? 'Europe/Athens'));
    return $config;
}
function academy_url(string $path=''): string { return '/academy/' . ltrim($path, '/'); }
function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function uuid_v4(): string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));
}
function redirect_to(string $path): never { header('Location: '.academy_url($path),true,303);exit; }
function format_datetime(?string $value): string { return $value ? (new DateTimeImmutable($value))->format('d/m/Y, H:i') : '—'; }
function academy_https(): bool {
    if (!empty($_SERVER['HTTPS']) && !in_array(strtolower((string)$_SERVER['HTTPS']),['off','0'],true)) return true;
    if ((int)($_SERVER['SERVER_PORT']??0)===443) return true;
    $trusted=academy_config()['app']['trusted_proxy_ips']??[];
    return in_array((string)($_SERVER['REMOTE_ADDR']??''),$trusted,true) && ($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
}
function academy_local_test(): bool {
    return PHP_SAPI==='cli-server' && in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)
        && preg_match('~^http://127\.0\.0\.1:[0-9]+$~D',academy_config()['app']['origin'])===1;
}
function academy_security_headers(): void {
    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
    // Browsers enforce form-action on redirects from the local SSO start POST too.
    $ssoOrigin=(academy_sso_enabled()&&(academy_config()['sso']['issuer']??'')==='distillogic-crm')?' https://distillogic.gr':'';
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'".$ssoOrigin."; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    if (PHP_SAPI==='cli') return;
    if (!academy_https() && !academy_local_test()) {
        // Never accept a credential or signed assertion submitted over HTTP.
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {http_response_code(400);exit('Απαιτείται ασφαλής HTTPS σύνδεση.');}
        header('Location: '.academy_config()['app']['origin'].academy_url('login.php'),true,302);exit;
    }
}
function academy_session(): void {
    if (session_status()===PHP_SESSION_ACTIVE) return;
    academy_security_headers();
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
    session_name('melas_academy_v1');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/academy/','secure'=>!academy_local_test(),'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
function academy_installed(): bool {
    try {$installed=(bool)db()->query('SELECT setting_value FROM '.academy_table('settings')." WHERE setting_key='installed_at'")->fetchColumn();}
    catch (PDOException $e) {if (($e->errorInfo[1]??0)===1146) return false;throw $e;}
    if ($installed) {academy_migrate_core_roles();academy_crm_schema();}
    return $installed;
}
function academy_privileged_email(string $email): bool {
    return in_array(strtolower(trim($email)),['melas@distillogic.gr','sophianos@distillogic.gr'],true);
}
function academy_is_admin(array $user): bool {
    // Both approved management accounts retain training administration. The
    // legacy Sophianos admin role stays compatible until the one-time migration.
    return !empty($user['active']) && in_array($user['role']??'', ['admin','manager'], true) && academy_privileged_email((string)($user['email']??''));
}
function academy_current_user(): ?array {
    academy_session();
    if (!isset($_SESSION['user_id'])) return null;
    $q=db()->prepare('SELECT * FROM '.academy_table('users').' WHERE id=?');$q->execute([$_SESSION['user_id']]);$user=$q->fetch();
    $expired=(time()-(int)($_SESSION['last_seen']??0)>1800)||(time()-(int)($_SESSION['signed_in_at']??0)>28800);
    if (($_SESSION['auth_method']??'')==='sso' && time()-(int)($_SESSION['signed_in_at']??0)>900) $expired=true;
    if (!$user || !$user['active'] || $expired || (int)$user['session_version']!==(int)($_SESSION['session_version']??0)) {
        academy_access_end($expired?'expired':'revoked');
        $_SESSION=[];return null;
    }
    if(academy_crm_enabled()&&!academy_crm_session_valid($user)){academy_access_end('revoked');$_SESSION=[];return null;}
    $_SESSION['last_seen']=time();academy_access_touch($user);return $user;
}
function academy_sign_in(array $user,string $method='local'): void {
    if(academy_crm_enabled()){
        if($method!=='crm'||empty($user['crm_user_id'])||!is_string($user['_crm_stamp']??null))throw new InvalidArgumentException('Χρησιμοποίησε το email και τον κωδικό του Energiaki CRM.');
        $source=academy_crm_user($user['crm_user_id'],true);
        if(!$source||!academy_crm_allowed($source)||!hash_equals(academy_crm_stamp($source),$user['_crm_stamp']))throw new InvalidArgumentException('Ο λογαριασμός CRM άλλαξε. Συνδέσου ξανά.');
    }
    academy_session();academy_access_end('reauthenticated');session_regenerate_id(true);
    $_SESSION=['user_id'=>$user['id'],'session_version'=>(int)$user['session_version'],'signed_in_at'=>time(),'last_seen'=>time(),'auth_method'=>$method,'csrf'=>bin2hex(random_bytes(32))];
    if($method==='crm')$_SESSION['crm_stamp']=$user['_crm_stamp'];
    academy_access_begin($user,$method);
}
function academy_require_login(): array {
    if (!academy_installed()) redirect_to('setup.php');
    $user=academy_current_user();if (!$user) redirect_to('login.php');
    if ($user['must_change_password'] && basename($_SERVER['SCRIPT_NAME']??'')!=='password.php') redirect_to('password.php');
    return $user;
}
function academy_require_admin(): array {
    $user=academy_require_login();if (!academy_is_admin($user)) {http_response_code(403);exit('Δεν έχετε πρόσβαση στη διαχείριση Academy.');}return $user;
}
function csrf_field(): string {
    academy_session();$_SESSION['csrf']??=bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';
}
function verify_csrf(): void {
    academy_session();$value=$_POST['csrf']??null;
    if (!is_string($value)||!isset($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$value)) {http_response_code(419);exit('Η φόρμα έληξε. Άνοιξε ξανά τη σελίδα και δοκίμασε πάλι.');}
}
function academy_input(string $key): string {return is_string($_POST[$key]??null)?trim($_POST[$key]):'';}
function academy_password_valid(string $password): bool {return mb_strlen($password)>=12 && strlen($password)<=72 && !str_contains($password,"\0");}
function academy_rate_limit(string $scope,string $identity='',int $max=10): void {
    // Apply independent per-IP and per-identity limits; no raw email/IP is retained.
    $keys=['ip:'.($_SERVER['REMOTE_ADDR']??'local')];if ($identity!=='') $keys[]='identity:'.strtolower($identity);
    foreach ($keys as $value) {
        $key=hash('sha256',$scope.'|'.$value);
        db()->prepare('INSERT INTO '.academy_table('auth_limits').'(limit_key,attempts,expires_at) VALUES(?,1,DATE_ADD(NOW(),INTERVAL 15 MINUTE)) ON DUPLICATE KEY UPDATE attempts=IF(expires_at<NOW(),1,attempts+1),expires_at=IF(expires_at<NOW(),DATE_ADD(NOW(),INTERVAL 15 MINUTE),expires_at)')->execute([$key]);
        $q=db()->prepare('SELECT attempts FROM '.academy_table('auth_limits').' WHERE limit_key=?');$q->execute([$key]);
        $limit=str_starts_with($value,'ip:')&&$identity!==''?$max*6:$max;
        if ((int)$q->fetchColumn()>$limit) throw new InvalidArgumentException('Πολλές προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.');
    }
}
function academy_admin_event(array $actor,?string $target,string $action): void {
    db()->prepare('INSERT INTO '.academy_table('admin_events').'(id,actor_id,target_id,action) VALUES(?,?,?,?)')->execute([uuid_v4(),$actor['id'],$target,$action]);
}
function academy_sso_enabled(): bool {
    if(academy_crm_enabled())return false;
    $c=academy_config()['sso']??[];$key=(string)($c['shared_key']??'');
    return !empty($c['enabled']) && strlen($key)>=64 && !str_starts_with($key,'REPLACE_');
}
function academy_sso_url(): string {
    $c=academy_config();$url=(string)($c['sso']['authorize_url']??'');$issuer=(string)($c['sso']['issuer']??'');
    $expected=$issuer==='distillogic-crm'?'https://distillogic.gr/crm/academy-connect.php':$c['app']['origin'].'/crm/academy-connect.php';
    if(!in_array($issuer,['melas-crm','distillogic-crm'],true)||$url!==$expected)throw new RuntimeException('Invalid configured identity provider');
    return $url;
}
function academy_sso_label(): string { return (academy_config()['sso']['issuer']??'')==='distillogic-crm'?'Distillogic':'Melas CRM'; }
function academy_local_login_enabled(): bool { return !academy_crm_enabled()&&(academy_config()['app']['local_login_enabled']??true)===true; }
function academy_notice(): void {
    if (isset($_SESSION['notice'])) {echo '<div class="school-info" role="status">'.e($_SESSION['notice']).'</div>';unset($_SESSION['notice']);}
}
function render_header(string $title, array $user): void {
    require_once __DIR__.'/courses.php';
    require_once __DIR__.'/interface-v38.php';
    academy_interface_header($title,$user);
}
function render_footer(): void {
    echo '</main><footer class="school-footer">Melas Sales Academy · Εκπαίδευση με ουσία, εφαρμογή στην πράξη.<span>Ξεχωριστός χώρος από τα εταιρικά CRM. <a href="'.e(academy_url('privacy.php')).'">Απόρρητο &amp; καταγραφή πρόσβασης (30 ημέρες)</a></span></footer></body></html>';
}
function academy_auth_header(string $title): void {
    ?><!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= e($title) ?> | Melas Sales Academy</title><link rel="icon" href="<?= e(academy_url('favicon.svg')) ?>" type="image/svg+xml"><link rel="stylesheet" href="<?= e(academy_url('shell.css?v=18')) ?>"><link rel="stylesheet" href="<?= e(academy_url('terms-v27.css?v=27')) ?>"></head><body class="school-auth"><main class="auth-layout"><section class="auth-story"><img src="<?= e(academy_url('melas-logo.png')) ?>" alt="Melas Energiaki"><span class="auth-kicker">MELAS SALES ACADEMY</span><h1>Η γνώση ανοίγει<br>νέες ευκαιρίες.</h1><p>Ο δικός σου χώρος για εκπαίδευση, εξάσκηση και συνεχή εξέλιξη.</p><ol><li><b>01</b> Melas Energiaki</li><li><b>02</b> Distillogic</li><li><b>03</b> CRM Melas Energiaki</li><li><b>04</b> CRM Distillogic</li></ol></section><section class="auth-form"><h2><?= e($title) ?></h2><?php
}
function academy_auth_footer(): void {echo '<p class="auth-note">Η πρόσβαση στην Academy δεν παρέχει πρόσβαση σε πελάτες, οικονομικά ή εταιρικά έγγραφα.</p><p class="auth-note">Καταγράφονται είσοδος, τελευταία δραστηριότητα και έξοδος/λήξη για 30 ημέρες. <a href="/academy/privacy.php">Ενημέρωση απορρήτου</a>.</p></section></main></body></html>';}

set_exception_handler(static function(Throwable $error):void {
    error_log('Academy: '.$error->getMessage());http_response_code(503);
    echo 'Η Academy δεν είναι προσωρινά διαθέσιμη. Ελέγξτε το δικό της config, το database.mode, τη σύνδεση βάσης και τα Logs. Μη διαγράψετε πίνακες ή αλλάξετε το CRM για να διορθώσετε το σφάλμα.';
});
academy_security_headers();
