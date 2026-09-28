<?php
declare(strict_types=1);
if (!defined('CRM_ROOT')) { http_response_code(403); exit; }
require_once __DIR__.'/partner-development.php';
require_once __DIR__.'/recurring-services.php';
require_once __DIR__.'/recruitment.php';
require_once __DIR__.'/portal-reporting.php';
require_once __DIR__.'/operations-v36.php';
require_once __DIR__.'/portal-pagination.php';

function crm_sales_restricted(array $user): bool
{
    return in_array($user['role'] ?? '', ['employee','partner'], true);
}

function ensure_sales_portal_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    foreach ([
        "CREATE TABLE IF NOT EXISTS sales_teams(id CHAR(36) PRIMARY KEY,name VARCHAR(150) NOT NULL,
            leader_id CHAR(36) NOT NULL,active TINYINT NOT NULL DEFAULT 1,created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6))",
        "CREATE TABLE IF NOT EXISTS sales_team_memberships(id CHAR(36) PRIMARY KEY,team_id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,starts_at DATETIME(6) NOT NULL,ends_at DATETIME(6) NULL,
            assigned_by CHAR(36) NOT NULL,reason TEXT NOT NULL,INDEX membership_user(user_id,starts_at,ends_at))",
        "CREATE TABLE IF NOT EXISTS sales_partner_profiles(user_id CHAR(36) PRIMARY KEY,
            stage VARCHAR(40) NOT NULL DEFAULT 'active',notes TEXT NULL,review_at DATE NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS sales_portal_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_id CHAR(36) NOT NULL,subject_type VARCHAR(40) NOT NULL,subject_id VARCHAR(64) NOT NULL,
            action VARCHAR(50) NOT NULL,details_json LONGTEXT NOT NULL,created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6))",
        "CREATE TABLE IF NOT EXISTS sales_lead_pipeline(lead_id CHAR(36) PRIMARY KEY,
            stage VARCHAR(30) NOT NULL DEFAULT 'contacted',temperature VARCHAR(10) NOT NULL DEFAULT 'warm',
            priority VARCHAR(10) NOT NULL DEFAULT 'normal',last_contact_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS sales_account_owners(company_id BIGINT UNSIGNED PRIMARY KEY,
            owner_id CHAR(36) NOT NULL,assigned_by CHAR(36) NOT NULL,reason TEXT NOT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS sales_requests(id CHAR(36) PRIMARY KEY,requester_id CHAR(36) NOT NULL,
            kind VARCHAR(30) NOT NULL,lead_id CHAR(36) NULL,subject VARCHAR(200) NOT NULL,body TEXT NOT NULL,
            priority VARCHAR(10) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'open',response TEXT NULL,
            resolved_by CHAR(36) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS sales_team_overrides(id CHAR(36) PRIMARY KEY,source_commission_id CHAR(36) NOT NULL UNIQUE,
            lead_id CHAR(36) NOT NULL,team_id CHAR(36) NOT NULL,membership_id CHAR(36) NOT NULL,
            beneficiary_user_id CHAR(36) NOT NULL,source_amount DECIMAL(14,2) NOT NULL,rate SMALLINT NOT NULL DEFAULT 10,
            amount DECIMAL(14,2) NOT NULL,status VARCHAR(30) NOT NULL,earned_at DATETIME NOT NULL,
            paid_at DATETIME NULL,payout_date DATE NULL,payout_reference VARCHAR(255) NULL)",
        "CREATE TABLE IF NOT EXISTS sales_toolkit_versions(id CHAR(36) PRIMARY KEY,category VARCHAR(30) NOT NULL,
            title VARCHAR(180) NOT NULL,version_label VARCHAR(50) NOT NULL,body MEDIUMTEXT NOT NULL,
            minimum_stage VARCHAR(30) NOT NULL DEFAULT 'approved',published_by CHAR(36) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS sales_policy_receipts(user_id CHAR(36) NOT NULL,version_id CHAR(36) NOT NULL,
            acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,version_id))"
    ] as $sql) $pdo->exec($sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    // No role/password change and no onboarding/signature lock on existing accounts.
    personnel_schema();
    $pdo->exec("INSERT IGNORE INTO sales_partner_profiles(user_id,stage) SELECT u.id,CASE WHEN r.policy='legacy' THEN 'active' ELSE 'candidate' END FROM users u LEFT JOIN personnel_access_rules r ON r.user_id=u.id");
    $pdo->exec("INSERT IGNORE INTO crm_settings(setting_key,setting_value) VALUES('team_override_started_at',DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'))");
    sales_recurring_schema($pdo);
    sales_recruitment_schema($pdo);
    sales_operations_schema($pdo);
    $ready = true;
}

function sales_portal_event(array $actor,string $type,string $id,string $action,array $details): void
{
    db()->prepare('INSERT INTO sales_portal_events(actor_id,subject_type,subject_id,action,details_json) VALUES(?,?,?,?,?)')
        ->execute([$actor['id'],$type,$id,$action,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function sales_require_manager(array $actor): void
{
    if (!can_view_all_sales_financials($actor)) throw new RuntimeException('Η ενέργεια απαιτεί εξουσιοδοτημένο διαχειριστή.');
}
function sales_text(array $input,string $key,int $min=0,int $max=3000): string
{
    $value=$input[$key]??'';
    if (!is_string($value)||mb_strlen(trim($value))<$min||mb_strlen($value)>$max) throw new InvalidArgumentException('Ελέγξτε το πεδίο '.$key.'.');
    return trim($value);
}
function sales_member_ids(array $actor): array
{
    $q=db()->prepare('SELECT DISTINCT m.user_id FROM sales_team_memberships m JOIN sales_teams t ON t.id=m.team_id WHERE t.leader_id=? AND t.active=1 AND m.ends_at IS NULL');
    $q->execute([$actor['id']]);return $q->fetchAll(PDO::FETCH_COLUMN);
}
function sales_team_can_view(array $actor,array $lead): bool
{
    return !empty($actor['active']) && in_array($lead['submitted_by'],sales_member_ids($actor),true);
}
function sales_create_team(array $actor,array $input): string
{
    sales_require_manager($actor);$name=sales_text($input,'name',3,150);$leader=sales_text($input,'leader_id',1,36);
    $q=db()->prepare('SELECT id FROM users WHERE id=? AND active=1');$q->execute([$leader]);
    if (!$q->fetchColumn()) throw new InvalidArgumentException('Επίλεξε ενεργό Team Leader.');
    $id=uuid_v4();$pdo=db();$pdo->beginTransaction();
    try {$pdo->prepare('INSERT INTO sales_teams(id,name,leader_id) VALUES(?,?,?)')->execute([$id,$name,$leader]);
        sales_portal_event($actor,'team',$id,'created',['name'=>$name,'leader_id'=>$leader]);$pdo->commit();return $id;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sales_assign_team(array $actor,array $input): void
{
    sales_require_manager($actor);$member=sales_text($input,'user_id',1,36);$team=sales_text($input,'team_id',0,36);$reason=sales_text($input,'reason',10,3000);
    $pdo=db();$pdo->beginTransaction();
    try {
        // User-row lock serialises transfers against commission creation for this seller.
        $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND active=1 FOR UPDATE');$q->execute([$member]);
        if (!$q->fetchColumn()) throw new InvalidArgumentException('Ο συνεργάτης δεν είναι ενεργός.');
        if($team!==''){$q=$pdo->prepare('SELECT id FROM sales_teams WHERE id=? AND active=1 FOR UPDATE');$q->execute([$team]);if(!$q->fetchColumn())throw new InvalidArgumentException('Η ομάδα δεν είναι ενεργή.');}
        $now=$pdo->query('SELECT NOW(6)')->fetchColumn();
        $pdo->prepare('UPDATE sales_team_memberships SET ends_at=? WHERE user_id=? AND ends_at IS NULL')->execute([$now,$member]);
        if($team!=='')$pdo->prepare('INSERT INTO sales_team_memberships(id,team_id,user_id,starts_at,assigned_by,reason) VALUES(?,?,?,?,?,?)')->execute([uuid_v4(),$team,$member,$now,$actor['id'],$reason]);
        sales_portal_event($actor,'user',$member,'team_changed',['team_id'=>$team,'effective_at'=>$now,'reason'=>$reason]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function sales_close_team(array $actor,array $input): void
{
    sales_require_manager($actor);$id=sales_text($input,'team_id',1,36);$reason=sales_text($input,'reason',20,3000);
    $pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM sales_teams WHERE id=? FOR UPDATE');$q->execute([$id]);$team=$q->fetch();
        if(!$team||!$team['active'])throw new InvalidArgumentException('Η ομάδα δεν είναι ενεργή.');
        $pdo->prepare('UPDATE sales_teams SET active=0 WHERE id=?')->execute([$id]);
        $pdo->prepare('UPDATE sales_team_memberships SET ends_at=NOW(6) WHERE team_id=? AND ends_at IS NULL')->execute([$id]);
        sales_portal_event($actor,'team',$id,'closed',['reason'=>$reason,'earned_overrides_unchanged'=>true]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Called inside the source transaction, never retrospectively for old commissions. */
function sales_create_override(string $commissionId): void
{
    $pdo=db();if(!$pdo->inTransaction())throw new LogicException('Override requires source transaction');
    $q=$pdo->prepare('SELECT * FROM lead_commissions WHERE id=? FOR UPDATE');$q->execute([$commissionId]);$source=$q->fetch();
    if(!$source||!in_array($source['status'],['earned','payable'],true))return;
    $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$source['beneficiary_user_id']]);$q->fetchColumn();
    $q=$pdo->prepare('SELECT m.id membership_id,t.id team_id,t.leader_id FROM sales_team_memberships m JOIN sales_teams t ON t.id=m.team_id JOIN users u ON u.id=t.leader_id WHERE m.user_id=? AND m.ends_at IS NULL AND t.active=1 AND u.active=1 FOR UPDATE');
    $q->execute([$source['beneficiary_user_id']]);$team=$q->fetch();
    if(!$team||$team['leader_id']===$source['beneficiary_user_id'])return;
    $cents=compensation_percent(compensation_cents((string)$source['commission_amount']),10);
    $pdo->prepare('INSERT INTO sales_team_overrides(id,source_commission_id,lead_id,team_id,membership_id,beneficiary_user_id,source_amount,amount,status,earned_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([uuid_v4(),$commissionId,$source['lead_id'],$team['team_id'],$team['membership_id'],$team['leader_id'],$source['commission_amount'],compensation_money($cents),$source['status'],$source['earned_at']]);
}
function sales_pay_override(array $actor,array $input): void
{
    sales_require_manager($actor);$id=sales_text($input,'override_id',1,36);$date=sales_text($input,'payout_date',10,10);$ref=sales_text($input,'payout_reference',1,255);
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date||$date>date('Y-m-d'))throw new InvalidArgumentException('Μη έγκυρη ημερομηνία πληρωμής.');
    $pdo=db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT o.*,c.status source_status FROM sales_team_overrides o JOIN lead_commissions c ON c.id=o.source_commission_id WHERE o.id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();
        if(!$row||$row['status']==='paid'||!in_array($row['source_status'],['payable','paid'],true))throw new RuntimeException('Η πρόσθετη αμοιβή δεν είναι διαθέσιμη για πληρωμή.');
        if($row['beneficiary_user_id']===$actor['id'])throw new RuntimeException('Η προσωπική αμοιβή πληρώνεται από τον άλλο εξουσιοδοτημένο διαχειριστή.');
        $pdo->prepare("UPDATE sales_team_overrides SET status='paid',paid_at=NOW(),payout_date=?,payout_reference=? WHERE id=?")->execute([$date,$ref,$id]);
        sales_portal_event($actor,'override',$id,'paid',['amount'=>$row['amount'],'reference'=>$ref,'date'=>$date]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sales_pipeline_stages(): array
{
    return ['prospect'=>'Αρχική έρευνα','contacted'=>'Πρώτη επικοινωνία','discovery'=>'Διερεύνηση ανάγκης',
        'site_visit'=>'Στοιχεία / επίσκεψη','offer'=>'Προσφορά','negotiation'=>'Διαπραγμάτευση','won'=>'Συμφωνία','lost'=>'Απώλεια','later'=>'Follow-up αργότερα'];
}
function sales_update_pipeline(array $actor,array $input): void
{
    $id=sales_text($input,'lead_id',1,36);$lead=load_lead($id);
    if(!$lead||(!lead_is_owner($actor,$lead)&&!can_view_all_sales_financials($actor)))throw new RuntimeException('Δεν έχετε πρόσβαση σε αυτή την ευκαιρία.');
    $stage=sales_text($input,'stage',1,30);$temperature=sales_text($input,'temperature',1,10);$priority=sales_text($input,'priority',1,10);
    if(!isset(sales_pipeline_stages()[$stage])||!in_array($temperature,['hot','warm','cold'],true)||!in_array($priority,['low','normal','high'],true))throw new InvalidArgumentException('Μη έγκυρη επιλογή pipeline.');
    $next=sales_text($input,'next_step',3,500);$follow=sales_text($input,'follow_up_at',16,16);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$follow);if(!$date||$date->format('Y-m-d\TH:i')!==$follow)throw new InvalidArgumentException('Χρειάζεται ακριβής ημερομηνία follow-up.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $pdo->prepare('INSERT INTO sales_lead_pipeline(lead_id,stage,temperature,priority,last_contact_at) VALUES(?,?,?,?,IF(?=1,NOW(),NULL)) ON DUPLICATE KEY UPDATE stage=VALUES(stage),temperature=VALUES(temperature),priority=VALUES(priority),last_contact_at=COALESCE(VALUES(last_contact_at),last_contact_at)')->execute([$id,$stage,$temperature,$priority,!empty($input['contact_completed'])?1:0]);
        $pdo->prepare("UPDATE sales_leads SET next_step='Άλλο',next_step_other=?,follow_up_at=?,follow_up_note=? WHERE id=?")->execute([$next,$date->format('Y-m-d H:i:s'),$next,$id]);
        sales_portal_event($actor,'lead',$id,'pipeline_updated',['stage'=>$stage,'temperature'=>$temperature,'priority'=>$priority,'next_step'=>$next,'follow_up_at'=>$follow]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sales_request_kinds(): array {return ['support'=>'Υποστήριξη','technical'=>'Τεχνική αξιολόγηση','commercial'=>'Τιμή / έκπτωση / ειδικοί όροι','ownership'=>'Διαφωνία κατοχύρωσης','inactivity'=>'Επανεξέταση αδράνειας','coaching'=>'Εκπαίδευση / coaching'];}
function sales_create_request(array $actor,array $input): string
{
    $kind=sales_text($input,'kind',1,30);$leadId=sales_text($input,'lead_id',0,36);$priority=sales_text($input,'priority',1,10);
    if(!isset(sales_request_kinds()[$kind])||!in_array($priority,['low','normal','high'],true))throw new InvalidArgumentException('Μη έγκυρη κατηγορία ή προτεραιότητα.');
    if($leadId!==''){$lead=load_lead($leadId);if(!$lead||(!lead_is_owner($actor,$lead)&&!can_view_all_sales_financials($actor)))throw new RuntimeException('Δεν έχετε πρόσβαση σε αυτό το lead.');}
    $subject=sales_text($input,'subject',5,200);$body=sales_text($input,'body',20,6000);$id=uuid_v4();
    db()->prepare('INSERT INTO sales_requests(id,requester_id,kind,lead_id,subject,body,priority) VALUES(?,?,?,?,?,?,?)')->execute([$id,$actor['id'],$kind,$leadId?:null,$subject,$body,$priority]);return $id;
}
function sales_resolve_request(array $actor,array $input): void
{
    sales_require_manager($actor);$id=sales_text($input,'request_id',1,36);$status=sales_text($input,'status',1,30);$response=sales_text($input,'response',10,6000);
    if(!in_array($status,['in_progress','approved','declined','resolved'],true))throw new InvalidArgumentException('Μη έγκυρη κατάσταση.');
    $pdo=db();$pdo->beginTransaction();
    try{$q=$pdo->prepare('SELECT * FROM sales_requests WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();if(!$old)throw new InvalidArgumentException('Το αίτημα δεν βρέθηκε.');
        if($old['requester_id']===$actor['id']&&$status==='approved')throw new RuntimeException('Το αίτημα εγκρίνεται από διαφορετικό διαχειριστή.');
        $pdo->prepare('UPDATE sales_requests SET status=?,response=?,resolved_by=? WHERE id=?')->execute([$status,$response,$actor['id'],$id]);
        sales_portal_event($actor,'request',$id,'response',['previous_status'=>$old['status'],'previous_response'=>$old['response'],'status'=>$status,'response'=>$response]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
