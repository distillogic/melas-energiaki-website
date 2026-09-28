<?php
declare(strict_types=1);
if (!defined('CRM_ROOT')) { http_response_code(403); exit; }

// Additive, single-identity installer. It never changes the CRM's login or
// general onboarding policy, and never overwrites an existing account.
function papadopoulos_v20_email(): string { return 'papadopoulos@distillogic.gr'; }
function papadopoulos_v20_admin(array $user): bool {
    return !empty($user['active'])
        && in_array($user['role'] ?? '', ['admin','manager'], true)
        && in_array(strtolower(trim((string)($user['email'] ?? ''))), ['melas@distillogic.gr','sophianos@distillogic.gr'], true)
        && can_manage_accounts($user);
}
function papadopoulos_v20_account(): ?array {
    $q=db()->prepare('SELECT id,name,email,role,active FROM users WHERE email=?');
    $q->execute([papadopoulos_v20_email()]);
    return $q->fetch() ?: null;
}
function papadopoulos_v20_create(array $actor,array $input): string {
    if (!papadopoulos_v20_admin($actor)) throw new InvalidArgumentException('Μόνο ο Μελάς ή ο διαχειριστής μπορούν να εγκρίνουν την πρόσβαση.');
    if (($input['email'] ?? papadopoulos_v20_email()) !== papadopoulos_v20_email()
        || ($input['role'] ?? 'employee') !== 'employee') {
        throw new InvalidArgumentException('Ο λογαριασμός και ο απλός ρόλος δεν αλλάζουν από αυτή τη φόρμα.');
    }
    $name=is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $reason=is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
    $password=is_string($input['password'] ?? null) ? $input['password'] : '';
    if ($name==='' || mb_strlen($name)>150) throw new InvalidArgumentException('Συμπλήρωσε το όνομα, έως 150 χαρακτήρες.');
    if (strlen($password)<12 || strlen($password)>72 || str_contains($password,"\0") || $password!==($input['password_confirm'] ?? null)) {
        throw new InvalidArgumentException('Ο νέος κωδικός πρέπει να έχει 12–72 bytes και ίδια επιβεβαίωση.');
    }
    if (($input['boss_approved'] ?? '') !== '1' || mb_strlen($reason)<10 || mb_strlen($reason)>1500) {
        throw new InvalidArgumentException('Επιβεβαίωσε την έγκριση εργοδότη και γράψε την αιτιολογία (10–1500 χαρακτήρες).');
    }
    // Existing reauthentication includes throttling. No secrets enter the audit.
    personnel_reauth($actor,$input);
    require_once CRM_ROOT.'/account-management.php';
    ensure_account_management_schema(); // All DDL precedes the data transaction.
    $pdo=db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$actor['id']]);$fresh=$q->fetch();
        if (!$fresh || !papadopoulos_v20_admin($fresh)
            || !password_verify((string)($input['actor_password'] ?? ''),(string)$fresh['password_hash'])
            || (int)($_SESSION['access_generation'] ?? -1)!==account_session_generation((string)$fresh['id'])) {
            throw new InvalidArgumentException('Η πρόσβαση διαχειριστή άλλαξε. Συνδέσου ξανά.');
        }
        $q=$pdo->prepare('SELECT id FROM users WHERE email=? FOR UPDATE');$q->execute([papadopoulos_v20_email()]);
        if ($q->fetchColumn()) throw new InvalidArgumentException('Το email υπάρχει ήδη. Δεν άλλαξε κωδικός, ρόλος ή κατάσταση. Έλεγξε την υπάρχουσα καρτέλα λογαριασμού.');
        $id=uuid_v4();
        $pdo->prepare('INSERT INTO users(id,name,email,role,active,password_hash) VALUES(?,?,?,?,1,?)')
            ->execute([$id,$name,papadopoulos_v20_email(),'employee',password_hash($password,PASSWORD_DEFAULT)]);
        $detail='Ειδική πρόσβαση χωρίς προϋπόθεση υπογραφής onboarding, κατόπιν δηλωμένης έγκρισης εργοδότη. Δεν αποτελεί υπογραφή ή ολοκλήρωση εγγράφων. Αιτιολογία: '.$reason;
        // Explicit exception via the existing non-enrolled account path. Do NOT
        // fabricate signed documents or released_at, or remove another record.
        $pdo->prepare('INSERT INTO user_work_profiles(user_id,details) VALUES(?,?)')->execute([$id,json_encode([
            'notes'=>$detail,'access_exception'=>'boss-approved-v20','approved_by'=>$fresh['id'],
        ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $pdo->prepare('INSERT INTO user_access_state(user_id,generation) VALUES(?,0)')->execute([$id]);
        $pdo->prepare('INSERT INTO user_lifecycle_events(id,actor_id,subject_id,action,reason) VALUES(?,?,?,?,?)')
            ->execute([uuid_v4(),$fresh['id'],$id,'approved_access_exception',$detail]);
        $pdo->prepare('INSERT INTO user_management_audit(id,actor_id,subject_id,action) VALUES(?,?,?,?)')
            ->execute([uuid_v4(),$fresh['id'],$id,'create_approved_user']);
        personnel_audit($fresh,$id,null,'access_exception',$detail);
        $pdo->commit();return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? null)===1062) {
            throw new InvalidArgumentException('Το email υπάρχει ήδη. Δεν έγινε αντικατάσταση.');
        }
        throw $error;
    }
}
