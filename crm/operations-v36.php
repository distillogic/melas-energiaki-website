<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
function sales_operations_schema(PDO $pdo):void {
    static $ready=false;if($ready)return;
    foreach([
        "CREATE TABLE IF NOT EXISTS sales_notification_state(user_id CHAR(36) NOT NULL,event_key CHAR(64) NOT NULL,read_at DATETIME NOT NULL,PRIMARY KEY(user_id,event_key))",
        "CREATE TABLE IF NOT EXISTS sales_notification_preferences(user_id CHAR(36) PRIMARY KEY,email_enabled TINYINT NOT NULL DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS sales_internal_mail(id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,run_day DATE NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'queued',attempts INT NOT NULL DEFAULT 0,sent_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE mail_day(user_id,run_day))",
        "CREATE TABLE IF NOT EXISTS sales_staff_capabilities(user_id CHAR(36) NOT NULL,capability VARCHAR(30) NOT NULL,granted_by CHAR(36) NOT NULL,granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,capability))",
        "CREATE TABLE IF NOT EXISTS sales_activity_records(id CHAR(36) PRIMARY KEY,actor_id CHAR(36) NOT NULL,lead_id CHAR(36) NULL,event_kind VARCHAR(30) NOT NULL,happened_on DATE NOT NULL,note VARCHAR(1000) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX actor_day(actor_id,happened_on))",
        "CREATE TABLE IF NOT EXISTS sales_activity_targets(id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,period_month CHAR(7) NOT NULL,event_kind VARCHAR(30) NOT NULL,target_count INT NOT NULL,assigned_by CHAR(36) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE target_period(user_id,period_month,event_kind))"
    ] as $sql)$pdo->exec($sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $ready=true;
}
function sales_notification_feed(array $actor):array {
    $items=sales_notifications($actor,true);$q=db()->prepare('SELECT event_key FROM sales_notification_state WHERE user_id=?');$q->execute([$actor['id']]);$read=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
    foreach($items as &$item){$item['key']=hash('sha256',$item['event'].'|'.$item['label']);$item['read']=isset($read[$item['key']]);}unset($item);return $items;
}
function sales_notification_read(array $actor,string $key):void {
    foreach(sales_notification_feed($actor) as $item)if(hash_equals($item['key'],$key)){
        db()->prepare('INSERT IGNORE INTO sales_notification_state(user_id,event_key,read_at) VALUES(?,?,NOW())')->execute([$actor['id'],$key]);return;
    }
    throw new InvalidArgumentException('Η ειδοποίηση δεν είναι δική σας ή δεν είναι πλέον διαθέσιμη.');
}
function sales_staff_can(array $actor,string $capability):bool {
    if(empty($actor['active']))return false;
    if(!in_array($capability,['sales_manager','trainer','examiner'],true))return false;
    if(can_view_all_sales_financials($actor))return true;
    $q=db()->prepare('SELECT 1 FROM sales_staff_capabilities WHERE user_id=? AND capability=?');$q->execute([$actor['id'],$capability]);return (bool)$q->fetchColumn();
}
function sales_staff_grant(array $actor,array $input):void {
    sales_require_manager($actor);personnel_reauth($actor,$input);$id=sales_text($input,'user_id',1,36);$cap=sales_text($input,'capability',1,30);
    if(!in_array($cap,['sales_manager','trainer','examiner'],true))throw new InvalidArgumentException('Μη έγκυρη αρμοδιότητα.');
    $q=db()->prepare('SELECT id FROM users WHERE id=? AND active=1');$q->execute([$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Απαιτείται ενεργός λογαριασμός.');
    $pdo=db();$pdo->beginTransaction();try{
        if(($input['enabled']??'')==='1')$pdo->prepare('INSERT INTO sales_staff_capabilities(user_id,capability,granted_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE granted_by=VALUES(granted_by),granted_at=NOW()')->execute([$id,$cap,$actor['id']]);
        else $pdo->prepare('DELETE FROM sales_staff_capabilities WHERE user_id=? AND capability=?')->execute([$id,$cap]);
        sales_portal_event($actor,'capability',$id,'changed',['capability'=>$cap,'enabled'=>($input['enabled']??'')==='1','financial_access_changed'=>false]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sales_activity_kinds():array {return ['call'=>'Πραγματική κλήση','decision_maker'=>'Συνομιλία με αρμόδιο απόφασης','meeting'=>'Πραγματοποιημένη συνάντηση','offer'=>'Αποστολή πραγματικής προσφοράς'];}
function sales_activity_record(array $actor,array $input):void {
    $kind=sales_text($input,'event_kind',1,30);$day=sales_text($input,'happened_on',10,10);$note=sales_text($input,'note',10,1000);
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$day);if(!isset(sales_activity_kinds()[$kind])||!$d||$d->format('Y-m-d')!==$day||$day>date('Y-m-d'))throw new InvalidArgumentException('Ελέγξτε είδος και ημερομηνία πραγματικής ενέργειας.');
    $id=sales_text($input,'submission_id',36,36);
    if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$id))throw new InvalidArgumentException('Ανανεώστε τη φόρμα ενέργειας.');
    // Stable request ID makes a double click/retry harmless without suppressing distinct real calls.
    try{db()->prepare('INSERT INTO sales_activity_records(id,actor_id,event_kind,happened_on,note) VALUES(?,?,?,?,?)')->execute([$id,$actor['id'],$kind,$day,$note]);}
    catch(PDOException $e){
        if(($e->errorInfo[1]??0)!==1062)throw $e;
        $q=db()->prepare('SELECT actor_id,event_kind,happened_on,note FROM sales_activity_records WHERE id=?');$q->execute([$id]);$old=$q->fetch();
        if(!$old||$old['actor_id']!==$actor['id']||$old['event_kind']!==$kind||$old['happened_on']!==$day||$old['note']!==$note)throw new InvalidArgumentException('Αυτό το αίτημα χρησιμοποιήθηκε ήδη. Ανοίξτε νέα φόρμα.');
    }
}
function sales_target_save(array $actor,array $input):void {
    if(!sales_staff_can($actor,'sales_manager'))throw new InvalidArgumentException('Δεν έχετε αρμοδιότητα στόχων.');
    $id=sales_text($input,'user_id',1,36);$month=sales_text($input,'period_month',7,7);$kind=sales_text($input,'event_kind',1,30);$count=filter_var($input['target_count']??'',FILTER_VALIDATE_INT);
    $d=DateTimeImmutable::createFromFormat('!Y-m',$month);if(!$d||$d->format('Y-m')!==$month||!isset(sales_activity_kinds()[$kind])||$count===false||$count<0||$count>100000)throw new InvalidArgumentException('Ελέγξτε στόχο και μήνα.');
    if(!can_view_all_sales_financials($actor)&&!in_array($id,array_merge([$actor['id']],sales_member_ids($actor)),true))throw new InvalidArgumentException('Ο χρήστης δεν ανήκει στην ομάδα σας.');
    $q=db()->prepare('SELECT id FROM users WHERE id=? AND active=1');$q->execute([$id]);if(!$q->fetch())throw new InvalidArgumentException('Δεν βρέθηκε ενεργός χρήστης.');
    $pdo=db();$pdo->beginTransaction();try{
        $pdo->prepare('INSERT INTO sales_activity_targets(id,user_id,period_month,event_kind,target_count,assigned_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE target_count=VALUES(target_count),assigned_by=VALUES(assigned_by)')->execute([uuid_v4(),$id,$month,$kind,$count,$actor['id']]);
        sales_portal_event($actor,'activity_target',$id,'saved',['month'=>$month,'kind'=>$kind,'target'=>$count]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sales_activity_report(array $actor,string $month):array {
    $d=DateTimeImmutable::createFromFormat('!Y-m',$month);if(!$d||$d->format('Y-m')!==$month)throw new InvalidArgumentException('Μη έγκυρος μήνας.');
    $all=can_view_all_sales_financials($actor);$scope=array_unique(array_merge([$actor['id']],sales_member_ids($actor)));
    $q=db()->prepare('SELECT u.id,u.name FROM users u'.($all?'':' WHERE u.id IN ('.implode(',',array_fill(0,count($scope),'?')).')').' ORDER BY u.name,u.id');$q->execute($all?[]:array_values($scope));$people=$q->fetchAll();$rows=[];
    foreach($people as $person){
        $q=db()->prepare('SELECT event_kind,COUNT(*) FROM sales_activity_records WHERE actor_id=? AND happened_on>=? AND happened_on<? GROUP BY event_kind');$q->execute([$person['id'],$d->format('Y-m-01'),$d->modify('+1 month')->format('Y-m-01')]);$actual=$q->fetchAll(PDO::FETCH_KEY_PAIR);
        $q=db()->prepare('SELECT event_kind,target_count FROM sales_activity_targets WHERE user_id=? AND period_month=?');$q->execute([$person['id'],$month]);$rows[]=$person+['actual'=>$actual,'targets'=>$q->fetchAll(PDO::FETCH_KEY_PAIR)];
    }return $rows;
}
function sales_corporate_email(string $email):bool {return filter_var($email,FILTER_VALIDATE_EMAIL)&&preg_match('/@(distillogic\.gr|melasenergiaki\.gr)$/iD',$email)===1;}
/** CLI only caller. No customer table is used and no customer address is accepted. */
function sales_internal_mail_run(bool $deliver=false):array {
    $pdo=db();$lock=$pdo->query("SELECT GET_LOCK('melas-internal-reminders-v36',0)");if((int)$lock->fetchColumn()!==1)return ['busy'=>true];
    $result=['eligible'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
    try{
        $users=$pdo->query('SELECT u.* FROM users u LEFT JOIN sales_notification_preferences p ON p.user_id=u.id WHERE u.active=1 AND COALESCE(p.email_enabled,1)=1')->fetchAll();
        foreach($users as $user){
            $fresh=$pdo->prepare('SELECT u.* FROM users u LEFT JOIN sales_notification_preferences p ON p.user_id=u.id WHERE u.id=? AND u.active=1 AND COALESCE(p.email_enabled,1)=1');$fresh->execute([$user['id']]);$user=$fresh->fetch();if(!$user)continue;
            if(!sales_corporate_email($user['email'])||!personnel_access_allowed($user))continue;
            $pending=array_filter(sales_notification_feed($user),fn($n)=>!$n['read']);if(!$pending)continue;$result['eligible']++;
            if(!$deliver)continue;
            $id=uuid_v4();$q=$pdo->prepare('INSERT IGNORE INTO sales_internal_mail(id,user_id,run_day) VALUES(?,?,CURRENT_DATE)');$q->execute([$id,$user['id']]);
            if(!$q->rowCount()){$result['skipped']++;continue;}
            $pdo->prepare("UPDATE sales_internal_mail SET status='dispatching',attempts=1 WHERE id=?")->execute([$id]);
            // No personal contact details or financial amounts in email; HTTPS only.
            $url='https://melasenergiaki.gr/crm/operations.php';
            try{$sent=send_crm_email($user['email'],'Melas CRM · Εσωτερικές υπενθυμίσεις','<p>Υπάρχουν μη αναγνωσμένες ενημερώσεις στον προσωπικό σου χώρο.</p><p><a href="'.$url.'">Άνοιγμα CRM</a></p><p>Μπορείς να απενεργοποιήσεις τα email από τις Εσωτερικές εργασίες.</p>');}catch(Throwable){$sent=false;}
            $pdo->prepare('UPDATE sales_internal_mail SET status=?,sent_at=? WHERE id=?')->execute([$sent?'sent':'failed',$sent?date('Y-m-d H:i:s'):null,$id]);$result[$sent?'sent':'failed']++;
        }
    }finally{$pdo->query("SELECT RELEASE_LOCK('melas-internal-reminders-v36')");}return $result;
}
function sales_applicant_retention(bool $apply=false):int {
    $pdo=db();$pdo->beginTransaction();try{
        $q=$pdo->query("SELECT id FROM sales_applicants WHERE status IN ('declined','closed') AND COALESCE(retention_closed_at,updated_at)<DATE_SUB(NOW(),INTERVAL 3 MONTH) FOR UPDATE");$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        if($apply)foreach($ids as $id){
            // Review text may itself contain candidate data; redact it as well.
            $pdo->prepare("UPDATE sales_portal_events SET details_json=? WHERE subject_type='applicant' AND subject_id=?")->execute(['{"redacted":"retention_3_months"}',$id]);
            $pdo->prepare('DELETE FROM sales_applicants WHERE id=?')->execute([$id]);
        }
        $pdo->commit();return count($ids);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
