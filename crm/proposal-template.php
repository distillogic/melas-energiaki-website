<?php
declare(strict_types=1);

function proposal_document_html(array $data): string
{
    $v = static fn(string $key, string $fallback = ''): string => e(trim((string)($data[$key] ?? $fallback)));
    $raw = static fn(string $key, string $fallback = ''): string => trim((string)($data[$key] ?? $fallback));
    $selected = array_map('intval', is_array($data['selected_sections'] ?? null) ? $data['selected_sections'] : range(1, 35));
    $show = static fn(int $number): bool => in_array($number, $selected, true);
    $items = static function (string $value): array {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $value) ?: [])));
    };
    $rows = static function (string $value, int $columns): array {
        $result = [];
        foreach (preg_split('/\R/u', $value) ?: [] as $line) {
            if (trim($line) === '') continue;
            $parts = array_map('trim', explode('|', $line));
            $result[] = array_pad(array_slice($parts, 0, $columns), $columns, '—');
        }
        return $result;
    };
    $renderList = static function (array $values): void {
        if (!$values) return;
        echo '<ul>';
        foreach ($values as $value) echo '<li>' . e($value) . '</li>';
        echo '</ul>';
    };
    $renderTable = static function (array $headers, array $values): void {
        if (!$values) return;
        echo '<table><thead><tr>';
        foreach ($headers as $header) echo '<th>' . e($header) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($values as $row) {
            echo '<tr>';
            foreach ($row as $cell) echo '<td>' . e($cell) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    };
    $section = static function (int $number, string $title, callable $content) use ($show): void {
        if (!$show($number)) return;
        echo '<section class="proposal-section"><h2>' . $number . '. ' . e($title) . '</h2>';
        $content();
        echo '</section>';
    };

    ob_start();
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= $v('proposal_reference') ?> — Commercial &amp; Technical Proposal</title>
<style>
@page{size:A4;margin:17mm 16mm 18mm}*{box-sizing:border-box}body{margin:0;color:#132238;background:#fff;font:9.7pt/1.46 Arial,Helvetica,sans-serif}.document{max-width:178mm;margin:auto}.brand{display:flex;align-items:center;justify-content:space-between;gap:18px;border-bottom:3px solid #176afc;padding:0 0 12px;margin-bottom:20px}.brand img{width:245px;max-width:58%;height:auto}.brand-meta{text-align:right;color:#5b6b80;font-size:8.5pt}.classification{color:#176afc;font-weight:800;letter-spacing:.12em}.cover{min-height:255mm;display:flex;flex-direction:column}.cover h1{font-size:28pt;line-height:1.05;margin:38mm 0 8px;color:#071426}.cover h2{font-size:15pt;color:#176afc;margin:0}.cover-card{margin-top:26mm;padding:20px;border:1px solid #cbd8e8;border-radius:12px;background:#f5f8fd}.cover-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 24px}.cover-grid strong{display:block;font-size:8pt;text-transform:uppercase;letter-spacing:.08em;color:#607086}.cover-note{margin-top:auto;border-top:1px solid #cbd8e8;padding-top:12px;color:#64748b;font-size:8.7pt}.page-break{break-before:page}.proposal-section{margin:0 0 13px;break-inside:auto;page-break-inside:auto}.proposal-section h2{margin:0 0 7px;padding:8px 10px;background:#071426;color:#fff;font-size:10.8pt;letter-spacing:.02em;break-after:avoid;page-break-after:avoid}.proposal-section h3{margin:9px 0 4px;color:#176afc;font-size:9.8pt;break-after:avoid;page-break-after:avoid}.proposal-section p{margin:0 0 7px;text-align:justify;orphans:3;widows:3}.proposal-section ul{margin:4px 0 8px;padding-left:20px}.proposal-section li{margin:2px 0}table{width:100%;border-collapse:collapse;margin:7px 0 12px;font-size:8.6pt;break-inside:auto;page-break-inside:auto}thead{display:table-header-group}tr{break-inside:avoid;page-break-inside:avoid}th{background:#071426;color:#fff;text-align:left;padding:7px}td{border:1px solid #cbd8e8;padding:7px;vertical-align:top}tr:nth-child(even) td{background:#f3f7fd}.highlight{padding:12px 14px;border-left:4px solid #176afc;background:#f3f7fd;margin:8px 0;break-inside:avoid}.flow{text-align:center;font-weight:700;color:#176afc;padding:12px;border:1px solid #bfd1eb;border-radius:8px;break-inside:avoid}.signature-page{break-before:page}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:18px;break-inside:avoid}.signature-card{border:1px solid #afbed0;border-radius:10px;padding:16px;min-height:112mm;break-inside:avoid}.signature-card h3{background:#071426;color:#fff;margin:-16px -16px 18px;padding:11px 16px;border-radius:9px 9px 0 0}.line{height:38px;border-bottom:1px solid #596b80;margin:5px 0 12px}.doc-footer{margin-top:16px;text-align:center;color:#64748b;font-size:8.3pt}@media print{.document{max-width:none}.proposal-section h2,th,.signature-card h3{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style></head><body><main class="document">
<section class="cover"><div class="brand"><img src="<?= $v('logo_url') ?>" alt="MELAS ENERGIAKI"><div class="brand-meta"><span class="classification">ΕΜΠΙΣΤΕΥΤΙΚΗ ΠΡΟΤΑΣΗ</span><br><?= $v('proposal_reference') ?><br>Έκδοση <?= $v('version', '1.0') ?> · <?= $v('proposal_date_display') ?></div></div><h1>ΕΠΑΓΓΕΛΜΑΤΙΚΗ<br>ΠΡΟΤΑΣΗ</h1><h2><?= $v('project_name') ?></h2><div class="cover-card"><div class="cover-grid"><div><strong>Προς</strong><?= $v('client_legal_name') ?></div><div><strong>Μοντέλο συνεργασίας</strong><?= $v('engagement_model') ?></div><div><strong>Ημερομηνία</strong><?= $v('proposal_date_display') ?></div><div><strong>Ισχύς</strong><?= $v('validity_days', '30') ?> ημέρες</div><div><strong>Ενδεικτική έναρξη</strong><?= $v('start_date_display', 'Προς επιβεβαίωση') ?></div><div><strong>Διάρκεια</strong><?= $v('duration', 'Προς επιβεβαίωση') ?></div></div></div><div class="cover-note"><strong>MELAS ENERGIAKI</strong><br>Ενεργειακές λύσεις και υπηρεσίες<br>Ελλάδα</div></section>
<div class="page-break"><div class="brand"><img src="<?= $v('logo_url') ?>" alt="MELAS ENERGIAKI"><div class="brand-meta"><span class="classification">ΕΜΠΙΣΤΕΥΤΙΚΗ ΠΡΟΤΑΣΗ</span><br><?= $v('proposal_reference') ?></div></div>
<?php
    $section(1, 'EXECUTIVE SUMMARY', function () use ($v, $raw, $items, $renderList): void { ?><p>Η MELAS ENERGIAKI υποβάλλει την παρούσα πρόταση προς την <strong><?= $v('client_legal_name') ?></strong> για <?= $v('project_name') ?>.</p><p><?= nl2br(e($raw('executive_summary', 'Η MELAS ENERGIAKI προτείνει μια οργανωμένη συνεργασία με σαφές αντικείμενο, υπευθυνότητες και εμπορικούς όρους.'))) ?></p><?php $renderList($items($raw('main_deliverables'))); ?><div class="highlight"><strong>Μοντέλο συνεργασίας:</strong> <?= $v('engagement_model') ?><br><strong>Ενδεικτική έναρξη:</strong> <?= $v('start_date_display', 'Προς επιβεβαίωση') ?><br><strong>Ενδεικτική διάρκεια:</strong> <?= $v('duration', 'Προς επιβεβαίωση') ?></div><?php });
    $section(2, 'UNDERSTANDING OF THE REQUIREMENT', function () use ($raw): void { ?><p><?= nl2br(e($raw('requirement'))) ?></p><p>This understanding shall be validated with the Client before mobilisation. Material changes may require adjustment to scope, timeline, resources and commercial terms.</p><?php });
    $section(3, 'PROPOSED SCOPE', function () use ($raw, $items, $rows, $renderList, $renderTable): void { ?><h3>3.1 In-Scope Services</h3><?php $renderList($items($raw('scope'))); ?><h3>3.2 Deliverables</h3><?php $renderTable(['Deliverable','Description','Acceptance Basis'], $rows($raw('deliverables_table'), 3)); ?><p>Final deliverables and acceptance criteria shall be confirmed in the applicable Statement of Work.</p><?php });
    $section(4, 'OUT OF SCOPE', function () use ($raw, $items, $renderList): void { $renderList($items($raw('out_of_scope', "Work not specifically described in the agreed scope\nThird-party licence and infrastructure costs unless specified\nMaterial changes to approved requirements\nOnsite travel unless specified"))); ?><p>Additional requirements may be evaluated through the agreed Change Request process.</p><?php });
    $section(5, 'DELIVERY APPROACH', function (): void { ?><p>DISTILLOGIC proposes a structured lifecycle covering discovery and confirmation, architecture and technical design, engineering, quality assurance, deployment and acceptance, followed where applicable by transition or ongoing support.</p><?php });
    $section(6, 'DELIVERY LIFECYCLE', function (): void { ?><div class="flow">Requirements → Architecture → Development → Code Review → Testing → Deployment → Monitoring → Continuous Improvement</div><p>The process may be adapted to the Client's engineering and governance standards.</p><?php });
    $section(7, 'PROPOSED TEAM', function () use ($raw, $rows, $renderTable): void { $renderTable(['Role','Seniority','Allocation','Responsibility'], $rows($raw('team'), 4)); ?><p>Team composition is subject to final technical review and resource availability. Equivalent profiles may be proposed before mobilisation.</p><?php });
    $section(8, 'TECHNOLOGY ENVIRONMENT', function () use ($raw): void { ?><p><?= nl2br(e($raw('technology_environment', 'Final technology selection shall remain aligned with the Client architecture, requirements and operational environment.'))) ?></p><?php });
    $section(9, 'PROJECT GOVERNANCE', function (): void { ?><p>Governance may include delivery meetings, technical reviews, progress reporting, risk and dependency management, issue escalation and commercial review.</p><?php });
    $section(10, 'COMMUNICATION MODEL', function () use ($raw): void { ?><p><?= nl2br(e($raw('communication_model', 'The Parties shall nominate authorised commercial, project, technical, scope, acceptance and escalation contacts. Approved channels may include Microsoft Teams, Slack, email, Jira, Azure DevOps or GitHub.'))) ?></p><?php });
    $section(11, 'CLIENT RESPONSIBILITIES', function () use ($raw, $items, $renderList): void { $renderList($items($raw('client_responsibilities', "Provide timely requirements and stakeholder access\nProvide required system access, documentation, environments and test data\nCoordinate relevant third parties\nPerform timely review and acceptance\nProvide required commercial approvals"))); ?><p>Client delays affecting critical dependencies may impact the delivery schedule.</p><?php });
    $section(12, 'ASSUMPTIONS', function () use ($raw, $items, $renderList): void { $renderList($items($raw('assumptions', "Information provided is materially accurate\nStakeholders will be reasonably available\nRequired access will be provided on time\nNo material undisclosed constraints exist\nRequirements will not materially change without formal review"))); ?><p>Materially incorrect assumptions may require adjustment to the delivery plan.</p><?php });
    $section(13, 'DEPENDENCIES', function () use ($raw, $items, $renderList): void { $renderList($items($raw('dependencies'))); ?><p>Παράγοντες εκτός του ελέγχου της MELAS ENERGIAKI ενδέχεται να επηρεάσουν τον χρόνο και την απαιτούμενη προσπάθεια.</p><?php });
    $section(14, 'INDICATIVE DELIVERY PLAN', function () use ($raw, $rows, $renderTable): void { $renderTable(['Phase','Indicative Duration','Target Completion'], $rows($raw('timeline'), 3)); ?><p>The final schedule shall be confirmed during mobilisation and documented in the Statement of Work or delivery plan.</p><?php });
    $section(15, 'COMMERCIAL MODEL', function () use ($v): void { ?><div class="highlight"><strong>Proposed commercial model:</strong> <?= $v('pricing_model') ?></div><?php });
    $section(16, 'COMMERCIAL PRICING', function () use ($v, $raw): void { ?><p><?= nl2br(e($raw('pricing_details'))) ?></p><div class="highlight"><strong>Indicative total / monthly value:</strong> <?= $v('currency', 'EUR') ?> <?= $v('total_value', 'To be confirmed') ?><br><span>Exclusive of VAT and applicable taxes.</span></div><p>Only the commercial model applicable to the engagement remains in this proposal.</p><?php });
    $section(17, 'PRICING BASIS', function (): void { ?><p>Pricing reflects the current scope, proposed team, duration, delivery responsibility, seniority, technology, mobilisation, payment terms and identified project risks. Material changes require commercial review.</p><?php });
    $section(18, 'PAYMENT TERMS', function () use ($raw): void { ?><p><?= nl2br(e($raw('payment_terms', 'Unless otherwise agreed, payment is due Net 30 from the date of a valid and undisputed invoice. Mobilisation or resource reservation may require an initial payment.'))) ?></p><?php });
    $section(19, 'INVOICING', function (): void { ?><p>Invoices may be issued monthly, at agreed milestones, as a mobilisation payment, for recurring capacity or according to approved Time & Materials effort. Where required they may reference the Purchase Order, SOW, project, billing period, timesheets or milestone acceptance.</p><?php });
    $section(20, 'PURCHASE ORDER', function () use ($raw): void { ?><p><?= nl2br(e($raw('purchase_order', "Όπου απαιτείται εντολή αγοράς ή αντίστοιχη έγγραφη έγκριση, η MELAS ENERGIAKI θα πρέπει να την έχει λάβει πριν από την έναρξη χρεώσιμων εργασιών."))) ?></p><?php });
    $section(21, 'COMMERCIAL FLEXIBILITY', function (): void { ?><p>Pricing is specific to this scope and its assumptions. Material changes to scope, team, duration, responsibility, mobilisation, payment terms, location or contractual risk may alter the proposal. Long-term commitments, larger teams or framework relationships may support preferential or blended arrangements.</p><?php });
    $section(22, 'EXPENSES', function (): void { ?><p>Unless included, approved travel, accommodation, transport, visas, third-party services and project-specific licences may be invoiced separately. No material reimbursable expense should be incurred without approval.</p><?php });
    $section(23, 'TAXES', function (): void { ?><p>All quoted prices are exclusive of VAT and applicable taxes unless expressly stated otherwise.</p><?php });
    $section(24, 'CHANGE CONTROL', function (): void { ?><p>A formal Change Request should identify the requested change, technical and schedule impact, and commercial adjustment. Implementation should normally begin following written approval.</p><?php });
    $section(25, 'ACCEPTANCE', function () use ($v): void { ?><p>Deliverables shall be reviewed against agreed criteria. Unless otherwise agreed, the Client should accept or provide documented material rejection within <?= $v('acceptance_days', '5-10') ?> business days. Any deemed-acceptance provision belongs in the final contract.</p><?php });
    $section(26, 'INTELLECTUAL PROPERTY', function (): void { ?><p>Ownership and licensing shall be governed by the applicable agreement. Each Party retains pre-existing intellectual property; project-specific arrangements shall be defined in the MSA and/or SOW.</p><?php });
    $section(27, 'CONFIDENTIALITY', function () use ($v): void { ?><p>Confidential information shall be handled under the applicable NDA, final agreement and law. This proposal is confidential and intended solely for authorised recipients of <?= $v('client_legal_name') ?>.</p><?php });
    $section(28, 'DATA PROTECTION', function (): void { ?><p>Where personal data is involved, the Parties shall comply with applicable obligations and, where necessary, agree a Data Processing Agreement, security requirements, access controls and retention obligations.</p><?php });
    $section(29, 'SECURITY', function (): void { ?><p>Applicable practices may include access control, secure authentication, secrets and dependency management, code review, security testing, encryption, logging and auditability. Project-specific requirements must be identified before commitment.</p><?php });
    $section(30, 'RESOURCE PROTECTION', function (): void { ?><p>Engineering profiles, CVs and resource information are confidential. Any non-solicitation, direct-hire or conversion arrangements are governed by the applicable contract.</p><?php });
    $section(31, 'PROPOSAL VALIDITY', function () use ($v): void { ?><p>This proposal remains valid for <?= $v('validity_days', '30') ?> calendar days from its date. After expiry, pricing, resource availability, start date and schedule may require reconfirmation.</p><?php });
    $section(32, 'CONTRACTUAL DOCUMENTATION', function (): void { ?><p>The engagement may be governed by an NDA, MSA, SOW, Purchase Order, Call-Off, DPA and approved Change Requests. This proposal does not replace final contractual documentation unless expressly agreed in writing.</p><?php });
    $section(33, 'MOBILISATION CONDITIONS', function (): void { ?><p>Mobilisation may depend on execution of agreements, receipt of a Purchase Order or mobilisation payment, confirmation of resource availability, system readiness and Client onboarding.</p><?php });
    $section(34, 'NEXT STEPS', function () use ($raw, $items, $renderList): void { $renderList($items($raw('next_steps', "Confirm scope and outstanding technical questions\nConfirm delivery model and team\nConfirm final commercial terms\nFinalise contractual documentation\nComplete mobilisation\nCommence delivery"))); });
?>
</div>
<?php if ($show(35)): ?><section class="signature-page"><div class="brand"><img src="<?= $v('logo_url') ?>" alt="MELAS ENERGIAKI"><div class="brand-meta"><span class="classification">ΕΜΠΙΣΤΕΥΤΙΚΗ ΠΡΟΤΑΣΗ</span><br><?= $v('proposal_reference') ?></div></div><section class="proposal-section"><h2>35. ΑΠΟΔΟΧΗ ΠΡΟΤΑΣΗΣ</h2><p>Οι εξουσιοδοτημένοι εκπρόσωποι μπορούν να συμπληρώσουν την ενότητα για την επίσημη αποδοχή της πρότασης.</p></section><div class="signatures"><div class="signature-card"><h3>Για τη MELAS ENERGIAKI</h3><strong>Όνομα</strong><div>Spiros Melas</div><br><strong>Ιδιότητα</strong><div>Managing Director</div><br><strong>Υπογραφή</strong><div class="line"></div><strong>Ημερομηνία</strong><div class="line"></div></div><div class="signature-card"><h3>Για την <?= $v('client_legal_name') ?></h3><strong>Όνομα</strong><div><?= $v('client_contact_name', '—') ?></div><br><strong>Ιδιότητα</strong><div><?= $v('client_contact_title', '—') ?></div><br><strong>Υπογραφή</strong><div class="line"></div><strong>Ημερομηνία</strong><div class="line"></div></div></div><div class="doc-footer">MELAS ENERGIAKI · <?= $v('proposal_reference') ?></div></section><?php endif; ?>
</main></body></html>
    <?php
    return (string)ob_get_clean();
}
