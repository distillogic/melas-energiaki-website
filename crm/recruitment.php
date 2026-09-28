<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}
function sales_recruitment_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_applicants(id CHAR(36) PRIMARY KEY,name VARCHAR(150) NOT NULL,
        email VARCHAR(320) NOT NULL,region VARCHAR(150) NOT NULL,experience TEXT NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'candidate',interview_notes TEXT NULL,review_at DATE NULL,
        cv_pdf MEDIUMBLOB NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX applicant_email(email),INDEX applicant_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec('ALTER TABLE sales_applicants ADD COLUMN IF NOT EXISTS retention_closed_at DATETIME NULL');
    $pdo->exec("UPDATE sales_applicants SET retention_closed_at=updated_at,updated_at=updated_at WHERE status IN ('declined','closed') AND retention_closed_at IS NULL");
}
function sales_apply(array $input,?array $file): string
{
    $name=sales_text($input,'name',3,150);$email=strtolower(sales_text($input,'email',5,320));$region=sales_text($input,'region',2,150);$experience=sales_text($input,'experience',30,4000);
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||empty($input['contact_permission']))throw new InvalidArgumentException('Χρειάζεται έγκυρο email και αποδοχή επικοινωνίας για την αίτηση.');
    $pdf=null;
    if($file&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
        if(($file['error']??-1)!==UPLOAD_ERR_OK||($file['size']??0)>4*1024*1024||!is_uploaded_file($file['tmp_name']??''))throw new InvalidArgumentException('Το PDF πρέπει να είναι έως 4 MB.');
        if((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])!=='application/pdf')throw new InvalidArgumentException('Επιτρέπεται μόνο PDF βιογραφικού.');
        $pdf=file_get_contents($file['tmp_name']);
    }
    $id=uuid_v4();$q=db()->prepare('INSERT INTO sales_applicants(id,name,email,region,experience,cv_pdf) VALUES(?,?,?,?,?,?)');$q->execute([$id,$name,$email,$region,$experience,$pdf]);return $id;
}
function sales_review_applicant(array $actor,array $input): void
{
    sales_require_manager($actor);$id=sales_text($input,'applicant_id',1,36);$stage=sales_text($input,'stage',1,30);$notes=sales_text($input,'notes',20,5000);$review=sales_text($input,'review_at',0,10);
    if(!in_array($stage,['candidate','screening','interview','training_approved','declined','closed'],true))throw new InvalidArgumentException('Μη έγκυρο στάδιο αίτησης.');
    if($review!==''){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$review);if(!$date||$date->format('Y-m-d')!==$review)throw new InvalidArgumentException('Μη έγκυρη ημερομηνία.');}
    $pdo=db();$pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT status,interview_notes FROM sales_applicants WHERE id=? FOR UPDATE');$q->execute([$id]);$previous=$q->fetch();if(!$previous)throw new InvalidArgumentException('Δεν βρέθηκε αίτηση.');
        // Editing a note on a closed application must not restart its retention clock.
        $closed=in_array($stage,['declined','closed'],true)?"COALESCE(retention_closed_at,NOW())":'NULL';
        $pdo->prepare('UPDATE sales_applicants SET status=?,interview_notes=?,review_at=?,retention_closed_at='.$closed.' WHERE id=?')->execute([$stage,$notes,$review?:null,$id]);
        sales_portal_event($actor,'applicant',$id,'review',['previous'=>$previous,'stage'=>$stage,'notes'=>$notes]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
