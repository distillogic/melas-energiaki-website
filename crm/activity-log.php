<?php
declare(strict_types=1);

function ensure_activity_schema(): void {
    static $ready=false;if($ready)return;
    db()->exec("CREATE TABLE IF NOT EXISTS crm_activity_sessions (
        id CHAR(36) PRIMARY KEY, actor_id CHAR(36) NOT NULL, actor_email VARCHAR(320) NOT NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ended_at DATETIME NULL, end_reason VARCHAR(20) NULL,
        INDEX session_actor(actor_id,started_at), INDEX session_time(started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_business_activity (
        id CHAR(36) PRIMARY KEY, actor_id CHAR(36) NOT NULL, actor_email VARCHAR(320) NOT NULL,
        description TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX business_actor(actor_id,created_at), INDEX business_time(created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready=true;
}

// Last-seen estimate only, not working time. Never store navigation paths.
function activity_presence(array $actor,?string $event):void {
    start_crm_session();$sid=$_SESSION['activity_period']??null;$existing=null;
    if(is_string($sid)){
        $q=db()->prepare('SELECT *,last_seen_at < DATE_SUB(NOW(),INTERVAL 30 MINUTE) AS stale FROM crm_activity_sessions WHERE id=? AND actor_id=?');
        $q->execute([$sid,$actor['id']]);$existing=$q->fetch();
    }
    if($existing&&!$existing['ended_at']&&($existing['stale']||$event==='login')){
        db()->prepare("UPDATE crm_activity_sessions SET ended_at=last_seen_at,end_reason='last_seen' WHERE id=? AND ended_at IS NULL")->execute([$sid]);$existing=null;
    }
    if($event==='logout'){
        if($existing&&!$existing['ended_at'])db()->prepare("UPDATE crm_activity_sessions SET ended_at=NOW(),last_seen_at=NOW(),end_reason='logout' WHERE id=? AND ended_at IS NULL")->execute([$sid]);
        unset($_SESSION['activity_period']);return;
    }
    if(!$existing||$existing['ended_at']){
        $sid=uuid_v4();db()->prepare('INSERT INTO crm_activity_sessions(id,actor_id,actor_email) VALUES(?,?,?)')->execute([$sid,$actor['id'],$actor['email']]);$_SESSION['activity_period']=$sid;
    }else db()->prepare('UPDATE crm_activity_sessions SET last_seen_at=NOW() WHERE id=? AND ended_at IS NULL')->execute([$sid]);
}

function activity_row(string $sql,$id):?array {
    if(!is_scalar($id)||trim((string)$id)==='')return null;
    $q=db()->prepare($sql);$q->execute([(string)$id]);return $q->fetch()?:null;
}

// Resolve saved names, not posted labels. Keep snapshots when entities are renamed.
function activity_description(string $route,string $action):?string {
    $id=$GLOBALS['id']??$_GET['id']??null;
    $verb=['archive'=>'Αρχειοθέτηση','delete'=>'Διαγραφή','delete_company'=>'Αρχειοθέτηση','deactivate'=>'Απενεργοποίηση','reactivate'=>'Επανενεργοποίηση','restore'=>'Επαναφορά'];
    if($route==='accounts.php'){
        $a=$GLOBALS['activity_account_change']??null;if(!$a)return null;
        $r=activity_row('SELECT name,email FROM users WHERE id=?',$a['id']);
        return ($a['new']?'Δημιουργία':($verb[$action]??'Ενημέρωση')).' χρήστη: '.($r?($r['name'].' — '.$r['email']):$a['id']);
    }
    if(in_array($route,['new-communication.php','communication.php','communications.php'],true)){
        $r=activity_row('SELECT c.name FROM communications m JOIN companies c ON c.id=m.company_id WHERE m.id=?',$GLOBALS['recordId']??$id);
        return ($route==='new-communication.php'?'Καταχώριση επικοινωνίας':(($verb[$action]??'Ενημέρωση').' επικοινωνίας')).($r?' με την εταιρεία '.$r['name']:'');
    }
    if(in_array($route,['customers.php','customer.php','new-customer.php'],true)&&$action!=='generate_nda'){
        $r=activity_row('SELECT name FROM companies WHERE id=?',$GLOBALS['companyId']??$id??$_POST['company_id']??null);
        return ($route==='new-customer.php'?'Δημιουργία':($verb[$action]??'Ενημέρωση')).' εταιρείας'.($r?': '.$r['name']:'');
    }
    if($route==='profile.php'){
        $r=activity_row('SELECT p.profile_id,c.name FROM engineering_profiles p LEFT JOIN companies c ON c.id=p.company_id WHERE p.id=?',$id);
        return 'Αποθήκευση Engineering Profile'.($r?' '.$r['profile_id'].($r['name']?' — εταιρεία '.$r['name']:''):'');
    }
    if(in_array($route,['sow.php','msa.php','dpa.php','proposal.php','nda-signing.php','document-finalize.php','client-packs.php'],true)||($route==='customer.php'&&$action==='generate_nda')){
        $r=activity_row('SELECT d.document_reference,d.document_type,c.name FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=?',$GLOBALS['documentId']??$id);
        if(!$r)return 'Αποθήκευση εταιρικού εγγράφου';
        $names=['generate_nda'=>'Δημιουργία','upload_final_pdf'=>'Καταχώριση τελικού PDF','send_final'=>'Αποστολή','verify_approval'=>'Έγκριση CEO','upload_client_signed'=>'Καταχώριση υπογραφής πελάτη','request_approval'=>'Αίτημα έγκρισης CEO','mark_accepted'=>'Αποδοχή','mark_rejected'=>'Απόρριψη','mark_expired'=>'Λήξη','mark_active'=>'Ενεργοποίηση','mark_terminated'=>'Τερματισμός'];
        $op=$route==='document-finalize.php'?'Οριστικοποίηση υπογεγραμμένου εγγράφου':($names[$action]??(empty($GLOBALS['activity_existing_id'])?'Δημιουργία':'Ενημέρωση'));
        return $op.' '.strtoupper($r['document_type']).' '.$r['document_reference'].' — εταιρεία '.$r['name'];
    }
    return $GLOBALS['activity_success_message']??null;
}

function activity_start(array $actor,?string $event=null):void {
    if(isset($GLOBALS['activity_registered']))return;$GLOBALS['activity_registered']=true;
    try{ensure_activity_schema();activity_presence($actor,$event);}catch(Throwable $error){error_log('CRM activity logging unavailable');}
    // No page-view, download, raw form or failed-validation events.
    if($event!==null||($_SERVER['REQUEST_METHOD']??'GET')!=='POST')return;
    $route=basename((string)($_SERVER['SCRIPT_NAME']??''));
    $raw=$_POST['action']??'save';$action=is_string($raw)?$raw:'save';
    $GLOBALS['activity_existing_id']=$_GET['id']??$_POST['id']??null;
    register_shutdown_function(static function()use($actor,$route,$action):void{
        try{
            $error=error_get_last();
            if($error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))return;
            if((http_response_code()?:200)>=400||($GLOBALS['crm_activity_result']??'')!=='app_success'||db()->inTransaction())return;
            $description=activity_description($route,$action);if(!$description)return;
            db()->prepare('INSERT INTO crm_business_activity(id,actor_id,actor_email,description) VALUES(?,?,?,?)')->execute([uuid_v4(),$actor['id'],$actor['email'],mb_substr($description,0,1500)]);
        }catch(Throwable $error){error_log('CRM business activity logging unavailable');}
    });
}
