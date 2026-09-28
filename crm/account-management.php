<?php
declare(strict_types=1);
// Included only after authentication. Never return personnel information directly.
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
function account_is_owner_email(string $email):bool {
    return strtolower(trim($email)) === 'melas@distillogic.gr';
}
function ensure_account_management_schema():void {
    personnel_schema();
    account_session_generation('');
    db()->exec("CREATE TABLE IF NOT EXISTS user_lifecycle_events (id CHAR(36) PRIMARY KEY, actor_id CHAR(36) NOT NULL, subject_id CHAR(36) NOT NULL, action VARCHAR(40) NOT NULL, reason TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX subject_events(subject_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS user_work_profiles (user_id CHAR(36) PRIMARY KEY, details LONGTEXT NULL, deleted_at DATETIME NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT work_profile_user_fk FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS user_management_audit (id CHAR(36) PRIMARY KEY, actor_id CHAR(36) NOT NULL, subject_id CHAR(36) NOT NULL, action VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function account_work_fields():array {
    return ['employee_code'=>['Κωδικός συνεργάτη','text',60], 'job_title'=>['Θέση / ειδικότητα','text',150], 'department'=>['Τμήμα / ομάδα','text',150], 'reports_to'=>['Προϊστάμενος','text',150], 'work_phone'=>['Επαγγελματικό τηλέφωνο','tel',60], 'engagement_type'=>['Σχέση συνεργασίας (εργαζόμενος / εξωτερικός συνεργάτης)','text',100], 'start_date'=>['Ημερομηνία έναρξης','date',10], 'end_date'=>['Ημερομηνία λήξης (αν υπάρχει)','date',10], 'work_location'=>['Τόπος / μοντέλο εργασίας','text',150], 'work_schedule'=>['Ωράριο / ζώνη ώρας','text',150], 'skills'=>['Δεξιότητες / πιστοποιήσεις','textarea',2000], 'equipment'=>['Εταιρικός εξοπλισμός / αριθμοί παγίων','textarea',2000], 'notes'=>['Επαγγελματικές σημειώσεις','textarea',2000]];
}
function account_apply_change(array $actor,?array $target,bool $isNew,string $action,array $input):void {
    if(!can_manage_accounts($actor))throw new InvalidArgumentException('Δεν έχετε δικαίωμα διαχείρισης χρηστών.');
    if(!in_array($action,['save','deactivate','archive','reactivate','restore'],true))throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
    if($isNew&&$action!=='save')throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
    // Quick creation uses the current management session + the page's CSRF
    // check. Existing-account edits and lifecycle actions still reauthenticate.
    start_crm_session();
    if(!$isNew)personnel_reauth($actor,$input);
    $pdo=db();$pdo->beginTransaction();
    try {
        // Serialise management changes, including protection of privileged identities.
        $lock=$pdo->prepare('SELECT id,email,role,active,password_hash FROM users WHERE id=? FOR UPDATE');$lock->execute([$actor['id']]);
        $freshActor=$lock->fetch();if(!$freshActor||!can_manage_accounts($freshActor))throw new InvalidArgumentException('Η πρόσβασή σας άλλαξε.');
        if(($_SESSION['user_id']??'')!==$freshActor['id'])throw new InvalidArgumentException('Απαιτείται ενεργή συνεδρία διαχειριστή.');
        $generation=$pdo->prepare('SELECT generation FROM user_access_state WHERE user_id=? FOR UPDATE');$generation->execute([$freshActor['id']]);
        if((int)$generation->fetchColumn()!==(int)($_SESSION['access_generation']??-1))throw new InvalidArgumentException('Η συνεδρία σας έχει ανακληθεί. Συνδεθείτε ξανά.');
        if(!$isNew&&!password_verify((string)($input['actor_password']??''),$freshActor['password_hash']))throw new InvalidArgumentException('Ο κωδικός διαχειριστή άλλαξε. Συνδεθείτε ξανά.');
        if(!$isNew){
            $lock=$pdo->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$lock->execute([$target['id']]);$target=$lock->fetch();
            if(!$target)throw new InvalidArgumentException('Ο χρήστης δεν βρέθηκε.');
        }
        $id=$isNew?uuid_v4():(string)$target['id'];
        $protected=!$isNew&&account_is_owner_email($target['email']);
        if($action!=='save'){
            if($protected||$id===$actor['id'])throw new InvalidArgumentException('Δεν επιτρέπεται διαγραφή ή απενεργοποίηση λογαριασμού διοίκησης ή του δικού σας λογαριασμού.');
            if(strcasecmp(trim((string)($input['confirm_email']??'')),$target['email'])!==0)throw new InvalidArgumentException('Πληκτρολογήστε σωστά το email του χρήστη.');
            $reason=trim((string)($input['reason']??''));
            if(mb_strlen($reason)<5||mb_strlen($reason)>2000)throw new InvalidArgumentException('Συμπληρώστε αιτιολογία 5–2000 χαρακτήρων.');
            $q=$pdo->prepare('SELECT deleted_at FROM user_work_profiles WHERE user_id=?');$q->execute([$id]);$archived=(bool)$q->fetchColumn();
            if(($archived&&$action!=='restore')||(!$archived&&$action==='restore')||($action==='reactivate'&&$target['active'])||($action==='deactivate'&&!$target['active']))throw new InvalidArgumentException('Η κατάσταση έχει αλλάξει. Ανανεώστε την καρτέλα.');
            $onboarding=personnel_record($id);
            // Reactivation restores credentials, not approval to bypass documents/training.
            if($onboarding&&onboarding_required($id)&&in_array($action,['archive','restore','deactivate'],true)){
                $pdo->prepare('UPDATE personnel_onboarding SET released_at=NULL,released_by=NULL,onboarding_cycle=onboarding_cycle+? WHERE user_id=?')->execute([$action==='restore'?1:0,$id]);
            }
            $pdo->prepare('UPDATE users SET active=? WHERE id=?')->execute([$action==='reactivate'?1:0,$id]);
            $pdo->prepare('INSERT INTO user_work_profiles(user_id,deleted_at) VALUES(?,?) ON DUPLICATE KEY UPDATE deleted_at=VALUES(deleted_at)')->execute([$id,$action==='archive'?date('Y-m-d H:i:s'):null]);
            $pdo->prepare('INSERT INTO user_access_state(user_id,generation) VALUES(?,1) ON DUPLICATE KEY UPDATE generation=generation+1')->execute([$id]);
            $pdo->prepare('INSERT INTO user_lifecycle_events(id,actor_id,subject_id,action,reason) VALUES(?,?,?,?,?)')->execute([uuid_v4(),$actor['id'],$id,$action,$reason]);
            if($action==='archive')personnel_audit($actor,$id,null,'departure_pending','Αποχώρηση: απαιτείται έλεγχος και ολοκλήρωση πρωτοκόλλου παράδοσης / παραλαβής. Η πρόσβαση έχει διακοπεί ανεξάρτητα από την υπογραφή.');
        }else{
            $name=trim((string)($input['name']??''));$email=strtolower(trim((string)($input['email']??'')));$role=(string)($input['role']??'employee');$active=isset($input['active'])?1:0;
            // Access changes must use the reasoned, audited lifecycle actions.
            if(!$isNew)$active=(int)$target['active'];
            if($isNew){
                // Three-field creation never accepts elevated roles or an
                // inactive/onboarding state from old or forged form fields.
                if(isset($input['role'])&&$input['role']!=='employee')throw new InvalidArgumentException('Ο νέος χρήστης δημιουργείται με απλά δικαιώματα. Αλλάξτε τον ρόλο αργότερα από την καρτέλα.');
                $active=1;$role='employee';
            }
            if($protected){$email=$target['email'];$role=$target['role'];$active=1;}
            elseif(in_array($email,['melas@distillogic.gr','sophianos@distillogic.gr'],true)&&($isNew||$email!==$target['email']))throw new InvalidArgumentException('Αυτό το email διοίκησης είναι δεσμευμένο.');
            if($name===''||mb_strlen($name)>150||strlen($email)>320||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,['employee','partner','manager','technical','admin'],true))throw new InvalidArgumentException('Ελέγξτε όνομα, email και ρόλο CRM.');
            if(!$isNew){$q=$pdo->prepare('SELECT deleted_at FROM user_work_profiles WHERE user_id=?');$q->execute([$id]);if($q->fetchColumn())throw new InvalidArgumentException('Επαναφέρετε πρώτα την αρχειοθετημένη καρτέλα (παραμένει ανενεργή).');}
            $password=(string)($input['password']??'');
            if(!$isNew&&!$protected&&($name!==$target['name']||$email!==$target['email'])&&personnel_record($id)){
                $pdo->prepare('UPDATE personnel_onboarding SET onboarding_cycle=onboarding_cycle+1,released_at=NULL,released_by=NULL WHERE user_id=?')->execute([$id]);
                if(onboarding_required($id))$pdo->prepare('UPDATE personnel_access_rules SET preapproved_at=NULL,preapproved_by=NULL,checklist_json=NULL WHERE user_id=?')->execute([$id]);
                personnel_audit($actor,$id,null,'identity_changed','New document cycle after identity change; CRM access is governed separately.');
            }
            if($isNew||$password!==''){
                if($protected&&$id!==$actor['id'])throw new InvalidArgumentException('Ο άλλος λογαριασμός διοίκησης αλλάζει μόνος του τον κωδικό του.');
                if(strlen($password)<12||strlen($password)>72||str_contains($password,"\0")||(!$isNew&&$password!==(string)($input['password_confirm']??'')))throw new InvalidArgumentException($isNew?'Ο κωδικός πρέπει να έχει 12–72 bytes.':'Ο νέος κωδικός πρέπει να έχει 12–72 bytes και να συμφωνεί με την επιβεβαίωση.');
            }
            $details=[];
            if(!$isNew){$q=$pdo->prepare('SELECT details FROM user_work_profiles WHERE user_id=?');$q->execute([$id]);$stored=json_decode((string)$q->fetchColumn(),true);if(is_array($stored))$details=$stored;}
            foreach(account_work_fields() as $key=>[$label,$type,$limit]){
                $value=$isNew?'':trim((string)($input[$key]??$details[$key]??''));if(mb_strlen($value)>$limit)throw new InvalidArgumentException('Υπερβολικά μεγάλο πεδίο: '.$label);
                if($type==='date'&&$value!==''){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Μη έγκυρη ημερομηνία: '.$label);}
                $details[$key]=$value;
            }
            if($details['start_date']&&$details['end_date']&&$details['end_date']<$details['start_date'])throw new InvalidArgumentException('Η λήξη δεν μπορεί να προηγείται της έναρξης.');
            if($isNew){
                $applicantId=is_string($input['applicant_id']??null)?$input['applicant_id']:'';
                if($applicantId!==''){
                    if(!can_view_all_sales_financials($actor))throw new InvalidArgumentException('Απαιτείται αρμόδιος διαχειριστής υποψηφιοτήτων.');
                    $q=$pdo->prepare("SELECT id,name,email FROM sales_applicants WHERE id=? AND status='training_approved' FOR UPDATE");$q->execute([$applicantId]);$applicant=$q->fetch();
                    if(!$applicant||strcasecmp($applicant['email'],$email)!==0)throw new InvalidArgumentException('Η αίτηση δεν είναι εγκεκριμένη για εκπαίδευση ή άλλαξε το email.');
                }
                $pdo->prepare('INSERT INTO users(id,name,email,role,active,password_hash) VALUES(?,?,?,?,?,?)')->execute([$id,$name,$email,$role,$active,password_hash($password,PASSWORD_DEFAULT)]);
                onboarding_new_user($id,$actor);
                if($applicantId!==''){
                    $pdo->prepare("UPDATE sales_applicants SET status='closed',retention_closed_at=COALESCE(retention_closed_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$applicantId]);
                    sales_portal_event($actor,'applicant',$applicantId,'account_created',['user_id'=>$id,'access'=>'academy_only']);
                }
            }
            else{
                $pdo->prepare('UPDATE users SET name=?,email=?,role=?,active=? WHERE id=?')->execute([$name,$email,$role,$active,$id]);
                if($password!=='')$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
                if($password!==''||$name!==$target['name']||$email!==$target['email']||$role!==$target['role']){
                    $pdo->prepare('INSERT INTO user_access_state(user_id,generation) VALUES(?,1) ON DUPLICATE KEY UPDATE generation=generation+1')->execute([$id]);
                }
            }
            $pdo->prepare('INSERT INTO user_work_profiles(user_id,details) VALUES(?,?) ON DUPLICATE KEY UPDATE details=VALUES(details)')->execute([$id,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            if($isNew)$pdo->prepare('INSERT INTO user_lifecycle_events(id,actor_id,subject_id,action,reason) VALUES(?,?,?,?,?)')->execute([uuid_v4(),$actor['id'],$id,'create_pending','Νέος χρήστης: πρόσβαση Academy, μετά εκπαιδευτική αξιολόγηση, έγγραφα και τελική έγκριση CRM. Τα υποχρεωτικά εργασιακά έγγραφα μισθωτού μπορούν να εκδοθούν πριν από την Academy (Melas v36).']);
        }
        $pdo->prepare('INSERT INTO user_management_audit(id,actor_id,subject_id,action) VALUES(?,?,?,?)')->execute([uuid_v4(),$actor['id'],$id,$isNew?'create':$action]);
        $pdo->commit();
        $GLOBALS['activity_account_change'] = ['id'=>$id,'new'=>$isNew];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
