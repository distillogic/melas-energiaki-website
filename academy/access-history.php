<?php
declare(strict_types=1);
if (!defined('ACADEMY_ROOT')) { http_response_code(403); exit; }

// An access/security log, not attendance, productivity or working-time evidence.
// It deliberately excludes IP addresses, device fingerprints and visited pages.
function academy_can_view_access_history(array $user): bool {
    return academy_is_admin($user) && strtolower(trim((string)($user['email'] ?? ''))) === 'sophianos@distillogic.gr';
}
function academy_access_schema(): void {
    static $ready=false;
    if ($ready) return;
    if (db()->inTransaction()) throw new RuntimeException('Access-log migration must precede transactions');
    db()->exec('CREATE TABLE IF NOT EXISTS '.academy_table('access_sessions')." (
        id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,login_at BIGINT NULL,
        observed_at BIGINT NOT NULL,last_seen_at BIGINT NOT NULL,expires_at BIGINT NOT NULL,
        ended_at BIGINT NULL,end_reason VARCHAR(30) NULL,auth_method VARCHAR(16) NOT NULL,
        notice_version VARCHAR(16) NOT NULL DEFAULT 'v36',
        INDEX access_user_time(user_id,observed_at),INDEX access_retention(observed_at),INDEX access_expiry(ended_at,expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready=true;
}
function academy_access_deadline(array $session): int {
    $absolute=(int)($session['signed_in_at']??0)+(($session['auth_method']??'')==='sso'?900:28800);
    return min((int)($session['last_seen']??0)+1800,$absolute);
}
function academy_access_maintain(?int $now=null): int {
    academy_access_schema();$now??=time();
    // Expiry is inferred from server session rules, never reported as a logout.
    db()->prepare('UPDATE '.academy_table('access_sessions')." SET ended_at=expires_at,end_reason='expired' WHERE ended_at IS NULL AND expires_at<?")->execute([$now]);
    $q=db()->prepare('DELETE FROM '.academy_table('access_sessions').' WHERE observed_at<?');
    $q->execute([$now-30*86400]);return $q->rowCount();
}
function academy_access_begin(array $user,string $method,bool $existing=false): void {
    academy_access_maintain();$now=time();$id=uuid_v4();
    db()->prepare('INSERT INTO '.academy_table('access_sessions').'(id,user_id,login_at,observed_at,last_seen_at,expires_at,auth_method) VALUES(?,?,?,?,?,?,?)')
        ->execute([$id,$user['id'],$existing?null:$now,$now,$now,academy_access_deadline($_SESSION),$method]);
    $_SESSION['access_log_id']=$id;
}
function academy_access_touch(array $user): void {
    academy_access_maintain();
    if (empty($_SESSION['access_log_id'])) {academy_access_begin($user,(string)($_SESSION['auth_method']??'local'),true);return;}
    db()->prepare('UPDATE '.academy_table('access_sessions').' SET last_seen_at=?,expires_at=? WHERE id=? AND user_id=? AND ended_at IS NULL')
        ->execute([time(),academy_access_deadline($_SESSION),$_SESSION['access_log_id'],$user['id']]);
}
function academy_access_end(string $reason): void {
    if (empty($_SESSION['access_log_id']) || empty($_SESSION['user_id'])) return;
    if (!in_array($reason,['logout','expired','revoked','reauthenticated'],true)) throw new InvalidArgumentException('Unknown session end');
    academy_access_schema();$when=$reason==='expired'?min(time(),academy_access_deadline($_SESSION)):time();
    db()->prepare('UPDATE '.academy_table('access_sessions').' SET ended_at=?,end_reason=? WHERE id=? AND user_id=? AND ended_at IS NULL')
        ->execute([$when,$reason,$_SESSION['access_log_id'],$_SESSION['user_id']]);
}
function academy_access_rows(array $actor,string $userId='',int $page=1): array {
    if (!academy_can_view_access_history($actor)) throw new InvalidArgumentException('Δεν έχεις πρόσβαση στο ιστορικό συνδέσεων.');
    academy_access_maintain();$page=max(1,$page);$where='';$params=[];
    if ($userId!=='') {$where=' WHERE a.user_id=?';$params[]=$userId;}
    $q=db()->prepare('SELECT COUNT(*) FROM '.academy_table('access_sessions').' a'.$where);$q->execute($params);$count=(int)$q->fetchColumn();
    $page=min($page,max(1,(int)ceil($count/50)));
    $q=db()->prepare('SELECT a.*,u.name,u.email FROM '.academy_table('access_sessions').' a LEFT JOIN '.academy_table('users').' u ON u.id=a.user_id'.$where.' ORDER BY a.observed_at DESC,a.id DESC LIMIT 50 OFFSET '.(($page-1)*50));$q->execute($params);
    return ['rows'=>$q->fetchAll(),'count'=>$count,'page'=>$page,'pages'=>max(1,(int)ceil($count/50))];
}
function academy_access_time(?int $value): string {
    return $value===null?'—':(new DateTimeImmutable('@'.$value))->setTimezone(new DateTimeZone('Europe/Athens'))->format('d/m/Y H:i:s');
}
function academy_access_reason(?string $reason): string {
    return match($reason) {'logout'=>'Έξοδος με το κουμπί','expired'=>'Εκτιμώμενη λήξη συνεδρίας','revoked'=>'Ανάκληση πρόσβασης που εντοπίστηκε','reauthenticated'=>'Νέα σύνδεση στην ίδια συνεδρία',default=>'Δεν έχει καταγραφεί έξοδος'};
}
