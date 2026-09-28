<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}

/** Shared POST preflight. Routes must call this immediately after CSRF validation. */
function contract_lock(string $id):bool
{
    $pdo=db();$name='crm-document-'.$id;
    $q=$pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$name]);
    if((int)$q->fetchColumn()!==1)return false;
    register_shutdown_function(static function()use($pdo,$name):void{try{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);}catch(Throwable $ignored){}});
    return true;
}

function contract_post_preflight(string &$action,array &$errors,?array &$document,string $type,array $user):void
{
    if(in_array($type,['msa','dpa','sow'],true)){
        $errors[]='Το παλιό πρότυπο λογισμικού δεν έχει εγκριθεί για MELAS ENERGEIAKI. Το ιστορικό παραμένει διαθέσιμο για ανάγνωση, αλλά έκδοση, αλλαγή, έγκριση και αποστολή νέου εγγράφου έχουν ανασταλεί. Οι εταιρικές προτάσεις και τα χωριστά έγγραφα προσωπικού συνεχίζουν κανονικά.';
        $action='blocked';return;
    }
    if($action==='save'){
        foreach(['effective_date','proposal_date','start_date','completion_date'] as $field){
            $value=(string)($_POST[$field]??'');
            if($value==='')continue;
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if(!$date||$date->format('Y-m-d')!==$value){$errors[]='Ελέγξτε τις ημερομηνίες του εγγράφου.';$action='blocked';return;}
        }
    }
    if(!$document)return;
    if(!contract_lock((string)$document['id'])){$errors[]='Το έγγραφο ενημερώνεται. Δοκιμάστε ξανά.';$action='blocked';return;}
    $fresh=db()->prepare('SELECT * FROM company_documents WHERE id=?');$fresh->execute([$document['id']]);$row=$fresh->fetch();
    if(!$row){$errors[]='Το έγγραφο δεν βρέθηκε.';$action='blocked';return;}
    $document=array_merge($document,$row);
    if(in_array($action,['save','upload_client_signed','upload_final_pdf'],true)
        && (!empty($document['approved_at']) || ($action==='save' && (!empty($document['client_signed_at']) || in_array($document['document_status'],['accepted','sent','active'],true))))){
        $errors[]='Το έγγραφο έχει υπογραφή ή έγκριση και δεν αντικαθίσταται. Δημιουργήστε νέο έγγραφο για αναθεώρηση, ώστε να διατηρηθεί το ιστορικό.';
        $action='blocked';return;
    }
    if(contract_action_requires_prerequisites($action)){
        $missing=contract_prerequisite_errors((int)$document['company_id'],$type);
        if($missing){$errors=array_merge($errors,$missing);$action='blocked';return;}
    }
    if(in_array($action,['request_approval','verify_approval','send_final'],true)&&in_array($document['document_status'],['expired','terminated','rejected','fully_signed_test'],true)){
        $errors[]='Η κατάσταση του εγγράφου δεν επιτρέπει έγκριση ή αποστολή.';$action='blocked';return;
    }
    if($action==='request_approval'&&!empty($document['approval_requested_at'])&&strtotime($document['approval_requested_at'])>time()-60){
        $errors[]='Περιμένετε ένα λεπτό πριν ζητήσετε νέο κωδικό.';$action='blocked';return;
    }
    if($action==='mark_active'&&!contract_document_complete($document,$type)){
        $errors[]='Απαιτείται πρώτα η πλήρως υπογεγραμμένη έκδοση.';$action='blocked';return;
    }
    if($action==='send_final'&&in_array($type,['msa','dpa','sow'],true)){
        $action='delivery_handled';
        $data=json_decode((string)$document['data_json'],true)?:[];
        $client=trim((string)($data['client_email']??''));
        $company=trim((string)($data['distillogic_signer_email']??'melas@distillogic.gr'));
        if(!contract_document_complete($document,$type)||!filter_var($client,FILTER_VALIDATE_EMAIL)||!filter_var($company,FILTER_VALIDATE_EMAIL)||strcasecmp($client,trim((string)($_POST['send_email_confirm']??'')))!==0){$errors[]='Απαιτείται πλήρως υπογεγραμμένο PDF και σωστή επιβεβαίωση email.';return;}
        $attachment=[['filename'=>(string)$document['final_signed_file_name'],'mime'=>'application/pdf','content'=>(string)$document['final_signed_pdf']]];
        $all=true;
        foreach(array_unique([$client,$company]) as $recipient){
            $q=db()->prepare("SELECT delivery_status FROM company_document_deliveries WHERE document_id=? AND recipient_email=?");$q->execute([$document['id'],$recipient]);
            if($q->fetchColumn()==='sent')continue;
            $mail='<div style="font-family:Arial"><h2>DISTILLOGIC — '.e(strtoupper($type)).'</h2><p>Please find attached the fully signed agreement.</p><p>Reference: '.e($document['document_reference']).'</p></div>';
            try{$sent=send_crm_email($recipient,'DISTILLOGIC '.strtoupper($type).' — '.$document['document_reference'],$mail,$attachment);}catch(Throwable $error){$sent=false;}
            db()->prepare('INSERT INTO company_document_deliveries(id,document_id,requested_by,recipient_email,recipient_role,delivery_status,sent_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE delivery_status=VALUES(delivery_status),sent_at=VALUES(sent_at),requested_by=VALUES(requested_by)')->execute([uuid_v4(),$document['id'],$user['id'],$recipient,$recipient===$client?'client':'distillogic',$sent?'sent':'failed',$sent?date('Y-m-d H:i:s'):null]);
            if(!$sent)$all=false;
        }
        if($all){db()->prepare("UPDATE company_documents SET document_status='sent' WHERE id=?")->execute([$document['id']]);flash('success','Το τελικό PDF στάλθηκε και στους δύο συμβαλλομένους.');redirect_to($type.'.php?id='.urlencode($document['id']));}
        $errors[]='Δεν ολοκληρώθηκαν όλες οι αποστολές. Δοκιμάστε ξανά: οι επιτυχείς παραλήπτες δεν θα λάβουν δεύτερο αντίγραφο.';return;
    }
    if($action!=='verify_approval')return;
    $action='approval_handled';
    $pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM company_documents WHERE id=? FOR UPDATE');$q->execute([$document['id']]);$current=$q->fetch();
        $code=trim((string)($_POST['approval_code']??''));
        $source=$type==='proposal'?'final_signed_pdf':'client_signed_pdf';
        $reason='';
        if(!$current||empty($current[$source])||!empty($current['approved_at']))$reason='Δεν υπάρχει PDF προς έγκριση ή έχει ήδη εγκριθεί.';
        elseif(empty($current['approval_code_hash'])||empty($current['approval_code_expires_at']))$reason='Ζητήστε πρώτα κωδικό CEO.';
        elseif((int)$current['approval_attempts']>=5)$reason='Έγιναν πέντε λανθασμένες προσπάθειες. Ζητήστε νέο κωδικό.';
        elseif(strtotime($current['approval_code_expires_at'])<time())$reason='Ο κωδικός έληξε. Ζητήστε νέο κωδικό.';
        elseif(!preg_match('/^[0-9]{6}$/',$code)||!password_verify($code,$current['approval_code_hash'])){
            $pdo->prepare('UPDATE company_documents SET approval_attempts=approval_attempts+1 WHERE id=?')->execute([$current['id']]);
            $reason='Ο κωδικός δεν είναι σωστός.';
        }
        if($reason!==''){$pdo->commit();$errors[]=$reason;return;}
        $pdo->prepare('UPDATE company_documents SET document_status=?,approved_at=NOW(),approval_confirmed_by=?,approval_code_hash=NULL,approval_code_expires_at=NULL,approval_attempts=0 WHERE id=?')->execute([$type==='proposal'?'approved_pdf':'approval_pending',$user['id'],$current['id']]);
        $pdo->commit();
        flash('success','Η έγκριση CEO καταγράφηκε για το αποθηκευμένο PDF.');
        redirect_to(($type==='proposal'?'proposal.php':'document-finalize.php').'?id='.urlencode($current['id']));
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();error_log('Contract approval failed: '.get_class($error));$errors[]='Η έγκριση δεν αποθηκεύτηκε. Δοκιμάστε ξανά.';}
}
