<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
require_once __DIR__.'/onboarding-policy.php';
function onboarding_schema(PDO $pdo):void {
    // Run outside business transactions. The one-time snapshot is atomic and serialized.
    $pdo->exec("CREATE TABLE IF NOT EXISTS personnel_access_rules(user_id CHAR(36) PRIMARY KEY,policy VARCHAR(16) NOT NULL,preapproved_at DATETIME NULL,preapproved_by CHAR(36) NULL,checklist_json TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS personnel_policy_receipts(user_id CHAR(36) NOT NULL,document_id CHAR(36) NOT NULL,snapshot_hash CHAR(64) NOT NULL,accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,document_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $q=$pdo->query("SELECT setting_value FROM crm_settings WHERE setting_key='onboarding_v28_initialized'");if($q->fetchColumn())return;
    $key='melas-onboarding-v28:'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,30);
    $q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$key]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Onboarding migration busy');
    try{
        $pdo->beginTransaction();
        $q=$pdo->query("SELECT setting_value FROM crm_settings WHERE setting_key='onboarding_v28_initialized' FOR UPDATE");
        if(!$q->fetchColumn()){
            $pdo->exec("INSERT IGNORE INTO personnel_access_rules(user_id,policy) SELECT id,'legacy' FROM users");
            $pdo->exec("INSERT INTO crm_settings(setting_key,setting_value) VALUES('onboarding_v28_initialized',DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'))");
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$key]);}
}
function onboarding_required(string $id):bool {
    $rule=melas_onboarding_rule(db(),$id);return !$rule||$rule['policy']!=='legacy';
}
function onboarding_new_user(string $id,array $actor):void {
    // Called in the same transaction as account insertion; never sets active=0.
    db()->prepare("INSERT INTO personnel_access_rules(user_id,policy) VALUES(?,'required')")->execute([$id]);
    db()->prepare("INSERT INTO personnel_onboarding(user_id,relationship) VALUES(?,'contractor')")->execute([$id]);
    personnel_audit($actor,$id,null,'onboarding_required','Νέος λογαριασμός: Academy πρώτα, μετά έγγραφα και υπογραφές, τέλος έγκριση για πραγματικό CRM.');
}
/** Read training evidence without loading Academy sessions or changing either database. */
function onboarding_training_status(string $id):array {
    if(!onboarding_required($id))return ['ready'=>true,'available'=>true];
    try{
        require_once __DIR__.'/partner-development.php';
        $evidence=sales_academy_evidence($id);
        return ['ready'=>!empty($evidence['approved']),'available'=>true];
    }catch(Throwable $e){
        error_log('Onboarding training evidence unavailable: '.get_class($e));
        return ['ready'=>false,'available'=>false];
    }
}
function onboarding_assert_documents_stage(string $id,string $kind=''):void {
    // Existing exceptions and departure paperwork retain their previous behaviour.
    if($kind==='offboarding'||!onboarding_required($id))return;
    // Training may gate production CRM access, never the employee's employment paperwork.
    $q=db()->prepare('SELECT relationship FROM personnel_onboarding WHERE user_id=?');$q->execute([$id]);
    if($q->fetchColumn()==='employee')return;
    $status=onboarding_training_status($id);
    if(!$status['available'])throw new InvalidArgumentException('Δεν μπορεί να ελεγχθεί προσωρινά η ολοκλήρωση της Academy. Ενημερώστε τον διαχειριστή· η υπογραφή δεν ξεκλειδώθηκε.');
    if(!$status['ready'])throw new InvalidArgumentException('Προηγούνται τα μαθήματα, η τελική εξέταση, η εικονική πρακτική και η εκπαιδευτική αξιολόγηση στην Academy. Μετά ξεκλειδώνουν τα έγγραφα και οι υπογραφές.');
}
function onboarding_documents_stage_ready(string $id,string $kind=''):bool {
    try {onboarding_assert_documents_stage($id,$kind);return true;}catch(InvalidArgumentException){return false;}
}
function onboarding_preapprove(array $actor,string $id,array $input):void {
    if(!onboarding_required($id))throw new InvalidArgumentException('Η υπάρχουσα εξαίρεση πρόσβασης διατηρείται.');
    if(!melas_onboarding_rule(db(),$id)||!personnel_record($id))throw new InvalidArgumentException('Ορίστε πρώτα τη σχέση συνεργασίας από την ένταξη στη ροή εγγράφων.');
    $checks=['application','screening','interview','research','roleplay','commission_terms'];
    foreach($checks as $key)if(($input[$key]??'')!=='1')throw new InvalidArgumentException('Επιβεβαιώστε αίτηση, screening, συνέντευξη, research test, αρχικό role-play και ενημέρωση αμοιβών.');
    $note=trim((string)($input['evidence']??''));if(mb_strlen($note)<20||mb_strlen($note)>3000)throw new InvalidArgumentException('Καταγράψτε τεκμηρίωση 20–3000 χαρακτήρων.');
    db()->prepare('UPDATE personnel_access_rules SET preapproved_at=NOW(),preapproved_by=?,checklist_json=? WHERE user_id=? AND policy=\'required\'')->execute([$actor['id'],json_encode(['checks'=>$checks,'evidence'=>$note],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
    personnel_audit($actor,$id,null,'training_preapproved',$note);
}
function onboarding_session_user():?array {
    start_crm_session();$id=$_SESSION['onboarding_user_id']??null;if(!is_string($id)||$id==='')return null;
    if((int)($_SESSION['onboarding_until']??0)<time()){unset($_SESSION['onboarding_user_id']);return null;}
    $q=db()->prepare('SELECT id,name,email,role,active FROM users WHERE id=?');$q->execute([$id]);$user=$q->fetch();
    if(!$user||!$user['active']||(int)($_SESSION['onboarding_generation']??-1)!==account_session_generation($id)){unset($_SESSION['onboarding_user_id']);return null;}
    return $user;
}
function onboarding_begin_session(array $account):void {
    session_regenerate_id(true);$_SESSION=[];
    $_SESSION['onboarding_user_id']=$account['id'];$_SESSION['onboarding_generation']=(int)$account['generation'];
    $_SESSION['onboarding_until']=time()+7200;$_SESSION['csrf']=bin2hex(random_bytes(32));
}
function onboarding_own_document(array $actor,string $id,bool $lock=false):array {
    $doc=personnel_document($id,$lock);
    if($doc['user_id']!==$actor['id'])throw new InvalidArgumentException('Το έγγραφο δεν είναι διαθέσιμο.');
    return $doc;
}
function onboarding_accept_policy(array $actor,array $doc,string $snapshot):void {
    if($doc['user_id']!==$actor['id']||!in_array($doc['kind'],['commission_policy','crm_rules'],true)||!personnel_document_current($doc)||!hash_equals(hash('sha256',$doc['snapshot']),$snapshot))throw new InvalidArgumentException('Η έκδοση άλλαξε. Διαβάστε ξανά το τρέχον έγγραφο.');
    onboarding_assert_documents_stage($actor['id'],$doc['kind']);
    db()->prepare('INSERT IGNORE INTO personnel_policy_receipts(user_id,document_id,snapshot_hash) VALUES(?,?,?)')->execute([$actor['id'],$doc['id'],$snapshot]);
    personnel_audit($actor,$actor['id'],$doc['id'],'policy_accepted',$snapshot);
}
function onboarding_label(array $user):string {
    if(empty($user['active']))return 'Ανενεργός';
    if(!onboarding_required($user['id']))return 'Υπάρχων χρήστης · πρόσβαση διατηρείται';
    if(personnel_access_allowed($user))return 'Εγκεκριμένος · CRM';
    return melas_onboarding_documents_ready(db(),$user['id'])?'Αναμονή τελικής έγκρισης CRM':'Academy / ένταξη · χωρίς πρόσβαση CRM';
}
