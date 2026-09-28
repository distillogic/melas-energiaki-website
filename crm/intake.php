<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/intake-template.php';
$user = require_login();
ensure_customer_workspace_schema();
if (!in_array($user['role'], ['admin', 'manager'], true)) { http_response_code(403); exit('Η πρόσβαση στο Project Intake επιτρέπεται μόνο στη διοίκηση.'); }

$id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$isExisting = preg_match('/^[a-f0-9-]{36}$/i', $id) === 1;
$errors = [];
$load = static function (string $documentId): array {
    $statement = db()->prepare("SELECT d.*, c.name AS company_name, c.legal_name, c.email AS company_email, c.website AS company_website, c.country AS company_country, c.contact_name, c.contact_title, c.phone AS company_phone FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=? AND d.document_type='intake' AND c.deleted_at IS NULL LIMIT 1");
    $statement->execute([$documentId]);
    $row = $statement->fetch();
    if (!$row) { http_response_code(404); exit('Το Project Intake δεν βρέθηκε.'); }
    return $row;
};
$intake = $isExisting ? $load($id) : null;
$data = $intake ? (json_decode((string)$intake['data_json'], true) ?: []) : [];
$companyId = (int)($intake['company_id'] ?? $_GET['company_id'] ?? $_POST['company_id'] ?? 0);
$companies = db()->query("SELECT id,name,legal_name,email,website,country,contact_name,contact_title,phone FROM companies WHERE deleted_at IS NULL ORDER BY name")->fetchAll();
$companyById = [];
foreach ($companies as $row) $companyById[(int)$row['id']] = $row;

$fields = [
    'Opportunity & Decision Structure' => [
        'source'=>'Source / προέλευση ευκαιρίας','primary_contact'=>'Primary contact','contact_position'=>'Position / department','contact_details'=>'Email / telephone','decision_role'=>'Role in decision','decision_structure'=>'Decision-making structure (Role | Name | Position | Influence)',
    ],
    'Requirement, Scope & Acceptance' => [
        'requirement'=>'Τι χρειάζεται ο πελάτης;','problem'=>'Ποιο πρόβλημα επιλύει;','driver'=>'Γιατί απαιτείται τώρα;','expected_outcome'=>'Expected outcome','engagement_type'=>'Engagement type','client_delivery_model'=>'Client delivery preference','recommended_model'=>'Recommended DISTILLOGIC model & reason','in_scope'=>'In scope','out_of_scope'=>'Explicitly out of scope','scope_clarity'=>'Scope clarity & notes','deliverables'=>'Deliverables (Deliverable | Description | Mandatory | Acceptance defined)','acceptance'=>'Acceptance criteria, approver & review period',
    ],
    'Technology, Resources & Timeline' => [
        'technical_environment'=>'Backend, frontend, mobile, databases, cloud, integrations, DevOps & infrastructure','architecture_status'=>'Architecture status & available documentation','legacy_system'=>'Legacy / existing system, age, technology, issues & technical debt','resource_requirements'=>'Requested roles (Role | Seniority | Quantity | Start | Duration)','delivery_location'=>'Delivery location, client location, onsite & travel','working_hours'=>'Working hours, time zone, overlap & non-standard requirements','timeline'=>'Requested start, target completion, duration & deadline type','urgency'=>'Urgency, reason & commercial impact',
    ],
    'Commercial & Procurement' => [
        'budget'=>'Budget, type & realism','commercial_expectations'=>'Rate / price expectations, discounts, volume or framework terms','payment_terms'=>'Payment terms, billing frequency & payment risk','procurement'=>'Purchase Order, supplier registration, vendor portal & lead time','contractual_requirements'=>'NDA, MSA, SOW, DPA, security schedule & client terms','liability'=>'Liability cap, unlimited liability & indemnities','insurance'=>'Required insurance, coverage & current ability to satisfy',
    ],
    'Security, Data & Dependencies' => [
        'security_requirements'=>'Security questionnaire, MFA, SSO, devices, VPN, VDI, testing & training','certification_requirements'=>'Required certifications, whether mandatory & present compliance','gdpr'=>'Personal data, data subjects, categories, special-category data & DPA','data_location'=>'Required data location','third_party_dependencies'=>'Dependency | Owner | Risk','client_dependencies'=>'Requirements, access, environments, test data, approvals & coordination',
    ],
    'Opportunity & Risk Assessment' => [
        'competitive_situation'=>'Competitors, incumbent supplier & reason for change','client_motivation'=>'Primary buying motivation','strategic_value'=>'Long-term, framework, repeat work, reference & follow-on value','risk_assessment'=>'Scope, technical, timeline, dependency, commercial, payment, legal, security & resource risks','red_flags'=>'All identified red flags',
    ],
    'Financial Exposure & Internal Readiness' => [
        'commercial_exposure'=>'Expected monthly cost, revenue, maximum unpaid exposure, terms & approval requirement','opportunity_value'=>'Expected contract value, duration & follow-on value','win_probability'=>'Win probability and reasoning','bid_decision'=>'BID / BID WITH CONDITIONS / HOLD / NO-BID and reason','conditions_before_proposal'=>'Conditions that must be clarified before proposal','pricing_review'=>'Model, opening position, target, internal floor, margin & payment-term adjustment','proposed_team'=>'Role | Quantity | Seniority | Availability','capability_check'=>'Immediate, mobilisation, partial, sourcing required or no capability',
    ],
    'Next Action & Qualification Summary' => [
        'required_next_action'=>'Required next action','next_action_owner'=>'Owner, action & target date','summary_client_need'=>'Client need','summary_solution'=>'Proposed DISTILLOGIC solution','summary_opportunity'=>'Commercial opportunity','summary_risk'=>'Key risk','summary_next_step'=>'Recommended next step','management_decision'=>'Management decision','management_comments'=>'Management comments / conditions',
    ],
];
$allKeys = [];
foreach ($fields as $group) foreach ($group as $key => $_label) $allKeys[] = $key;

$logoData = static function (): string {
    $path = dirname(__DIR__) . '/assets/logo/distillogic-logo-light.svg';
    $logo = is_file($path) ? file_get_contents($path) : false;
    return $logo === false ? '' : 'data:image/svg+xml;base64,' . base64_encode($logo);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $companyId = (int)($_POST['company_id'] ?? 0);
    $client = $companyById[$companyId] ?? null;
    $projectName = trim(mb_substr((string)($_POST['project_name'] ?? ''), 0, 255));
    $receivedDate = trim((string)($_POST['received_date'] ?? date('Y-m-d')));
    if (!$client) $errors[] = 'Επιλέξτε πελάτη.';
    if ($projectName === '') $errors[] = 'Το opportunity / project name είναι υποχρεωτικό.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedDate)) $errors[] = 'Η ημερομηνία δεν είναι έγκυρη.';
    if (!$errors && $client) {
        $reference = $intake ? (string)$intake['document_reference'] : 'DL-INT-' . date('Ymd') . '-' . $companyId . '-' . strtoupper(bin2hex(random_bytes(2)));
        $intakeData = [
            'document_reference'=>$reference,'opportunity_reference'=>trim((string)($_POST['opportunity_reference'] ?? '')) ?: 'DL-OPP-' . date('Ymd') . '-' . $companyId,
            'version'=>trim((string)($_POST['version'] ?? '1.0')) ?: '1.0','received_date'=>$receivedDate,'date_display'=>(new DateTimeImmutable($receivedDate))->format('d/m/Y'),
            'project_name'=>$projectName,'client_legal_name'=>trim((string)($_POST['client_legal_name'] ?? '')) ?: (string)($client['legal_name'] ?: $client['name']),
            'country'=>trim((string)($_POST['country'] ?? '')),'website'=>trim((string)($_POST['website'] ?? '')),'logo_url'=>$logoData(),
        ];
        foreach ($allKeys as $key) $intakeData[$key] = trim(mb_substr((string)($_POST[$key] ?? ''), 0, 12000));
        $html = intake_document_html($intakeData);
        $status = match (strtoupper($intakeData['bid_decision'])) { 'BID' => 'qualified', 'NO-BID', 'NO BID' => 'no_bid', 'HOLD' => 'hold', default => 'draft' };
        $fileName = preg_replace('/[^A-Za-z0-9._-]/', '-', $reference . '-' . $projectName) . '.doc';
        if ($intake) {
            db()->prepare("UPDATE company_documents SET company_id=?,document_status=?,title=?,file_name=?,mime_type='application/msword; charset=UTF-8',content=?,data_json=?,effective_date=? WHERE id=? AND document_type='intake'")->execute([$companyId,$status,'Project Intake — '.$projectName,$fileName,$html,json_encode($intakeData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$receivedDate,$id]);
        } else {
            $id = uuid_v4();
            db()->prepare("INSERT INTO company_documents (id,company_id,created_by,document_type,document_status,document_reference,title,file_name,mime_type,content,data_json,effective_date) VALUES (?,?,?,'intake',?,?,?,?, 'application/msword; charset=UTF-8',?,?,?)")->execute([$id,$companyId,$user['id'],$status,$reference,'Project Intake — '.$projectName,$fileName,$html,json_encode($intakeData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$receivedDate]);
        }
        flash('success', 'Το Project Intake αποθηκεύτηκε με ασφάλεια και συνδέθηκε με την καρτέλα του πελάτη.');
        redirect_to('intake.php?id=' . urlencode($id));
    }
    $data = array_merge($data, $_POST);
}

if (!$data) {
    $client = $companyById[$companyId] ?? null;
    $data = [
        'received_date'=>date('Y-m-d'),'version'=>'1.0','client_legal_name'=>$client ? (string)($client['legal_name'] ?: $client['name']) : '',
        'country'=>$client['country'] ?? '','website'=>$client['website'] ?? '','primary_contact'=>$client['contact_name'] ?? '',
        'contact_position'=>$client['contact_title'] ?? '','contact_details'=>implode(' / ', array_filter([$client['email'] ?? '', $client['phone'] ?? ''])),
        'payment_terms'=>'Net 30 / Monthly billing / Risk not yet assessed','win_probability'=>'25% - Qualified Opportunity',
    ];
}
render_header($intake ? 'Project Intake ' . $intake['document_reference'] : 'Νέο Project Intake', $user);
?>
<div class="page-heading"><div><p class="eyebrow">INTERNAL QUALIFICATION</p><h1><?= $intake ? e($intake['document_reference']) : 'Νέο Project Intake / RFP Qualification' ?></h1><p>Εσωτερική αξιολόγηση πριν από proposal, pricing ή δέσμευση πόρων.</p></div><div class="actions"><a class="button" href="<?= e(crm_url('intakes.php')) ?>">Όλα τα Intakes</a><?php if($intake): ?><a class="button primary" target="_blank" href="<?= e(crm_url('intake-view.php?id='.urlencode($id))) ?>">Προβολή / PDF</a><?php endif; ?></div></div>
<div class="alert info"><strong>Management only.</strong> Το έγγραφο περιέχει internal floor, margin, risk και credit exposure και δεν αποστέλλεται στον πελάτη.</div>
<?php if($errors): ?><div class="alert error"><?= e(implode(' ', array_unique($errors))) ?></div><?php endif; ?>
<form method="post" class="stack proposal-editor"><?= csrf_field() ?><?php if($intake): ?><input type="hidden" name="id" value="<?= e($id) ?>"><?php endif; ?>
<section class="card form-section"><header><h2>1. Opportunity control</h2></header><div class="form-grid">
<label>Πελάτης *<select name="company_id" required><option value="">Επιλέξτε</option><?php foreach($companies as $row): ?><option value="<?= (int)$row['id'] ?>" <?= $companyId===(int)$row['id']?'selected':'' ?>><?= e($row['name']) ?></option><?php endforeach; ?></select></label>
<label>Opportunity / Project name *<input name="project_name" required value="<?= e($data['project_name'] ?? '') ?>"></label><label>Legal entity<input name="client_legal_name" value="<?= e($data['client_legal_name'] ?? '') ?>"></label><label>Date received *<input type="date" name="received_date" required value="<?= e($data['received_date'] ?? date('Y-m-d')) ?>"></label><label>Internal opportunity reference<input name="opportunity_reference" value="<?= e($data['opportunity_reference'] ?? '') ?>" placeholder="Auto-generated if blank"></label><label>Version<input name="version" value="<?= e($data['version'] ?? '1.0') ?>"></label><label>Country<input name="country" value="<?= e($data['country'] ?? '') ?>"></label><label>Website<input name="website" value="<?= e($data['website'] ?? '') ?>"></label>
</div></section>
<?php $groupNumber=2; foreach($fields as $groupTitle=>$groupFields): ?><section class="card form-section"><header><h2><?= $groupNumber++ ?>. <?= e($groupTitle) ?></h2></header><div class="form-grid"><?php foreach($groupFields as $key=>$fieldLabel): ?><label class="field-full"><?= e($fieldLabel) ?><textarea name="<?= e($key) ?>" rows="3"><?= e($data[$key] ?? '') ?></textarea></label><?php endforeach; ?></div></section><?php endforeach; ?>
<section class="card form-section"><div class="actions"><button class="button primary" type="submit"><?= $intake?'Αποθήκευση ενημέρωσης':'Δημιουργία Project Intake' ?></button></div></section></form>
<?php render_footer(); ?>
