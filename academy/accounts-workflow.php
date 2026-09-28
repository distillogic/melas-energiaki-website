<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(403);exit;}
function academy_manage_account(array $actor,string $action,array $input):void {
    if(!academy_is_admin($actor))throw new InvalidArgumentException('Δεν επιτρέπεται διαχείριση εκπαιδευόμενων.');
    if(academy_crm_enabled()&&in_array($action,['create','reset'],true))throw new InvalidArgumentException('Η δημιουργία χρήστη και η αλλαγή κωδικού γίνονται στο Energiaki CRM. Η Academy χρησιμοποιεί τα ίδια στοιχεία.');
    $pdo=db();$pdo->beginTransaction();
    try {
        if($action==='create'){
            $name=trim(is_string($input['name']??null)?$input['name']:'');$email=strtolower(trim(is_string($input['email']??null)?$input['email']:''));$password=is_string($input['password']??null)?$input['password']:'';
            if($name===''||mb_strlen($name)>150||strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)||!academy_password_valid($password))throw new InvalidArgumentException('Συμπλήρωσε όνομα, έγκυρο email και προσωρινό κωδικό 12–72 bytes.');
            $id=uuid_v4();$q=$pdo->prepare('INSERT INTO '.academy_table('users').'(id,name,email,role,password_hash,must_change_password) VALUES(?,?,?,?,?,1)');
            try {$q->execute([$id,$name,$email,academy_initial_role($email),password_hash($password,PASSWORD_DEFAULT)]);}catch(PDOException $e){if(($e->errorInfo[1]??0)===1062)throw new InvalidArgumentException('Υπάρχει ήδη λογαριασμός με αυτό το email.');throw $e;}
        } else {
            $id=is_string($input['id']??null)?$input['id']:'';$q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE id=? FOR UPDATE');$q->execute([$id]);$target=$q->fetch();
            if(!$target)throw new InvalidArgumentException('Ο λογαριασμός δεν βρέθηκε.');
            if($id===$actor['id'])throw new InvalidArgumentException('Χρησιμοποίησε τον προσωπικό σου λογαριασμό για αλλαγή κωδικού. Δεν επιτρέπεται να απενεργοποιήσεις τον εαυτό σου.');
            if(academy_privileged_email($target['email']))throw new InvalidArgumentException('Η απενεργοποίηση ή επαναφορά του άλλου διαχειριστή δεν γίνεται από αυτή τη σελίδα.');
            if($action==='deactivate'||$action==='reactivate'){
                $pdo->prepare('UPDATE '.academy_table('users').' SET active=?,session_version=session_version+1 WHERE id=?')->execute([$action==='reactivate'?1:0,$id]);
            }elseif($action==='reset'){
                if(!$target['password_hash'])throw new InvalidArgumentException('Ο χρήστης συνδέεται μέσω CRM. Η επαναφορά κωδικού γίνεται εκεί.');
                $password=is_string($input['password']??null)?$input['password']:'';
                if(!academy_password_valid($password))throw new InvalidArgumentException('Δώσε νέο προσωρινό κωδικό 12–72 bytes.');
                $pdo->prepare('UPDATE '.academy_table('users').' SET password_hash=?,must_change_password=1,session_version=session_version+1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
            }else throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
        }
        academy_admin_event($actor,$id,$action);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
