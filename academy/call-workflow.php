<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(403);exit;}
require_once __DIR__.'/courses.php';
require_once __DIR__.'/call-evidence.php';

function academy_call_schema(): void
{
    static $ready=false;if($ready)return;
    ensure_academy_schema();academy_final_schema();
    db()->exec('CREATE TABLE IF NOT EXISTS '.academy_table('call_runs')." (
        id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,scenario_id VARCHAR(40) NOT NULL,
        scenario_version SMALLINT NOT NULL,variant TINYINT NOT NULL,mode VARCHAR(16) NOT NULL,
        attempt_number SMALLINT NOT NULL DEFAULT 1,official_key CHAR(64) NULL UNIQUE,
        status VARCHAR(20) NOT NULL DEFAULT 'in_progress',node_id VARCHAR(40) NOT NULL DEFAULT 'open',
        choices_json TEXT NOT NULL,draft_json TEXT NOT NULL,result_json LONGTEXT NULL,
        score SMALLINT NULL,critical_failure TINYINT NULL,passed TINYINT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,submitted_at DATETIME NULL,
        INDEX call_user(user_id,scenario_id,mode)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec('CREATE TABLE IF NOT EXISTS '.academy_table('call_grants')." (
        id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,scenario_id VARCHAR(40) NOT NULL,
        scenario_version SMALLINT NOT NULL,actor_id CHAR(36) NOT NULL,reason TEXT NOT NULL,
        run_id CHAR(36) NULL UNIQUE,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        consumed_at DATETIME NULL,INDEX call_grant_user(user_id,scenario_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec('CREATE TABLE IF NOT EXISTS '.academy_table('call_evidence')." (
        assessment_id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,policy_version SMALLINT NOT NULL,
        run_ids_json TEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready=true;
}
function academy_call_lock_user(array $user): array
{
    $q=db()->prepare('SELECT * FROM '.academy_table('users').' WHERE id=? AND active=1 FOR UPDATE');$q->execute([$user['id']]);
    $current=$q->fetch();if(!$current)throw new InvalidArgumentException('Ο λογαριασμός δεν είναι ενεργός.');return $current;
}
function academy_call_require_exam(array $user): void
{
    if(!academy_exam_ready($user))throw new InvalidArgumentException('Για βαθμολογούμενη προσομοίωση ολοκλήρωσε πρώτα τα μαθήματα και τα CRM Labs.');
    $q=db()->prepare('SELECT id FROM '.academy_table('final_exams')." WHERE user_id=? AND content_hash=? AND status='passed' LIMIT 1");
    $q->execute([$user['id'],academy_exam_fingerprint()]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Προηγείται επιτυχής τελική εξέταση. Η εξάσκηση παραμένει διαθέσιμη.');
}
function academy_call_start(array $user,string $scenario,string $mode): string
{
    $meta=academy_call_catalog()[$scenario]??null;
    if(!$meta||!in_array($mode,['guided','assessment'],true))throw new InvalidArgumentException('Μη έγκυρη άσκηση.');
    academy_call_schema();$pdo=db();$pdo->beginTransaction();
    try{
        $user=academy_call_lock_user($user);$number=1;$key=null;$grant=null;
        if($mode==='assessment'&&academy_can_test_retake($user))$mode='test';
        if($mode==='assessment'){
            academy_call_require_exam($user);
            $state=academy_call_readiness($pdo,academy_table_prefix(),$user['id']);$last=$state['latest'][$scenario]??null;
            if($last&&($last['status']==='in_progress'||(isset($state['passed'][$scenario])&&!academy_can_test_retake($user)))){$pdo->commit();return $last['id'];}
            if($last){
                $q=$pdo->prepare('SELECT id FROM '.academy_table('call_grants').' WHERE user_id=? AND scenario_id=? AND scenario_version=? AND run_id IS NULL ORDER BY created_at,id LIMIT 1');
                $q->execute([$user['id'],$scenario,$meta['version']]);$grant=$q->fetchColumn();
                if(!$grant){$pdo->commit();return $last['id'];}
                $number=(int)$last['attempt_number']+1;
            }
            $key=hash('sha256',$user['id'].':'.$scenario.':'.$meta['version'].':'.($grant?:'first'));
        }else{
            $q=$pdo->prepare('SELECT id FROM '.academy_table('call_runs')." WHERE user_id=? AND scenario_id=? AND scenario_version=? AND mode=? AND status='in_progress' ORDER BY created_at DESC LIMIT 1");
            $q->execute([$user['id'],$scenario,$meta['version'],$mode]);if($id=$q->fetchColumn()){$pdo->commit();return $id;}
        }
        $id=uuid_v4();$pdo->prepare('INSERT INTO '.academy_table('call_runs').'(id,user_id,scenario_id,scenario_version,variant,mode,attempt_number,official_key,choices_json,draft_json) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$user['id'],$scenario,$meta['version'],random_int(0,1),$mode,$number,$key,'[]','{}']);
        if($grant)$pdo->prepare('UPDATE '.academy_table('call_grants').' SET run_id=?,consumed_at=NOW() WHERE id=? AND run_id IS NULL')->execute([$id,$grant]);
        if($mode==='test')academy_admin_event($user,$user['id'],'call:personal-test');
        $pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function academy_call_get(array $user,string $id,bool $lock=false): array
{
    $sql='SELECT * FROM '.academy_table('call_runs').' WHERE id=?';$params=[$id];
    // Delegated reviewers can inspect; all mutation paths lock and remain owner-only.
    if($lock||!academy_can_review_training($user)){$sql.=' AND user_id=?';$params[]=$user['id'];}
    if($lock)$sql.=' FOR UPDATE';
    $q=db()->prepare($sql);$q->execute($params);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Η προσομοίωση δεν βρέθηκε ή δεν είναι δική σου.');
    return $row;
}
function academy_call_test_retake(array $user,string $scenario): void
{
    $meta=academy_call_catalog()[$scenario]??null;
    if(!$meta||!academy_can_test_retake($user))throw new InvalidArgumentException('Η επανεξέταση δοκιμής είναι προσωπική και δεν είναι διαθέσιμη.');
    academy_call_schema();$pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE id=? AND active=1 FOR UPDATE');$q->execute([$user['id']]);$current=$q->fetch()?:[];
        if(!academy_can_test_retake($current))throw new InvalidArgumentException('Δεν έχετε δικαίωμα επανεξέτασης δοκιμής.');
        $state=academy_call_readiness($pdo,academy_table_prefix(),$current['id']);$last=$state['latest'][$scenario]??null;
        if(!$last)throw new InvalidArgumentException('Ξεκίνησε πρώτα την αρχική προσπάθεια.');
        if($last['status']==='in_progress'){$pdo->commit();return;}
        $q=$pdo->prepare('SELECT id FROM '.academy_table('call_grants').' WHERE user_id=? AND scenario_id=? AND scenario_version=? AND run_id IS NULL');$q->execute([$current['id'],$scenario,$meta['version']]);
        if(!$q->fetchColumn()){
            $pdo->prepare('INSERT INTO '.academy_table('call_grants').'(id,user_id,scenario_id,scenario_version,actor_id,reason) VALUES(?,?,?,?,?,?)')->execute([uuid_v4(),$current['id'],$scenario,$meta['version'],$current['id'],'Προσωπική δοκιμή λειτουργίας από Sophianos. Η πρώτη προσπάθεια διατηρείται.']);
            academy_admin_event($current,$current['id'],'call:personal-test');
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function academy_call_trace(array $case,array $choices): array
{
    $node=$case['start'];$known=[];$score=0;$critical=false;$turns=[];
    foreach($choices as $choice){
        if($node==='crm'||!is_string($choice)||!isset($case['nodes'][$node]['options'][$choice]))throw new InvalidArgumentException('Μη έγκυρη ακολουθία συνομιλίας.');
        $item=$case['nodes'][$node];$option=$item['options'][$choice];
        $turns[]=['node'=>$node,'prompt'=>$item['prompt'],'choice'=>$option['text'],'reply'=>$option['reply'],'points'=>$option['points'],'critical'=>$option['critical'],'correct'=>$item['options']['a']['text']];
        $score+=$option['points'];$critical=$critical||$option['critical'];
        foreach($option['reveals'] as $key)$known[$key]=true;
        $node=$option['next'];
        if(count($turns)>12)throw new InvalidArgumentException('Υπέρβαση μήκους συνομιλίας.');
    }
    return compact('node','known','score','critical','turns');
}
function academy_call_token(array $run,string $node,string $choice): string
{
    return hash('sha256',$run['id'].':'.$node.':'.$choice);
}
function academy_call_choose(array $user,string $id,string $expectedNode,string $token): void
{
    $pdo=db();$pdo->beginTransaction();
    try{
        $user=academy_call_lock_user($user);$run=academy_call_get($user,$id,true);
        if($run['mode']==='test'&&!academy_can_test_retake($user))throw new InvalidArgumentException('Δεν υπάρχει δικαίωμα προσωπικής δοκιμής.');
        if($run['status']!=='in_progress')throw new InvalidArgumentException('Η προσπάθεια έχει ήδη υποβληθεί.');
        $case=academy_call_case($run['scenario_id'],(int)$run['variant'],(int)$run['scenario_version']);
        if((int)$run['scenario_version']!==$case['version'])throw new InvalidArgumentException('Η άσκηση ενημερώθηκε. Άνοιξε το τρέχον σενάριο.');
        $choices=json_decode($run['choices_json'],true,512,JSON_THROW_ON_ERROR);$trace=academy_call_trace($case,$choices);
        if($expectedNode!==$trace['node']){
            // A repeated POST/second tab may replay only the exact recorded choice.
            foreach($choices as $i=>$choice)if($trace['turns'][$i]['node']===$expectedNode&&hash_equals(academy_call_token($run,$expectedNode,$choice),$token)){$pdo->commit();return;}
            throw new InvalidArgumentException('Η συνομιλία προχώρησε. Ανανεώστε τη σελίδα.');
        }
        $selected=null;foreach($case['nodes'][$expectedNode]['options']??[] as $key=>$option)if(hash_equals(academy_call_token($run,$expectedNode,$key),$token))$selected=$key;
        if($selected===null)throw new InvalidArgumentException('Μη έγκυρη απάντηση.');
        $choices[]=$selected;$next=academy_call_trace($case,$choices)['node'];
        $pdo->prepare('UPDATE '.academy_table('call_runs').' SET choices_json=?,node_id=? WHERE id=?')->execute([json_encode($choices,JSON_THROW_ON_ERROR),$next,$id]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function academy_call_normalize(string $value): string
{
    $value=mb_strtolower(trim($value));
    return preg_replace('/\s+/u',' ',strtr($value,['ά'=>'α','έ'=>'ε','ή'=>'η','ί'=>'ι','ό'=>'ο','ύ'=>'υ','ώ'=>'ω','ϊ'=>'ι','ΐ'=>'ι','ϋ'=>'υ','ΰ'=>'υ','ς'=>'σ']));
}
function academy_call_expected(array $case,array $trace): array
{
    $expected=[];foreach($case['fields'] as $key=>$field)$expected[$key]=isset($trace['known'][$key])?$field['answer']:'__unknown';
    // A CRM stage is a decision, not an invented customer fact.
    $needed=$case['required']??match($case['id']){
        'panels'=>['authority','quantity','watts','location','consent'],
        'service'=>['location','capacity','need','consent'],
        'discovery'=>['need','integration','months','consent'],
        'proposal'=>['scope','authority','price','consent'],
        default=>['consent']
    };
    $complete=!array_diff($needed,array_keys($trace['known']));
    $expected['stage']=$case['id']==='refusal'&&isset($trace['known']['consent'])?'closed':($complete?'review':'pending');
    return $expected;
}
function academy_call_grade(array $case,array $choices,array $answers): array
{
    $trace=academy_call_trace($case,$choices);if($trace['node']!=='crm')throw new InvalidArgumentException('Ολοκλήρωσε πρώτα τη συνομιλία.');
    if(array_diff(array_keys($answers),array_keys($case['fields'])))throw new InvalidArgumentException('Άγνωστο πεδίο καρτέλας.');
    $expected=academy_call_expected($case,$trace);$correct=0;$feedback=[];$normalized=[];$critical=$trace['critical'];
    foreach($case['fields'] as $key=>$field){
        $value=$answers[$key]??'';
        if(!is_string($value)||mb_strlen($value)>250)throw new InvalidArgumentException('Μη έγκυρο πεδίο καρτέλας.');
        $value=trim($value);$normalized[$key]=$value;$answer=$expected[$key];
        if($field['type']==='select'&&$value!==''&&$value!=='__unknown'&&!isset($field['options'][$value]))throw new InvalidArgumentException('Μη έγκυρη επιλογή καρτέλας.');
        if($answer==='__unknown')$ok=in_array($value,['','__unknown'],true);
        elseif($field['type']==='number'){$n=str_replace(',','.',$value);$ok=preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D',$n)&&abs((float)$n-(float)$answer)<0.005;}
        else $ok=academy_call_normalize($value)===academy_call_normalize($answer);
        if($ok)$correct++;elseif($field['critical'])$critical=true;
        $display=$answer==='__unknown'?'Δεν επιβεβαιώθηκε στη συγκεκριμένη συνομιλία — αφήνουμε κενό / δεν γνωρίζω':($field['options'][$answer]??$answer);
        $feedback[$key]=['label'=>$field['label'],'answer'=>$value,'expected'=>$display,'correct'=>(bool)$ok,'critical'=>$field['critical']&&!$ok];
    }
    $dialogueScore=min(50,(int)floor(50*$trace['score']/$case['max_dialogue']));
    $score=min(50,$dialogueScore)+(int)floor(50*$correct/count($case['fields']));
    $missing=array_values(array_diff($case['required']??[],array_keys($trace['known'])));
    return ['score'=>$score,'passed'=>$score>=65&&!$critical&&!$missing,'critical_failure'=>$critical,'dialogue_score'=>$dialogueScore,
        'missing_required'=>array_map(fn($key)=>$case['fields'][$key]['label'],$missing),
        'crm_score'=>$score-$dialogueScore,'turns'=>$trace['turns'],'fields'=>$feedback,'answers'=>$normalized,'threshold'=>65];
}
function academy_call_submit(array $user,string $id,array $answers,bool $final): void
{
    $pdo=db();$pdo->beginTransaction();
    try{
        $user=academy_call_lock_user($user);$run=academy_call_get($user,$id,true);
        if($run['mode']==='test'&&!academy_can_test_retake($user))throw new InvalidArgumentException('Δεν υπάρχει δικαίωμα προσωπικής δοκιμής.');
        if($run['status']==='submitted'){$pdo->commit();return;}
        $case=academy_call_case($run['scenario_id'],(int)$run['variant'],(int)$run['scenario_version']);
        if((int)$run['scenario_version']!==$case['version'])throw new InvalidArgumentException('Η άσκηση ενημερώθηκε.');
        $result=academy_call_grade($case,json_decode($run['choices_json'],true,512,JSON_THROW_ON_ERROR),$answers);
        if(!$final){$pdo->prepare('UPDATE '.academy_table('call_runs').' SET draft_json=? WHERE id=?')->execute([json_encode($result['answers'],JSON_THROW_ON_ERROR),$id]);$pdo->commit();return;}
        if($run['mode']==='assessment')academy_call_require_exam($user);
        $pdo->prepare('UPDATE '.academy_table('call_runs')." SET status='submitted',draft_json=?,result_json=?,score=?,passed=?,critical_failure=?,submitted_at=NOW() WHERE id=?")
            ->execute([json_encode($result['answers'],JSON_THROW_ON_ERROR),json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$result['score'],(int)$result['passed'],(int)$result['critical_failure'],$id]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function academy_call_grant(array $actor,string $target,string $scenario,string $reason): void
{
    $meta=academy_call_catalog()[$scenario]??null;$reason=trim($reason);
    if(!$meta||!academy_is_admin($actor)||$actor['id']===$target||mb_strlen($reason)<15||mb_strlen($reason)>2000)throw new InvalidArgumentException('Χρειάζεται άλλος εξουσιοδοτημένος διαχειριστής και αιτιολογία 15–2.000 χαρακτήρων.');
    $pdo=db();$pdo->beginTransaction();
    try{
        academy_call_lock_user(['id'=>$target]);
        $q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE id=? AND active=1');$q->execute([$actor['id']]);
        if(!academy_is_admin($q->fetch()?:[]))throw new InvalidArgumentException('Δεν έχετε δικαίωμα έγκρισης.');
        $state=academy_call_readiness($pdo,academy_table_prefix(),$target);$last=$state['latest'][$scenario]??null;
        if(!$last||$last['status']!=='submitted'||isset($state['passed'][$scenario]))throw new InvalidArgumentException('Η επανεξέταση δίνεται μετά από αποτυχημένη βαθμολογούμενη προσπάθεια.');
        $q=$pdo->prepare('SELECT id FROM '.academy_table('call_grants').' WHERE user_id=? AND scenario_id=? AND scenario_version=? AND run_id IS NULL');$q->execute([$target,$scenario,$meta['version']]);
        if($q->fetchColumn())throw new InvalidArgumentException('Υπάρχει ήδη άδεια επανεξέτασης.');
        $pdo->prepare('INSERT INTO '.academy_table('call_grants').'(id,user_id,scenario_id,scenario_version,actor_id,reason) VALUES(?,?,?,?,?,?)')->execute([uuid_v4(),$target,$scenario,$meta['version'],$actor['id'],$reason]);
        academy_admin_event($actor,$target,'call:retake-approved');$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
