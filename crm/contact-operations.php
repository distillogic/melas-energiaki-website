<?php
declare(strict_types=1);
if (!defined('CRM_ROOT')) { http_response_code(403); exit; }

/** Internal workflow only. No scheduler, customer messages or external registry lookup. */
function contact_ops_schema(): void
{
    static $ready=false; if ($ready) return;
    foreach ([
        "crm_contact_blocks"=>"token CHAR(64) PRIMARY KEY,active TINYINT NOT NULL,generation BIGINT UNSIGNED NOT NULL DEFAULT 1,reason VARCHAR(1000) NOT NULL,actor_id CHAR(36) NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        "crm_contact_checks"=>"subject_type VARCHAR(20) NOT NULL,subject_id VARCHAR(64) NOT NULL,contact_hash CHAR(64) NOT NULL,evidence VARCHAR(1000) NOT NULL,checked_by CHAR(36) NOT NULL,expires_at DATETIME NOT NULL,checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(subject_type,subject_id)",
        "crm_contact_events"=>"id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,subject_type VARCHAR(20) NOT NULL,subject_id VARCHAR(64) NOT NULL,actor_id CHAR(36) NOT NULL,action VARCHAR(40) NOT NULL,note VARCHAR(1000) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX subject_idx(subject_type,subject_id,id)",
        "crm_lead_signals"=>"lead_id CHAR(36) PRIMARY KEY,signals_json TEXT NOT NULL,evidence VARCHAR(1000) NOT NULL,actor_id CHAR(36) NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        "crm_reminder_actions"=>"user_id CHAR(36) NOT NULL,reminder_key CHAR(64) NOT NULL,state VARCHAR(10) NOT NULL,snoozed_until DATETIME NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(user_id,reminder_key)",
        "crm_operations_lock"=>"id TINYINT PRIMARY KEY"
    ] as $table=>$columns) db()->exec("CREATE TABLE IF NOT EXISTS $table ($columns) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec('INSERT IGNORE INTO crm_operations_lock(id) VALUES(1)');
    $ready=true;
}

function contact_ops_tokens(string $phone, string $email): array
{
    $tokens=[]; $email=mb_strtolower(trim($email));
    if (filter_var($email,FILTER_VALIDATE_EMAIL)) $tokens[]=hash('sha256','email:'.$email);
    // Local ten-digit Greek numbers and international +30 / 0030 are equivalent.
    $digits=preg_replace('/[^0-9]/','',$phone);
    if (str_starts_with($digits,'00')) $digits=substr($digits,2);
    if (strlen($digits)===10 && preg_match('/^[26]/',$digits)) $digits='30'.$digits;
    if (strlen($digits)>=8 && strlen($digits)<=15) $tokens[]=hash('sha256','phone:'.$digits);
    sort($tokens); return array_values(array_unique($tokens));
}
function contact_ops_blocked(array $tokens): bool
{
    contact_ops_schema(); if (!$tokens) return false;
    $q=db()->prepare('SELECT 1 FROM crm_contact_blocks WHERE active=1 AND token IN ('.implode(',',array_fill(0,count($tokens),'?')).') LIMIT 1');
    $q->execute($tokens); return (bool)$q->fetchColumn();
}
function contact_ops_subject(array $actor,string $type,string $id): array
{
    if (empty($actor['active'])) throw new RuntimeException('Απαιτείται ενεργός λογαριασμός.');
    if ($type==='lead') {
        $row=load_lead($id);
        if (!$row || !can_access_lead($actor,$row) || !can_view_lead_identity($actor,$row)) throw new RuntimeException('Δεν έχετε πρόσβαση στα στοιχεία αυτής της επαφής.');
        return ['type'=>$type,'id'=>$id,'name'=>$row['company_name'],'tokens'=>contact_ops_tokens((string)$row['contact_phone'],(string)$row['contact_email']),'row'=>$row];
    }
    if ($type==='company' && !crm_sales_restricted($actor) && ctype_digit($id)) {
        $q=db()->prepare('SELECT * FROM companies WHERE id=? AND deleted_at IS NULL');$q->execute([$id]);$row=$q->fetch();
        if ($row) return ['type'=>$type,'id'=>$id,'name'=>$row['name'],'tokens'=>contact_ops_tokens((string)$row['phone'],(string)$row['email']),'row'=>$row];
    }
    throw new RuntimeException('Η επαφή δεν είναι διαθέσιμη.');
}
function contact_ops_state(array $subject): array
{
    contact_ops_schema();
    $q=db()->prepare('SELECT * FROM crm_contact_checks WHERE subject_type=? AND subject_id=?');$q->execute([$subject['type'],$subject['id']]);$check=$q->fetch();
    $blocked=contact_ops_blocked($subject['tokens']);
    $valid=$subject['tokens'] && $check && hash_equals($check['contact_hash'],contact_ops_fingerprint($subject['tokens'])) && strtotime($check['expires_at'])>time();
    return ['blocked'=>$blocked,'cleared'=>!$blocked && (bool)$valid,'check'=>$check ?: null];
}
function contact_ops_fingerprint(array $tokens): string
{
    $versions=[];
    foreach($tokens as $token){$q=db()->prepare('SELECT generation FROM crm_contact_blocks WHERE token=?');$q->execute([$token]);$versions[]=$token.':'.(int)$q->fetchColumn();}
    return hash('sha256',implode('|',$versions));
}
function contact_ops_update(array $actor,string $type,string $id,array $input): void
{
    contact_ops_schema();
    $action=(string)($input['action']??'');
    if (!in_array($action,['block','unblock','clearance','revoke'],true)) throw new InvalidArgumentException('Άγνωστη ενέργεια.');
    $note=sales_text($input,'note',10,1000);
    if ($action!=='block' && !can_view_all_sales_financials($actor)) throw new RuntimeException('Μόνο ο ιδιοκτήτης και ο εξουσιοδοτημένος διαχειριστής καταχωρίζουν έλεγχο ή άρση αποκλεισμού.');
    if (in_array($action,['clearance','unblock'],true) && ($input['confirmed']??'')!=='1') throw new InvalidArgumentException('Απαιτείται επιβεβαίωση του τεκμηριωμένου ελέγχου.');
    $pdo=db();$pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM crm_operations_lock WHERE id=1 FOR UPDATE')->fetchColumn();
        $subject=contact_ops_subject($actor,$type,$id);
        if (!$subject['tokens']) throw new InvalidArgumentException('Προσθέστε έγκυρο τηλέφωνο ή email στην επαφή.');
        if ($action==='clearance') {
            if (contact_ops_blocked($subject['tokens'])) throw new InvalidArgumentException('Υπάρχει ενεργός αποκλεισμός. Ο έλεγχος δεν τον παρακάμπτει.');
            $expires=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($input['expires_at']??''));
            if (!$expires || $expires->format('Y-m-d\TH:i')!==($input['expires_at']??'') || $expires->getTimestamp()<=time() || $expires->getTimestamp()>time()+30*86400) throw new InvalidArgumentException('Η λήξη ελέγχου πρέπει να είναι στο μέλλον, έως 30 ημέρες.');
            $pdo->prepare('INSERT INTO crm_contact_checks(subject_type,subject_id,contact_hash,evidence,checked_by,expires_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE contact_hash=VALUES(contact_hash),evidence=VALUES(evidence),checked_by=VALUES(checked_by),expires_at=VALUES(expires_at),checked_at=NOW()')
                ->execute([$type,$id,contact_ops_fingerprint($subject['tokens']),$note,$actor['id'],$expires->format('Y-m-d H:i:s')]);
        } elseif ($action==='revoke') {
            $pdo->prepare('DELETE FROM crm_contact_checks WHERE subject_type=? AND subject_id=?')->execute([$type,$id]);
        } else {
            foreach ($subject['tokens'] as $token) $pdo->prepare('INSERT INTO crm_contact_blocks(token,active,reason,actor_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE active=VALUES(active),generation=generation+1,reason=VALUES(reason),actor_id=VALUES(actor_id)')->execute([$token,$action==='block'?1:0,$note,$actor['id']]);
            // Generation changes invalidate only checks containing a changed contact.
        }
        $pdo->prepare('INSERT INTO crm_contact_events(subject_type,subject_id,actor_id,action,note) VALUES(?,?,?,?,?)')->execute([$type,$id,$actor['id'],$action,$note]);
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Informational completeness only: NOT qualification, consent or commission approval. */
function contact_ops_lead_indicators(array $lead): array
{
    $d=json_decode((string)($lead['opportunity_data_json']??''),true)?:[];
    $checks=[
        'Τύπος ευκαιρίας'=>isset(opportunity_type_labels()[$lead['opportunity_type']??'']),
        'Περιοχή'=>trim((string)($lead['country_region']??''))!=='' || !empty($d['public_region']),
        'Σύνοψη συζήτησης'=>mb_strlen(trim((string)($lead['conversation_summary']??'')))>=20,
        'Επόμενο βήμα'=>trim((string)($lead['next_step']??''))!=='' && !empty($lead['follow_up_at']),
    ];
    if (is_panel_opportunity((string)($lead['opportunity_type']??''))) {
        $checks['Ποσότητα πάνελ']=(float)($d['panel_quantity']??0)>0;
        $checks['Ισχύς πάνελ']=(float)($d['panel_watts']??0)>0;
    } else $checks['Κατηγορία υπηρεσίας']=isset(service_category_labels()[$d['service_category']??'']);
    $intent=!empty($lead['contact_consent']) && (($d['sale_intent_confirmed']??'')==='yes' || ($lead['opportunity_type']??'')==='solar_service');
    return ['checks'=>$checks,'percent'=>(int)round(count(array_filter($checks))*100/count($checks)),
        'intent'=>$intent?'Δηλωμένη πρόθεση / αναμενόμενη επικοινωνία — απαιτείται επαλήθευση':'Η πρόθεση χρειάζεται διευκρίνιση'];
}
function contact_ops_rubric(): array
{
    return ['fit'=>['offering'=>'Η ανάγκη αντιστοιχεί σε προσφερόμενη υπηρεσία / αγορά πάνελ','scope'=>'Ποσότητα ή αντικείμενο εντός των τρεχόντων εταιρικών κριτηρίων','location'=>'Τοποθεσία και βασική δυνατότητα εξυπηρέτησης επιβεβαιωμένες','technical'=>'Βασικά τεχνικά στοιχεία και κατάσταση διαθέσιμα'],
        'intent'=>['authority'=>'Επιβεβαιωμένος αρμόδιος συνομιλητής','need'=>'Ρητή πρόθεση πώλησης ή συγκεκριμένη ανάγκη υπηρεσίας','timeline'=>'Συγκεκριμένος πραγματικός χρονικός ορίζοντας','next'=>'Συμφωνημένο συγκεκριμένο επόμενο βήμα']];
}
function contact_ops_score(array $signals): array
{
    $out=[];foreach(contact_ops_rubric() as $group=>$items){$yes=$known=0;foreach($items as $key=>$label){$value=$signals[$group.'_'.$key]??'unknown';if(in_array($value,['yes','no'],true))$known++;if($value==='yes')$yes++;}$out[$group]=['score'=>$yes*25,'known'=>$known,'total'=>4];}return $out;
}
function contact_ops_save_signals(array $actor,string $id,array $input): void
{
    contact_ops_schema();contact_ops_subject($actor,'lead',$id);
    $signals=[];foreach(contact_ops_rubric() as $group=>$items)foreach($items as $key=>$label){$value=$input[$group.'_'.$key]??'unknown';if(!in_array($value,['yes','no','unknown'],true))throw new InvalidArgumentException('Μη έγκυρη αξιολόγηση.');$signals[$group.'_'.$key]=$value;}
    $note=sales_text($input,'evidence',10,1000);$pdo=db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id FROM sales_leads WHERE id=? FOR UPDATE');$q->execute([$id]);contact_ops_subject($actor,'lead',$id);
        $pdo->prepare('INSERT INTO crm_lead_signals(lead_id,signals_json,evidence,actor_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE signals_json=VALUES(signals_json),evidence=VALUES(evidence),actor_id=VALUES(actor_id)')->execute([$id,json_encode($signals,JSON_THROW_ON_ERROR),$note,$actor['id']]);
        $pdo->prepare('INSERT INTO crm_contact_events(subject_type,subject_id,actor_id,action,note) VALUES(?,?,?,?,?)')->execute(['lead',$id,$actor['id'],'signals',$note.' · '.json_encode(contact_ops_score($signals),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function contact_ops_followup(array $actor,string $id,array $input): void
{
    contact_ops_schema();$note=sales_text($input,'follow_up_note',5,500);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($input['follow_up_at']??''));
    if (!$date || $date->format('Y-m-d\TH:i')!==($input['follow_up_at']??'') || $date->getTimestamp()<=time()) throw new InvalidArgumentException('Επίλεξε μελλοντική ημερομηνία και ώρα.');
    $pdo=db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT * FROM sales_leads WHERE id=? FOR UPDATE');$q->execute([$id]);$lead=$q->fetch();
        if (!$lead || !lead_is_owner($actor,$lead)) throw new RuntimeException('Το επόμενο βήμα προγραμματίζεται από τον αρχικό ιδιοκτήτη του lead.');
        if (in_array($lead['status'],['won','lost','rejected'],true)) throw new RuntimeException('Το lead έχει κλείσει.');
        $pdo->prepare('UPDATE sales_leads SET follow_up_at=?,follow_up_note=? WHERE id=?')->execute([$date->format('Y-m-d H:i:s'),$note,$id]);
        $pdo->prepare('INSERT INTO crm_contact_events(subject_type,subject_id,actor_id,action,note) VALUES(?,?,?,?,?)')->execute(['lead',$id,$actor['id'],'followup',$note]);
        $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function contact_ops_reminders(array $actor): array
{
    contact_ops_schema();ensure_lead_schema();
    $q=db()->prepare("SELECT id,lead_reference title,follow_up_at due_at,follow_up_note note,contact_phone phone,contact_email email FROM sales_leads WHERE submitted_by=? AND status NOT IN ('won','lost','rejected') AND follow_up_at IS NOT NULL ORDER BY follow_up_at,id");$q->execute([$actor['id']]);
    $rows=[];foreach($q->fetchAll() as $row){$row['type']='lead';$row['subject_id']=$row['id'];$row['url']='lead-operations.php?id='.$row['id'];$rows[]=$row;}
    if (!crm_sales_restricted($actor)) {
        $q=db()->prepare("SELECT c.id,co.name title,c.next_action_at due_at,c.next_action note,co.phone,co.email,co.id subject_id FROM communications c JOIN companies co ON co.id=c.company_id WHERE COALESCE(c.assigned_user_id,c.user_id)=? AND c.deleted_at IS NULL AND co.deleted_at IS NULL AND c.status NOT IN ('won','lost','archived') AND c.next_action_at IS NOT NULL ORDER BY c.next_action_at,c.id");$q->execute([$actor['id']]);
        foreach($q->fetchAll() as $row){$row['type']='communication';$row['url']='communication.php?id='.$row['id'];$rows[]=$row;}
    }
    $q=db()->prepare('SELECT * FROM crm_reminder_actions WHERE user_id=?');$q->execute([$actor['id']]);$marks=[];foreach($q->fetchAll() as $mark)$marks[$mark['reminder_key']]=$mark;
    $out=[];
    foreach($rows as $row) {
        $row['key']=hash('sha256',$row['type'].'|'.$row['id'].'|'.$row['due_at'].'|'.$row['note']);$mark=$marks[$row['key']]??null;
        if (($mark['state']??'')==='done') continue;
        $row['display_at']=($mark['state']??'')==='snoozed'?$mark['snoozed_until']:$row['due_at'];
        $row['snoozed']=strtotime($row['display_at'])>time() && ($mark['state']??'')==='snoozed';
        $subject=['type'=>$row['type']==='lead'?'lead':'company','id'=>(string)$row['subject_id'],'tokens'=>contact_ops_tokens((string)$row['phone'],(string)$row['email'])];
        $row['contact_state']=contact_ops_state($subject);unset($row['phone'],$row['email']);$out[]=$row;
    }
    usort($out,fn($a,$b)=>strcmp($a['display_at'],$b['display_at']));return $out;
}
function contact_ops_reminder_action(array $actor,array $input): void
{
    $key=(string)($input['key']??'');$action=(string)($input['action']??'');
    $found=false;foreach(contact_ops_reminders($actor) as $r)if(hash_equals($r['key'],$key))$found=true;
    if (!$found || !in_array($action,['done','snoozed'],true)) throw new InvalidArgumentException('Η υπενθύμιση άλλαξε ή δεν ανήκει στον λογαριασμό σου.');
    $date=null;
    if ($action==='snoozed') {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($input['snoozed_until']??''));
        if (!$d || $d->format('Y-m-d\TH:i')!==($input['snoozed_until']??'') || $d->getTimestamp()<=time() || $d->getTimestamp()>time()+30*86400) throw new InvalidArgumentException('Επίλεξε μελλοντική αναβολή έως 30 ημέρες.');
        $date=$d->format('Y-m-d H:i:s');
    }
    db()->prepare('INSERT INTO crm_reminder_actions(user_id,reminder_key,state,snoozed_until) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),snoozed_until=VALUES(snoozed_until)')->execute([$actor['id'],$key,$action,$date]);
}
function contact_ops_banner(array $actor,string $type,string $id): void
{
    try {$subject=contact_ops_subject($actor,$type,$id);}catch(RuntimeException){echo '<section class="card"><p>Η επαφή δεν είναι διαθέσιμη για έλεγχο. Ζήτησε έλεγχο από τον διαχειριστή πριν επικοινωνήσεις.</p></section>';return;}
    $state=contact_ops_state($subject);
    $text=$state['blocked']?'DNC — Μην πραγματοποιήσεις προωθητική επικοινωνία.':($state['cleared']?'Υπάρχει καταχωρισμένος έλεγχος κλήσης.':'Πριν από κλήση απαιτείται καταχώριση ελέγχου επικοινωνίας.');
    echo '<section class="card"><p>'.e($text).'</p><a class="button" href="'.e(crm_url('lead-operations.php?type='.$type.'&id='.urlencode($id))).'">Έλεγχος επικοινωνίας & επόμενο βήμα →</a></section>';
}
function contact_ops_proposal_blocked(array $proposal,string $email): bool
{
    $q=db()->prepare('SELECT phone,email FROM companies WHERE id=?');$q->execute([$proposal['company_id']??0]);$company=$q->fetch()?:[];
    $data=json_decode((string)($proposal['data_json']??'{}'),true)?:[];
    $tokens=array_unique(array_merge(contact_ops_tokens((string)($company['phone']??''),(string)($company['email']??'')),contact_ops_tokens((string)($data['client_phone']??''),$email)));
    return contact_ops_blocked(array_values($tokens));
}
