<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_lead_schema();

if (!can_view_all_sales_financials($user)) {
    http_response_code(403);
    render_header('Δεν επιτρέπεται η πρόσβαση', $user);
    echo '<section class="card"><h1>Δεν επιτρέπεται η πρόσβαση</h1><p>Τα συγκεντρωτικά οικονομικά στοιχεία είναι διαθέσιμα μόνο στους εξουσιοδοτημένους διαχειριστές.</p></section>';
    render_footer();
    exit;
}

function analytics_date(string $value, string $fallback): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}

function analytics_query(string $sql, array $params = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function analytics_one(string $sql, array $params = []): array
{
    $rows = analytics_query($sql, $params);
    return $rows[0] ?? [];
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$yearStart = (new DateTimeImmutable('first day of January'))->format('Y-m-d');
$from = analytics_date(trim((string)($_GET['from'] ?? $yearStart)), $yearStart);
$to = analytics_date(trim((string)($_GET['to'] ?? $today)), $today);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$partners = analytics_query(
    "SELECT DISTINCT u.id,u.name,u.email
     FROM users u
     LEFT JOIN sales_leads l ON l.submitted_by=u.id
     LEFT JOIN lead_commissions c ON c.beneficiary_user_id=u.id
     WHERE u.active=1 OR l.id IS NOT NULL OR c.id IS NOT NULL
     ORDER BY u.name"
);
$partnerIds = array_column($partners, 'id');
$partnerId = trim((string)($_GET['user_id'] ?? ''));
if ($partnerId !== '' && !in_array($partnerId, $partnerIds, true)) {
    $partnerId = '';
}

$visibleCompanySql="CASE WHEN l.contacts_released_at IS NOT NULL OR l.submitted_by=".db()->quote((string)$user['id'])." THEN l.company_name ELSE 'Προστατευμένο lead' END";
$leadWhere = 'l.created_at >= ? AND l.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
$leadParams = [$from, $to];
if ($partnerId !== '') {
    $leadWhere .= ' AND l.submitted_by = ?';
    $leadParams[] = $partnerId;
}

$leadSummary = analytics_one(
    "SELECT COUNT(*) total_leads,
            COALESCE(SUM(l.qualified_at IS NOT NULL),0) qualified_leads,
            COALESCE(SUM(l.opportunity_type IN ('used_panels','repowering','equipment_replacement')),0) panel_leads
     FROM sales_leads l WHERE {$leadWhere}",
    $leadParams
);

$converted = analytics_one(
    "SELECT COUNT(DISTINCT l.id) converted_leads
     FROM sales_leads l
     INNER JOIN panel_purchase_payments p ON p.lead_id=l.id
     WHERE {$leadWhere}",
    $leadParams
);
$panelLeadCount = (int)($leadSummary['panel_leads'] ?? 0);
$convertedCount = (int)($converted['converted_leads'] ?? 0);
$conversionRate = $panelLeadCount > 0 ? ($convertedCount / $panelLeadCount) * 100 : 0.0;

$paymentWhere = 'p.seller_paid_at >= ? AND p.seller_paid_at <= ?';
$paymentParams = [$from, $to];
if ($partnerId !== '') {
    $paymentWhere .= ' AND l.submitted_by = ?';
    $paymentParams[] = $partnerId;
}
$panelSummary = analytics_one(
    "SELECT COALESCE(SUM(p.panel_quantity),0) panel_quantity,
            COALESCE(SUM(p.seller_payment_amount),0) seller_amount
     FROM panel_purchase_payments p
     JOIN sales_leads l ON l.id=p.lead_id
     WHERE {$paymentWhere}",
    $paymentParams
);

$commissionPartner = $partnerId !== '' ? ' AND c.beneficiary_user_id = ?' : '';
$pendingParams = [$from, $to];
if ($partnerId !== '') $pendingParams[] = $partnerId;
$pendingSummary = analytics_one(
    "SELECT COALESCE(SUM(c.commission_amount),0) amount,COUNT(*) item_count
     FROM lead_commissions c
     WHERE c.status IN ('earned','payable')
       AND COALESCE(c.earned_at,c.approved_at) >= ?
       AND COALESCE(c.earned_at,c.approved_at) < DATE_ADD(?, INTERVAL 1 DAY){$commissionPartner}",
    $pendingParams
);
$paidParams = [$from, $to];
if ($partnerId !== '') $paidParams[] = $partnerId;
$paidSummary = analytics_one(
    "SELECT COALESCE(SUM(c.commission_amount),0) amount,COUNT(*) item_count
     FROM lead_commissions c
     WHERE c.status='paid' AND c.paid_at >= ? AND c.paid_at < DATE_ADD(?, INTERVAL 1 DAY){$commissionPartner}",
    $paidParams
);

$qualifiedParams = $leadParams;
$qualifiedRows = analytics_query(
    "SELECT l.id,l.lead_reference,{$visibleCompanySql} company_name,l.qualified_at,
            u.name partner_name,c.commission_amount,c.status,c.approved_at,c.paid_at,c.payment_reference
     FROM sales_leads l
     JOIN users u ON u.id=l.submitted_by
     JOIN lead_commissions c ON c.lead_id=l.id AND c.commission_type='qualified_lead'
     WHERE {$leadWhere}
     ORDER BY COALESCE(c.paid_at,c.approved_at) DESC",
    $qualifiedParams
);
$qualifiedPaidCount = 0;
foreach ($qualifiedRows as $row) {
    if ($row['status'] === 'paid') $qualifiedPaidCount++;
}

$monthlyRows = analytics_query(
    "SELECT DATE_FORMAT(p.seller_paid_at,'%Y-%m') month_key,
            COALESCE(SUM(p.panel_quantity),0) panel_quantity,
            COALESCE(SUM(p.seller_payment_amount),0) seller_amount,
            COALESCE(SUM(c.commission_amount),0) commission_amount
     FROM panel_purchase_payments p
     JOIN sales_leads l ON l.id=p.lead_id
     JOIN lead_commissions c ON c.id=p.commission_id
     WHERE {$paymentWhere}
     GROUP BY DATE_FORMAT(p.seller_paid_at,'%Y-%m')
     ORDER BY month_key DESC",
    $paymentParams
);

$partnerLeadRows = analytics_query(
    "SELECT l.submitted_by user_id,u.name,u.email,COUNT(*) total_leads,
            COALESCE(SUM(l.qualified_at IS NOT NULL),0) qualified_leads,
            COUNT(DISTINCT p.lead_id) purchased_leads,
            COALESCE(SUM(pp.panel_quantity),0) panel_quantity
     FROM sales_leads l
     JOIN users u ON u.id=l.submitted_by
     LEFT JOIN (SELECT DISTINCT lead_id FROM panel_purchase_payments) p ON p.lead_id=l.id
     LEFT JOIN (SELECT lead_id,SUM(panel_quantity) panel_quantity FROM panel_purchase_payments GROUP BY lead_id) pp ON pp.lead_id=l.id
     WHERE {$leadWhere}
     GROUP BY l.submitted_by,u.name,u.email
     ORDER BY u.name",
    $leadParams
);
$partnerCommissionParams = [$from, $to];
if ($partnerId !== '') $partnerCommissionParams[] = $partnerId;
$partnerCommissionRows = analytics_query(
    "SELECT c.beneficiary_user_id user_id,
            COALESCE(SUM(CASE WHEN c.status<>'cancelled' THEN c.commission_amount ELSE 0 END),0) total_amount,
            COALESCE(SUM(CASE WHEN c.status IN ('earned','payable') THEN c.commission_amount ELSE 0 END),0) pending_amount,
            COALESCE(SUM(CASE WHEN c.status='paid' THEN c.commission_amount ELSE 0 END),0) paid_amount
     FROM lead_commissions c
     WHERE COALESCE(c.earned_at,c.approved_at) >= ?
       AND COALESCE(c.earned_at,c.approved_at) < DATE_ADD(?, INTERVAL 1 DAY){$commissionPartner}
     GROUP BY c.beneficiary_user_id",
    $partnerCommissionParams
);
$commissionsByPartner = [];
foreach ($partnerCommissionRows as $row) $commissionsByPartner[$row['user_id']] = $row;
$listedPartnerIds = [];
foreach ($partnerLeadRows as &$row) {
    $listedPartnerIds[] = $row['user_id'];
    $money = $commissionsByPartner[$row['user_id']] ?? [];
    $row['total_amount'] = $money['total_amount'] ?? 0;
    $row['pending_amount'] = $money['pending_amount'] ?? 0;
    $row['paid_amount'] = $money['paid_amount'] ?? 0;
}
unset($row);
foreach ($partners as $partner) {
    if (!isset($commissionsByPartner[$partner['id']]) || in_array($partner['id'], $listedPartnerIds, true)) continue;
    $money = $commissionsByPartner[$partner['id']];
    $partnerLeadRows[] = [
        'user_id'=>$partner['id'],'name'=>$partner['name'],'email'=>$partner['email'],
        'total_leads'=>0,'qualified_leads'=>0,'purchased_leads'=>0,'panel_quantity'=>0,
        'total_amount'=>$money['total_amount'],'pending_amount'=>$money['pending_amount'],'paid_amount'=>$money['paid_amount'],
    ];
}
usort($partnerLeadRows, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));

$clientRows = analytics_query(
    "SELECT {$visibleCompanySql} company_name,COUNT(*) total_leads,
            COALESCE(SUM(l.qualified_at IS NOT NULL),0) qualified_leads,
            COALESCE(SUM(l.status='won'),0) won_leads,
            COALESCE(SUM(pp.panel_quantity),0) panel_quantity,
            COALESCE(SUM(cc.commission_amount),0) commission_amount
     FROM sales_leads l
     LEFT JOIN (SELECT lead_id,SUM(panel_quantity) panel_quantity FROM panel_purchase_payments GROUP BY lead_id) pp ON pp.lead_id=l.id
     LEFT JOIN (SELECT lead_id,SUM(CASE WHEN status<>'cancelled' THEN commission_amount ELSE 0 END) commission_amount FROM lead_commissions GROUP BY lead_id) cc ON cc.lead_id=l.id
     WHERE {$leadWhere}
     GROUP BY {$visibleCompanySql}
     ORDER BY total_leads DESC,company_name
     LIMIT 100",
    $leadParams
);

if (($_GET['export'] ?? '') === 'accounting') {
    $exportParams = [$from, $to];
    if ($partnerId !== '') $exportParams[] = $partnerId;
    $exportRows = analytics_query(
        "SELECT l.lead_reference,{$visibleCompanySql} company_name,u.name partner_name,u.email partner_email,
                c.commission_type,c.basis_quantity,c.basis_amount,c.rate,c.commission_amount,
                c.status,CASE WHEN l.contacts_released_at IS NOT NULL OR l.submitted_by=".db()->quote((string)$user['id'])." THEN c.payment_reference ELSE '' END payment_reference,c.earned_at,c.approved_at,c.paid_at
         FROM lead_commissions c
         JOIN sales_leads l ON l.id=c.lead_id
         JOIN users u ON u.id=c.beneficiary_user_id
         WHERE COALESCE(c.earned_at,c.approved_at) >= ?
           AND COALESCE(c.earned_at,c.approved_at) < DATE_ADD(?, INTERVAL 1 DAY){$commissionPartner}
         ORDER BY COALESCE(c.paid_at,c.earned_at,c.approved_at),l.lead_reference",
        $exportParams
    );
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="melas-accounting-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Lead','Εταιρεία','Συνεργάτης','Email','Τύπος προμήθειας','Ποσότητα/Βάση','Συντελεστής','Προμήθεια EUR','Κατάσταση','Αναφορά πληρωμής','Ημερομηνία δικαιώματος','Εγκρίθηκε','Πληρώθηκε']);
    foreach ($exportRows as $row) {
        $basis = $row['commission_type'] === 'panel_purchase' ? $row['basis_quantity'] : $row['basis_amount'];
        fputcsv($out, array_map('sales_csv_cell',[$row['lead_reference'],$row['company_name'],$row['partner_name'],$row['partner_email'],commission_type_label((string)$row['commission_type']),$basis,$row['rate'],number_format((float)$row['commission_amount'],2,'.',''),commission_status_label((string)$row['status']),$row['payment_reference'],$row['earned_at'],$row['approved_at'],$row['paid_at']]),',','"','');
    }
    fclose($out);
    exit;
}

$filterQuery = ['from'=>$from,'to'=>$to];
if ($partnerId !== '') $filterQuery['user_id'] = $partnerId;
render_header('Στατιστικά πελατών', $user);
?>
<div class="page-heading analytics-heading">
  <div><span class="eyebrow">Sales intelligence</span><h1>Στατιστικά πελατών</h1><p>Leads, αγορές πάνελ, προμήθειες συνεργατών και έτοιμη αναφορά για το λογιστήριο.</p></div>
  <a class="button primary" href="<?= e(crm_url('sales-analytics.php?'.http_build_query($filterQuery+['export'=>'accounting']))) ?>">Αναφορά λογιστηρίου CSV</a>
</div>

<form class="card analytics-filter" method="get">
  <label>Από<input type="date" name="from" value="<?= e($from) ?>" required></label>
  <label>Έως<input type="date" name="to" value="<?= e($to) ?>" required></label>
  <label>Συνεργάτης<select name="user_id"><option value="">Όλοι οι συνεργάτες</option><?php foreach ($partners as $partner): ?><option value="<?= e($partner['id']) ?>" <?= $partnerId===$partner['id']?'selected':'' ?>><?= e($partner['name']) ?> · <?= e($partner['email']) ?></option><?php endforeach; ?></select></label>
  <button class="button primary" type="submit">Εφαρμογή</button>
  <a class="button" href="<?= e(crm_url('sales-analytics.php')) ?>">Καθαρισμός</a>
</form>

<section class="analytics-kpis" aria-label="Βασικοί δείκτες">
  <article><span>Σύνολο leads</span><strong><?= e((string)($leadSummary['total_leads'] ?? 0)) ?></strong><small><?= e((string)($leadSummary['qualified_leads'] ?? 0)) ?> έχουν εγκριθεί</small></article>
  <article><span>Qualified leads με εξοφλημένη αμοιβή</span><strong><?= e((string)$qualifiedPaidCount) ?></strong><small>από <?= e((string)count($qualifiedRows)) ?> εγκεκριμένα qualified leads</small></article>
  <article><span>Πάνελ που αγοράστηκαν</span><strong><?= e(number_format((float)($panelSummary['panel_quantity'] ?? 0),0,',','.')) ?></strong><small>Πληρωμή πωλητών €<?= e(number_format((float)($panelSummary['seller_amount'] ?? 0),2,',','.')) ?></small></article>
  <article><span>Πληρωμένες προμήθειες</span><strong>€<?= e(number_format((float)($paidSummary['amount'] ?? 0),2,',','.')) ?></strong><small><?= e((string)($paidSummary['item_count'] ?? 0)) ?> πληρωμές στην περίοδο</small></article>
  <article><span>Εκκρεμείς προμήθειες</span><strong>€<?= e(number_format((float)($pendingSummary['amount'] ?? 0),2,',','.')) ?></strong><small><?= e((string)($pendingSummary['item_count'] ?? 0)) ?> δεδουλευμένες / πληρωτέες</small></article>
  <article class="analytics-rate"><span>Μετατροπή lead → αγορά</span><strong><?= e(number_format($conversionRate,1,',','.')) ?>%</strong><small><?= e((string)$convertedCount) ?> από <?= e((string)$panelLeadCount) ?> leads πάνελ</small><i style="--rate:<?= e((string)min(100,max(0,$conversionRate))) ?>%"></i></article>
</section>

<div class="analytics-grid">
  <section class="card table-card analytics-wide"><header class="analytics-card-head"><div><h2>Σύνολο προμηθειών ανά συνεργάτη</h2><p>Απόδοση και οικονομική εικόνα για την επιλεγμένη περίοδο.</p></div></header><div class="table-wrap"><table><thead><tr><th>Συνεργάτης</th><th>Leads</th><th>Qualified</th><th>Αγορές</th><th>Πάνελ</th><th>Σύνολο</th><th>Εκκρεμή</th><th>Πληρωμένα</th></tr></thead><tbody><?php foreach ($partnerLeadRows as $row): ?><tr><td><strong><?= e($row['name']) ?></strong><br><small><?= e($row['email']) ?></small></td><td><?= e((string)$row['total_leads']) ?></td><td><?= e((string)$row['qualified_leads']) ?></td><td><?= e((string)$row['purchased_leads']) ?></td><td><?= e(number_format((float)$row['panel_quantity'],0,',','.')) ?></td><td><strong>€<?= e(number_format((float)$row['total_amount'],2,',','.')) ?></strong></td><td>€<?= e(number_format((float)$row['pending_amount'],2,',','.')) ?></td><td>€<?= e(number_format((float)$row['paid_amount'],2,',','.')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php if (!$partnerLeadRows): ?><p class="empty-state">Δεν υπάρχουν στοιχεία συνεργατών για αυτή την περίοδο.</p><?php endif; ?></section>

  <section class="card table-card"><header class="analytics-card-head"><div><h2>Πάνελ ανά μήνα</h2><p>Μετράμε μόνο πάνελ των οποίων ο πωλητής πληρώθηκε.</p></div></header><div class="table-wrap"><table><thead><tr><th>Μήνας</th><th>Πάνελ</th><th>Πληρωμή πωλητών</th><th>Προμήθεια</th></tr></thead><tbody><?php foreach ($monthlyRows as $row): ?><tr><td><strong><?= e((new DateTimeImmutable($row['month_key'].'-01'))->format('m/Y')) ?></strong></td><td><?= e(number_format((float)$row['panel_quantity'],0,',','.')) ?></td><td>€<?= e(number_format((float)$row['seller_amount'],2,',','.')) ?></td><td>€<?= e(number_format((float)$row['commission_amount'],2,',','.')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php if (!$monthlyRows): ?><p class="empty-state">Δεν υπάρχουν πληρωμένες αγορές πάνελ.</p><?php endif; ?></section>

  <section class="card table-card"><header class="analytics-card-head"><div><h2>Εγκεκριμένα qualified leads</h2><p>Τρέχουσα σταθερή αμοιβή €80. Τα ήδη πληρωμένα παλαιότερα ποσά διατηρούνται στο ιστορικό.</p></div></header><div class="table-wrap"><table><thead><tr><th>Lead / Εταιρεία</th><th>Συνεργάτης</th><th>Κατάσταση</th><th>Ημερομηνία</th></tr></thead><tbody><?php foreach ($qualifiedRows as $row): ?><tr><td><a href="<?= e(crm_url('lead.php?id='.$row['id'])) ?>"><strong><?= e($row['lead_reference']) ?></strong></a><br><small><?= e($row['company_name']) ?></small></td><td><?= e($row['partner_name']) ?></td><td><span class="commission-status status-<?= e($row['status']) ?>"><?= e(commission_status_label((string)$row['status'])) ?></span></td><td><?= e(format_datetime($row['paid_at'] ?: $row['approved_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php if (!$qualifiedRows): ?><p class="empty-state">Δεν υπάρχουν εγκεκριμένα qualified leads.</p><?php endif; ?></section>

  <section class="card table-card analytics-wide"><header class="analytics-card-head"><div><h2>Στατιστικά ανά πελάτη / εταιρεία</h2><p>Συγκεντρωτική εικόνα ανά εταιρεία που εμφανίζεται στα leads.</p></div></header><div class="table-wrap"><table><thead><tr><th>Εταιρεία</th><th>Leads</th><th>Qualified</th><th>Won</th><th>Πάνελ</th><th>Προμήθειες</th></tr></thead><tbody><?php foreach ($clientRows as $row): ?><tr><td><strong><?= e($row['company_name']) ?></strong></td><td><?= e((string)$row['total_leads']) ?></td><td><?= e((string)$row['qualified_leads']) ?></td><td><?= e((string)$row['won_leads']) ?></td><td><?= e(number_format((float)$row['panel_quantity'],0,',','.')) ?></td><td>€<?= e(number_format((float)$row['commission_amount'],2,',','.')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php if (!$clientRows): ?><p class="empty-state">Δεν υπάρχουν πελάτες ή εταιρείες για αυτή την περίοδο.</p><?php endif; ?></section>
</div>
<p class="analytics-note">Η μετατροπή υπολογίζεται στα leads αγοράς πάνελ που δημιουργήθηκαν μέσα στην περίοδο και έχουν τουλάχιστον μία καταχωρισμένη πληρωμή προς πωλητή. Οι εκκρεμείς προμήθειες περιλαμβάνουν τις καταστάσεις «δεδουλευμένη» και «πληρωτέα».</p>
<?php render_footer(); ?>
