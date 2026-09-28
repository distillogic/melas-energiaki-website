<?php
declare(strict_types=1);
if (!defined('ACADEMY_ROOT')) { http_response_code(403); exit; }

// v23: the sibling Energiaki CRM is the canonical identity source. The
// Academy keeps its own records/progress; it never writes to CRM tables.
function academy_crm_enabled(): bool {
    return (academy_config()['crm_identity']['enabled'] ?? true) === true;
}
function academy_crm_db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $path=dirname(ACADEMY_ROOT).'/crm/config.php';
    if (!is_file($path)) throw new RuntimeException('Sibling Energiaki CRM configuration missing');
    $config=require $path;
    if (!is_array($config)||($config['app']['base_path']??'')!=='/crm'||!is_array($config['database']??null)) throw new RuntimeException('Invalid sibling CRM configuration');
    $c=$config['database'];
    foreach (['host','name','user','password'] as $key) if (!is_string($c[$key]??null)) throw new RuntimeException('Incomplete CRM database configuration');
    try {
        $pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$c['host'],(int)($c['port']??3306),$c['name']),$c['user'],$c['password'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
    } catch (PDOException) { throw new RuntimeException('Energiaki CRM identity database unavailable'); }
    return $pdo;
}
function academy_crm_user(string $value,bool $byId=false): ?array {
    static $tables;
    $pdo=academy_crm_db();
    if (!is_array($tables)) $tables=$pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    $state=in_array('user_access_state',$tables,true);$profile=in_array('user_work_profiles',$tables,true);
    $sql='SELECT u.id,u.name,u.email,u.role,u.active,u.password_hash,'.($state?'COALESCE(s.generation,0)':'0').' AS generation,'.($profile?'p.deleted_at':'NULL').' AS deleted_at FROM users u';
    if($state)$sql.=' LEFT JOIN user_access_state s ON s.user_id=u.id';
    if($profile)$sql.=' LEFT JOIN user_work_profiles p ON p.user_id=u.id';
    $sql.=$byId?' WHERE u.id=? LIMIT 1':' WHERE u.email=? LIMIT 1';
    $q=$pdo->prepare($sql);$q->execute([$value]);return $q->fetch()?:null;
}
function academy_crm_allowed(array $user): bool {
    $policy=dirname(ACADEMY_ROOT).'/crm/onboarding-policy.php';
    if(!is_file($policy))throw new RuntimeException('Upload Melas CRM v28 before Academy v28');
    require_once $policy;
    return !empty($user['active'])&&empty($user['deleted_at'])
        && !in_array(strtolower(trim((string)$user['email'])),['sophianos@melasenenrgiaki.gr','sophianos@melasenergiaki.gr'],true)
        && in_array($user['role'],['employee','partner','technical','manager','admin'],true)
        && melas_onboarding_allowed(academy_crm_db(),$user,'academy');
}
function academy_crm_role(array $user): string {
    $email=strtolower(trim((string)$user['email']));$role=$user['role'];
    if($email==='melas@distillogic.gr'&&$role==='admin')return 'admin';
    if($email==='sophianos@distillogic.gr'&&in_array($role,['admin','manager'],true))return 'manager';
    if($email==='support@distillogic.gr'&&$role==='technical')return 'technical';
    return 'learner';
}
function academy_crm_stamp(array $user): string {
    // One-way revocation fingerprint, never the CRM password hash in a session.
    return hash('sha256',json_encode(array_intersect_key($user,array_flip(['id','name','email','role','active','password_hash','generation','deleted_at'])),JSON_THROW_ON_ERROR));
}
function academy_crm_schema(): void {
    static $done=false;if($done||!academy_crm_enabled())return;
    $pdo=db();$table=academy_table('users');
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([academy_table_prefix().'users','crm_user_id']);
    if(!(int)$q->fetchColumn()){
        // Serialize only our Academy migration; no CRM DDL and no cross-app FK.
        $lockName=hash('sha256','academy-crm-identity-v23:'.$pdo->query('SELECT DATABASE()')->fetchColumn().':'.$table);
        $lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);
        if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Academy identity migration busy');
        try{
            $q->execute([academy_table_prefix().'users','crm_user_id']);
            if(!(int)$q->fetchColumn())$pdo->exec("ALTER TABLE $table ADD COLUMN crm_user_id CHAR(36) NULL, ADD UNIQUE KEY academy_crm_identity_unique(crm_user_id)");
        }finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
    }
    $done=true;
}
function academy_crm_login(string $email,string $password): ?array {
    if(!academy_crm_enabled())throw new InvalidArgumentException('Η εταιρική σύνδεση δεν είναι ενεργή.');
    academy_crm_schema();$source=academy_crm_user(strtolower(trim($email)));
    $hash=$source['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $verified=strlen($password)<=72&&!str_contains($password,"\0")&&password_verify($password,$hash);
    if(!$source||!$verified||!academy_crm_allowed($source))return null;
    if(!preg_match('/^[a-zA-Z0-9-]{1,36}$/D',$source['id'])||strlen($source['email'])>254||!filter_var($source['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Η καρτέλα CRM χρειάζεται έλεγχο email από τον διαχειριστή.');
    $pdo=db();$table=academy_table('users');$pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT * FROM $table WHERE crm_user_id=? FOR UPDATE");$q->execute([$source['id']]);$user=$q->fetch();$linked=(bool)$user;
        if(!$user){$q=$pdo->prepare("SELECT * FROM $table WHERE email=? FOR UPDATE");$q->execute([$source['email']]);$user=$q->fetch();}
        if($user){
            // The owner explicitly selected CRM identity migration. On first
            // CRM-password proof only, reuse the same-email Academy record to
            // preserve progress. Never rebind an already linked CRM identity.
            if(!$user['active'])throw new InvalidArgumentException('Η πρόσβαση στην Academy έχει απενεργοποιηθεί. Ζήτησε επανενεργοποίηση από τον διαχειριστή.');
            if(($user['crm_user_id']&&$user['crm_user_id']!==$source['id'])||(!$linked&&$user['sso_issuer']==='melas-crm'&&$user['sso_subject']!==$source['id']))throw new InvalidArgumentException('Υπάρχει σύγκρουση εταιρικής ταυτότητας. Χρειάζεται έλεγχος από τον διαχειριστή.');
            $q=$pdo->prepare("SELECT id FROM $table WHERE email=? AND id<>? FOR UPDATE");$q->execute([$source['email'],$user['id']]);
            if($q->fetch())throw new InvalidArgumentException('Το email ανήκει σε άλλη καρτέλα Academy. Η πρόοδος δεν συγχωνεύτηκε.');
            $changed=!$linked||$user['name']!==$source['name']||$user['email']!==$source['email']||$user['role']!==academy_crm_role($source)||$user['password_hash']!==null||$user['must_change_password'];
            $pdo->prepare("UPDATE $table SET crm_user_id=?,name=?,email=?,role=?,password_hash=NULL,must_change_password=0,session_version=session_version+? WHERE id=?")->execute([$source['id'],$source['name'],$source['email'],academy_crm_role($source),$changed?1:0,$user['id']]);
            $id=$user['id'];
            if($changed)academy_admin_event(['id'=>$id],$id,$linked?'crm:identity-refresh':'crm:identity-linked');
        }else{
            $id=uuid_v4();$pdo->prepare("INSERT INTO $table(id,name,email,role,crm_user_id) VALUES(?,?,?,?,?)")->execute([$id,$source['name'],$source['email'],academy_crm_role($source),$source['id']]);
            academy_admin_event(['id'=>$id],$id,'crm:identity-created');
        }
        $q=$pdo->prepare("SELECT * FROM $table WHERE id=?");$q->execute([$id]);$user=$q->fetch();$pdo->commit();
        $user['_crm_stamp']=academy_crm_stamp($source);return $user;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function academy_crm_session_valid(array $user): bool {
    if(($_SESSION['auth_method']??'')!=='crm'||empty($user['crm_user_id'])||!is_string($_SESSION['crm_stamp']??null))return false;
    $source=academy_crm_user($user['crm_user_id'],true);
    return $source&&academy_crm_allowed($source)&&$source['email']===$user['email']&&academy_crm_role($source)===$user['role']
        &&hash_equals(academy_crm_stamp($source),$_SESSION['crm_stamp']);
}
