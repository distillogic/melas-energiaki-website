<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$user=require_login(); ensure_lead_schema();
$statuses=lead_status_labels(); $status=trim((string)($_GET['status']??''));
$where=[];$params=[];
if(!can_review_leads($user)){ $where[]='l.submitted_by=?';$params[]=$user['id']; }
if($status!==''&&isset($statuses[$status])){$where[]='l.status=?';$params[]=$status;}
$sql='SELECT l.*,u.name submitter_name FROM sales_leads l JOIN users u ON u.id=l.submitted_by'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY l.updated_at DESC';
$query=db()->prepare($sql);$query->execute($params);$leads=array_map(static fn(array $lead):array=>protected_lead_view($user,$lead),$query->fetchAll());
$countWhere=[];$countParams=[];if(!can_review_leads($user)){$countWhere[]='submitted_by=?';$countParams[]=$user['id'];}$countQuery=db()->prepare('SELECT status,COUNT(*) total FROM sales_leads'.($countWhere?' WHERE '.implode(' AND ',$countWhere):'').' GROUP BY status');$countQuery->execute($countParams);$counts=array_fill_keys(array_keys($statuses),0);foreach($countQuery->fetchAll() as $row)$counts[$row['status']]=(int)$row['total'];
render_header('Εμπορικές ευκαιρίες',$user);
?>
<div class="page-heading"><div><span class="eyebrow">Sales partner workspace</span><h1>Εμπορικές ευκαιρίες</h1><p>Καταχώριση, αξιολόγηση και παρακολούθηση leads και προμηθειών.</p></div><a class="button primary" href="<?= e(crm_url('new-lead.php')) ?>">+ Νέο lead</a></div>
<div class="ux-flow-note"><strong>Η διαδρομή μιας ευκαιρίας</strong><p>Καταχωρίστε όσα γνωρίζετε → συμπληρώστε τα απαιτούμενα στοιχεία → στείλτε για αξιολόγηση → παρακολουθήστε την εξέλιξη. Η αρχική καταχώριση δεν ενεργοποιεί από μόνη της προμήθεια.</p></div>
<div class="pipeline-strip"><?php foreach(['prospect','contacted','decision_maker_found','need_identified','qualification_in_progress','submitted','under_review','qualified','commercial_discussion','won'] as $key): ?><a href="<?= e(crm_url('leads.php?status='.$key)) ?>"><strong><?= $counts[$key] ?></strong><span><?= e(crm_stage_label_v38($key)) ?></span></a><?php endforeach; ?></div>
<form class="card search-card" method="get"><label>Κατάσταση<select name="status"><option value="">Όλες</option><?php foreach($statuses as $key=>$label): ?><option value="<?= e($key) ?>" <?= $status===$key?'selected':'' ?>><?= e(crm_stage_label_v38($key)) ?></option><?php endforeach; ?></select></label><button class="button" type="submit">Φιλτράρισμα</button></form>
<section class="card table-card"><div class="table-wrap"><table><thead><tr><th>Lead</th><th>Εταιρεία / επαφή</th><th>Ευκαιρία</th><th>Ιδιοκτήτης / δικαιούχος</th><th>Κατάσταση</th><th>Follow-up</th></tr></thead><tbody>
<?php foreach($leads as $lead): $overdue=$lead['follow_up_at']&&strtotime($lead['follow_up_at'])<time()&&!in_array($lead['status'],['won','lost','rejected'],true); ?><tr><td><a href="<?= e(crm_url('lead.php?id='.$lead['id'])) ?>"><strong><?= e($lead['lead_reference']) ?></strong></a></td><td><strong><?= e($lead['company_name']) ?></strong><br><span class="muted"><?= e($lead['contact_name']?:'Στοιχεία προστατευμένα') ?></span></td><td><?= e(opportunity_type_labels()[$lead['opportunity_type']]??$lead['opportunity_type']) ?></td><td><?= e($lead['submitter_name']) ?></td><td><span class="lead-status status-<?= e($lead['status']) ?>"><?= e(crm_stage_label_v38($lead['status'])) ?></span></td><td class="<?= $overdue?'follow-overdue':'' ?>"><?= e(format_datetime($lead['follow_up_at'])) ?><?= $overdue?' · Εκπρόθεσμο':'' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php if(!$leads): ?><p class="empty-state">Δεν υπάρχουν leads σε αυτή την προβολή.</p><?php endif; ?></section>
<?php render_footer(); ?>
