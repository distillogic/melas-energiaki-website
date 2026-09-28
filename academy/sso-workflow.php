<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(403);exit;}
function academy_sso_claims(string $payload,string $signature,string $state,int $now):array {
    if(!academy_sso_enabled())throw new InvalidArgumentException('Η σύνδεση μέσω CRM δεν έχει ενεργοποιηθεί.');
    if(strlen($payload)>6000||!preg_match('/^[a-zA-Z0-9_-]+$/D',$payload)||!preg_match('/^[a-f0-9]{64}$/D',$signature))throw new InvalidArgumentException('Μη έγκυρη εταιρική σύνδεση.');
    $config=academy_config();$expected=hash_hmac('sha256',$payload,$config['sso']['shared_key']);
    if(!hash_equals($expected,$signature))throw new InvalidArgumentException('Δεν επαληθεύτηκε η εταιρική σύνδεση.');
    $json=base64_decode(strtr($payload,'-_','+/'),true);$c=$json===false?null:json_decode($json,true);
    if(!is_array($c)||($c['v']??null)!==1||($c['iss']??null)!==$config['sso']['issuer']||($c['aud']??null)!==$config['app']['origin'].academy_url('sso.php')
        ||!is_string($c['state']??null)||strlen($state)!==64||!hash_equals($state,$c['state'])
        ||!is_int($c['iat']??null)||!is_int($c['exp']??null)||$c['iat']>$now+15||$c['exp']<$now||$c['exp']>$c['iat']+90||$c['exp']<=$c['iat']
        ||!is_string($c['jti']??null)||!preg_match('/^[a-f0-9]{64}$/D',$c['jti'])
        ||!is_string($c['sub']??null)||!preg_match('/^[a-zA-Z0-9-]{1,100}$/D',$c['sub'])
        ||!is_string($c['email']??null)||strlen($c['email'])>254||!filter_var($c['email'],FILTER_VALIDATE_EMAIL)
        ||!is_string($c['name']??null)||trim($c['name'])===''||mb_strlen($c['name'])>150)throw new InvalidArgumentException('Η εταιρική σύνδεση έληξε ή δεν αντιστοιχεί σε αυτή τη συνεδρία.');
    $c['email']=strtolower(trim($c['email']));return $c;
}
function academy_sso_accept(array $claims,?array $linkUser):array {
    $pdo=db();$pdo->beginTransaction();
    try {
        try{$pdo->prepare('INSERT INTO '.academy_table('sso_uses').'(token_id) VALUES(?)')->execute([$claims['jti']]);}catch(PDOException $e){if(($e->errorInfo[1]??0)===1062)throw new InvalidArgumentException('Αυτή η εταιρική σύνδεση χρησιμοποιήθηκε ήδη. Ξεκίνα ξανά.');throw $e;}
        $q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE sso_issuer=? AND sso_subject=? FOR UPDATE');$q->execute([$claims['iss'],$claims['sub']]);$user=$q->fetch();
        if($user){
            if(!$user['active']||$user['email']!==$claims['email']||($linkUser&&$linkUser['id']!==$user['id']))throw new InvalidArgumentException('Ο λογαριασμός χρειάζεται έλεγχο από τον διαχειριστή Academy.');
        }else{
            $q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE email=? FOR UPDATE');$q->execute([$claims['email']]);$existing=$q->fetch();
            if($existing){
                // No automatic email-based account takeover: linking requires an
                // existing authenticated Academy session with the same identity.
                if(!$linkUser||$linkUser['id']!==$existing['id']||!$existing['active']||$existing['sso_subject']||(int)$existing['session_version']!==(int)$linkUser['session_version'])throw new InvalidArgumentException('Το email υπάρχει ήδη στην Academy. Συνδέσου πρώτα με τον κωδικό Academy και επίλεξε «Σύνδεση εταιρικού λογαριασμού» από το προφίλ σου.');
                $pdo->prepare('UPDATE '.academy_table('users').' SET sso_issuer=?,sso_subject=? WHERE id=?')->execute([$claims['iss'],$claims['sub'],$existing['id']]);$user=$existing;
            }else{
                if($linkUser)throw new InvalidArgumentException('Το email του CRM πρέπει να είναι ίδιο με τον λογαριασμό Academy.');
                $id=uuid_v4();$pdo->prepare('INSERT INTO '.academy_table('users').'(id,name,email,role,sso_issuer,sso_subject) VALUES(?,?,?,?,?,?)')->execute([$id,$claims['name'],$claims['email'],academy_initial_role($claims['email']),$claims['iss'],$claims['sub']]);
                $q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE id=?');$q->execute([$id]);$user=$q->fetch();
            }
        }
        $pdo->commit();return $user;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
