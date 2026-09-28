<?php
declare(strict_types=1);

if (!defined('CRM_ROOT')) { http_response_code(403); exit; }

function ensure_lead_protection_schema(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE sales_leads ADD COLUMN IF NOT EXISTS contacts_released_at DATETIME NULL");
    $pdo->exec("ALTER TABLE sales_leads ADD COLUMN IF NOT EXISTS contacts_released_by CHAR(36) NULL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_protection_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lead_id CHAR(36) NOT NULL, actor_id CHAR(36) NOT NULL,
        event_type VARCHAR(40) NOT NULL, note TEXT NOT NULL,
        snapshot_json LONGTEXT NOT NULL, snapshot_sha256 CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX protection_lead_idx(lead_id,id),
        CONSTRAINT protection_lead_fk FOREIGN KEY(lead_id) REFERENCES sales_leads(id),
        CONSTRAINT protection_actor_fk FOREIGN KEY(actor_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO crm_settings(setting_key,setting_value) VALUES ('lead_registry_v15','1')");
    // Legacy approvals already created a fee. Do not claim that previously exposed
    // contacts were secret, and never create retrospective commissions here.
    $pdo->exec("UPDATE sales_leads l SET contacts_released_at=l.qualified_fee_approved_at,
        contacts_released_by=l.qualified_fee_approved_by
        WHERE l.contacts_released_at IS NULL AND l.qualified_fee_approved_at IS NOT NULL
        AND EXISTS (SELECT 1 FROM lead_commissions c WHERE c.lead_id=l.id
          AND c.beneficiary_user_id=l.submitted_by AND c.commission_type='qualified_lead'
          AND c.status IN ('payable','paid') AND (c.commission_amount=80 OR (c.status='paid' AND c.commission_amount=60)))");
}

function lead_is_owner(array $user, array $lead): bool
{
    return !empty($user['id']) && (string)$user['id'] === (string)($lead['submitted_by'] ?? '');
}

function can_view_lead_identity(array $user, array $lead): bool
{
    return lead_is_owner($user, $lead)
        || (can_review_leads($user) && !empty($lead['contacts_released_at']));
}

function can_edit_protected_lead(array $user, array $lead): bool
{
    return lead_is_owner($user, $lead) && empty($lead['contacts_released_at'])
        && empty($lead['qualified_fee_approved_at']);
}

function lead_public_regions(): array
{
    return ['attica'=>'Αττική','central_greece'=>'Στερεά Ελλάδα','thessaly'=>'Θεσσαλία',
        'central_macedonia'=>'Κεντρική Μακεδονία','western_macedonia'=>'Δυτική Μακεδονία',
        'eastern_macedonia'=>'Ανατολική Μακεδονία / Θράκη','epirus'=>'Ήπειρος',
        'western_greece'=>'Δυτική Ελλάδα','peloponnese'=>'Πελοπόννησος',
        'ionian'=>'Ιόνια Νησιά','north_aegean'=>'Βόρειο Αιγαίο',
        'south_aegean'=>'Νότιο Αιγαίο','crete'=>'Κρήτη','abroad'=>'Εξωτερικό'];
}

// Only controlled values go into the anonymous card. Free text, even technical
// text, may contain a name, address, URL or phone number and stays private.
function lead_anonymous_data(array $data): array
{
    $safe=[];
    foreach (['panel_quantity','panel_watts','asking_price','asking_total_price'] as $key) {
        $value=(string)($data[$key] ?? '');
        if (preg_match('/^\d{1,10}(?:\.\d{1,4})?$/D', $value)) $safe[$key]=$value;
    }
    $enums=['seller_authority'=>['owner','authorized_seller','other_contact'],
        'sale_intent_confirmed'=>['yes','future_possible','no'],
        'panel_technology'=>['Monofacial','Polycrystalline','Bifacial','Άλλο'],
        'service_category'=>array_keys(service_category_labels()),
        'public_region'=>array_keys(lead_public_regions())];
    foreach ($enums as $key=>$values) {
        if (in_array((string)($data[$key]??''), array_map('strval',$values),true)) $safe[$key]=(string)$data[$key];
    }
    $value=(string)($data['availability_date']??'');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if ($date && $date->format('Y-m-d')===$value) $safe['availability_date']=$value;
    return $safe;
}

function protected_lead_view(array $user, array $lead): array
{
    if (can_view_lead_identity($user,$lead)) return $lead;
    $lead['company_name']='Προστατευμένο lead';
    foreach (['contact_name','contact_title','contact_phone','contact_email','website','country_region',
        'conversation_summary','next_step','next_step_other','lead_source','source_other',
        'follow_up_note','review_note'] as $key) $lead[$key]='';
    $lead['company_id']=null;
    $data=json_decode((string)($lead['opportunity_data_json']??'{}'),true)?:[];
    $lead['opportunity_data_json']=json_encode(lead_anonymous_data($data),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    return $lead;
}

function lead_record_event(array $lead, array $actor, string $kind, string $note): void
{
    $snapshot=json_encode([
        'lead_reference'=>$lead['lead_reference'],'owner_id'=>$lead['submitted_by'],
        'owner_name'=>$lead['submitter_name']??'', 'owner_email'=>$lead['submitter_email']??'',
        'registered_at'=>$lead['created_at'], 'status'=>$lead['status'],
        'company_name'=>$lead['company_name'],'opportunity_type'=>$lead['opportunity_type'],
        'contact_email'=>$lead['contact_email'],'contact_phone'=>$lead['contact_phone'],
        'contact_name'=>$lead['contact_name'],'data'=>$lead['opportunity_data_json'],
        'contacts_released_at'=>$lead['contacts_released_at']??null,
    ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    db()->prepare('INSERT INTO lead_protection_events(lead_id,actor_id,event_type,note,snapshot_json,snapshot_sha256) VALUES(?,?,?,?,?,?)')
        ->execute([$lead['id'],$actor['id'],$kind,$note,$snapshot,hash('sha256',$snapshot)]);
}

function lead_registry_lock(): void
{
    db()->query("SELECT setting_value FROM crm_settings WHERE setting_key='lead_registry_v15' FOR UPDATE")->fetchColumn();
}

function assert_lead_not_duplicate(string $company, string $email, string $type, string $except='',array $data=[]): void
{
    // Closed/rejected records still belong to their original submitter. Separate
    // service and panel opportunities remain possible for the same company.
    $family=is_panel_opportunity($type)?'panels':$type;
    $q=db()->prepare("SELECT id,opportunity_data_json FROM sales_leads WHERE id<>? AND
        (CASE WHEN opportunity_type IN ('used_panels','repowering','equipment_replacement') THEN 'panels' ELSE opportunity_type END)=?
        AND (LOWER(TRIM(company_name))=LOWER(TRIM(?))
          OR (?<>'' AND LOWER(TRIM(contact_email))=LOWER(TRIM(?))))");
    $q->execute([$except,$family,$company,$email,$email]);
    $scope=lead_opportunity_scope($type,$data);
    foreach($q->fetchAll() as $existing){
        $prior=lead_opportunity_scope($type,json_decode($existing['opportunity_data_json'],true)?:[]);
        if($scope===''||$prior===''||hash_equals($scope,$prior))throw new RuntimeException('Υπάρχει ήδη κατοχυρωμένη ευκαιρία για το ίδιο αντικείμενο. Για διαφορετική εγκατάσταση/περίοδο συμπλήρωσε ακριβή στοιχεία· για αμφισβήτηση χρησιμοποίησε αίτημα κατοχύρωσης. Η απόρριψη ή το Lost δεν μεταφέρει το lead.');
    }
}

function lead_opportunity_scope(string $type,array $data): string
{
    $keys=is_panel_opportunity($type)?['panel_location','availability_date']:['service_category','service_type','park_project','service_location','service_timing'];
    $values=[];foreach($keys as $key){$value=mb_strtolower(trim((string)($data[$key]??'')));if($value==='')return '';$values[]=preg_replace('/\s+/u',' ',$value);}
    return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
}

function lead_submission_errors(array $input, array $data, string $type): array
{
    $errors=[];
    foreach (['company_name','country_region','contact_name','contact_title','contact_phone','contact_email','next_step'] as $field) {
        if (trim((string)($input[$field]??''))==='') $errors[]='Λείπουν βασικά στοιχεία εταιρείας ή επικοινωνίας.';
    }
    if (!filter_var($input['contact_email']??'',FILTER_VALIDATE_EMAIL)) $errors[]='Απαιτείται έγκυρο email επαφής.';
    if (mb_strlen(trim((string)($input['conversation_summary']??'')))<80) $errors[]='Απαιτείται σύνοψη τουλάχιστον 80 χαρακτήρων.';
    if (empty($input['contact_consent'])) $errors[]='Απαιτείται συναίνεση επικοινωνίας.';
    if (empty($input['follow_up_at'])) $errors[]='Συμπλήρωσε ημερομηνία για το επόμενο follow-up.';
    if (is_panel_opportunity($type)) {
        if (!in_array($data['seller_authority']??'', ['owner','authorized_seller'],true)
            || ($data['sale_intent_confirmed']??'')!=='yes') $errors[]='Απαιτούνται εξουσιοδοτημένος πωλητής και επιβεβαιωμένη πρόθεση πώλησης.';
        if (!is_numeric($data['panel_quantity']??null) || (float)$data['panel_quantity']<=0
            || !is_numeric($data['panel_watts']??null) || (float)$data['panel_watts']<=0) $errors[]='Απαιτούνται θετική ποσότητα πάνελ και Watt.';
        foreach (['panel_location','availability_date'] as $key) if (trim((string)($data[$key]??''))==='') $errors[]='Λείπει τοποθεσία ή διαθεσιμότητα πάνελ.';
        if (trim((string)($data['panel_age']??''))==='' && trim((string)($data['panel_condition']??''))==='') $errors[]='Συμπληρώστε ηλικία ή κατάσταση πάνελ.';
    } elseif ($type==='solar_service') {
        foreach (['service_category','service_type','park_project','service_location','service_timing','decision_maker'] as $key) {
            if (trim((string)($data[$key]??''))==='') $errors[]='Λείπουν τα απαιτούμενα στοιχεία υπηρεσίας.';
        }
    } else $errors[]='Μη έγκυρος τύπος ευκαιρίας.';
    return array_values(array_unique($errors));
}

function lead_accept_and_release(array $actor, string $id, array $input): void
{
    if (!can_review_leads($actor) || !can_view_all_sales_financials($actor)) throw new RuntimeException('Δεν έχετε δικαίωμα οικονομικής αποδοχής.');
    $pdo=db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT * FROM sales_leads WHERE id=? FOR UPDATE');$q->execute([$id]);$lead=$q->fetch();
        if (!$lead || lead_is_owner($actor,$lead)) throw new RuntimeException('Απαιτείται αξιολόγηση από άλλον εξουσιοδοτημένο διαχειριστή.');
        if (!empty($lead['contacts_released_at'])) { $pdo->commit(); return; }
        if (!in_array($lead['status'],['submitted','under_review','more_information','qualified','commercial_discussion','won'],true)) throw new RuntimeException('Ο συνεργάτης πρέπει πρώτα να υποβάλει την ευκαιρία για αξιολόγηση.');
        $checks=[];
        foreach (qualification_labels_for((string)$lead['opportunity_type']) as $key=>$label) {
            $checks[$key]=!empty($input['qualification'][$key]);
        }
        if (in_array(false,$checks,true) || empty($input['accept_commitment'])) throw new RuntimeException('Επιβεβαιώστε τα κριτήρια και την αποδοχή της αμοιβής €80 πριν την αποκάλυψη.');
        $data=json_decode($lead['opportunity_data_json'],true)?:[];
        $errors=lead_submission_errors($lead,$data,(string)$lead['opportunity_type']);
        if ($errors) throw new RuntimeException('Ζητήστε συμπλήρωση από τον συνεργάτη. '.implode(' ',$errors));
        $q=$pdo->prepare("SELECT * FROM lead_commissions WHERE lead_id=? AND commission_type='qualified_lead' FOR UPDATE");$q->execute([$id]);$fees=$q->fetchAll();
        if (count($fees)>1) throw new RuntimeException('Υπάρχουν διπλές παλαιές αμοιβές. Απαιτείται έλεγχος πριν την αποκάλυψη.');
        if ($fees && ($fees[0]['beneficiary_user_id']!==$lead['submitted_by'] || !qualified_fee_valid($fees[0]))) throw new RuntimeException('Η υπάρχουσα αμοιβή χρειάζεται έλεγχο δικαιούχου/ποσού/κατάστασης.');
        if (!$fees) {
            $feeId=uuid_v4();
            $pdo->prepare("INSERT INTO lead_commissions(id,lead_id,beneficiary_user_id,commission_type,basis_quantity,basis_amount,rate,commission_amount,status,approved_by,earned_at) VALUES(?,?,?,'qualified_lead',1,80,80,80,'payable',?,NOW())")
                ->execute([$feeId,$id,$lead['submitted_by'],$actor['id']]);
            sales_create_override($feeId);
        }
        $next=in_array($lead['status'],['commercial_discussion','won'],true)?$lead['status']:'fee_approved';
        $pdo->prepare("UPDATE sales_leads SET status=?,qualification_check_json=?,qualification_reviewed_at=NOW(),qualification_reviewed_by=?,qualified_at=COALESCE(qualified_at,NOW()),qualified_by=COALESCE(qualified_by,?),qualified_fee_approved_at=COALESCE(qualified_fee_approved_at,NOW()),qualified_fee_approved_by=COALESCE(qualified_fee_approved_by,?),contacts_released_at=NOW(),contacts_released_by=? WHERE id=?")
            ->execute([$next,json_encode($checks,JSON_THROW_ON_ERROR),$actor['id'],$actor['id'],$actor['id'],$actor['id'],$id]);
        $note='Αποδοχή κατοχύρωσης στον αρχικό καταχωρητή, αμοιβής €80 και αποκάλυψη στοιχείων επικοινωνίας.';
        $pdo->prepare('INSERT INTO lead_status_history(id,lead_id,changed_by,old_status,new_status,note) VALUES(?,?,?,?,?,?)')->execute([uuid_v4(),$id,$actor['id'],$lead['status'],$next,$note]);
        lead_record_event(load_lead($id),$actor,'accepted_and_released',$note);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function lead_review_status(array $actor, string $id, array $input): void
{
    $pdo=db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT * FROM sales_leads WHERE id=? FOR UPDATE');$q->execute([$id]);$lead=$q->fetch();
        if (!$lead || !can_review_leads($actor) || lead_is_owner($actor,$lead)) throw new RuntimeException('Δεν μπορείτε να αξιολογήσετε αυτό το lead.');
        $next=(string)($input['status']??'');$note=trim((string)($input['review_note']??''));
        $allowed=!empty($lead['contacts_released_at'])?['fee_approved','commercial_discussion','won','lost']:['under_review','more_information','rejected'];
        if (!in_array($next,$allowed,true)) throw new RuntimeException('Η αποδοχή και αποκάλυψη γίνεται μόνο με το ειδικό κουμπί. Μετά την αποδοχή δεν επιτρέπεται απόρριψη ή επαναφορά σε pending.');
        if (in_array($next,['more_information','rejected','lost'],true) && mb_strlen($note)<10) throw new RuntimeException('Συμπληρώστε συγκεκριμένη αιτιολογία τουλάχιστον 10 χαρακτήρων.');
        if (in_array($lead['status'],['prospect','contacted','decision_maker_found','need_identified','qualification_in_progress'],true)) throw new RuntimeException('Η ευκαιρία δεν έχει υποβληθεί ακόμη από τον συνεργάτη.');
        $pdo->prepare('UPDATE sales_leads SET status=?,review_note=? WHERE id=?')->execute([$next,$note?:null,$id]);
        $pdo->prepare('INSERT INTO lead_status_history(id,lead_id,changed_by,old_status,new_status,note) VALUES(?,?,?,?,?,?)')->execute([uuid_v4(),$id,$actor['id'],$lead['status'],$next,$note?:'Ενημέρωση αξιολόγησης']);
        lead_record_event(load_lead($id),$actor,'review_decision',$note?:'Ενημέρωση αξιολόγησης');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function lead_set_commission_status(array $actor, string $leadId, string $commissionId, string $next, array $input=[]): void
{
    if (!can_view_all_sales_financials($actor) || !can_review_leads($actor)) throw new RuntimeException('Δεν έχετε δικαίωμα οικονομικής διαχείρισης.');
    $pdo=db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT * FROM sales_leads WHERE id=? FOR UPDATE');$q->execute([$leadId]);$lead=$q->fetch();
        if (!$lead || lead_is_owner($actor,$lead)) throw new RuntimeException('Δεν μπορείτε να εξοφλήσετε το δικό σας lead.');
        $q=$pdo->prepare('SELECT * FROM lead_commissions WHERE id=? AND lead_id=? FOR UPDATE');$q->execute([$commissionId,$leadId]);$row=$q->fetch();
        if (!$row || $row['beneficiary_user_id']!==$lead['submitted_by']) throw new RuntimeException('Μη έγκυρη προμήθεια ή δικαιούχος.');
        if ($next==='payable' && $row['status']==='earned') {
            $pdo->prepare("UPDATE lead_commissions SET status='payable' WHERE id=?")->execute([$commissionId]);
        } elseif ($next==='paid' && $row['status']==='payable') {
            $payoutDate=(string)($input['payout_date']??'');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$payoutDate);
            $payoutRef=trim((string)($input['payout_reference']??''));
            if(!$date||$date->format('Y-m-d')!==$payoutDate||$payoutDate>date('Y-m-d')||$payoutRef===''||mb_strlen($payoutRef)>255)throw new RuntimeException('Χρειάζονται ημερομηνία και αναφορά πληρωμής του συνεργάτη.');
            $pdo->prepare("UPDATE lead_commissions SET status='paid',paid_at=NOW(),payout_date=?,payout_reference=? WHERE id=?")->execute([$payoutDate,$payoutRef,$commissionId]);
        } else throw new RuntimeException('Δεν επιτρέπεται ακύρωση ή μείωση καταχωρισμένης αμοιβής. Καταγράψτε τη διαφωνία στο ιστορικό.');
        lead_record_event(load_lead($leadId),$actor,'commission_'.$next,'Προμήθεια '.$commissionId.' · €'.$row['commission_amount'].' · '.commission_status_label($next));
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
