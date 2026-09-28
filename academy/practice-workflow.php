<?php
declare(strict_types=1);
if (!defined('ACADEMY_ROOT')) { http_response_code(403); exit; }
require_once __DIR__.'/courses.php';
require_once __DIR__.'/practice-content.php';

function academy_lab_schema(): void
{
    static $ready=false;
    if ($ready) return;
    ensure_academy_schema();
    db()->exec('CREATE TABLE IF NOT EXISTS '.academy_table('lab_drafts').' (
        user_id CHAR(36) NOT NULL, lesson_id VARCHAR(64) NOT NULL, mode VARCHAR(12) NOT NULL,
        content_version SMALLINT UNSIGNED NOT NULL, variant TINYINT UNSIGNED NOT NULL,
        submission_key CHAR(64) NOT NULL, answers_json TEXT NOT NULL,
        attempt_id CHAR(36) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(user_id,lesson_id,mode),
        CONSTRAINT '.academy_constraint('lab_draft_user_fk').' FOREIGN KEY(user_id) REFERENCES '.academy_table('users').'(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    db()->exec('CREATE TABLE IF NOT EXISTS '.academy_table('lab_attempts').' (
        id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, lesson_id VARCHAR(64) NOT NULL,
        content_version SMALLINT UNSIGNED NOT NULL, mode VARCHAR(12) NOT NULL, variant TINYINT UNSIGNED NOT NULL,
        submission_key CHAR(64) NOT NULL, answers_json TEXT NOT NULL, feedback_json TEXT NOT NULL,
        score SMALLINT UNSIGNED NOT NULL, passed TINYINT(1) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY lab_submission_unique(user_id,submission_key), KEY lab_user_idx(user_id,created_at),
        CONSTRAINT '.academy_constraint('lab_attempt_user_fk').' FOREIGN KEY(user_id) REFERENCES '.academy_table('users').'(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $ready=true;
}

function academy_lab_unlocked(array $user): bool
{
    return ($_SESSION['academy_lab_user']??null)===$user['id'] && (int)($_SESSION['academy_lab_until']??0)>time();
}
function academy_lab_unlock(array $user, string $email, string $password): void
{
    // These deliberately public training credentials are NOT an authentication
    // method for Academy or either CRM. The route already requires a real login.
    if (empty($user['active']) || strtolower(trim($email))!=='demo@distillogic.gr'
        || !hash_equals('Demo-CRM-Lab-2026!', $password)) {
        throw new InvalidArgumentException('Χρησιμοποίησε τα demo στοιχεία που εμφανίζονται παρακάτω. Ο προσωπικός σου λογαριασμός δεν αλλάζει.');
    }
    $_SESSION['academy_lab_user']=$user['id'];
    $_SESSION['academy_lab_until']=time()+7200;
}
function academy_lab_mode(string $mode): void
{
    if (!in_array($mode,['guided','assessment'],true)) throw new InvalidArgumentException('Άγνωστος τρόπος εξάσκησης.');
}
function academy_lab_missing(array $user, string $lessonId): array
{
    return academy_missing_prerequisites($user,$lessonId);
}
function academy_lab_lock_user(array $user): void
{
    if (empty($user['id']) || empty($user['active'])) throw new RuntimeException('Απαιτείται ενεργός προσωπικός λογαριασμός.');
    $q=db()->prepare('SELECT id FROM '.academy_table('users').' WHERE id=? AND active=1 FOR UPDATE');
    $q->execute([$user['id']]);
    if (!$q->fetchColumn()) throw new RuntimeException('Ο προσωπικός λογαριασμός δεν είναι ενεργός.');
}
function academy_lab_draft(array $user,string $lessonId,string $mode): ?array
{
    academy_lab_mode($mode);
    $q=db()->prepare('SELECT * FROM '.academy_table('lab_drafts').' WHERE user_id=? AND lesson_id=? AND mode=?');
    $q->execute([$user['id'],$lessonId,$mode]);$row=$q->fetch();
    if (!$row || (int)$row['content_version']!==(academy_lab_catalog()[$lessonId]['version']??0)) return null;
    return $row;
}
function academy_lab_start(array $user,string $lessonId,string $mode,bool $restart=false): array
{
    academy_lab_mode($mode);$meta=academy_lab_catalog()[$lessonId]??null;
    if (!$meta) throw new InvalidArgumentException('Άγνωστη άσκηση.');
    academy_lab_schema();$pdo=db();$pdo->beginTransaction();
    try {
        academy_lab_lock_user($user);
        if ($mode==='assessment' && academy_lab_missing($user,$lessonId)) throw new InvalidArgumentException('Ολοκλήρωσε πρώτα τις απαιτούμενες ασκήσεις. Η καθοδηγούμενη προεπισκόπηση παραμένει ανοικτή.');
        $q=$pdo->prepare('SELECT * FROM '.academy_table('lab_drafts').' WHERE user_id=? AND lesson_id=? AND mode=? FOR UPDATE');
        $q->execute([$user['id'],$lessonId,$mode]);$old=$q->fetch();
        if ($old && !$restart && (int)$old['content_version']===$meta['version']) {$pdo->commit();return $old;}
        if ($mode==='assessment') academy_attempt_assert($user,$lessonId);
        if ($restart && $old && !$old['attempt_id']) throw new InvalidArgumentException('Συνέχισε το ανοικτό πρόχειρο. Υπόβαλέ το πριν ξεκινήσεις νέα παραλλαγή.');
        $variant=$old?((int)$old['variant']+1)%3:random_int(0,2);
        $q=$pdo->prepare('INSERT INTO '.academy_table('lab_drafts').'
            (user_id,lesson_id,mode,content_version,variant,submission_key,answers_json,attempt_id)
            VALUES(?,?,?,?,?,?,?,NULL) ON DUPLICATE KEY UPDATE content_version=VALUES(content_version),
            variant=VALUES(variant),submission_key=VALUES(submission_key),answers_json=VALUES(answers_json),attempt_id=NULL,updated_at=NOW()');
        $q->execute([$user['id'],$lessonId,$mode,$meta['version'],$variant,bin2hex(random_bytes(32)),'{}']);
        $row=academy_lab_draft($user,$lessonId,$mode);$pdo->commit();return $row;
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function academy_lab_answers(array $case,array $input): array
{
    if (array_diff(array_keys($input),array_keys($case['f']))) throw new InvalidArgumentException('Άγνωστα πεδία απάντησης.');
    $out=[];
    foreach($case['f'] as $key=>$spec) {
        $value=$input[$key]??'';
        if(!is_string($value) || mb_strlen($value)>($spec['type']==='textarea'?2500:300)) throw new InvalidArgumentException('Μη έγκυρη τιμή για: '.$spec['label']);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value)) throw new InvalidArgumentException('Η απάντηση περιέχει μη έγκυρους χαρακτήρες.');
        $value=trim($value);
        if($spec['type']==='select' && $value!=='' && !array_key_exists($value,$spec['options'])) throw new InvalidArgumentException('Μη έγκυρη επιλογή για: '.$spec['label']);
        $out[$key]=$value;
    }
    return $out;
}
function academy_lab_normalize(string $value): string
{
    return strtr(mb_strtolower(preg_replace('/\s+/u',' ',trim($value)),'UTF-8'),['ά'=>'α','έ'=>'ε','ή'=>'η','ί'=>'ι','ό'=>'ο','ύ'=>'υ','ώ'=>'ω','ς'=>'σ']);
}
function academy_lab_numeric(string $value): ?float
{
    // Decimal point OR comma, no thousands separators: 1800.50 / 1800,50.
    $value=str_replace(',','.',$value);
    if(!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D',$value))return null;
    return (float)$value;
}
function academy_lab_grade(array $case,array $answers): array
{
    $answers=academy_lab_answers($case,$answers);$feedback=[];$earned=0;$total=0;$criticalOk=true;
    foreach($case['f'] as $key=>$spec) {
        if($spec['type']==='textarea')continue;
        $value=$answers[$key];$expected=$spec['answer'];
        if($spec['type']==='number' && $expected!=='') {
            $number=academy_lab_numeric($value);$correct=$number!==null && abs($number-(float)$expected)<0.00001;
        } elseif(in_array($spec['type'],['select','date','datetime-local'],true)){$correct=$value===$expected;}
        else {$correct=academy_lab_normalize($value)===academy_lab_normalize($expected);}
        $weight=$spec['critical']?2:1;$total+=$weight;if($correct)$earned+=$weight;
        if($spec['critical']&&!$correct)$criticalOk=false;
        $feedback[$key]=['correct'=>$correct,'expected'=>$expected,'why'=>$spec['why'],'critical'=>$spec['critical'],
            'label'=>$spec['label'],'entered_display'=>$spec['options'][$value]??($value===''?'— Κενό':$value),
            'expected_display'=>$spec['options'][$expected]??($expected===''?'— Κενό (δεν δόθηκε)':$expected)];
    }
    $score=$total?(int)floor($earned*100/$total):0;
    return ['score'=>$score,'passed'=>$score>=85&&$criticalOk,'critical_ok'=>$criticalOk,'feedback'=>$feedback,'answers'=>$answers];
}
function academy_lab_write(array $user,string $lessonId,string $mode,string $key,array $input,bool $submit): string
{
    academy_lab_mode($mode);
    if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Άκυρη προσπάθεια. Άνοιξε την άσκηση ξανά.');
    academy_lab_schema();$pdo=db();$pdo->beginTransaction();
    try {
        academy_lab_lock_user($user);
        $q=$pdo->prepare('SELECT * FROM '.academy_table('lab_drafts').' WHERE user_id=? AND lesson_id=? AND mode=? FOR UPDATE');
        $q->execute([$user['id'],$lessonId,$mode]);$draft=$q->fetch();
        if(!$draft || !hash_equals($draft['submission_key'],$key))throw new InvalidArgumentException('Αυτή η καρτέλα είναι παλιά. Άνοιξε ξανά την τρέχουσα προσπάθεια.');
        $case=academy_lab_case($lessonId,(int)$draft['variant']);
        if((int)$draft['content_version']!==$case['version'])throw new InvalidArgumentException('Το μάθημα ενημερώθηκε. Άνοιξε νέα προσπάθεια.');
        $answers=academy_lab_answers($case,$input);$json=json_encode($answers,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if($draft['attempt_id']) {
            if(!$submit || json_decode($draft['answers_json'],true)!==$answers)throw new InvalidArgumentException('Η προσπάθεια έχει υποβληθεί. Ξεκίνα νέα για διαφορετικές απαντήσεις.');
            $pdo->commit();return $draft['attempt_id'];
        }
        if($mode==='assessment' && academy_lab_missing($user,$lessonId))throw new InvalidArgumentException('Δεν έχουν ολοκληρωθεί οι προαπαιτούμενες ασκήσεις.');
        $attemptState=$mode==='assessment'?academy_attempt_assert($user,$lessonId):null;
        $id='';
        if($submit) {
            $grade=academy_lab_grade($case,$answers);$id=uuid_v4();
            $q=$pdo->prepare('INSERT INTO '.academy_table('lab_attempts').'
                (id,user_id,lesson_id,content_version,mode,variant,submission_key,answers_json,feedback_json,score,passed) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$id,$user['id'],$lessonId,$case['version'],$mode,$draft['variant'],$key,$json,json_encode($grade['feedback'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$grade['score'],(int)$grade['passed']]);
            if($mode==='assessment') {
                academy_attempt_consume($user,$lessonId,$id,$attemptState);
                $q=$pdo->prepare('INSERT INTO '.academy_table('progress').'
                    (user_id,lesson_id,content_version,attempts,best_score,last_score,question_count,completed_at,last_attempt_at)
                    VALUES(?,?,?,1,?,?,100,IF(?=1,NOW(),NULL),NOW()) ON DUPLICATE KEY UPDATE attempts=attempts+1,
                    best_score=GREATEST(best_score,VALUES(best_score)),last_score=VALUES(last_score),question_count=100,
                    completed_at=COALESCE(completed_at,VALUES(completed_at)),last_attempt_at=NOW()');
                $q->execute([$user['id'],$lessonId,$case['version'],$grade['score'],$grade['score'],(int)$grade['passed']]);
            }
        }
        $q=$pdo->prepare('UPDATE '.academy_table('lab_drafts').' SET answers_json=?,attempt_id=?,updated_at=NOW() WHERE user_id=? AND lesson_id=? AND mode=?');
        $q->execute([$json,$id?:null,$user['id'],$lessonId,$mode]);$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function academy_lab_result(array $viewer,string $id,bool $team=false): ?array
{
    if($team&&!academy_is_admin($viewer))throw new InvalidArgumentException('Δεν έχεις πρόσβαση σε άλλους μαθητές.');
    $sql='SELECT a.*,u.name AS learner_name,u.email AS learner_email FROM '.academy_table('lab_attempts').' a JOIN '.academy_table('users').' u ON u.id=a.user_id WHERE a.id=?';
    $params=[$id];if(!$team){$sql.=' AND a.user_id=?';$params[]=$viewer['id'];}
    $q=db()->prepare($sql);$q->execute($params);return $q->fetch()?:null;
}
function academy_lab_history(array $viewer,bool $team=false): array
{
    if($team&&!academy_is_admin($viewer))throw new InvalidArgumentException('Δεν έχεις πρόσβαση σε άλλους μαθητές.');
    $sql='SELECT a.id,a.user_id,a.lesson_id,a.mode,a.score,a.passed,a.created_at,u.name AS learner_name FROM '.academy_table('lab_attempts').' a JOIN '.academy_table('users').' u ON u.id=a.user_id';
    $params=[];if(!$team){$sql.=' WHERE a.user_id=?';$params[]=$viewer['id'];}
    $q=db()->prepare($sql.' ORDER BY a.created_at DESC,a.id DESC LIMIT 100');$q->execute($params);return $q->fetchAll();
}
