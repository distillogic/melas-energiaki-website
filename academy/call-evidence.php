<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(403);exit;}
require_once __DIR__.'/call-content.php';

// Read-only bridge: safe to include from Melas CRM without Academy bootstrap.
function academy_call_readiness(PDO $pdo,string $prefix,string $userId): array
{
    if(!in_array($prefix,['academy_','academy_school_'],true))throw new InvalidArgumentException('Invalid Academy namespace');
    $q=$pdo->prepare("SELECT * FROM {$prefix}call_runs WHERE user_id=? AND mode='assessment' ORDER BY attempt_number,id");$q->execute([$userId]);$rows=$q->fetchAll();
    $q=$pdo->prepare("SELECT * FROM {$prefix}call_grants WHERE user_id=? AND run_id IS NOT NULL");$q->execute([$userId]);$grants=array_column($q->fetchAll(),null,'run_id');
    $passed=[];$first=[];$latest=[];$graded=[];
    foreach($rows as $row){
        $meta=academy_call_catalog()[$row['scenario_id']]??null;
        if(!$meta||!in_array((int)$row['scenario_version'],$meta['accepted_versions']??[$meta['version']],true))continue;
        $scenario=$row['scenario_id'];$initial=(int)$row['attempt_number']===1;
        $grant=$grants[$row['id']]??null;
        if(!$initial&&(!$grant||$grant['scenario_id']!==$scenario||(int)$grant['scenario_version']!==(int)$row['scenario_version']))continue;
        $expected=hash('sha256',$userId.':'.$scenario.':'.$row['scenario_version'].':'.($initial?'first':$grant['id']));
        if(!hash_equals($expected,(string)$row['official_key']))continue;
        if($row['status']==='submitted'&&$row['score']!==null)$graded[]=$row;
        if($initial&&(!isset($first[$scenario])||(int)$row['scenario_version']===$meta['version']))$first[$scenario]=$row;
        if((int)$row['scenario_version']===$meta['version'])$latest[$scenario]=$row;
        if($row['status']==='submitted'&&(int)$row['score']>=65&&!$row['critical_failure']&&$row['passed'])$passed[$scenario]??=$row;
    }
    $brands=[];foreach($passed as $id=>$row)$brands[academy_call_catalog()[$id]['brand']]=true;
    return ['ready'=>count($passed)>=3&&isset($brands['melas'],$brands['distillogic']),
        'passed'=>$passed,'first'=>$first,'latest'=>$latest,'count'=>count($passed),'graded'=>$graded];
}
function academy_call_assessment_proof(PDO $pdo,string $prefix,string $userId,string $assessmentId): bool
{
    $state=academy_call_readiness($pdo,$prefix,$userId);
    $q=$pdo->prepare("SELECT run_ids_json FROM {$prefix}call_evidence WHERE assessment_id=? AND user_id=? AND policy_version=1");$q->execute([$assessmentId,$userId]);
    $ids=json_decode((string)$q->fetchColumn(),true);
    if(!is_array($ids)||count($ids)<3||count($ids)>5||count(array_unique($ids))!==count($ids))return false;
    // A later attempt/version must not invalidate an earlier genuine approval.
    $valid=array_filter($state['graded'],fn($r)=>$r['passed']&&!$r['critical_failure']&&(int)$r['score']>=65);
    $allowed=array_column($valid,null,'id');$brands=[];$scenarios=[];
    foreach($ids as $id){if(!is_string($id)||!isset($allowed[$id]))return false;$scenario=$allowed[$id]['scenario_id'];$scenarios[$scenario]=true;$brands[academy_call_catalog()[$scenario]['brand']]=true;}
    return count($scenarios)>=3&&isset($brands['melas'],$brands['distillogic']);
}
