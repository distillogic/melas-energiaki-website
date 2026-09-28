<?php
declare(strict_types=1);
if (!defined('ACADEMY_ROOT')) { http_response_code(403); exit; }

// Storage choice comes only from the private config, never from an HTTP request.
function academy_database_mode(): string {
    $mode = academy_config()['database']['mode'] ?? 'dedicated';
    if (!in_array($mode, ['dedicated', 'shared'], true)) throw new RuntimeException('Invalid Academy database mode');
    return $mode;
}
function academy_table_prefix(): string {
    return academy_database_mode() === 'shared' ? 'academy_school_' : 'academy_';
}
function academy_table_names(): array {
    return ['settings','users','auth_limits','sso_uses','admin_events','progress','attempts','quiz_snapshots','quiz_second_attempts','assessments','lab_drafts','lab_attempts','final_exams','supervised_calls','attempt_controls','retake_grants','call_runs','call_grants','call_evidence','access_sessions'];
}
function academy_table(string $name): string {
    if (!in_array($name, academy_table_names(), true)) throw new InvalidArgumentException('Unknown Academy table');
    return '`'.academy_table_prefix().$name.'`';
}
function academy_constraint(string $name): string {
    if (!in_array($name, ['progress_user_fk','attempt_user_fk','assessment_user_fk','assessment_assessor_fk','lab_draft_user_fk','lab_attempt_user_fk'], true)) {
        throw new InvalidArgumentException('Unknown Academy constraint');
    }
    return '`'.academy_table_prefix().$name.'`';
}
function academy_check_storage(PDO $pdo): void {
    if (academy_database_mode() === 'dedicated') {
        foreach (['users','sales_leads','company_documents'] as $table) {
            $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $q->execute([$table]);
            if ((int)$q->fetchColumn()>0) throw new RuntimeException('CRM database detected. Explicit database.mode=shared is required; do not reuse legacy academy_* tables.');
        }
        return;
    }
    // Legacy CRM Academy tables use academy_*. Only our longer, fixed namespace
    // may be claimed. Never adopt, rename, truncate or migrate those old tables.
    $prefix=academy_table_prefix();
    $q=$pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,CHAR_LENGTH(?))=?');
    $q->execute([$prefix,$prefix]);$tables=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$tables) return;
    $allowed=array_map(static fn(string $name):string=>$prefix.$name,academy_table_names());$hasSettings=false;
    foreach ($tables as $table) {
        if (!in_array($table['TABLE_NAME'],$allowed,true) || $table['TABLE_TYPE']!=='BASE TABLE' || strcasecmp($table['ENGINE']??'','InnoDB')!==0) {
            throw new RuntimeException('Academy namespace collision: unexpected object in academy_school_. No adoption allowed.');
        }
        if ($table['TABLE_NAME']===$prefix.'settings') $hasSettings=true;
    }
    if (!$hasSettings) throw new RuntimeException('Academy namespace collision: settings marker missing.');
    $q=$pdo->prepare('SELECT setting_value FROM '.academy_table('settings').' WHERE setting_key=?');
    $q->execute(['application_id']);
    if ($q->fetchColumn()!=='melas-sales-academy:shared:v1') throw new RuntimeException('Academy namespace collision: ownership marker missing or invalid.');
    $q=$pdo->prepare('SELECT TABLE_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,CHAR_LENGTH(?))=? AND REFERENCED_TABLE_NAME IS NOT NULL');
    $q->execute([$prefix,$prefix]);$database=$pdo->query('SELECT DATABASE()')->fetchColumn();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $key) {
        if ($key['REFERENCED_TABLE_SCHEMA']!==$database || !in_array($key['REFERENCED_TABLE_NAME'],$allowed,true)) {
            throw new RuntimeException('Academy namespace has an external foreign key. Installation blocked.');
        }
    }
}
function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $c=academy_config()['database'];
    $connection=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$c['host'],(int)($c['port']??3306),$c['name']),$c['user'],$c['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    academy_check_storage($connection);
    return $pdo=$connection;
}
function academy_install(string $name,string $email,string $password): void {
    if ($name==='' || mb_strlen($name)>150 || !academy_privileged_email($email) || !academy_password_valid($password)) {
        throw new InvalidArgumentException('Μη έγκυρα στοιχεία αρχικής εγκατάστασης.');
    }
    $pdo=db();
    $lockName=hash('sha256','melas-academy:'.$pdo->query('SELECT DATABASE()')->fetchColumn().':'.academy_table_prefix());
    $lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);
    if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Installation busy');
    try {
        // Recheck after the lock: another setup request may have just finished.
        academy_check_storage($pdo);
        if (academy_installed()) throw new InvalidArgumentException('Η εγκατάσταση έχει ήδη ολοκληρωθεί.');
        $schema=require __DIR__.'/schema.php';
        if (academy_database_mode()==='shared') {
            $pdo->exec($schema[0]);
            $pdo->prepare('INSERT INTO '.academy_table('settings')."(setting_key,setting_value) VALUES('application_id','melas-sales-academy:shared:v1') ON DUPLICATE KEY UPDATE setting_value=setting_value")->execute();
        }
        // DDL commits implicitly in MariaDB. Partial owned installs can resume;
        // only administrator + installed_at are part of the data transaction.
        foreach ($schema as $sql) $pdo->exec($sql);
        require_once __DIR__.'/courses.php';ensure_academy_schema();
        require_once __DIR__.'/assessment-workflow.php';academy_assessment_schema();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO '.academy_table('users').'(id,name,email,role,password_hash) VALUES(?,?,?,?,?)')->execute([uuid_v4(),$name,$email,academy_initial_role($email),password_hash($password,PASSWORD_DEFAULT)]);
        $pdo->prepare('INSERT INTO '.academy_table('settings')."(setting_key,setting_value) VALUES('installed_at',?)")->execute([(new DateTimeImmutable())->format(DATE_ATOM)]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);
    }
}
