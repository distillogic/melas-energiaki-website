<?php
declare(strict_types=1);
// Shared, read-only policy. Safe to load from Academy without CRM bootstrap/session.
if(!defined('CRM_ROOT')&&!defined('ACADEMY_ROOT')){http_response_code(403);exit;}
function melas_onboarding_rule(PDO $pdo,string $id):?array {
    $q=$pdo->prepare('SELECT * FROM personnel_access_rules WHERE user_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function melas_onboarding_documents_ready(PDO $pdo,string $id):bool {
    $rule=melas_onboarding_rule($pdo,$id);
    if(!$rule)return false;
    if($rule['policy']==='legacy')return true;
    if(empty($rule['preapproved_at']))return false;
    $q=$pdo->prepare('SELECT * FROM personnel_onboarding WHERE user_id=?');$q->execute([$id]);$record=$q->fetch(PDO::FETCH_ASSOC);
    if(!$record||!in_array($record['relationship'],['employee','contractor'],true))return false;
    foreach([$record['relationship']==='employee'?'employment':'services','confidentiality','commission_policy','crm_rules'] as $kind){
        $q=$pdo->prepare('SELECT id,snapshot,worker_at,approved_at,finalized_at,worker_hash,final_hash,(LENGTH(worker_pdf)>100) AS has_worker,(LENGTH(final_pdf)>100) AS has_final FROM personnel_documents WHERE user_id=? AND onboarding_cycle=? AND kind=? ORDER BY revision DESC LIMIT 1');
        $q->execute([$id,$record['onboarding_cycle'],$kind]);$doc=$q->fetch(PDO::FETCH_ASSOC);if(!$doc)return false;
        if(in_array($kind,['commission_policy','crm_rules'],true)){
            $q=$pdo->prepare('SELECT snapshot_hash FROM personnel_policy_receipts WHERE user_id=? AND document_id=?');$q->execute([$id,$doc['id']]);$hash=$q->fetchColumn();
            if(!$hash||!hash_equals(hash('sha256',$doc['snapshot']),(string)$hash))return false;
        }elseif(!$doc['worker_at']||!$doc['approved_at']||!$doc['finalized_at']||!$doc['worker_hash']||!$doc['final_hash']||!$doc['has_worker']||!$doc['has_final'])return false;
    }
    return true;
}
function melas_onboarding_allowed(PDO $pdo,array $user,string $scope):bool {
    if(empty($user['active'])||!in_array($scope,['academy','crm'],true))return false;
    $rule=melas_onboarding_rule($pdo,(string)$user['id']);
    // Unknown identities never inherit the exemption of users present at migration.
    if(!$rule)return false;
    if($rule['policy']==='legacy')return true;
    if($rule['policy']!=='required')return false;
    // Training is the first step, not a privilege obtained by signing documents.
    // This grants no CRM session, role change, business data or signing authority.
    if($scope==='academy')return true;
    if(!melas_onboarding_documents_ready($pdo,(string)$user['id']))return false;
    $q=$pdo->prepare('SELECT released_at FROM personnel_onboarding WHERE user_id=?');$q->execute([$user['id']]);
    return (bool)$q->fetchColumn();
}
