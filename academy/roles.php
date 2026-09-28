<?php
declare(strict_types=1);
if (!defined('ACADEMY_ROOT')) { http_response_code(403); exit; }

// Explicitly approved core identities, matching the setup roles in both CRMs.
// Never accept privileges from a submitted form or arbitrary SSO extra claim.
function academy_core_roles(): array {
    return ['melas@distillogic.gr'=>'admin','sophianos@distillogic.gr'=>'manager','support@distillogic.gr'=>'technical'];
}
function academy_initial_role(string $email): string {
    return academy_core_roles()[strtolower(trim($email))] ?? 'learner';
}
function academy_role_label(string $role): string {
    return ['admin'=>'Ιδιοκτήτης · Admin','manager'=>'Διαχειριστής · Manager','technical'=>'Τεχνική υποστήριξη · Technical','learner'=>'Εκπαιδευόμενος'][$role] ?? 'Εκπαιδευόμενος';
}
function academy_is_technical(array $user): bool {
    return !empty($user['active']) && ($user['role']??'')==='technical'
        && strtolower(trim((string)($user['email']??'')))==='support@distillogic.gr';
}
function academy_can_view_accounts(array $user): bool {
    return academy_is_admin($user) || academy_is_technical($user);
}
function academy_migrate_core_roles(): void {
    static $done=false;
    if ($done) return;
    $pdo=db();$settings=academy_table('settings');
    $q=$pdo->query("SELECT setting_value FROM $settings WHERE setting_key='core_roles_version'");
    if ($q->fetchColumn()==='20') {$done=true;return;}
    $pdo->beginTransaction();
    try {
        foreach (academy_core_roles() as $email=>$role) {
            $q=$pdo->prepare('SELECT id,role FROM '.academy_table('users').' WHERE LOWER(email)=? FOR UPDATE');$q->execute([$email]);$user=$q->fetch();
            if (!$user || $user['role']===$role) continue;
            $pdo->prepare('UPDATE '.academy_table('users').' SET role=?,session_version=session_version+1 WHERE id=?')->execute([$role,$user['id']]);
            // Reserved system actor, not an attribution to a human administrator.
            academy_admin_event(['id'=>'00000000-0000-0000-0000-000000000000'],$user['id'],'system:core-role:'.$role);
        }
        $pdo->exec("INSERT INTO $settings(setting_key,setting_value) VALUES('core_roles_version','20') ON DUPLICATE KEY UPDATE setting_value='20'");
        $pdo->commit();$done=true;
    } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
