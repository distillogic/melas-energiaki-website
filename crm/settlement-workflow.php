<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
function sales_settlement_schema():void {
    static $ready=false;if($ready)return;
    db()->exec("CREATE TABLE IF NOT EXISTS sales_settlement_batches(id CHAR(36) PRIMARY KEY,period_month CHAR(7) NOT NULL,due_on DATE NOT NULL,closed_by CHAR(36) NOT NULL,closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,note TEXT NOT NULL,INDEX settlement_month(period_month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS sales_settlement_items(id CHAR(36) PRIMARY KEY,batch_id CHAR(36) NOT NULL,source_type VARCHAR(16) NOT NULL,source_id CHAR(36) NOT NULL,beneficiary_user_id CHAR(36) NOT NULL,name VARCHAR(150) NOT NULL,email VARCHAR(320) NOT NULL,relationship VARCHAR(20) NOT NULL,amount DECIMAL(14,2) NOT NULL,earned_at DATETIME NOT NULL,due_on DATE NOT NULL,UNIQUE settlement_source(source_type,source_id),INDEX settlement_scope(batch_id,beneficiary_user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");$ready=true;
}
function sales_settlement_due(string $month,string $earlier=''):string {
    $d=DateTimeImmutable::createFromFormat('!Y-m',$month);if(!$d||$d->format('Y-m')!==$month)throw new InvalidArgumentException('Μη έγκυρος μήνας.');
    $due=$d->modify('+2 months')->modify('-1 day')->format('Y-m-d');
    if($earlier!==''){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$earlier);if(!$date||$date->format('Y-m-d')!==$earlier||$earlier>$due)throw new InvalidArgumentException('Η ειδική προθεσμία μπορεί μόνο να συντομεύει τον μηνιαίο κανόνα.');$due=$earlier;}return $due;
}
function sales_settlement_close(array $actor,array $input):string {
    sales_require_manager($actor);personnel_reauth($actor,$input);sales_settlement_schema();$month=sales_text($input,'period_month',7,7);$note=sales_text($input,'note',10,2000);$due=sales_settlement_due($month,sales_text($input,'earlier_due_on',0,10));
    if($month>=date('Y-m')||($input['reviewed']??'')!=='1')throw new InvalidArgumentException('Κλείνετε μόνο ολοκληρωμένο μήνα, μετά από έλεγχο.');
    $end=(new DateTimeImmutable($month.'-01'))->modify('+1 month')->format('Y-m-d');$pdo=db();$q=$pdo->query("SELECT GET_LOCK('melas-settlements-v36',10)");if((int)$q->fetchColumn()!==1)throw new InvalidArgumentException('Άλλη εκκαθάριση εκτελείται. Δοκιμάστε ξανά.');
    try{
        $pdo->beginTransaction();$rows=[];
        foreach(['commission'=>'lead_commissions','override'=>'sales_team_overrides'] as $type=>$table){
            $amount=$type==='commission'?'commission_amount':'amount';$eligible=$type==='commission'?"c.status='payable'":"c.status<>'paid' AND EXISTS(SELECT 1 FROM lead_commissions src WHERE src.id=c.source_commission_id AND src.status IN ('payable','paid'))";
            $q=$pdo->prepare("SELECT c.id,c.beneficiary_user_id,c.$amount amount,c.earned_at,u.name,u.email,COALESCE(p.relationship,'unclassified') relationship FROM $table c JOIN users u ON u.id=c.beneficiary_user_id LEFT JOIN personnel_onboarding p ON p.user_id=u.id WHERE $eligible AND c.earned_at<? AND NOT EXISTS(SELECT 1 FROM sales_settlement_items i WHERE i.source_type=? AND i.source_id=c.id) ORDER BY c.id FOR UPDATE");$q->execute([$end,$type]);foreach($q->fetchAll() as $row)$rows[]=$row+['type'=>$type];
        }
        if(!$rows)throw new InvalidArgumentException('Δεν υπάρχουν νέες πληρωτέες αμοιβές για αυτή την εκκαθάριση.');
        $id=uuid_v4();$pdo->prepare('INSERT INTO sales_settlement_batches(id,period_month,due_on,closed_by,note) VALUES(?,?,?,?,?)')->execute([$id,$month,$due,$actor['id'],$note]);
        $insert=$pdo->prepare('INSERT INTO sales_settlement_items(id,batch_id,source_type,source_id,beneficiary_user_id,name,email,relationship,amount,earned_at,due_on) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        foreach($rows as $row){$itemDue=min($due,sales_settlement_due(substr($row['earned_at'],0,7)));$insert->execute([uuid_v4(),$id,$row['type'],$row['id'],$row['beneficiary_user_id'],$row['name'],$row['email'],$row['relationship'],$row['amount'],$row['earned_at'],$itemDue]);}
        sales_portal_event($actor,'settlement',$id,'closed',['month'=>$month,'due'=>$due,'items'=>count($rows),'no_payment_executed'=>true]);$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{$pdo->query("SELECT RELEASE_LOCK('melas-settlements-v36')");}
}
function sales_settlement_read(array $actor,string $id):array {
    $q=db()->prepare('SELECT * FROM sales_settlement_batches WHERE id=?');$q->execute([$id]);$batch=$q->fetch();if(!$batch)throw new InvalidArgumentException('Δεν βρέθηκε εκκαθάριση.');
    $sql="SELECT i.*,COALESCE(c.status,o.status,'missing') current_status,COALESCE(c.payout_date,o.payout_date) payout_date,COALESCE(c.payout_reference,o.payout_reference) payout_reference FROM sales_settlement_items i LEFT JOIN lead_commissions c ON i.source_type='commission' AND c.id=i.source_id LEFT JOIN sales_team_overrides o ON i.source_type='override' AND o.id=i.source_id WHERE i.batch_id=?";
    $params=[$id];if(!can_view_all_sales_financials($actor)){$sql.=' AND i.beneficiary_user_id=?';$params[]=$actor['id'];}
    $q=db()->prepare($sql.' ORDER BY i.name,i.id');$q->execute($params);$rows=$q->fetchAll();if(!$rows)throw new InvalidArgumentException('Δεν βρέθηκε διαθέσιμη εκκαθάριση.');
    if(!can_view_all_sales_financials($actor))$batch['note']='Προσωπικό απόσπασμα εκκαθάρισης.';
    return [$batch,$rows];
}
function sales_settlement_list(array $actor):array {
    $all=can_view_all_sales_financials($actor);$q=db()->prepare('SELECT b.id,b.period_month,b.due_on,b.closed_at FROM sales_settlement_batches b'.(' WHERE EXISTS(SELECT 1 FROM sales_settlement_items i WHERE i.batch_id=b.id'.($all?'':' AND i.beneficiary_user_id=?').')').' ORDER BY b.closed_at DESC,b.id DESC');$q->execute($all?[]:[$actor['id']]);return $q->fetchAll();
}
function sales_settlement_relationship(string $relationship):string {return match($relationship){'employee'=>'Μισθοδοσία · πρόσθετες μικτές αποδοχές','contractor'=>'Εξωτερικός συνεργάτης · παραστατικό',default=>'Απαιτείται ταξινόμηση σχέσης από λογιστήριο'};}
