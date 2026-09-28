<?php
declare(strict_types=1);
if (!defined('CRM_ROOT')) { http_response_code(403); exit; }

// Policy snapshot from MELAS_ENERGEIAKI_CRM_Compensation_Rules.pdf, pp. 6-10.
// Existing ledger rows are never recalculated when this catalogue changes.
function service_commission_rates(): array
{
    return ['01'=>10,'02'=>10,'03'=>7,'04'=>7,'05'=>8,'06'=>10,'07'=>10,'08'=>10,'09'=>8,'10'=>12,'11'=>7];
}
function compensation_cents(mixed $amount): int
{
    if (!is_string($amount) && !is_int($amount)) throw new InvalidArgumentException('Μη έγκυρο χρηματικό ποσό.');
    $amount=trim((string)$amount);
    if (!preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/D',$amount,$m)) throw new InvalidArgumentException('Χρησιμοποίησε θετικό ποσό με μέχρι δύο δεκαδικά, χωρίς διαχωριστικά χιλιάδων.');
    return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}
function compensation_money(int $cents): string { return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
function compensation_percent(int $baseCents,int $rate): int
{
    if ($baseCents<0 || $rate<0 || $rate>100) throw new InvalidArgumentException('Μη έγκυρη βάση ή ποσοστό.');
    return intdiv($baseCents*$rate+50,100);
}
function ensure_service_compensation_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_service_components (
      id CHAR(36) PRIMARY KEY, lead_id CHAR(36) NOT NULL, service_code CHAR(2) NOT NULL,
      description VARCHAR(255) NOT NULL, approved_base DECIMAL(14,2) NOT NULL,
      approved_rate SMALLINT UNSIGNED NOT NULL, policy_version VARCHAR(30) NOT NULL,
      beneficiary_user_id CHAR(36) NOT NULL, approved_by CHAR(36) NOT NULL,
      approval_note TEXT NOT NULL, approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY service_scope_unique(lead_id,description),
      CONSTRAINT service_component_lead_fk FOREIGN KEY(lead_id) REFERENCES sales_leads(id),
      CONSTRAINT service_component_owner_fk FOREIGN KEY(beneficiary_user_id) REFERENCES users(id),
      CONSTRAINT service_component_approver_fk FOREIGN KEY(approved_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec('ALTER TABLE service_revenue_receipts ADD COLUMN IF NOT EXISTS component_id CHAR(36) NULL');
    $pdo->exec('ALTER TABLE service_revenue_receipts ADD COLUMN IF NOT EXISTS eligible_base_amount DECIMAL(14,2) NULL');
    $pdo->exec('ALTER TABLE lead_commissions ADD COLUMN IF NOT EXISTS source_record_id CHAR(36) NULL');
    $pdo->exec('ALTER TABLE lead_commissions ADD COLUMN IF NOT EXISTS policy_snapshot_json LONGTEXT NULL');
    $pdo->exec('ALTER TABLE lead_commissions ADD COLUMN IF NOT EXISTS payout_reference VARCHAR(255) NULL');
    $pdo->exec('ALTER TABLE lead_commissions ADD COLUMN IF NOT EXISTS payout_date DATE NULL');
}
function compensation_lock_lead(array $actor,string $id): array
{
    if (!db()->inTransaction()) throw new LogicException('A transaction is required.');
    if (!can_review_leads($actor)||!can_view_all_sales_financials($actor)) throw new RuntimeException('Δεν έχετε δικαίωμα οικονομικής διαχείρισης.');
    $q=db()->prepare('SELECT * FROM sales_leads WHERE id=? FOR UPDATE');$q->execute([$id]);$lead=$q->fetch();
    if (!$lead||lead_is_owner($actor,$lead)) throw new RuntimeException('Απαιτείται άλλος εξουσιοδοτημένος διαχειριστής.');
    if (empty($lead['contacts_released_at'])) throw new RuntimeException('Προηγείται κατοχύρωση και αποδοχή της αμοιβής €80.');
    return $lead;
}
function service_approve_component(array $actor,string $leadId,array $input): string
{
    $code=is_string($input['service_code']??null)?$input['service_code']:'';
    $rates=service_commission_rates();
    $base=compensation_cents($input['approved_base']??'');
    $scope=trim((string)($input['component_description']??''));$note=trim((string)($input['base_approval_note']??''));
    if (!isset($rates[$code])||$base<=0||mb_strlen($scope)<5||mb_strlen($scope)>255||mb_strlen($note)<10||mb_strlen($note)>3000||empty($input['base_confirmed'])) throw new RuntimeException('Συμπλήρωσε υπηρεσία, διακριτό αντικείμενο, θετική εγκεκριμένη βάση και τεκμηρίωση εξαιρέσεων.');
    if ($code==='10'&&empty($input['fee_only_confirmed'])) throw new RuntimeException('Η υπηρεσία 10 αφορά μόνο την αμοιβή MELAS, ποτέ την αξία πώλησης του πάρκου.');
    $pdo=db();$pdo->beginTransaction();
    try {
        $lead=compensation_lock_lead($actor,$leadId);
        $q=$pdo->prepare('SELECT id FROM lead_service_components WHERE lead_id=? AND description=?');$q->execute([$leadId,$scope]);
        if ($q->fetchColumn()) throw new RuntimeException('Το συγκεκριμένο αντικείμενο έχει ήδη εγκεκριμένη βάση.');
        $id=uuid_v4();
        $pdo->prepare('INSERT INTO lead_service_components(id,lead_id,service_code,description,approved_base,approved_rate,policy_version,beneficiary_user_id,approved_by,approval_note) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$id,$leadId,$code,$scope,compensation_money($base),$rates[$code],'2026-09-pdf-v18',$lead['submitted_by'],$actor['id'],$note]);
        lead_record_event(load_lead($leadId),$actor,'service_base_approved','Component '.$id.' · service '.$code.' · βάση €'.compensation_money($base).' · '.$rates[$code].'% · '.$note);
        $pdo->commit();return $id;
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function service_record_receipt(array $actor,string $leadId,array $input): string
{
    $received=compensation_cents($input['received_amount']??'');$eligible=compensation_cents($input['eligible_base_amount']??'');
    $date=(string)($input['received_at']??'');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    $ref=trim((string)($input['receipt_reference']??''));$notes=trim((string)($input['notes']??''));
    if ($received<=0||$eligible<=0||$eligible>$received||!$parsed||$parsed->format('Y-m-d')!==$date||$date>date('Y-m-d')||$ref===''||mb_strlen($ref)>255||mb_strlen($notes)>3000||empty($input['revenue_confirmed'])) throw new RuntimeException('Χρειάζονται πραγματική είσπραξη, επιλέξιμο μέρος έως το ποσό είσπραξης, ημερομηνία και αναφορά.');
    $pdo=db();$pdo->beginTransaction();
    try {
        $lead=compensation_lock_lead($actor,$leadId);
        $q=$pdo->prepare('SELECT * FROM lead_service_components WHERE id=? AND lead_id=? FOR UPDATE');$q->execute([(string)($input['component_id']??''),$leadId]);$component=$q->fetch();
        if (!$component||$component['beneficiary_user_id']!==$lead['submitted_by']) throw new RuntimeException('Επίλεξε εγκεκριμένο αντικείμενο αυτού του lead.');
        sales_assert_recurring_active($component);
        $q=$pdo->prepare('SELECT id FROM service_revenue_receipts WHERE lead_id=? AND receipt_reference=?');$q->execute([$leadId,$ref]);
        if ($q->fetchColumn()) throw new RuntimeException('Η αναφορά έχει ήδη καταχωριστεί σε αυτό το lead.');
        $q=$pdo->prepare('SELECT COALESCE(SUM(eligible_base_amount),0) FROM service_revenue_receipts WHERE component_id=?');$q->execute([$component['id']]);$prior=compensation_cents((string)$q->fetchColumn());
        $base=compensation_cents($component['approved_base']);
        if ($prior+$eligible>$base) throw new RuntimeException('Οι επιλέξιμες εισπράξεις ξεπερνούν την εγκεκριμένη βάση.');
        $rate=(int)$component['approved_rate'];
        // Cumulative rounding prevents instalments from earning extra cents.
        $commission=compensation_percent($prior+$eligible,$rate)-compensation_percent($prior,$rate);
        $id=uuid_v4();$receiptId=uuid_v4();
        $snapshot=json_encode(['policy'=>$component['policy_version'],'component_id'=>$component['id'],'service_code'=>$component['service_code'],'approved_base'=>$component['approved_base'],'rate'=>$rate,'base_approved_by'=>$component['approved_by'],'base_approved_at'=>$component['approved_at'],'eligible_received'=>compensation_money($eligible),'actual_received'=>compensation_money($received),'currency'=>'EUR'],JSON_THROW_ON_ERROR);
        $pdo->prepare("INSERT INTO lead_commissions(id,lead_id,beneficiary_user_id,commission_type,basis_amount,rate,commission_amount,payment_reference,notes,status,approved_by,earned_at,source_record_id,policy_snapshot_json) VALUES(?,?,?,'service_revenue',?,?,?,?,?,'earned',?,NOW(),?,?)")
            ->execute([$id,$leadId,$component['beneficiary_user_id'],compensation_money($eligible),$rate,compensation_money($commission),$ref,$notes?:null,$actor['id'],$receiptId,$snapshot]);
        $pdo->prepare('INSERT INTO service_revenue_receipts(id,lead_id,commission_id,received_amount,received_at,receipt_reference,notes,confirmed_by,component_id,eligible_base_amount) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$receiptId,$leadId,$id,compensation_money($received),$date,$ref,$notes?:null,$actor['id'],$component['id'],compensation_money($eligible)]);
        lead_record_event(load_lead($leadId),$actor,'service_receipt_recorded','Είσπραξη '.$receiptId.' · component '.$component['id'].' · επιλέξιμη βάση €'.compensation_money($eligible).' · '.$rate.'% · προμήθεια €'.compensation_money($commission));
        sales_create_override($id);
        $pdo->commit();return $id;
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function panel_set_purchase_quantity(array $actor,string $leadId,mixed $quantity): void
{
    if(!is_string($quantity)||!preg_match('/^[1-9][0-9]{0,8}$/D',$quantity))throw new RuntimeException('Χρειάζεται θετικός ακέραιος αριθμός πάνελ.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $lead=compensation_lock_lead($actor,$leadId);
        if(!is_panel_opportunity($lead['opportunity_type']))throw new RuntimeException('Απαιτείται ευκαιρία αγοράς πάνελ.');
        $q=$pdo->prepare('SELECT COALESCE(SUM(panel_quantity),0) FROM panel_purchase_payments WHERE lead_id=?');$q->execute([$leadId]);
        if((int)$quantity<(int)$q->fetchColumn())throw new RuntimeException('Η ποσότητα δεν μπορεί να είναι μικρότερη από τα ήδη πληρωμένα πάνελ.');
        $pdo->prepare('UPDATE sales_leads SET agreed_panel_quantity=? WHERE id=?')->execute([$quantity,$leadId]);
        lead_record_event(load_lead($leadId),$actor,'panel_quantity_agreed','Συμφωνημένη ποσότητα: '.$quantity.' πάνελ. Δεν δημιουργήθηκε προμήθεια.');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function panel_record_payment(array $actor,string $leadId,array $input): string
{
    $qty=$input['panel_quantity']??'';$amount=compensation_cents($input['seller_payment_amount']??'');
    $date=(string)($input['seller_paid_at']??'');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    $ref=trim((string)($input['payment_reference']??''));$notes=trim((string)($input['notes']??''));
    if(!is_string($qty)||!preg_match('/^[1-9][0-9]{0,8}$/D',$qty)||$amount<=0||!$parsed||$parsed->format('Y-m-d')!==$date||$date>date('Y-m-d')||$ref===''||mb_strlen($ref)>255||mb_strlen($notes)>3000||empty($input['seller_payment_confirmed']))throw new RuntimeException('Χρειάζονται ακέραιη ποσότητα, πραγματική πληρωμή, ημερομηνία, αναφορά και επιβεβαίωση.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $lead=compensation_lock_lead($actor,$leadId);
        if(!is_panel_opportunity($lead['opportunity_type']))throw new RuntimeException('Απαιτείται ευκαιρία αγοράς πάνελ.');
        $q=$pdo->prepare('SELECT id FROM panel_purchase_payments WHERE lead_id=? AND payment_reference=?');$q->execute([$leadId,$ref]);
        if($q->fetchColumn())throw new RuntimeException('Η αναφορά πληρωμής έχει ήδη καταχωριστεί.');
        $q=$pdo->prepare('SELECT COALESCE(SUM(panel_quantity),0) FROM panel_purchase_payments WHERE lead_id=?');$q->execute([$leadId]);$prior=(int)$q->fetchColumn();
        if($prior+(int)$qty>(int)($lead['agreed_panel_quantity']??0))throw new RuntimeException('Η πληρωμένη ποσότητα ξεπερνά τη συμφωνημένη αγορά.');
        $id=uuid_v4();$paymentId=uuid_v4();$commission=compensation_money((int)$qty*60);
        $snapshot=json_encode(['policy'=>'2026-09-pdf-v18','paid_quantity'=>(int)$qty,'rate_per_panel'=>'0.60','agreed_quantity'=>$lead['agreed_panel_quantity'],'currency'=>'EUR'],JSON_THROW_ON_ERROR);
        $pdo->prepare("INSERT INTO lead_commissions(id,lead_id,beneficiary_user_id,commission_type,basis_quantity,rate,commission_amount,payment_reference,notes,status,approved_by,earned_at,source_record_id,policy_snapshot_json) VALUES(?,?,?,'panel_purchase',?,0.60,?,?,?,'payable',?,NOW(),?,?)")->execute([$id,$leadId,$lead['submitted_by'],$qty,$commission,$ref,$notes?:null,$actor['id'],$paymentId,$snapshot]);
        $pdo->prepare('INSERT INTO panel_purchase_payments(id,lead_id,commission_id,panel_quantity,seller_payment_amount,seller_paid_at,payment_reference,notes,confirmed_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$paymentId,$leadId,$id,$qty,compensation_money($amount),$date,$ref,$notes?:null,$actor['id']]);
        lead_record_event(load_lead($leadId),$actor,'panel_payment_recorded','Πληρωμή '.$paymentId.' · '.$qty.' πάνελ · προμήθεια €'.$commission);
        sales_create_override($id);
        $pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
