<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(403);exit;}
require_once __DIR__.'/call-workflow.php';
function academy_weighted_grade(?float $quizzes,?float $exam,?float $calls): ?float
{
    if($quizzes===null||$exam===null||$calls===null)return null;
    return round(.1*$quizzes+.7*$exam+.2*$calls,2);
}
function academy_grade_report(array $actor,string $target): array
{
    if($actor['id']!==$target&&!academy_can_review_training($actor))throw new InvalidArgumentException('Η βαθμολογία ανήκει σε άλλον εκπαιδευόμενο.');
    academy_call_schema();$progress=academy_my_progress(['id'=>$target]);$data=academy_attempt_data($target);
    $lessons=[];$quizGrades=[];$quizzesTotal=0;$settled=0;$supplementary=academy_v37_supplementary(['id'=>$target]);
    foreach(academy_lessons() as $id=>$lesson){
        $state=academy_attempt_state_from($data,$id,$lesson['version']);$lab=($lesson['type']??'')==='lab';
        $grade=$state['final_grade'];$isSettled=$state['count']>0&&($state['completed_at']||$state['remaining']===0)&&!$state['ambiguous'];
        $optional=$supplementary&&($lesson['additional_curriculum']??0)===37;
        $lessons[$id]=['title'=>$lesson['title'],'lab'=>$lab,'state'=>$state,'grade'=>$grade,'settled'=>$isSettled,'optional'=>$optional];
        if(!$lab&&!$optional){$quizzesTotal++;if($isSettled&&$grade!==null){$quizGrades[]=$grade;$settled++;}}
    }
    $quizMean=count($quizGrades)===$quizzesTotal&&$quizzesTotal?array_sum($quizGrades)/$quizzesTotal:null;
    $q=db()->prepare('SELECT * FROM '.academy_table('final_exams').' WHERE user_id=? AND content_hash=? ORDER BY started_at,id LIMIT 1');
    $q->execute([$target,academy_exam_fingerprint()]);$exam=$q->fetch()?:null;
    $examGrade=$exam&&in_array($exam['status'],['passed','failed'],true)&&$exam['score']!==null?(float)$exam['score']:null;
    $calls=academy_call_readiness(db(),academy_table_prefix(),$target);$graded=$calls['graded'];
    $callMean=$graded?array_sum(array_column($graded,'score'))/count($graded):null;
    $distinct=count(array_unique(array_column($graded,'scenario_id')));
    $total=academy_weighted_grade($quizMean,$examGrade,$distinct>=3?$callMean:null);
    $summary=academy_summary($progress);
    $complete=$total!==null&&academy_exam_ready(['id'=>$target])&&$exam['status']==='passed'&&$calls['ready'];
    return compact('lessons','quizMean','quizzesTotal','settled','examGrade','exam','callMean','calls','distinct','total','complete');
}
