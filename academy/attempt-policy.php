<?php
declare(strict_types=1);
if (!defined('ACADEMY_ROOT')) { http_response_code(403); exit; }

// A named, authenticated testing exception, not a general administrator bypass.
function academy_can_test_retake(array $user): bool
{
    return academy_is_admin($user)
        && strtolower(trim((string)($user['email']??'')))==='sophianos@distillogic.gr';
}
function academy_test_retake(array $user,string $lessonId): void
{
    if(!academy_can_test_retake($user))throw new InvalidArgumentException('Η προσωπική επανεξέταση δοκιμής δεν είναι διαθέσιμη για αυτόν τον λογαριασμό.');
    $lesson=academy_lesson($lessonId);ensure_academy_schema();$pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE id=? AND active=1 FOR UPDATE');$q->execute([$user['id']]);$current=$q->fetch()?:[];
        if(!academy_can_test_retake($current))throw new InvalidArgumentException('Ο λογαριασμός δεν δικαιούται επανεξέταση δοκιμής.');
        $state=academy_attempt_state($current,$lessonId);
        if(!$state['count'])throw new InvalidArgumentException('Δεν υπάρχει προηγούμενη προσπάθεια. Ξεκίνησε το πρώτο τεστ.');
        // Repeated requests reuse the unconsumed grant, never accumulate retries.
        if(!$state['grant']){
            $pdo->prepare('INSERT INTO '.academy_table('retake_grants').'(id,user_id,lesson_id,content_version,actor_id,reason) VALUES(?,?,?,?,?,?)')
                ->execute([uuid_v4(),$current['id'],$lessonId,$lesson['version'],$current['id'],'Προσωπική δοκιμή λειτουργίας από Sophianos. Η πρώτη προσπάθεια διατηρείται.']);
            academy_admin_event($current,$current['id'],'retake:personal-test');
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function academy_attempt_policy_schema(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS '.academy_table('quiz_second_attempts').' (
        user_id CHAR(36) NOT NULL,lesson_id VARCHAR(64) NOT NULL,content_version SMALLINT UNSIGNED NOT NULL,
        attempt_id CHAR(36) NOT NULL,PRIMARY KEY(user_id,lesson_id,content_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    db()->exec('CREATE TABLE IF NOT EXISTS '.academy_table('attempt_controls').' (
        user_id CHAR(36) NOT NULL,lesson_id VARCHAR(64) NOT NULL,content_version SMALLINT UNSIGNED NOT NULL,
        first_attempt_id CHAR(36) NOT NULL,PRIMARY KEY(user_id,lesson_id,content_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    db()->exec('CREATE TABLE IF NOT EXISTS '.academy_table('retake_grants').' (
        id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,lesson_id VARCHAR(64) NOT NULL,
        content_version SMALLINT UNSIGNED NOT NULL,actor_id CHAR(36) NOT NULL,reason TEXT NOT NULL,
        attempt_id CHAR(36) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        consumed_at DATETIME NULL,KEY retake_user(user_id,lesson_id,content_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

// Read-only projection: the old attempts and historical best-score aggregates
// are never rewritten. Only this projection is used for current completion.
function academy_attempt_data(string $userId,?PDO $connection=null,?string $prefix=null): array
{
    $connection??=db();
    if($prefix!==null&&!in_array($prefix,['academy_','academy_school_'],true))throw new InvalidArgumentException('Invalid Academy namespace');
    $tableName=fn(string $name):string=>$prefix!==null?'`'.$prefix.$name.'`':academy_table($name);
    $data=['attempts'=>[], 'controls'=>[], 'grants'=>[], 'recorded'=>[], 'seconds'=>[]];
    foreach(['attempts','lab_attempts'] as $table) {
        $sql='SELECT * FROM '.$tableName($table).' WHERE user_id=?';
        if($table==='lab_attempts')$sql.=" AND mode='assessment'";
        $sql.=' ORDER BY created_at,id';
        try{$q=$connection->prepare($sql);$q->execute([$userId]);$rows=$q->fetchAll();}
        catch(PDOException $e){if($table==='lab_attempts'&&($e->errorInfo[1]??0)===1146)continue;throw $e;}
        foreach($rows as $row){$row['question_count']??=100;$key=$row['lesson_id'].':'.$row['content_version'];$data['attempts'][$key][]=$row;}
    }
    foreach(['attempt_controls'=>'controls','retake_grants'=>'grants'] as $table=>$bucket){
        $q=$connection->prepare('SELECT * FROM '.$tableName($table).' WHERE user_id=? ORDER BY '.($bucket==='controls'?'lesson_id':'created_at,id'));
        $q->execute([$userId]);foreach($q->fetchAll() as $row)$data[$bucket][$row['lesson_id'].':'.$row['content_version']][]=$row;
    }
    $q=$connection->prepare('SELECT lesson_id,content_version,attempts FROM '.$tableName('progress').' WHERE user_id=?');$q->execute([$userId]);
    foreach($q->fetchAll() as $row)$data['recorded'][$row['lesson_id'].':'.$row['content_version']]=(int)$row['attempts'];
    try {
        $q=$connection->prepare('SELECT * FROM '.$tableName('quiz_second_attempts').' WHERE user_id=?');$q->execute([$userId]);
        foreach($q->fetchAll() as $row)$data['seconds'][$row['lesson_id'].':'.$row['content_version']]=$row['attempt_id'];
    } catch(PDOException $e) { if(($e->errorInfo[1]??0)!==1146)throw $e; }
    return $data;
}
function academy_attempt_state_from(array $data,string $lessonId,int $version): array
{
    $key=$lessonId.':'.$version;$rows=$data['attempts'][$key]??[];$ambiguous=false;
    $authorizedIds=array_filter(array_column($data['grants'][$key]??[],'attempt_id'));
    // A retake must never become the original score when legacy detail is missing.
    $initialRows=array_values(array_filter($rows,fn($row)=>!in_array($row['id'],$authorizedIds,true)));
    $first=$initialRows[0]??null;
    $pinned=$data['controls'][$key][0]['first_attempt_id']??null;
    if($pinned){$first=null;foreach($rows as $row)if($row['id']===$pinned)$first=$row;$ambiguous=$first===null;}
    elseif(count($initialRows)>1&&$initialRows[0]['created_at']===$initialRows[1]['created_at']){
        // Legacy timestamps have only second precision; UUID order is not time.
        $ambiguous=true;$first=null;
    }
    $pending=null;$retakePass=null;$retakes=[];
    foreach($data['grants'][$key]??[] as $grant){
        if(!$grant['attempt_id']){$pending=$grant['id'];continue;}
        foreach($rows as $row)if($row['id']===$grant['attempt_id']){$retakes[]=$row;if($row['passed']&&!$retakePass)$retakePass=$row;}
    }
    $count=max(count($rows),(int)($data['recorded'][$key]??0),$pinned?1:0);
    if($count&&!$first)$ambiguous=true;
    $limit=(academy_lessons()[$lessonId]['type']??'')==='lab'?1:2;
    $personalIds=[];
    foreach($data['grants'][$key]??[] as $g)if(!empty($g['attempt_id'])&&isset($g['actor_id'],$g['user_id'])&&$g['actor_id']===$g['user_id'])$personalIds[]=$g['attempt_id'];
    $quizRows=array_values(array_filter($rows,fn($r)=>!in_array($r['id'],$personalIds,true)));
    $regular=$first?[$first]:[];$second=null;
    if($limit===2&&$first){
        $secondPin=$data['seconds'][$key]??null;
        if($secondPin){foreach($rows as $row)if($row['id']===$secondPin)$second=$row;}
        else {
            $candidates=array_values(array_filter($quizRows,fn($row)=>$row['id']!==$first['id']));
            if($candidates&&$candidates[0]['created_at']>$first['created_at']
                &&(!isset($candidates[1])||$candidates[0]['created_at']!==$candidates[1]['created_at']))$second=$candidates[0];
        }
        if($second)$regular[]=$second;
    }
    $used=max(count($initialRows),$count-count($retakes));
    if($limit===2)$used=max(count($quizRows),$count-count($personalIds));
    $secondUncertain=$limit===2&&$used>=2&&!$second&&!($first['passed']??false);
    $ambiguous=$ambiguous||$secondUncertain;
    $remaining=$ambiguous?0:max(0,$limit-$used);
    $grade=academy_lesson_final_grade($first,$second,$limit);
    if($secondUncertain)$grade=['percent'=>null,'completed_at'=>null,'rule'=>'Η δεύτερη παλιά προσπάθεια χρειάζεται έλεγχο χρονοσήμανσης / ελλιπούς ιστορικού'];
    $best=$grade['percent']===null?null:['score'=>$grade['percent'],'question_count'=>100];
    $completion=$grade['completed_at'];
    if($first&&$first['passed'])$remaining=0;
    return ['count'=>$count,'first'=>$first,'ambiguous'=>$ambiguous,'grant'=>$pending,
        'allowed'=>$remaining>0||$pending!==null,'retakes'=>$retakes,'limit'=>$limit,'remaining'=>$remaining,
        'second'=>$second,'best'=>$best,'regular_completed_at'=>$completion,
        'final_grade'=>$grade['percent'],'grade_rule'=>$grade['rule'],
        'completed_at'=>$completion??($retakePass['created_at']??null)];
}
function academy_lesson_final_grade(?array $first,?array $second,int $limit=2): array
{
    $percent=fn(array $r):float=>(int)$r['question_count']>0?100*(float)$r['score']/(int)$r['question_count']:0;
    if(!$first)return ['percent'=>null,'completed_at'=>null,'rule'=>'Χωρίς επιβεβαιωμένη πρώτη προσπάθεια'];
    $one=$percent($first);
    if($first['passed'])return ['percent'=>round($one,2),'completed_at'=>$first['created_at'],'rule'=>'Επιτυχία στην πρώτη — ο βαθμός κλειδώνει'];
    if($limit===1||!$second)return ['percent'=>round($one,2),'completed_at'=>null,'rule'=>$limit===1?'Πρώτη προσπάθεια CRM Lab':'Αναμονή δεύτερης προσπάθειας'];
    $mean=($one+$percent($second))/2;
    return ['percent'=>round($second['passed']?max(80,$mean):$mean,2),
        'completed_at'=>$second['passed']?$second['created_at']:null,
        'rule'=>$second['passed']?'Επιτυχής δεύτερη: max(μέσος όρος δύο, 80%)':'Δύο αποτυχίες — μέσος όρος, χωρίς προβιβασμό'];
}
function academy_attempt_state(array $user,string $lessonId): array
{
    $lesson=academy_lesson($lessonId);
    return academy_attempt_state_from(academy_attempt_data($user['id']),$lessonId,$lesson['version']);
}
function academy_attempt_assert(array $user,string $lessonId): array
{
    if(!db()->inTransaction())throw new LogicException('Attempt checks require the learner lock.');
    $state=academy_attempt_state($user,$lessonId);
    if(!$state['allowed'])throw new InvalidArgumentException('Οι διαθέσιμες βαθμολογούμενες προσπάθειες εξαντλήθηκαν. Νέα υποβολή απαιτεί άδεια επανεξέτασης από διαχειριστή. Η εξάσκηση παραμένει ελεύθερη.');
    return $state;
}
function academy_attempt_consume(array $user,string $lessonId,string $attemptId,array $state): void
{
    $version=academy_lesson($lessonId)['version'];
    if($state['count']===0){
        db()->prepare('INSERT INTO '.academy_table('attempt_controls').'(user_id,lesson_id,content_version,first_attempt_id) VALUES(?,?,?,?)')->execute([$user['id'],$lessonId,$version,$attemptId]);
    }elseif($state['remaining']>0){
        db()->prepare('INSERT INTO '.academy_table('quiz_second_attempts').'(user_id,lesson_id,content_version,attempt_id) VALUES(?,?,?,?)')->execute([$user['id'],$lessonId,$version,$attemptId]);
        if($state['grant']){
            $q=db()->prepare('UPDATE '.academy_table('retake_grants').' SET attempt_id=?,consumed_at=NOW() WHERE id=? AND user_id=? AND attempt_id IS NULL');
            $q->execute([$attemptId,$state['grant'],$user['id']]);if($q->rowCount()!==1)throw new RuntimeException('Η άδεια επανεξέτασης δεν είναι διαθέσιμη.');
        }
    }else{
        $q=db()->prepare('UPDATE '.academy_table('retake_grants').' SET attempt_id=?,consumed_at=NOW() WHERE id=? AND user_id=? AND attempt_id IS NULL');
        $q->execute([$attemptId,$state['grant'],$user['id']]);if($q->rowCount()!==1)throw new RuntimeException('Η άδεια επανεξέτασης δεν είναι διαθέσιμη.');
    }
}
function academy_official_progress(array $rows): array
{
    $data=[];$out=[];
    foreach($rows as $row){
        $lesson=academy_lessons()[$row['lesson_id']]??null;
        if(!$lesson||(int)$row['content_version']!==$lesson['version'])continue;
        $data[$row['user_id']]??=academy_attempt_data($row['user_id']);
        $state=academy_attempt_state_from($data[$row['user_id']],$row['lesson_id'],$lesson['version']);
        $row['historical_best_score']=$row['best_score'];$row['first_attempt_id']=$state['first']['id']??null;
        $row['first_score']=$state['first']['score']??null;$row['best_score']=$state['best']['score']??0;
        $row['second_score']=$state['second']['score']??null;$row['attempt_limit']=$state['limit'];
        $row['final_grade']=$state['final_grade'];$row['grade_rule']=$state['grade_rule'];
        $row['first_question_count']=$state['first']['question_count']??$row['question_count'];
        $row['first_uncertain']=$state['ambiguous']||!$state['count'];
        $row['question_count']=$state['best']['question_count']??$row['question_count'];
        $row['completed_at']=$state['completed_at'];$row['authorized_retakes']=count($state['retakes']);
        $row['retake_passed']=$state['completed_at']&&!$state['regular_completed_at'];
        $out[$row['lesson_id']]=$row;
    }
    return $out;
}
function academy_grant_retake(array $actor,string $target,string $lessonId,string $reason): void
{
    $reason=trim($reason);$lesson=academy_lesson($lessonId);
    if(!academy_is_admin($actor)||$target===$actor['id'])throw new InvalidArgumentException('Η επανεξέταση εγκρίνεται από άλλον εξουσιοδοτημένο διαχειριστή.');
    if(mb_strlen($reason)<15||mb_strlen($reason)>2000)throw new InvalidArgumentException('Συμπλήρωσε αιτιολογία 15–2.000 χαρακτήρων.');
    ensure_academy_schema();$pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE id=? AND active=1 FOR UPDATE');$q->execute([$target]);$learner=$q->fetch();
        if(!$learner)throw new InvalidArgumentException('Ο μαθητής δεν είναι ενεργός.');
        // Re-read the approving account, rather than trusting a stale role.
        $q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE id=? AND active=1');$q->execute([$actor['id']]);
        if(!academy_is_admin($q->fetch()?:[]))throw new InvalidArgumentException('Δεν έχεις δικαίωμα έγκρισης.');
        $state=academy_attempt_state($learner,$lessonId);
        if(!$state['count']||$state['allowed']||$state['completed_at'])throw new InvalidArgumentException('Άδεια δίνεται μετά την εξάντληση των προσπαθειών χωρίς επιτυχία και χωρίς άλλη ανοικτή άδεια.');
        $id=uuid_v4();$pdo->prepare('INSERT INTO '.academy_table('retake_grants').'(id,user_id,lesson_id,content_version,actor_id,reason) VALUES(?,?,?,?,?,?)')->execute([$id,$target,$lessonId,$lesson['version'],$actor['id'],$reason]);
        academy_admin_event($actor,$target,'retake:approved');$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function academy_attempt_notice(array $state): string
{
    if(!$state['count'])return $state['limit']===2?'Έως δύο προσπάθειες. Επιτυχία στην πρώτη κλειδώνει τον βαθμό. Αν περάσεις στη δεύτερη: max(μέσος όρος δύο, 80%). Δύο αποτυχίες δεν δίνουν προβιβασμό.':'Μία βαθμολογούμενη προσπάθεια στο CRM Lab. Η καθοδηγούμενη εξάσκηση παραμένει ελεύθερη.';
    $first=$state['first'];$text=$first?'Επίσημος πρώτος βαθμός: '.$first['score'].'/'.$first['question_count'].'.':'Η ακριβής πρώτη προσπάθεια χρειάζεται έλεγχο: υπάρχει ίδια χρονοσήμανση ή ελλιπές παλιό ιστορικό. Δεν επιλέγουμε αυθαίρετα τον καλύτερο βαθμό.';
    if($state['second'])$text.=' Δεύτερος βαθμός: '.$state['second']['score'].'/'.$state['second']['question_count'].'. Βαθμός μαθήματος: '.$state['final_grade'].'/100. '.$state['grade_rule'].'.';
    if($state['remaining']>0)return $text.' Διαθέσιμη ακόμη μία προσπάθεια μαθήματος, χωρίς έγκριση διαχειριστή.';
    if($state['grant'])return $text.' Έχει εγκριθεί μία επιπλέον επανεξέταση. Το αρχικό ιστορικό διατηρείται.';
    return $text.($state['completed_at']?' Η ολοκλήρωση έχει καταγραφεί.':' Για επανεξέταση επικοινώνησε με τον διαχειριστή.').' Οι παλιές προσπάθειες παραμένουν στο ιστορικό.';
}
