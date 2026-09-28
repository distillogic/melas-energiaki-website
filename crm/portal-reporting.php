<?php
declare(strict_types=1);
if(!defined('CRM_ROOT')){http_response_code(403);exit;}

function sales_csv_cell(mixed $value): string
{
    $text=(string)($value??'');
    return preg_match('/^[\s\x{FEFF}]*[=+@\-]/u',$text)?"'".$text:$text;
}

/** Operational counts only. A Team Leader never gets member financial ledgers. */
function sales_pipeline_metrics(array $actor): array
{
    $scope=array_values(array_unique(array_merge([$actor['id']],sales_member_ids($actor))));
    $all=can_view_all_sales_financials($actor);
    $where=$all?'1=1':'l.submitted_by IN ('.implode(',',array_fill(0,count($scope),'?')).')';
    $q=db()->prepare("SELECT COALESCE(p.stage,'contacted') stage,COUNT(*) total
        FROM sales_leads l LEFT JOIN sales_lead_pipeline p ON p.lead_id=l.id
        WHERE $where GROUP BY COALESCE(p.stage,'contacted')");
    $q->execute($all?[]:$scope);return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}

function sales_notifications(array $actor,bool $all=false): array
{
    $notices=[];$pdo=db();
    $q=$pdo->prepare("SELECT lead_reference,id,follow_up_at FROM sales_leads WHERE submitted_by=?
        AND follow_up_at<=DATE_ADD(NOW(),INTERVAL 1 DAY) AND status NOT IN ('lost','rejected','won')
        ORDER BY follow_up_at".($all?'':' LIMIT 20'));$q->execute([$actor['id']]);
    foreach($q->fetchAll() as $row)$notices[]=['event'=>'followup:'.$row['id'].':'.$row['follow_up_at'],'label'=>$row['lead_reference'].' · follow-up '.$row['follow_up_at'],'url'=>'lead.php?id='.$row['id']];
    $q=$pdo->prepare("SELECT id,subject,status,updated_at FROM sales_requests WHERE requester_id=? AND response IS NOT NULL ORDER BY updated_at DESC".($all?'':' LIMIT 10'));$q->execute([$actor['id']]);
    foreach($q->fetchAll() as $row)$notices[]=['event'=>'request:'.$row['id'].':'.$row['updated_at'],'label'=>'Απάντηση: '.$row['subject'].' · '.$row['status'],'url'=>'sales-portal.php?tab=requests'];
    $q=$pdo->prepare("SELECT c.id,c.commission_amount,c.status,l.lead_reference FROM lead_commissions c JOIN sales_leads l ON l.id=c.lead_id
        WHERE c.beneficiary_user_id=? AND c.status IN ('payable','paid') ORDER BY COALESCE(c.paid_at,c.earned_at) DESC".($all?'':' LIMIT 10'));$q->execute([$actor['id']]);
    foreach($q->fetchAll() as $row)$notices[]=['event'=>'commission:'.$row['id'].':'.$row['status'],'label'=>$row['lead_reference'].' · €'.$row['commission_amount'].' · '.($row['status']==='paid'?'Πληρωμή καταχωρίστηκε':'Αμοιβή πληρωτέα'),'url'=>'commissions.php'];
    return $notices;
}

/** The same fixed account scope applies to every export, never a submitted user_id. */
function sales_accounting_rows(array $actor): array
{
    $all=can_view_all_sales_financials($actor);$params=$all?[]:[$actor['id']];
    $q=db()->prepare("SELECT c.id,c.lead_id,l.lead_reference,u.name,u.email,c.commission_type,c.commission_amount amount,
        c.status,c.earned_at,c.payout_date,c.payout_reference,c.source_record_id
        FROM lead_commissions c JOIN sales_leads l ON l.id=c.lead_id JOIN users u ON u.id=c.beneficiary_user_id".
        ($all?'':' WHERE c.beneficiary_user_id=?'));$q->execute($params);$rows=$q->fetchAll();
    $q=db()->prepare("SELECT o.id,o.lead_id,l.lead_reference,u.name,u.email,'team_leader_override' commission_type,o.amount,
        CASE WHEN o.status='paid' THEN 'paid' WHEN c.status IN ('payable','paid') THEN 'payable' ELSE c.status END status,
        o.earned_at,o.payout_date,o.payout_reference,o.source_commission_id source_record_id
        FROM sales_team_overrides o JOIN lead_commissions c ON c.id=o.source_commission_id JOIN sales_leads l ON l.id=o.lead_id
        JOIN users u ON u.id=o.beneficiary_user_id".($all?'':' WHERE o.beneficiary_user_id=?'));$q->execute($params);
    $rows=array_merge($rows,$q->fetchAll());usort($rows,fn($a,$b)=>strcmp((string)($b['earned_at']??''),(string)($a['earned_at']??'')));return $rows;
}
