<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
function sales_recurring_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_recurring_contracts(id CHAR(36) PRIMARY KEY,
        source_component_id CHAR(36) NOT NULL,lead_id CHAR(36) NOT NULL,beneficiary_user_id CHAR(36) NOT NULL,
        service_code CHAR(2) NOT NULL,rate SMALLINT NOT NULL,period_base DECIMAL(14,2) NOT NULL,
        starts_on DATE NOT NULL,ends_on DATE NULL,active TINYINT NOT NULL DEFAULT 1,
        note TEXT NOT NULL,approved_by CHAR(36) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_recurring_periods(id CHAR(36) PRIMARY KEY,contract_id CHAR(36) NOT NULL,
        period_month CHAR(7) NOT NULL,component_id CHAR(36) NOT NULL UNIQUE,
        UNIQUE recurring_month(contract_id,period_month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec('ALTER TABLE sales_recurring_contracts ADD COLUMN IF NOT EXISTS closed_on DATE NULL');
    $pdo->exec('ALTER TABLE sales_recurring_contracts ADD COLUMN IF NOT EXISTS interval_months SMALLINT NOT NULL DEFAULT 1');
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_recurring_agreements(contract_id CHAR(36) PRIMARY KEY,signed_on DATE NOT NULL,reference VARCHAR(200) NOT NULL,document_pdf LONGBLOB NOT NULL,document_hash CHAR(64) NOT NULL,terms_snapshot LONGTEXT NOT NULL,verified_by CHAR(36) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function sales_create_recurring(array $actor,array $input): string
{
    sales_require_manager($actor);$componentId=sales_text($input,'component_id',1,36);$note=sales_text($input,'note',20,3000);
    $start=sales_text($input,'starts_on',10,10);$end=sales_text($input,'ends_on',0,10);
    foreach(array_filter([$start,$end]) as $value){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Μη έγκυρη διάρκεια σύμβασης.');}
    if($end!==''&&$end<$start)throw new InvalidArgumentException('Η λήξη προηγείται της έναρξης.');
    $signed=sales_text($input,'signed_on',10,10);$reference=sales_text($input,'agreement_reference',5,200);
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$signed);
    if(!$d||$d->format('Y-m-d')!==$signed||$signed>$start||$signed>date('Y-m-d')||($input['signed_confirmed']??'')!=='1')throw new InvalidArgumentException('Χρειάζεται χωριστή συμφωνία, υπογεγραμμένη από τον Μελά και αποδεκτή από τον δικαιούχο πριν από τη νέα εργασία.');
    personnel_reauth($actor,$input);$pdf=personnel_pdf_upload('agreement_pdf');
    $base=compensation_cents($input['period_base']??'');if($base<=0)throw new InvalidArgumentException('Χρειάζεται εγκεκριμένη καθαρή επιλέξιμη βάση ανά περίοδο.');
    $interval=filter_var($input['interval_months']??'1',FILTER_VALIDATE_INT);
    if(!in_array($interval,[1,2,3,6,12],true))throw new InvalidArgumentException('Επιλέξτε τη συχνότητα που προβλέπει η υπογεγραμμένη συμφωνία.');
    $pdo=db();$pdo->beginTransaction();
    try{$q=$pdo->prepare('SELECT * FROM lead_service_components WHERE id=? FOR UPDATE');$q->execute([$componentId]);$component=$q->fetch();if(!$component)throw new InvalidArgumentException('Δεν βρέθηκε εγκεκριμένο αντικείμενο.');
        compensation_lock_lead($actor,$component['lead_id']);$id=uuid_v4();
        $pdo->prepare('INSERT INTO sales_recurring_contracts(id,source_component_id,lead_id,beneficiary_user_id,service_code,rate,period_base,starts_on,ends_on,note,approved_by,interval_months) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$componentId,$component['lead_id'],$component['beneficiary_user_id'],$component['service_code'],$component['approved_rate'],compensation_money($base),$start,$end?:null,$note,$actor['id'],$interval]);
        $snapshot=json_encode(['lead_id'=>$component['lead_id'],'beneficiary'=>$component['beneficiary_user_id'],'service'=>$component['service_code'],'rate'=>$component['approved_rate'],'base'=>compensation_money($base),'interval_months'=>$interval,'starts_on'=>$start,'ends_on'=>$end,'note'=>$note],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO sales_recurring_agreements(contract_id,signed_on,reference,document_pdf,document_hash,terms_snapshot,verified_by) VALUES(?,?,?,?,?,?,?)')->execute([$id,$signed,$reference,$pdf,hash('sha256',$pdf),$snapshot,$actor['id']]);
        sales_portal_event($actor,'recurring',$id,'approved',['source'=>$componentId,'base'=>compensation_money($base),'rate'=>$component['approved_rate'],'note'=>$note]);$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sales_close_recurring(array $actor,array $input): void
{
    sales_require_manager($actor);$id=sales_text($input,'contract_id',1,36);$reason=sales_text($input,'reason',20,3000);$pdo=db();$pdo->beginTransaction();
    try{$q=$pdo->prepare('SELECT * FROM sales_recurring_contracts WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Δεν βρέθηκε συμφωνία.');
        $pdo->prepare('UPDATE sales_recurring_contracts SET active=0,closed_on=COALESCE(closed_on,CURRENT_DATE) WHERE id=?')->execute([$id]);sales_portal_event($actor,'recurring',$id,'closed',['reason'=>$reason,'earned_commissions_unchanged'=>true]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sales_recurring_receipt(array $actor,array $input): string
{
    sales_require_manager($actor);$contractId=sales_text($input,'contract_id',1,36);$month=sales_text($input,'period_month',7,7);
    $date=DateTimeImmutable::createFromFormat('!Y-m',$month);if(!$date||$date->format('Y-m')!==$month)throw new InvalidArgumentException('Μη έγκυρος μήνας υπηρεσίας.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM sales_recurring_contracts WHERE id=? FOR UPDATE');$q->execute([$contractId]);$contract=$q->fetch();
        if(!$contract||!sales_recurring_covers($contract,$month))throw new RuntimeException('Η συμφωνία δεν καλύπτει αυτή την περίοδο. Για παλιά διακοπή χωρίς ημερομηνία χρησιμοποιήστε ήδη εγκεκριμένο αντικείμενο ή τεκμηριωμένη αποκατάσταση, όχι νέα εργασία.');
        compensation_lock_lead($actor,$contract['lead_id']);
        $q=$pdo->prepare('SELECT component_id FROM sales_recurring_periods WHERE contract_id=? AND period_month=?');$q->execute([$contractId,$month]);$component=$q->fetchColumn();
        if(!$component)$component=uuid_v4();
        // Create one immutable approved component per month; a failed receipt can retry it.
        if(!is_string($component))throw new LogicException('Missing recurring component');
        $q=$pdo->prepare('SELECT id FROM lead_service_components WHERE id=?');$q->execute([$component]);
        if(!$q->fetchColumn()){
            $pdo->prepare('INSERT INTO lead_service_components(id,lead_id,service_code,description,approved_base,approved_rate,policy_version,beneficiary_user_id,approved_by,approval_note) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$component,$contract['lead_id'],$contract['service_code'],'Recurring '.$contractId.' / '.$month,$contract['period_base'],$contract['rate'],'recurring-v26',$contract['beneficiary_user_id'],$actor['id'],$contract['note']]);
            $pdo->prepare('INSERT INTO sales_recurring_periods(id,contract_id,period_month,component_id) VALUES(?,?,?,?)')->execute([uuid_v4(),$contractId,$month,$component]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    // The receipt has its own locked financial transaction and duplicate reference checks.
    return service_record_receipt($actor,$contract['lead_id'],array_replace($input,['component_id'=>$component,'revenue_confirmed'=>'1']));
}

function sales_assert_recurring_active(array $component): void
{
    if($component['policy_version']!=='recurring-v26')return;
    // This component was already approved for a covered period. Later closure,
    // departure or loss of login access cannot erase its agreed receivable.
    $q=db()->prepare('SELECT c.id FROM sales_recurring_periods p JOIN sales_recurring_contracts c ON c.id=p.contract_id WHERE p.component_id=? AND c.beneficiary_user_id=? FOR UPDATE');$q->execute([$component['id'],$component['beneficiary_user_id']]);
    if(!$q->fetch())throw new RuntimeException('Δεν βρέθηκε η τεκμηριωμένη περίοδος αυτής της προμήθειας.');
}
function sales_recurring_covers(array $contract,string $month):bool {
    if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month))return false;
    $interval=(int)($contract['interval_months']??1);
    $distance=((int)substr($month,0,4)-(int)substr($contract['starts_on'],0,4))*12+(int)substr($month,5,2)-(int)substr($contract['starts_on'],5,2);
    if(!in_array($interval,[1,2,3,6,12],true)||$distance<0||$distance%$interval!==0)return false;
    if($month>date('Y-m')||$month<substr($contract['starts_on'],0,7)||(!empty($contract['ends_on'])&&$month>substr($contract['ends_on'],0,7)))return false;
    if(!$contract['active']&&(empty($contract['closed_on'])||$month>substr($contract['closed_on'],0,7)))return false;
    return true;
}
