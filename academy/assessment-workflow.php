<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(403);exit;}
function academy_assessment_rubric(): array {
    return ['pv'=>['Βασικές γνώσεις φωτοβολταϊκών',10],'qualified'=>['Qualified Lead',15],'calling'=>['Cold Calling',15],'discovery'=>['Discovery',15],'objections'=>['Objection Handling',10],'closing'=>['Negotiation & Closing',15],'crm'=>['CRM & Follow-up',10],'conduct'=>['Επαγγελματική συμπεριφορά',10]];
}
function academy_assessment_schema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS ".academy_table('assessments')." (
      id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, assessor_id CHAR(36) NOT NULL,
      submission_key CHAR(64) NOT NULL UNIQUE, rubric_version SMALLINT NOT NULL,
      target_track VARCHAR(30) NOT NULL, scores_json TEXT NOT NULL, total_score SMALLINT NOT NULL,
      threshold_score SMALLINT NOT NULL, critical_failure TINYINT(1) NOT NULL,
      roleplay_passed TINYINT(1) NOT NULL, supervised_calls SMALLINT NOT NULL,
      outcome VARCHAR(40) NOT NULL, evidence_notes TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX assessment_user_idx(user_id,created_at),
      CONSTRAINT ".academy_constraint('assessment_user_fk')." FOREIGN KEY(user_id) REFERENCES ".academy_table('users')."(id),
      CONSTRAINT ".academy_constraint('assessment_assessor_fk')." FOREIGN KEY(assessor_id) REFERENCES ".academy_table('users')."(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function academy_assessment_outcomes(): array {
    return ['supervised'=>'Επιτυχία θεωρίας · απαιτείται πρακτική','approved'=>'Approved · ολοκλήρωση αξιολόγησης','approved_with_supervision'=>'Approved with supervision · επιπλέον coaching','retraining'=>'Retraining · επανάληψη ενοτήτων','not_approved'=>'Not approved'];
}
function academy_assessment_validate(array $input,?float $officialTotal=null): array {
    $scores=$input['scores']??null;$normalized=[];$total=0;
    if($officialTotal===null){
    if(!is_array($scores)||count($scores)!==count(academy_assessment_rubric()))throw new InvalidArgumentException('Συμπλήρωσε και τις οκτώ βαθμολογίες.');
    foreach(academy_assessment_rubric() as $key=>[$title,$max]){
        $value=$scores[$key]??null;
        if((!is_string($value)&&!is_int($value))||!preg_match('/^(0|[1-9][0-9]?)$/D',(string)$value)||(int)$value>$max)throw new InvalidArgumentException('Μη έγκυρος βαθμός: '.$title);
        $normalized[$key]=(int)$value;$total+=(int)$value;
    }
    }else{$total=(int)floor($officialTotal);}
    $track=$input['target_track']??'';
    if(!in_array($track,['sales_partner','team_leader'],true))throw new InvalidArgumentException('Επίλεξε εκπαιδευτική διαδρομή.');
    $threshold=$track==='team_leader'?90:65;
    $calls=$input['supervised_calls']??'';
    if(!is_string($calls)||!preg_match('/^[0-5]$/D',$calls))throw new InvalidArgumentException('Μη έγκυρος αριθμός επιτυχών προσομοιώσεων.');
    $critical=!empty($input['critical_failure']);$practical=!empty($input['roleplay_passed']);$outcome=$input['outcome']??'';
    if(!is_string($outcome)||!isset(academy_assessment_outcomes()[$outcome]))throw new InvalidArgumentException('Μη έγκυρο αποτέλεσμα.');
    if(in_array($outcome,['approved','approved_with_supervision','supervised'],true)&&($total<$threshold||$critical))throw new InvalidArgumentException('Δεν επιτρέπεται έγκριση κάτω από τη βάση ή με σοβαρό λόγο απόρριψης.');
    if(in_array($outcome,['approved','approved_with_supervision'],true)&&(!$practical||(int)$calls<3))throw new InvalidArgumentException('Χρειάζονται τουλάχιστον 3 διαφορετικές επιτυχείς εικονικές κλήσεις, με περιστατικά και από τις δύο εταιρείες.');
    $notes=$input['evidence_notes']??'';
    if(!is_string($notes)||mb_strlen(trim($notes))<30||mb_strlen($notes)>5000)throw new InvalidArgumentException('Τεκμηρίωσε αξιολόγηση, πρακτική και coaching (30–5000 χαρακτήρες).');
    return ['scores'=>$normalized,'total'=>$total,'threshold'=>$threshold,'track'=>$track,'calls'=>(int)$calls,'critical'=>$critical,'practical'=>$practical,'outcome'=>$outcome,'notes'=>trim($notes)];
}
function academy_assessment_save(array $actor,string $target,array $input,string $key): string {
    if(!academy_can_assess_training($actor)||$actor['id']===$target)throw new InvalidArgumentException('Χρειάζεται άλλος εξουσιοδοτημένος εξεταστής.');
    if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Μη έγκυρη υποβολή.');
    require_once __DIR__.'/grades-workflow.php';academy_call_schema();academy_assessment_schema();
    $pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id,active FROM '.academy_table('users').' WHERE id=? FOR UPDATE');$q->execute([$target]);$learner=$q->fetch();
        if(!$learner||!$learner['active'])throw new InvalidArgumentException('Δεν βρέθηκε ενεργός εκπαιδευόμενος.');
        $q=$pdo->prepare('SELECT * FROM '.academy_table('users').' WHERE id=? AND active=1');$q->execute([$actor['id']]);$actor=$q->fetch();
        if(!$actor||!academy_can_assess_training($actor))throw new InvalidArgumentException('Δεν υπάρχει ενεργό δικαίωμα αξιολόγησης.');
        $q=$pdo->prepare('SELECT id,user_id,assessor_id FROM '.academy_table('assessments').' WHERE submission_key=?');$q->execute([$key]);$existing=$q->fetch();
        if($existing){if($existing['user_id']!==$target||$existing['assessor_id']!==$actor['id'])throw new InvalidArgumentException('Η υποβολή δεν αντιστοιχεί στην αξιολόγηση.');$pdo->commit();return $existing['id'];}
        $simulations=academy_call_readiness($pdo,academy_table_prefix(),$target);
        // Practical evidence is automatic and cannot be supplied by a form field.
        $input['supervised_calls']=(string)$simulations['count'];$input['roleplay_passed']=$simulations['ready']?'1':'';
        $gradeReport=academy_grade_report($actor,$target);
        if($gradeReport['total']===null)throw new InvalidArgumentException('Πρώτα χρειάζονται οι βαθμοί όλων των quiz, της τελικής εξέτασης και τουλάχιστον τριών διαφορετικών κλήσεων. Δες την καρτέλα Βαθμολογία.');
        // The form cannot submit or override the calculated 10/70/20 total.
        $result=academy_assessment_validate($input,$gradeReport['total']);
        $result['scores']=['quiz_average'=>$gradeReport['quizMean'],'final_exam'=>$gradeReport['examGrade'],
            'calls_average'=>$gradeReport['callMean'],'weighted_total'=>$gradeReport['total'],
            'call_ids'=>array_column($gradeReport['calls']['graded'],'id'),
            'lesson_grades'=>array_map(fn($row)=>$row['grade'],$gradeReport['lessons'])];
        if(in_array($result['outcome'],['approved','approved_with_supervision'],true)){
            if(!$gradeReport['complete'])throw new InvalidArgumentException('Ο σταθμισμένος βαθμός δεν αρκεί χωρίς επιτυχή μαθήματα, επίσημη τελική εξέταση και απαιτούμενη πρακτική.');
            if(!academy_exam_ready($learner))throw new InvalidArgumentException('Προηγείται επιτυχής ολοκλήρωση όλων των μαθημάτων.');
            $q=$pdo->prepare('SELECT id FROM '.academy_table('final_exams')." WHERE user_id=? AND status='passed' AND content_hash=? LIMIT 1");$q->execute([$target,academy_exam_fingerprint()]);
            if(!$q->fetchColumn())throw new InvalidArgumentException('Προηγείται επιτυχής τελική εξέταση στην τρέχουσα ύλη.');
            if(!$simulations['ready'])throw new InvalidArgumentException('Δεν έχει ολοκληρωθεί επιτυχώς η αυτόματη εικονική πρακτική.');
        }
        $id=uuid_v4();$pdo->prepare('INSERT INTO '.academy_table('assessments').'(id,user_id,assessor_id,submission_key,rubric_version,target_track,scores_json,total_score,threshold_score,critical_failure,roleplay_passed,supervised_calls,outcome,evidence_notes) VALUES(?,?,?,?,4,?,?,?,?,?,?,?,?,?)')->execute([$id,$target,$actor['id'],$key,$result['track'],json_encode($result['scores'],JSON_THROW_ON_ERROR),$result['total'],$result['threshold'],(int)$result['critical'],(int)$result['practical'],$result['calls'],$result['outcome'],$result['notes']]);
        $pdo->prepare('INSERT INTO '.academy_table('call_evidence').'(assessment_id,user_id,policy_version,run_ids_json) VALUES(?,?,1,?)')->execute([$id,$target,json_encode(array_column($simulations['passed'],'id'),JSON_THROW_ON_ERROR)]);
        academy_admin_event($actor,$target,'assessment:'.$result['outcome']);$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
