<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_lead_schema();
$leadWhere=can_review_leads($user)?'':' WHERE submitted_by='.db()->quote($user['id']);
$leadRows=db()->query('SELECT status,COUNT(*) total FROM sales_leads'.$leadWhere.' GROUP BY status')->fetchAll();$leadCounts=array_fill_keys(array_keys(lead_status_labels()),0);foreach($leadRows as $row)$leadCounts[$row['status']]=(int)$row['total'];
$leadFollowWhere=can_review_leads($user)?'':' AND submitted_by='.db()->quote($user['id']);
$leadFollowUps=db()->query("SELECT id,submitted_by,contacts_released_at,lead_reference,company_name,follow_up_at,follow_up_note,(follow_up_at<NOW()) overdue FROM sales_leads WHERE follow_up_at IS NOT NULL AND status NOT IN ('won','lost','rejected')".$leadFollowWhere.' ORDER BY follow_up_at LIMIT 5')->fetchAll();
$leadFollowUps=array_map(static fn(array $lead):array=>protected_lead_view($user,$lead),$leadFollowUps);

$totals = db()->query(
    "SELECT COUNT(*) AS communications,
            COUNT(DISTINCT company_id) AS companies,
            SUM(source = 'website') AS website,
            SUM(source = 'telephone') AS telephone,
            SUM(assigned_user_id IS NULL) AS unassigned,
            SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS last_30_days,
            SUM(next_action_at IS NOT NULL AND next_action_at < NOW() AND status NOT IN ('won','lost','archived')) AS overdue,
            SUM(next_action_at >= NOW() AND status NOT IN ('won','lost','archived')) AS upcoming
       FROM communications
      WHERE deleted_at IS NULL AND status <> 'archived'"
)->fetch() ?: [];

$recent = db()->query(
    "SELECT c.id, c.source, c.outcome, c.interest_level, c.contact_name, c.created_at,
            co.name AS company_name, COALESCE(u.name, 'Μη ανατεθειμένο') AS user_name
       FROM communications c
       JOIN companies co ON co.id = c.company_id
       LEFT JOIN users u ON u.id = c.assigned_user_id
      WHERE c.deleted_at IS NULL
      ORDER BY c.created_at DESC LIMIT 6"
)->fetchAll();

$followUps = db()->query(
    "SELECT c.id, c.next_action, c.next_action_at, co.name AS company_name,
            (c.next_action_at < NOW()) AS overdue
       FROM communications c JOIN companies co ON co.id = c.company_id
      WHERE c.deleted_at IS NULL AND c.next_action_at IS NOT NULL
        AND c.status NOT IN ('won','lost','archived')
      ORDER BY c.next_action_at ASC LIMIT 6"
)->fetchAll();

$activityRows = db()->query(
    "SELECT DATE(created_at) AS activity_date, COUNT(*) AS activity_count
       FROM communications
      WHERE deleted_at IS NULL AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
      GROUP BY DATE(created_at)"
)->fetchAll();
$activityMap = [];
foreach ($activityRows as $row) {
    $activityMap[$row['activity_date']] = (int)$row['activity_count'];
}
$activity = [];
for ($daysAgo = 13; $daysAgo >= 0; $daysAgo--) {
    $date = (new DateTimeImmutable("-{$daysAgo} days"))->format('Y-m-d');
    $activity[] = ['date' => $date, 'count' => $activityMap[$date] ?? 0];
}
$maxActivity = max(1, ...array_column($activity, 'count'));

$outcomeRows = db()->query(
    "SELECT COALESCE(outcome, 'unset') AS outcome_key, COUNT(*) AS outcome_count
       FROM communications
      WHERE deleted_at IS NULL AND status <> 'archived'
      GROUP BY outcome"
)->fetchAll();
$outcomeCounts = [];
foreach ($outcomeRows as $row) {
    $outcomeCounts[$row['outcome_key']] = (int)$row['outcome_count'];
}
$outcomeLabels = [
    'interested' => 'Ενδιαφέρεται',
    'callback' => 'Επανάκληση',
    'no_answer' => 'Δεν απάντησε',
    'not_interested' => 'Δεν ενδιαφέρεται',
    'unset' => 'Χωρίς αποτέλεσμα',
];
$communicationTotal = max(1, (int)($totals['communications'] ?? 0));

render_header('Επισκόπηση', $user);
?>
<div class="studio-dashboard">
<section class="studio-heading" aria-labelledby="dashboard-title">
  <div><span class="eyebrow">Η ΕΡΓΑΣΙΑ ΣΑΣ ΣΗΜΕΡΑ <span> / <?= e(date('d.m.Y')) ?></span></span><h1 id="dashboard-title">Κάθε επαφή, ένα επόμενο βήμα.</h1><p>Οι ευκαιρίες, οι επικοινωνίες και οι προτεραιότητές σας, σε έναν χώρο.</p></div>
  <a class="button primary" href="<?= e(crm_url('new-lead.php')) ?>"><span aria-hidden="true">＋</span> Νέα ευκαιρία</a>
</section>
<nav class="ux-action-grid" aria-label="Ξεκινήστε από εδώ">
  <a class="ux-action-card" href="<?= e(crm_url('leads.php')) ?>"><?= crm_icon('qualification') ?><span><strong>Διαχειριστείτε ευκαιρίες</strong><small>Καταχώριση, αξιολόγηση και εξέλιξη</small></span><b aria-hidden="true">↗</b></a>
  <a class="ux-action-card" href="<?= e(crm_url('reminders.php')) ?>"><?= crm_icon('bell') ?><span><strong>Δείτε τις υπενθυμίσεις σας</strong><small>Τι χρειάζεται την προσοχή σας</small></span><b aria-hidden="true">↗</b></a>
  <a class="ux-action-card" href="<?= e(crm_url('new-communication.php')) ?>"><?= crm_icon('companies') ?><span><strong>Καταγράψτε επικοινωνία</strong><small>Κρατήστε τη συζήτηση και το επόμενο βήμα</small></span><b aria-hidden="true">↗</b></a>
</nav>
<section aria-label="Sales pipeline"><div class="pipeline-strip"><?php foreach(['prospect','contacted','decision_maker_found','need_identified','qualification_in_progress','submitted','under_review','qualified','commercial_discussion','won'] as $key): ?><a href="<?= e(crm_url('leads.php?status='.$key)) ?>"><strong><?= $leadCounts[$key] ?></strong><span><?= e(crm_stage_label_v38($key)) ?></span></a><?php endforeach; ?></div></section>

<section class="studio-metrics" aria-label="Σύνοψη δραστηριότητας">
  <article><span class="metric-icon"><?= crm_icon('companies') ?></span><span class="metric-label">Εταιρείες σε επαφή</span><strong><?= (int)($totals['companies'] ?? 0) ?></strong><small>με καταγεγραμμένη επικοινωνία</small></article>
  <article><span class="metric-icon teal"><?= crm_icon('documents') ?></span><span class="metric-label">Επικοινωνίες</span><strong><?= (int)($totals['communications'] ?? 0) ?></strong><small><?= (int)($totals['last_30_days'] ?? 0) ?> τις τελευταίες 30 ημέρες</small></article>
  <article><span class="metric-icon violet"><?= crm_icon('accounts') ?></span><span class="metric-label">Χωρίς ανάθεση</span><strong><?= (int)($totals['unassigned'] ?? 0) ?></strong><small><?= (int)($totals['unassigned'] ?? 0) > 0 ? 'χρειάζονται υπεύθυνο' : 'όλα έχουν ανατεθεί' ?></small></article>
  <article><span class="metric-icon amber"><?= crm_icon('bell') ?></span><span class="metric-label">Υπενθυμίσεις</span><strong><?= (int)($totals['overdue'] ?? 0) + (int)($totals['upcoming'] ?? 0) ?></strong><small><?= (int)($totals['overdue'] ?? 0) ?> εκπρόθεσμες · <?= (int)($totals['upcoming'] ?? 0) ?> προσεχείς</small></article>
</section>

<section class="studio-main-grid">
  <article class="card report-card studio-chart"><header><div><span class="eyebrow">ACTIVITY PULSE</span><h2>Ο ρυθμός των επικοινωνιών</h2></div><span class="period-chip">14 ημέρες</span></header>
    <div class="chart-summary"><strong><?= array_sum(array_column($activity, 'count')) ?></strong><span>καταγραφές στο διάστημα<br><small><?= e((new DateTimeImmutable('-13 days'))->format('d/m')) ?> — <?= e(date('d/m')) ?></small></span></div>
    <div class="studio-chart-area"><div class="chart-axis" aria-hidden="true"><span><?= $maxActivity ?></span><span>0</span></div><div class="bar-chart" role="img" aria-label="Επικοινωνίες τις τελευταίες 14 ημέρες">
    <?php foreach ($activity as $day): ?><div class="bar-day" title="<?= e($day['date']) ?> · <?= $day['count'] ?>"><span class="sr-only"><?= e($day['date']) ?>: <?= $day['count'] ?></span><i style="height:<?= (int)round(($day['count'] / $maxActivity) * 100) ?>%"></i><small><?= e((new DateTimeImmutable($day['date']))->format('d')) ?></small></div><?php endforeach; ?>
    </div></div>
    <div class="channel-summary"><span><i></i> Χειροκίνητες καταγραφές <b><?= (int)($totals['telephone'] ?? 0) ?></b></span><small>Δεν υπάρχει σύνδεση με φόρμα ιστοσελίδας.</small></div>
  </article>
  <article class="card report-card studio-agenda" id="next-actions"><header><div><span class="eyebrow">YOUR NEXT MOVES</span><h2>Επόμενες ενέργειες</h2></div><span class="agenda-icon"><?= crm_icon('qualification') ?></span></header>
    <div class="agenda-summary"><strong><?= (int)($totals['overdue'] ?? 0) ?></strong><span>εκπρόθεσμες υπενθυμίσεις<br><small>Οι παλαιότερες εμφανίζονται πρώτες</small></span></div>
    <?php if (!$followUps): ?><p class="report-empty">Δεν υπάρχουν προγραμματισμένες υπενθυμίσεις.</p><?php else: ?><ul class="report-list"><?php foreach ($followUps as $item): ?><li><a href="<?= e(crm_url('communication.php?id=' . urlencode($item['id']))) ?>"><span><strong><?= e($item['company_name']) ?></strong><small><?= e($item['next_action'] ?: 'Χωρίς περιγραφή ενέργειας') ?></small></span><span><time><?= e(format_datetime($item['next_action_at'])) ?></time><small class="<?= $item['overdue'] ? 'negative-text' : '' ?>"><?= $item['overdue'] ? 'Εκπρόθεσμη' : 'Προγραμματισμένη' ?></small></span></a></li><?php endforeach; ?></ul><?php endif; ?>
  </article>
  <article class="card report-card studio-recent"><header><div><span class="eyebrow">RELATIONSHIP FEED</span><h2>Πρόσφατες επικοινωνίες</h2></div><a class="studio-text-link" href="<?= e(crm_url('communications.php')) ?>">Όλες <span aria-hidden="true">↗</span></a></header>
    <?php if (!$recent): ?><p class="report-empty">Δεν έχει καταγραφεί ακόμη καμία επικοινωνία.</p><?php else: ?><ul class="report-list"><?php foreach ($recent as $item): ?><li><a href="<?= e(crm_url('communication.php?id=' . urlencode($item['id']))) ?>"><span><strong><?= e($item['company_name']) ?></strong><small><?= e(($item['contact_name'] ?: 'Χωρίς επαφή') . ' · ' . $item['user_name']) ?></small></span><span><small><?= e($outcomeLabels[$item['outcome'] ?: 'unset']) ?><?= $item['interest_level'] ? ' · ' . (int)$item['interest_level'] . '/5' : '' ?></small><time><?= e(format_datetime($item['created_at'])) ?></time></span></a></li><?php endforeach; ?></ul><?php endif; ?>
  </article>
  <article class="card report-card studio-outcomes"><header><div><span class="eyebrow">CONVERSATION OUTCOMES</span><h2>Πού βρισκόμαστε</h2></div></header><ul class="outcome-list">
    <?php foreach ($outcomeLabels as $key => $label): $count = $outcomeCounts[$key] ?? 0; if ($key === 'unset' && $count === 0) continue; $share = (int)round(($count / $communicationTotal) * 100); ?><li><div><span><i class="tone-<?= e($key) ?>"></i><?= e($label) ?></span><b><?= $count ?> · <?= $share ?>%</b></div><em><i class="tone-<?= e($key) ?>" style="width:<?= $share ?>%"></i></em></li><?php endforeach; ?>
  </ul></article>
  <article class="card report-card"><header><div><span class="eyebrow">LEAD FOLLOW-UPS</span><h2>Επόμενες κινήσεις pipeline</h2></div><a class="studio-text-link" href="<?= e(crm_url('leads.php')) ?>">Όλες <span aria-hidden="true">↗</span></a></header><?php if(!$leadFollowUps): ?><p class="report-empty">Δεν υπάρχουν προγραμματισμένα follow-ups.</p><?php else: ?><ul class="report-list"><?php foreach($leadFollowUps as $item): ?><li><a href="<?= e(crm_url('lead.php?id='.$item['id'])) ?>"><span><strong><?= e($item['company_name']) ?></strong><small><?= e($item['follow_up_note']?:$item['lead_reference']) ?></small></span><span><time><?= e(format_datetime($item['follow_up_at'])) ?></time><small class="<?= $item['overdue']?'negative-text':'' ?>"><?= $item['overdue']?'Εκπρόθεσμο':'Προγραμματισμένο' ?></small></span></a></li><?php endforeach; ?></ul><?php endif; ?></article>
</section>
<nav class="studio-shortcuts" aria-label="Γρήγορη πρόσβαση"><?php if(($user['role']??'')!=='partner'): ?><a href="<?= e(crm_url('customers.php')) ?>"><?= crm_icon('companies') ?><span><strong>Εταιρείες &amp; συνεργασίες</strong><small>Στοιχεία και ιστορικό επικοινωνίας</small></span><b aria-hidden="true">↗</b></a><?php endif; ?><a href="<?= e(crm_url('leads.php')) ?>"><?= crm_icon('qualification') ?><span><strong>Sales pipeline</strong><small>Prospects, qualified leads και follow-ups</small></span><b aria-hidden="true">↗</b></a><a href="<?= e(crm_url('commissions.php')) ?>"><?= crm_icon('documents') ?><span><strong>Προμήθειες</strong><small>Πληρωτέες και πληρωμένες αμοιβές</small></span><b aria-hidden="true">↗</b></a><?php if(($user['role']??'')!=='partner'): ?><a href="<?= e(crm_url('proposals.php')) ?>"><?= crm_icon('documents') ?><span><strong>Επαγγελματικές προτάσεις</strong><small>Δημιουργία, CEO Approval και αποστολή</small></span><b aria-hidden="true">↗</b></a><?php endif; ?></nav>
</div>
<?php render_footer(); ?>
