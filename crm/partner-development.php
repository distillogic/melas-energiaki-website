<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
function sales_partner_stages(): array
{
    return ['candidate'=>'Υποψήφιος','screening'=>'Αρχικός έλεγχος','interview'=>'Συνέντευξη','training'=>'Εκπαίδευση',
        'exam_passed'=>'Επιτυχία εξέτασης','supervised'=>'Εικονική πρακτική','documents'=>'Έγγραφα & υπογραφές','approved'=>'Εγκεκριμένος συνεργάτης',
        'active'=>'Ενεργός συνεργάτης','suspended'=>'Παύση εμπορικής δραστηριότητας','terminated'=>'Λήξη συνεργασίας'];
}
function sales_partner_ready(array $user): bool
{
    if(can_view_all_sales_financials($user))return true;
    $q=db()->prepare('SELECT stage FROM sales_partner_profiles WHERE user_id=?');$q->execute([$user['id']]);$stage=$q->fetchColumn();
    return $stage===false||in_array($stage,['supervised','approved','active'],true);
}
/** Read only the Academy's approval evidence. Never copy credentials or load its bootstrap. */
function sales_academy_evidence(string $crmId): array
{
    $path=dirname(__DIR__).'/academy/config.php';
    if(!is_file($path))throw new RuntimeException('Ανέβασε και ρύθμισε πρώτα την Academy.');
    $config=require $path;$c=$config['database']??[];
    // Load only the sibling Academy's pure curriculum, never its sessions/bootstrap.
    if(!defined('ACADEMY_ROOT'))define('ACADEMY_ROOT',dirname($path));
    require_once dirname($path).'/content.php';
    require_once dirname($path).'/final-challenges.php';
    $currentHash=hash('sha256',json_encode([array_map(fn($lesson)=>[$lesson['id'],$lesson['version'],$lesson['quiz']],academy_lessons()),academy_final_cases(),'balanced-32-v33'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $prefix=($c['mode']??'dedicated')==='shared'?'academy_school_':'academy_';
    $pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$c['host'],(int)($c['port']??3306),$c['name']),$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $q=$pdo->prepare("SELECT id FROM {$prefix}users WHERE crm_user_id=? AND active=1");$q->execute([$crmId]);$id=$q->fetchColumn();
    if(!$id)return ['exam'=>false,'approved'=>false];
    // Recheck lesson completion under the Academy's first-attempt policy.
    // Historical final exams stay intact but cannot bypass a now-incomplete course.
    $policy=dirname($path).'/attempt-policy.php';
    if(is_file($policy)){
        require_once $policy;$attemptData=academy_attempt_data($id,$pdo,$prefix);
        foreach(academy_lessons() as $lessonId=>$lesson){
            if(!academy_attempt_state_from($attemptData,$lessonId,$lesson['version'])['completed_at'])return ['exam'=>false,'approved'=>false];
        }
    }
    $q=$pdo->prepare("SELECT id,submitted_at FROM {$prefix}final_exams WHERE user_id=? AND status='passed' AND content_hash=? ORDER BY submitted_at DESC LIMIT 1");$q->execute([$id,$currentHash]);$exam=$q->fetch();
    $q=$pdo->prepare("SELECT id,outcome,created_at,total_score,threshold_score,critical_failure,roleplay_passed,supervised_calls,rubric_version,target_track FROM {$prefix}assessments WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 1");$q->execute([$id]);$assessment=$q->fetch();
    $practical=true;$version=(int)($assessment['rubric_version']??0);
    $minimum=$version>=2?(($assessment['target_track']??'')==='team_leader'?90:65):(($assessment['target_track']??'')==='team_leader'?85:75);
    if($version>=3){
        $callPolicy=dirname($path).'/call-evidence.php';
        if(!in_array($version,[3,4],true)||!is_file($callPolicy))$practical=false;
        else{require_once $callPolicy;$practical=academy_call_assessment_proof($pdo,$prefix,$id,$assessment['id']);}
    }
    return ['exam'=>(bool)$exam,'exam_id'=>$exam['id']??null,'assessment_id'=>$assessment['id']??null,
        'approved'=>$exam&&$assessment&&in_array($assessment['outcome'],['approved','approved_with_supervision'],true)&&$assessment['created_at']>=$exam['submitted_at']
            &&(int)$assessment['total_score']>=max($minimum,(int)$assessment['threshold_score'])&&!$assessment['critical_failure']
            &&$assessment['roleplay_passed']&&(int)$assessment['supervised_calls']>=3&&$practical];
}
function sales_update_partner(array $actor,array $input): void
{
    personnel_schema();
    sales_require_manager($actor);$id=sales_text($input,'user_id',1,36);$stage=sales_text($input,'stage',1,40);$notes=sales_text($input,'notes',20,5000);$review=sales_text($input,'review_at',0,10);
    if(!isset(sales_partner_stages()[$stage]))throw new InvalidArgumentException('Μη έγκυρο στάδιο.');
    if($id===$actor['id'])throw new RuntimeException('Η προσωπική αξιολόγηση καταχωρίζεται από άλλον διαχειριστή.');
    if($review!==''){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$review);if(!$date||$date->format('Y-m-d')!==$review)throw new InvalidArgumentException('Μη έγκυρη ημερομηνία ανασκόπησης.');}
    $evidence=[];
    if(in_array($stage,['exam_passed','supervised','documents','approved','active'],true)){
        $evidence=sales_academy_evidence($id);
        if(!$evidence['exam']||(in_array($stage,['documents','approved','active'],true)&&!$evidence['approved']))throw new RuntimeException('Δεν υπάρχει η απαιτούμενη επιτυχής εξέταση / τελική αξιολόγηση στην Academy.');
    }
    $pdo=db();$pdo->beginTransaction();
    try{$q=$pdo->prepare('SELECT id,email FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$target=$q->fetch();if(!$target)throw new InvalidArgumentException('Ο χρήστης δεν βρέθηκε.');
        if(onboarding_required($id)){
            if(in_array($stage,['approved','active'],true)&&!melas_onboarding_documents_ready($pdo,$id))throw new RuntimeException('Η Academy προηγείται, αλλά για ενεργοποίηση CRM χρειάζονται και προέγκριση, υπογεγραμμένη σύμβαση και NDA με CEO approval, τελικά PDF και αποδοχή των δύο πολιτικών.');
            if(in_array($stage,['approved','active'],true)){
                $pdo->prepare('UPDATE personnel_onboarding SET released_at=NOW(),released_by=? WHERE user_id=?')->execute([$actor['id'],$id]);
                personnel_audit($actor,$id,null,'crm_released',json_encode($evidence,JSON_THROW_ON_ERROR));
            }else{
                $pdo->prepare('UPDATE personnel_onboarding SET released_at=NULL,released_by=NULL WHERE user_id=?')->execute([$id]);
            }
        }
        if(in_array(strtolower($target['email']),['melas@distillogic.gr','sophianos@distillogic.gr'],true))throw new RuntimeException('Οι λογαριασμοί διοίκησης δεν περιορίζονται από αυτή τη ροή.');
        $q=$pdo->prepare('SELECT * FROM sales_partner_profiles WHERE user_id=? FOR UPDATE');$q->execute([$id]);$previous=$q->fetch();
        $pdo->prepare('INSERT INTO sales_partner_profiles(user_id,stage,notes,review_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE stage=VALUES(stage),notes=VALUES(notes),review_at=VALUES(review_at)')->execute([$id,$stage,$notes,$review?:null]);
        sales_portal_event($actor,'user',$id,'training_stage',['previous'=>$previous,'stage'=>$stage,'notes'=>$notes,'evidence'=>$evidence]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sales_assign_account(array $actor,array $input): void
{
    sales_require_manager($actor);$company=sales_text($input,'company_id',1,20);$owner=sales_text($input,'owner_id',1,36);$reason=sales_text($input,'reason',20,3000);
    if(!ctype_digit($company))throw new InvalidArgumentException('Μη έγκυρη εταιρεία.');
    $pdo=db();$pdo->beginTransaction();
    try{$q=$pdo->prepare('SELECT id FROM companies WHERE id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$company]);if(!$q->fetchColumn())throw new InvalidArgumentException('Δεν βρέθηκε ενεργή εταιρεία.');
        $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND active=1');$q->execute([$owner]);if(!$q->fetchColumn())throw new InvalidArgumentException('Ο συνεργάτης δεν είναι ενεργός.');
        $q=$pdo->prepare('SELECT * FROM sales_account_owners WHERE company_id=? FOR UPDATE');$q->execute([$company]);$old=$q->fetch();
        $pdo->prepare('INSERT INTO sales_account_owners(company_id,owner_id,assigned_by,reason) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE owner_id=VALUES(owner_id),assigned_by=VALUES(assigned_by),reason=VALUES(reason),assigned_at=NOW()')->execute([$company,$owner,$actor['id'],$reason]);
        sales_portal_event($actor,'company',$company,'account_owner',['previous'=>$old,'owner'=>$owner,'reason'=>$reason,'lead_owners_unchanged'=>true]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sales_publish_toolkit(array $actor,array $input): string
{
    sales_require_manager($actor);$title=sales_text($input,'title',5,180);$version=sales_text($input,'version_label',1,50);$body=sales_text($input,'body',40,30000);$category=sales_text($input,'category',1,30);
    if(!in_array($category,['script','email','linkedin','policy','presentation','checklist'],true))throw new InvalidArgumentException('Μη έγκυρη κατηγορία.');
    $id=uuid_v4();db()->prepare('INSERT INTO sales_toolkit_versions(id,category,title,version_label,body,published_by) VALUES(?,?,?,?,?,?)')->execute([$id,$category,$title,$version,$body,$actor['id']]);return $id;
}
function sales_acknowledge_toolkit(array $actor,string $id): void
{
    if(!sales_partner_ready($actor)&&!can_view_all_sales_financials($actor))throw new RuntimeException('Το toolkit διατίθεται στους εγκεκριμένους / εποπτευόμενους συνεργάτες.');
    $q=db()->prepare('SELECT id FROM sales_toolkit_versions WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Δεν βρέθηκε έκδοση.');
    // Receipt of information only, NOT a digital signature or an access condition.
    db()->prepare('INSERT IGNORE INTO sales_policy_receipts(user_id,version_id) VALUES(?,?)')->execute([$actor['id'],$id]);
}
