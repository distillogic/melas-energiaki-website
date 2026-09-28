<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(403);exit;}
require_once __DIR__.'/final-challenges.php';

function academy_prerequisites(string $id): array
{
    $lessons=academy_lessons();$lesson=$lessons[$id]??null;
    if(!$lesson)throw new InvalidArgumentException('Άγνωστο μάθημα.');
    $required=$lesson['requires']??[];$previous=null;
    foreach($lessons as $key=>$item){
        if($item['pillar']!==$lesson['pillar']||($item['type']??'')==='lab')continue;
        if($key===$id)break;
        $previous=$key;
        if(($lesson['type']??'')==='lab')$required[]=$key;
    }
    if(($lesson['type']??'')!=='lab'&&$previous)$required[]=$previous;
    return array_values(array_unique($required));
}
function academy_missing_prerequisites(array $user,string $id): array
{
    if(academy_is_admin($user))return [];
    $progress=academy_my_progress($user);
    if(!empty($progress[$id]['completed_at']))return []; // Completed courses remain available for revision.
    return array_values(array_filter(academy_prerequisites($id),fn($key)=>empty($progress[$key]['completed_at'])));
}
function academy_assert_unlocked(array $user,string $id): void
{
    $missing=academy_missing_prerequisites($user,$id);
    if($missing){$titles=array_map(fn($key)=>academy_lesson($key)['title'],$missing);throw new InvalidArgumentException('Προηγείται επιτυχής ολοκλήρωση: '.implode(' · ',$titles));}
}
function academy_exam_fingerprint(): string
{
    // Additive v37 teaching must not erase the previous official exam or grant a
    // second official attempt. Keep its policy identity; papers are snapshotted.
    $core=array_filter(academy_lessons(),fn($lesson)=>($lesson['additional_curriculum']??0)!==37);
    return hash('sha256',json_encode([array_map(fn($lesson)=>[$lesson['id'],$lesson['version'],$lesson['quiz']],$core),academy_final_cases(),'balanced-32-v33'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function academy_v37_supplementary(array $user): bool
{
    // Only a passed pre-v37 official paper exempts its holder from the addition.
    // Personal QA papers have a different hash and can never qualify.
    try{$q=db()->prepare('SELECT questions_json FROM '.academy_table('final_exams')." WHERE user_id=? AND content_hash=? AND status='passed' ORDER BY started_at,id LIMIT 1");
        $q->execute([$user['id'],academy_exam_fingerprint()]);$json=$q->fetchColumn();
    }catch(PDOException $e){if(($e->errorInfo[1]??0)===1146)return false;throw $e;}
    if(!$json)return false;
    $questions=json_decode($json,true,512,JSON_THROW_ON_ERROR);
    return is_array($questions)&&count($questions)>0&&($questions[0]['curriculum']??0)<37;
}
function academy_required_lessons(array $user): array
{
    $lessons=academy_lessons();
    return academy_v37_supplementary($user)?array_filter($lessons,fn($l)=>($l['additional_curriculum']??0)!==37):$lessons;
}
function academy_exam_ready(array $user): bool
{
    $progress=academy_my_progress($user);$required=academy_required_lessons($user);
    return count($required)>0&&count(array_filter($required,fn($l)=>empty($progress[$l['id']]['completed_at'])))===0;
}
function academy_final_access(array $user): bool
{
    return academy_can_test_retake($user)||academy_final_current($user)!==null||academy_exam_ready($user);
}
function academy_final_run_hash(array $user): string
{
    // Test runs can never satisfy the official exam or CRM access requirements.
    return academy_can_test_retake($user)?hash('sha256',academy_exam_fingerprint().':personal-qa-v34'):academy_exam_fingerprint();
}
function academy_final_current(array $user): ?array
{
    $q=db()->prepare('SELECT * FROM '.academy_table('final_exams').' WHERE user_id=? AND content_hash=? ORDER BY started_at,id LIMIT 1');
    $q->execute([$user['id'],academy_final_run_hash($user)]);return $q->fetch()?:null;
}
function academy_final_schema(): void
{
    foreach([
        'final_exams'=>"id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,content_hash CHAR(64) NOT NULL,questions_json LONGTEXT NOT NULL,answers_json LONGTEXT NULL,score SMALLINT NULL,critical_failure TINYINT NULL,status VARCHAR(20) NOT NULL DEFAULT 'in_progress',started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,expires_at DATETIME NOT NULL,submitted_at DATETIME NULL,INDEX final_user(user_id,started_at)",
        'supervised_calls'=>"id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,assessor_id CHAR(36) NOT NULL,occurred_at DATETIME NOT NULL,scenario VARCHAR(200) NOT NULL,score SMALLINT NOT NULL,critical_failure TINYINT NOT NULL,feedback TEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX calls_user(user_id,occurred_at)"
    ] as $name=>$fields)db()->exec('CREATE TABLE IF NOT EXISTS '.academy_table($name).' ('.$fields.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
function academy_start_final(array $user,bool $newTest=false): string
{
    $pdo=db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE id=? AND active=1 FOR UPDATE');$q->execute([$user['id']]);$user=$q->fetch();if(!$user)throw new InvalidArgumentException('Ανενεργός λογαριασμός.');
        if(!academy_final_access($user))throw new InvalidArgumentException('Ολοκλήρωσε πρώτα όλα τα μαθήματα και τις πρακτικές ασκήσεις.');
        if($newTest&&!academy_can_test_retake($user))throw new InvalidArgumentException('Η νέα δοκιμή επιτρέπεται μόνο στον Sophianos.');
        if(!academy_can_test_retake($user)&&($existing=academy_final_current($user))){$pdo->commit();return $existing['id'];}
        $q=$pdo->prepare('SELECT id FROM '.academy_table('final_exams')." WHERE user_id=? AND content_hash=? AND status='in_progress' AND expires_at>NOW() ORDER BY started_at DESC LIMIT 1");
        $q->execute([$user['id'],academy_final_run_hash($user)]);
        if($id=$q->fetchColumn()){$pdo->commit();return $id;}
        $questions=academy_final_questions();
        shuffle($questions);$id=uuid_v4();
        $pdo->prepare('INSERT INTO '.academy_table('final_exams')."(id,user_id,content_hash,questions_json,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 90 MINUTE))")
            ->execute([$id,$user['id'],academy_final_run_hash($user),json_encode($questions,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        if(academy_can_test_retake($user))academy_admin_event($user,$user['id'],'final:personal-test');
        $pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function academy_submit_final(array $user,string $id,array $answers): void
{
    $pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE id=? AND active=1 FOR UPDATE');$q->execute([$user['id']]);$user=$q->fetch();
        if(!$user||!academy_final_access($user))throw new InvalidArgumentException('Πρέπει πρώτα να ολοκληρωθούν τα μαθήματα.');
        $q=$pdo->prepare('SELECT * FROM '.academy_table('final_exams').' WHERE id=? AND user_id=? FOR UPDATE');$q->execute([$id,$user['id']]);$exam=$q->fetch();
        if(!$exam)throw new InvalidArgumentException('Η εξέταση δεν βρέθηκε.');
        if($exam['status']!=='in_progress')throw new InvalidArgumentException('Η εξέταση έχει ήδη υποβληθεί.');
        if(strtotime($exam['expires_at'])<time()||!hash_equals($exam['content_hash'],academy_final_run_hash($user)))throw new InvalidArgumentException('Η εξέταση έληξε ή η ύλη ενημερώθηκε. Επικοινώνησε με τον διαχειριστή.');
        if(!academy_can_test_retake($user)&&academy_final_current($user)['id']!==$id)throw new InvalidArgumentException('Επιτρέπεται μόνο η πρώτη τελική εξέταση για την τρέχουσα ύλη.');
        $questions=json_decode($exam['questions_json'],true,512,JSON_THROW_ON_ERROR);$correct=0;$critical=false;$normalized=[];
        if(count($answers)!==count($questions))throw new InvalidArgumentException('Απάντησε σε όλες τις ερωτήσεις.');
        foreach($questions as $i=>$question){$answer=$answers[$i]??null;
            if((!is_string($answer)&&!is_int($answer))||!preg_match('/^[0-9]+$/D',(string)$answer)||!array_key_exists((int)$answer,$question['options']))throw new InvalidArgumentException('Μη έγκυρη απάντηση.');
            $normalized[]=(int)$answer;if((int)$answer===$question['correct'])$correct++;elseif($question['critical'])$critical=true;
        }
        $score=(int)floor($correct*100/count($questions));$status=$score>=65&&!$critical?'passed':'failed';
        $pdo->prepare('UPDATE '.academy_table('final_exams').' SET answers_json=?,score=?,critical_failure=?,status=?,submitted_at=NOW() WHERE id=?')->execute([json_encode($normalized,JSON_THROW_ON_ERROR),$score,(int)$critical,$status,$id]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function academy_record_call(array $actor,string $target,array $input): void
{
    throw new InvalidArgumentException('Οι χειροκίνητες κλήσεις διατηρούνται μόνο ως ιστορικό. Χρησιμοποίησε τις αυτόματες εικονικές προσομοιώσεις.');
}
