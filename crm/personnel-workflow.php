<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
require_once __DIR__.'/onboarding-workflow.php';
require_once __DIR__.'/personnel-terms-v36.php';

function personnel_owner(array $user):bool {
    return in_array(strtolower(trim((string)($user['email']??''))),['melas@distillogic.gr','sophianos@distillogic.gr'],true);
}
function personnel_ceo(array $user):bool {return strtolower(trim((string)($user['email']??'')))==='melas@distillogic.gr';}
function personnel_signatures_required_for_access():bool {
    return true; // v28: only identities created after the explicit legacy snapshot.
}
function personnel_schema():void {
    static $ready=false;if($ready)return;
    $pdo=db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS personnel_onboarding (user_id CHAR(36) PRIMARY KEY, relationship VARCHAR(20) NOT NULL, onboarding_cycle INT NOT NULL DEFAULT 1, released_at DATETIME NULL, released_by CHAR(36) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS personnel_templates (id CHAR(36) PRIMARY KEY, kind VARCHAR(30) NOT NULL, title VARCHAR(200) NOT NULL, body LONGTEXT NOT NULL, approved_at DATETIME NULL, approved_by CHAR(36) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY initial_seed(kind,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS personnel_documents (id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, onboarding_cycle INT NOT NULL, kind VARCHAR(30) NOT NULL, revision INT NOT NULL, reference VARCHAR(80) NOT NULL, snapshot LONGTEXT NOT NULL, worker_pdf LONGBLOB NULL, worker_hash CHAR(64) NULL, worker_at DATETIME NULL, code_hash VARCHAR(255) NULL, code_expires DATETIME NULL, code_requested DATETIME NULL, code_attempts INT NOT NULL DEFAULT 0, code_binding CHAR(64) NULL, approved_at DATETIME NULL, approved_by CHAR(36) NULL, final_pdf LONGBLOB NULL, final_hash CHAR(64) NULL, finalized_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY doc_revision(user_id,onboarding_cycle,kind,revision)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS personnel_audit (id CHAR(36) PRIMARY KEY, actor_id CHAR(36) NOT NULL, user_id CHAR(36) NULL, document_id CHAR(36) NULL, action VARCHAR(40) NOT NULL, detail TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $seeds=json_decode((string)file_get_contents(__DIR__.'/private-assets/personnel-templates.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($seeds as $kind=>$data){
        // Stable seed IDs avoid duplicate inserts during concurrent first requests.
        $id=substr(hash('sha256','personnel-v1-'.$kind),0,36);
        $pdo->prepare('INSERT IGNORE INTO personnel_templates(id,kind,title,body) VALUES(?,?,?,?)')->execute([$id,$kind,$data['title'],$data['body']]);
    }
    $seeds=json_decode((string)file_get_contents(__DIR__.'/private-assets/onboarding-templates-v28.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($seeds as $kind=>$data){
        $id=substr(hash('sha256','melas-onboarding-v28-'.$kind),0,36);
        $pdo->prepare('INSERT IGNORE INTO personnel_templates(id,kind,title,body) VALUES(?,?,?,?)')->execute([$id,$kind,$data['title'],$data['body']]);
    }
    foreach(require __DIR__.'/personnel-templates-v36.php' as $kind=>$data){
        $id=substr(hash('sha256','melas-legal-v36-'.$kind),0,36);
        $pdo->prepare('INSERT IGNORE INTO personnel_templates(id,kind,title,body) VALUES(?,?,?,?)')->execute([$id,$kind,$data['title'],$data['body']]);
    }
    onboarding_schema($pdo);
    $ready=true;
}
function personnel_record(string $id):?array {
    personnel_schema();$q=db()->prepare('SELECT * FROM personnel_onboarding WHERE user_id=?');$q->execute([$id]);return $q->fetch()?:null;
}
function personnel_latest(string $id,int $onboarding_cycle,string $kind):?array {
    $q=db()->prepare('SELECT id,kind,revision,reference,worker_at,approved_at,finalized_at,final_hash,(worker_pdf IS NOT NULL) AS has_worker,(final_pdf IS NOT NULL) AS has_final FROM personnel_documents WHERE user_id=? AND onboarding_cycle=? AND kind=? ORDER BY revision DESC LIMIT 1');$q->execute([$id,$onboarding_cycle,$kind]);return $q->fetch()?:null;
}
function personnel_complete(array $record):bool {
    if(onboarding_required($record['user_id']))return melas_onboarding_documents_ready(db(),$record['user_id']);
    foreach([$record['relationship']==='employee'?'employment':'services','confidentiality'] as $kind){
        $doc=personnel_latest($record['user_id'],(int)$record['onboarding_cycle'],$kind);
        if(!$doc||!$doc['worker_at']||!$doc['approved_at']||!$doc['finalized_at']||!$doc['final_hash'])return false;
        if(!$doc['has_worker']||!$doc['has_final'])return false;
    }
    return true;
}
function personnel_access_allowed(array $user):bool {
    personnel_schema();return melas_onboarding_allowed(db(),$user,'crm');
}
function personnel_reauth(array $actor,array $input):void {
    if(!can_manage_accounts($actor))throw new InvalidArgumentException('Δεν επιτρέπεται διαχείριση προσωπικού.');
    start_crm_session();
    if((int)($_SESSION['account_auth_block_until']??0)>time())throw new InvalidArgumentException('Περιμένετε πέντε λεπτά πριν δοκιμάσετε ξανά.');
    $q=db()->prepare('SELECT * FROM users WHERE id=? AND active=1');$q->execute([$actor['id']]);$fresh=$q->fetch();
    if(!$fresh||!can_manage_accounts($fresh)||!password_verify((string)($input['actor_password']??''),(string)$fresh['password_hash'])){
        $_SESSION['account_auth_failures']=(int)($_SESSION['account_auth_failures']??0)+1;
        if($_SESSION['account_auth_failures']>=5){$_SESSION['account_auth_block_until']=time()+300;$_SESSION['account_auth_failures']=0;}
        throw new InvalidArgumentException('Αποτυχία επιβεβαίωσης διαχειριστή.');
    }
    $_SESSION['account_auth_failures']=0;
}
function personnel_audit(array $actor,?string $uid,?string $doc,string $action,string $detail):void {
    db()->prepare('INSERT INTO personnel_audit(id,actor_id,user_id,document_id,action,detail) VALUES(?,?,?,?,?,?)')->execute([uuid_v4(),$actor['id'],$uid,$doc,$action,$detail]);
}
function personnel_pdf_upload(string $field):string {
    $file=$_FILES[$field]??null;
    if(!is_array($file)||(int)($file['error']??-1)!==UPLOAD_ERR_OK||(int)$file['size']<100||(int)$file['size']>10*1024*1024||!is_uploaded_file((string)$file['tmp_name']))throw new InvalidArgumentException('Επιλέξτε PDF έως 10 MB.');
    $bytes=file_get_contents($file['tmp_name']);
    if($bytes===false||!str_starts_with($bytes,'%PDF-')||(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])!=='application/pdf')throw new InvalidArgumentException('Το αρχείο δεν είναι έγκυρο PDF.');
    return $bytes;
}
function personnel_document(string $id,bool $lock=false):array {
    $q=db()->prepare('SELECT * FROM personnel_documents WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$doc=$q->fetch();
    if(!$doc)throw new InvalidArgumentException('Το έγγραφο δεν βρέθηκε.');return $doc;
}
function personnel_document_current(array $doc):bool {
    $record=personnel_record($doc['user_id']);
    if(!$record||(int)$record['onboarding_cycle']!==(int)$doc['onboarding_cycle'])return false;
    $latest=personnel_latest($doc['user_id'],(int)$doc['onboarding_cycle'],$doc['kind']);return $latest&&$latest['id']===$doc['id'];
}
function personnel_binding(array $doc):string {
    return hash('sha256',$doc['id'].'|'.$doc['snapshot'].'|'.$doc['worker_hash']);
}
