<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proposal-template.php';
$user = require_login();
ensure_customer_workspace_schema();
$id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$isExisting = preg_match('/^[a-f0-9-]{36}$/i', $id) === 1;
$errors = [];
$approvalEmail = crm_approval_email();

$loadProposal = static function (string $proposalId): array {
    $statement = db()->prepare("SELECT d.*, c.name AS company_name, c.legal_name, c.email AS company_email, c.contact_name, c.contact_title FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=? AND d.document_type='proposal' AND c.deleted_at IS NULL LIMIT 1");
    $statement->execute([$proposalId]);
    $proposal = $statement->fetch();
    if (!$proposal) { http_response_code(404); exit('Η προσφορά δεν βρέθηκε.'); }
    return $proposal;
};
$proposal = $isExisting ? $loadProposal($id) : null;
$data = $proposal ? json_decode((string)$proposal['data_json'], true) : [];
$data = is_array($data) ? $data : [];
$companyId = (int)($proposal['company_id'] ?? $_GET['company_id'] ?? $_POST['company_id'] ?? 0);
$companies = db()->query("SELECT id, name, legal_name, email, contact_name, contact_title FROM companies WHERE deleted_at IS NULL ORDER BY name")->fetchAll();
$companyById = [];
foreach ($companies as $companyRow) $companyById[(int)$companyRow['id']] = $companyRow;

$sectionLabels = [
    1=>'Executive Summary',2=>'Understanding of Requirement',3=>'Proposed Scope',4=>'Out of Scope',5=>'Delivery Approach',6=>'Delivery Lifecycle',7=>'Proposed Team',8=>'Technology Environment',9=>'Project Governance',10=>'Communication Model',11=>'Client Responsibilities',12=>'Assumptions',13=>'Dependencies',14=>'Indicative Delivery Plan',15=>'Commercial Model',16=>'Commercial Pricing',17=>'Pricing Basis',18=>'Payment Terms',19=>'Invoicing',20=>'Purchase Order',21=>'Commercial Flexibility',22=>'Expenses',23=>'Taxes',24=>'Change Control',25=>'Acceptance',26=>'Intellectual Property',27=>'Confidentiality',28=>'Data Protection',29=>'Security',30=>'Resource Protection',31=>'Proposal Validity',32=>'Contractual Documentation',33=>'Mobilisation Conditions',34=>'Next Steps',35=>'Proposal Acceptance',
];
$teamProfile = [1,2,3,4,7,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,27,30,31,32,33,34,35];
$projectProfile = range(1, 35);

$proposalLogo = static function (): string {
    $siteUrl = rtrim((string)(crm_config()['app']['site_url'] ?? 'https://melasenergiaki.gr'), '/');
    $logoPath = __DIR__ . '/assets/melas-energiaki-logo.png';
    $logo = is_file($logoPath) ? file_get_contents($logoPath) : false;
    return $logo === false ? $siteUrl . '/assets/logo.png' : 'data:image/png;base64,' . base64_encode($logo);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    contract_post_preflight($action,$errors,$proposal,'proposal',$user);

    if ($proposal && contract_action_requires_prerequisites($action)) {
        $prerequisiteErrors = contract_prerequisite_errors((int)$proposal['company_id'], 'proposal');
        if ($prerequisiteErrors) {
            $errors = array_merge($errors, $prerequisiteErrors);
            $action = 'blocked_by_contract_flow';
        }
    }

    if ($action === 'save') {
        $companyId = (int)($_POST['company_id'] ?? 0);
        $client = $companyById[$companyId] ?? null;
        $projectName = trim(mb_substr((string)($_POST['project_name'] ?? ''), 0, 220));
        $clientEmail = trim(mb_substr((string)($_POST['client_email'] ?? ''), 0, 320));
        $clientEmailConfirm = trim(mb_substr((string)($_POST['client_email_confirm'] ?? ''), 0, 320));
        $proposalDate = (string)($_POST['proposal_date'] ?? date('Y-m-d'));
        $validityDays = max(1, min(180, (int)($_POST['validity_days'] ?? 30)));
        $selectedSections = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_sections'] ?? [])), static fn(int $number): bool => $number >= 1 && $number <= 35)));
        sort($selectedSections);
        if (!$client) $errors[] = 'Επιλέξτε πελάτη.';
        if ($projectName === '') $errors[] = 'Το project / engagement είναι υποχρεωτικό.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $proposalDate)) $errors[] = 'Η ημερομηνία πρότασης δεν είναι έγκυρη.';
        if (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Το email του πελάτη είναι υποχρεωτικό και πρέπει να είναι έγκυρο.';
        if (mb_strtolower($clientEmail) !== mb_strtolower($clientEmailConfirm)) $errors[] = 'Τα δύο email του πελάτη δεν είναι ίδια.';
        if (!in_array(1, $selectedSections, true) || !in_array(16, $selectedSections, true) || !in_array(34, $selectedSections, true)) $errors[] = 'Τα sections 1, 16 και 34 πρέπει να παραμείνουν στην πρόταση.';

        if (!$errors && $client) {
            $reference = $proposal ? (string)$proposal['document_reference'] : 'ME-PRO-' . date('Ymd') . '-' . $companyId . '-' . strtoupper(bin2hex(random_bytes(2)));
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $proposalData = [
                'proposal_reference'=>$reference, 'version'=>trim((string)($_POST['version'] ?? '1.0')) ?: '1.0',
                'proposal_date'=>$proposalDate, 'proposal_date_display'=>(new DateTimeImmutable($proposalDate))->format('d/m/Y'),
                'validity_days'=>$validityDays, 'project_name'=>$projectName,
                'client_legal_name'=>trim((string)($_POST['client_legal_name'] ?? '')) ?: ((string)($client['legal_name'] ?: $client['name'])),
                'client_email'=>$clientEmail, 'client_contact_name'=>trim((string)($_POST['client_contact_name'] ?? '')),
                'client_contact_title'=>trim((string)($_POST['client_contact_title'] ?? '')),
                'engagement_model'=>trim((string)($_POST['engagement_model'] ?? '')),
                'start_date'=>$startDate, 'start_date_display'=>$startDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ? (new DateTimeImmutable($startDate))->format('d/m/Y') : 'Προς επιβεβαίωση',
                'duration'=>trim((string)($_POST['duration'] ?? '')), 'executive_summary'=>trim((string)($_POST['executive_summary'] ?? '')),
                'main_deliverables'=>trim((string)($_POST['main_deliverables'] ?? '')), 'requirement'=>trim((string)($_POST['requirement'] ?? '')),
                'scope'=>trim((string)($_POST['scope'] ?? '')), 'deliverables_table'=>trim((string)($_POST['deliverables_table'] ?? '')),
                'out_of_scope'=>trim((string)($_POST['out_of_scope'] ?? '')), 'team'=>trim((string)($_POST['team'] ?? '')),
                'technology_environment'=>trim((string)($_POST['technology_environment'] ?? '')), 'communication_model'=>trim((string)($_POST['communication_model'] ?? '')),
                'client_responsibilities'=>trim((string)($_POST['client_responsibilities'] ?? '')), 'assumptions'=>trim((string)($_POST['assumptions'] ?? '')),
                'dependencies'=>trim((string)($_POST['dependencies'] ?? '')), 'timeline'=>trim((string)($_POST['timeline'] ?? '')),
                'pricing_model'=>trim((string)($_POST['pricing_model'] ?? '')), 'currency'=>'EUR (€)',
                'total_value'=>trim((string)($_POST['total_value'] ?? '')), 'pricing_details'=>trim((string)($_POST['pricing_details'] ?? '')),
                'payment_terms'=>trim((string)($_POST['payment_terms'] ?? '')), 'purchase_order'=>trim((string)($_POST['purchase_order'] ?? '')),
                'acceptance_days'=>trim((string)($_POST['acceptance_days'] ?? '5-10')), 'next_steps'=>trim((string)($_POST['next_steps'] ?? '')),
                'selected_sections'=>$selectedSections, 'profile'=>(string)($_POST['profile'] ?? 'project'), 'logo_url'=>$proposalLogo(),
            ];
            $html = proposal_document_html($proposalData);
            $fileName = preg_replace('/[^A-Za-z0-9._-]/', '-', $reference) . '.doc';
            if ($proposal) {
                $update = db()->prepare("UPDATE company_documents SET company_id=?, document_status='draft', title=?, file_name=?, mime_type='application/msword; charset=UTF-8', content=?, data_json=?, effective_date=?, approval_code_hash=NULL, approval_code_expires_at=NULL, approval_attempts=0, approval_requested_at=NULL, approval_requested_by=NULL, approved_at=NULL, approval_confirmed_by=NULL, final_signed_file_name=NULL, final_signed_pdf=NULL, final_signed_at=NULL WHERE id=? AND document_type='proposal'");
                $update->execute([$companyId, 'Commercial & Technical Proposal — ' . $projectName, $fileName, $html, json_encode($proposalData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $proposalDate, $id]);
            } else {
                $id = uuid_v4();
                $insert = db()->prepare("INSERT INTO company_documents (id, company_id, created_by, document_type, document_status, document_reference, title, file_name, mime_type, content, data_json, effective_date) VALUES (?, ?, ?, 'proposal', 'draft', ?, ?, ?, 'application/msword; charset=UTF-8', ?, ?, ?)");
                $insert->execute([$id, $companyId, $user['id'], $reference, 'Commercial & Technical Proposal — ' . $projectName, $fileName, $html, json_encode($proposalData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $proposalDate]);
            }
            db()->prepare('UPDATE companies SET legal_name=?, email=?, contact_name=?, contact_title=? WHERE id=?')->execute([$proposalData['client_legal_name'], $clientEmail, $proposalData['client_contact_name'] ?: null, $proposalData['client_contact_title'] ?: null, $companyId]);
            flash('success', 'Η πρόταση αποθηκεύτηκε ως draft. Κάθε αλλαγή μηδενίζει προηγούμενη έγκριση για ασφάλεια.');
            redirect_to('proposal.php?id=' . urlencode($id));
        }
        $data = $_POST;
        $data['selected_sections'] = $selectedSections;
    }

    if ($proposal && $action === 'request_approval') {
        if (!$proposal['final_signed_pdf']) $errors[] = 'Ανεβάστε πρώτα το ακριβές τελικό PDF που θα εγκριθεί.';
        elseif ($proposal['approved_at']) $errors[] = 'Η πρόταση έχει ήδη εγκριθεί.';
        elseif ($proposal['approval_requested_at'] && strtotime((string)$proposal['approval_requested_at']) > time() - 60) $errors[] = 'Περιμένετε ένα λεπτό πριν ζητήσετε νέο κωδικό.';
        else {
            $code = (string)random_int(100000, 999999);
            $expiresAt = (new DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');
            $emailHtml = '<div style="font-family:Arial,sans-serif;color:#10213a;max-width:620px"><h2 style="color:#176afc">Proposal approval</h2><p>Ζητήθηκε έγκριση για την πρόταση <strong>' . e($proposal['document_reference']) . '</strong> προς <strong>' . e($proposal['company_name']) . '</strong>.</p><p style="font-size:32px;font-weight:800;letter-spacing:8px;background:#eef5ff;padding:18px;text-align:center;border-radius:10px">' . e($code) . '</p><p>Ο κωδικός λήγει σε 10 λεπτά και επιτρέπει έως 5 προσπάθειες.</p><p style="color:#64748b">Αίτημα από: ' . e($user['name']) . ' (' . e($user['email']) . ')</p></div>';
            if (send_crm_email($approvalEmail, 'Proposal approval code — ' . $proposal['document_reference'], $emailHtml)) {
                db()->prepare("UPDATE company_documents SET document_status='approval_pending', approval_code_hash=?, approval_code_expires_at=?, approval_attempts=0, approval_requested_at=NOW(), approval_requested_by=? WHERE id=?")->execute([password_hash($code, PASSWORD_DEFAULT), $expiresAt, $user['id'], $id]);
                flash('success', 'Ο εξαψήφιος κωδικός στάλθηκε στο email έγκρισης της Melas Energiaki.');
                redirect_to('proposal.php?id=' . urlencode($id));
            }
            $errors[] = 'Δεν ήταν δυνατή η αποστολή του κωδικού έγκρισης.';
        }
    }

    if ($proposal && $action === 'verify_approval') {
        $code = preg_replace('/\D/', '', (string)($_POST['approval_code'] ?? ''));
        if (strlen((string)$code) !== 6) $errors[] = 'Ο κωδικός πρέπει να έχει 6 ψηφία.';
        elseif (!$proposal['approval_code_hash'] || !$proposal['approval_code_expires_at']) $errors[] = 'Ζητήστε πρώτα κωδικό.';
        elseif ((int)$proposal['approval_attempts'] >= 5) $errors[] = 'Έγιναν πολλές αποτυχημένες προσπάθειες.';
        elseif (new DateTimeImmutable((string)$proposal['approval_code_expires_at']) < new DateTimeImmutable()) $errors[] = 'Ο κωδικός έληξε.';
        elseif (!password_verify((string)$code, (string)$proposal['approval_code_hash'])) {
            db()->prepare('UPDATE company_documents SET approval_attempts=approval_attempts+1 WHERE id=?')->execute([$id]);
            $errors[] = 'Ο κωδικός δεν είναι σωστός.';
        } else {
            db()->prepare("UPDATE company_documents SET document_status='approved_pdf', approved_at=NOW(), approval_confirmed_by=?, approval_code_hash=NULL, approval_code_expires_at=NULL, approval_attempts=0 WHERE id=?")->execute([$user['id'], $id]);
            flash('success', 'Το συγκεκριμένο PDF εγκρίθηκε από τον CEO και είναι έτοιμο για αποστολή.');
            redirect_to('proposal.php?id=' . urlencode($id));
        }
    }

    if ($proposal && $action === 'upload_final_pdf') {
        $file = $_FILES['final_pdf'] ?? null;
        if ($proposal['approved_at']) $errors[] = 'Η εγκεκριμένη έκδοση είναι κλειδωμένη. Για αλλαγή, αποθηκεύστε πρώτα νέα draft έκδοση.';
        elseif (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $errors[] = 'Επιλέξτε το τελικό PDF.';
        elseif ((int)$file['size'] < 100 || (int)$file['size'] > 12*1024*1024) $errors[] = 'Το PDF πρέπει να είναι έως 12 MB.';
        else {
            $tmp = (string)$file['tmp_name'];
            $content = is_uploaded_file($tmp) ? file_get_contents($tmp) : false;
            $mime = $content === false ? '' : (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if ($content === false || $mime !== 'application/pdf' || !str_starts_with($content, '%PDF-')) $errors[] = 'Το αρχείο πρέπει να είναι πραγματικό PDF.';
            else {
                $name = preg_replace('/[^A-Za-z0-9._-]/', '-', basename((string)$file['name'])) ?: 'approved-proposal.pdf';
                db()->prepare("UPDATE company_documents SET document_status='pdf_ready', final_signed_file_name=?, final_signed_pdf=?, final_signed_at=NOW(), approval_code_hash=NULL, approval_code_expires_at=NULL, approval_attempts=0, approval_requested_at=NULL, approval_requested_by=NULL WHERE id=?")->execute([$name, $content, $id]);
                flash('success', 'Το τελικό PDF αποθηκεύτηκε. Τώρα ζητήστε την έγκριση CEO για αυτό ακριβώς το αρχείο.');
                redirect_to('proposal.php?id=' . urlencode($id));
            }
        }
    }

    if ($proposal && $action === 'send_final') {
        require_once __DIR__.'/contact-operations.php';
        $proposalData = json_decode((string)$proposal['data_json'], true) ?: [];
        $email = trim((string)($proposalData['client_email'] ?? ''));
        $confirm = trim((string)($_POST['send_email_confirm'] ?? ''));
        if (!$proposal['approved_at'] || !$proposal['final_signed_pdf']) $errors[] = 'Η εγκεκριμένη τελική PDF έκδοση δεν είναι έτοιμη.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strtolower($email) !== mb_strtolower($confirm)) $errors[] = 'Πληκτρολογήστε ξανά σωστά το email του πελάτη.';
        elseif (contact_ops_proposal_blocked($proposal,$email)) $errors[] = 'Η αποστολή σταμάτησε: υπάρχει κεντρικός αποκλεισμός επικοινωνίας για τον παραλήπτη ή την εταιρική επαφή. Απαιτείται τεκμηριωμένος έλεγχος από τον υπεύθυνο.';
        else {
            $mailHtml = '<div style="font-family:Arial,sans-serif;color:#07383c;max-width:640px"><h2 style="color:#159a86">Επαγγελματική πρόταση</h2><p>Αγαπητέ/ή ' . e((string)($proposalData['client_contact_name'] ?? 'συνεργάτη')) . ',</p><p>Σας αποστέλλουμε συνημμένη την πρόταση της MELAS ENERGIAKI για <strong>' . e((string)($proposalData['project_name'] ?? 'τη σχετική συνεργασία')) . '</strong>.</p><p>Κωδικός πρότασης: <strong>' . e($proposal['document_reference']) . '</strong><br>Ισχύς: ' . e((string)($proposalData['validity_days'] ?? 30)) . ' ημερολογιακές ημέρες.</p><p>Παραμένουμε στη διάθεσή σας για διευκρινίσεις και τα επόμενα βήματα.</p><p>MELAS ENERGIAKI</p></div>';
            $sent = send_crm_email($email, 'MELAS ENERGIAKI — Πρόταση ' . $proposal['document_reference'], $mailHtml, [['filename'=>(string)$proposal['final_signed_file_name'],'mime'=>'application/pdf','content'=>(string)$proposal['final_signed_pdf']]]);
            db()->prepare("INSERT INTO company_document_deliveries (id, document_id, requested_by, recipient_email, recipient_role, delivery_status, sent_at) VALUES (?, ?, ?, ?, 'client', ?, ?) ON DUPLICATE KEY UPDATE requested_by=VALUES(requested_by), delivery_status=VALUES(delivery_status), sent_at=VALUES(sent_at)")->execute([uuid_v4(),$id,$user['id'],$email,$sent?'sent':'failed',$sent?date('Y-m-d H:i:s'):null]);
            if ($sent) {
                db()->prepare("UPDATE company_documents SET document_status='sent' WHERE id=?")->execute([$id]);
                flash('success', 'Η εγκεκριμένη πρόταση στάλθηκε στο ' . $email . '.');
                redirect_to('proposal.php?id=' . urlencode($id));
            }
            $errors[] = 'Η αποστολή απέτυχε. Ελέγξτε τις ρυθμίσεις email.';
        }
    }

    if ($proposal && $action === 'mark_accepted' && (!$proposal['approved_at'] || !in_array($proposal['document_status'], ['approved_pdf','sent'], true))) {
        $errors[] = 'Η πρόταση μπορεί να γίνει Accepted μόνο αφού εγκριθεί από τον CEO και αποσταλεί ή είναι έτοιμη για αποστολή.';
    } elseif ($proposal && in_array($action, ['mark_accepted','mark_rejected','mark_expired'], true)) {
        $status = ['mark_accepted'=>'accepted','mark_rejected'=>'rejected','mark_expired'=>'expired'][$action];
        db()->prepare('UPDATE company_documents SET document_status=? WHERE id=?')->execute([$status,$id]);
        flash('success', 'Η κατάσταση της πρότασης ενημερώθηκε.');
        redirect_to('proposal.php?id=' . urlencode($id));
    }
    if ($isExisting && !$errors) { $proposal = $loadProposal($id); $data = json_decode((string)$proposal['data_json'], true) ?: []; }
}

if (!$data) {
    $client = $companyById[$companyId] ?? null;
    $profile = (string)($_GET['profile'] ?? 'project');
    $data = [
        'profile'=>$profile, 'proposal_date'=>date('Y-m-d'), 'validity_days'=>30, 'version'=>'1.0',
        'client_legal_name'=>$client ? (string)($client['legal_name'] ?: $client['name']) : '', 'client_email'=>$client['email'] ?? '',
        'client_contact_name'=>$client['contact_name'] ?? '', 'client_contact_title'=>$client['contact_title'] ?? '',
        'engagement_model'=>$profile === 'team' ? 'Dedicated Engineering Team' : 'Full Project / Work Package',
        'payment_terms'=>'Unless otherwise agreed, payment is due Net 30 from the date of a valid and undisputed invoice.',
        'acceptance_days'=>'5-10', 'selected_sections'=>$profile === 'team' ? $teamProfile : $projectProfile,
    ];
}
$selected = array_map('intval', (array)($data['selected_sections'] ?? $projectProfile));
$contractPrerequisites = $proposal ? contract_prerequisite_errors((int)$proposal['company_id'], 'proposal') : [];
$statusLabels = ['draft'=>'Draft','pdf_ready'=>'PDF ready for approval','approval_pending'=>'Pending CEO approval','ceo_approved'=>'CEO approved','approved_pdf'=>'CEO-approved PDF','sent'=>'Sent','accepted'=>'Accepted','rejected'=>'Rejected','expired'=>'Expired'];
render_header($proposal ? 'Πρόταση ' . $proposal['document_reference'] : 'Νέα πρόταση', $user);
?>
<div class="page-heading"><div><p class="eyebrow">COMMERCIAL PROPOSALS</p><h1><?= $proposal ? e($proposal['document_reference']) : 'Νέα εμπορική & τεχνική πρόταση' ?></h1><p>Client-specific proposal από το εγκεκριμένο master template.</p></div><div class="actions"><a class="button" href="<?= e(crm_url('proposals.php')) ?>">Όλες οι προτάσεις</a><?php if ($proposal): ?><a class="button primary" target="_blank" href="<?= e(crm_url('proposal-view.php?id='.urlencode($id))) ?>">Προβολή / Save PDF</a><?php endif; ?></div></div>
<?php if ($errors): ?><div class="alert error"><?= e(implode(' ', array_unique($errors))) ?></div><?php endif; ?>
<?php if ($contractPrerequisites): ?><div class="alert warning"><strong>Το signing flow είναι κλειδωμένο.</strong> <?= e(implode(' ', $contractPrerequisites)) ?> Μπορείτε να επεξεργαστείτε το draft, αλλά όχι να το εγκρίνετε ή να το αποδεχτείτε ακόμη.</div><?php endif; ?>
<?php if ($proposal): ?><section class="card proposal-status-card"><div><span class="proposal-status proposal-status-<?= e($proposal['document_status']) ?>"><?= e($statusLabels[$proposal['document_status']] ?? $proposal['document_status']) ?></span><h2><?= e($proposal['title']) ?></h2><p><?= e($proposal['company_name']) ?> · <?= e(format_datetime($proposal['updated_at'])) ?></p></div><div class="actions"><a class="button" href="<?= e(crm_url('customer.php?id='.(int)$proposal['company_id'])) ?>">Καρτέλα πελάτη</a><?php if ($proposal['final_signed_pdf']): ?><a class="button primary" href="<?= e(crm_url('proposal-download.php?id='.urlencode($id).'&pdf=1')) ?>">Λήψη τελικού PDF</a><?php endif; ?></div></section><?php endif; ?>

<form method="post" class="stack proposal-editor"><?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($proposal): ?><input type="hidden" name="id" value="<?= e($id) ?>"><?php endif; ?>
<section class="card form-section"><header><h2>1. Πελάτης και proposal control</h2><p>Τα στοιχεία αποθηκεύονται και στην καρτέλα του πελάτη.</p></header><div class="form-grid">
<label>Πελάτης *<select name="company_id" required><?php foreach ($companies as $companyRow): ?><option value="<?= (int)$companyRow['id'] ?>" <?= $companyId===(int)$companyRow['id']?'selected':'' ?>><?= e($companyRow['name']) ?></option><?php endforeach; ?></select></label>
<label>Project / Engagement *<input name="project_name" maxlength="220" required value="<?= e($data['project_name'] ?? '') ?>"></label>
<label>Πλήρης νομική επωνυμία *<input name="client_legal_name" required value="<?= e($data['client_legal_name'] ?? '') ?>"></label>
<label>Ημερομηνία πρότασης *<input type="date" name="proposal_date" required value="<?= e($data['proposal_date'] ?? date('Y-m-d')) ?>"></label>
<label>Ισχύς (ημέρες)<input type="number" name="validity_days" min="1" max="180" value="<?= e((string)($data['validity_days'] ?? 30)) ?>"></label><label>Version<input name="version" value="<?= e($data['version'] ?? '1.0') ?>"></label>
<label>Email πελάτη *<input type="email" name="client_email" required value="<?= e($data['client_email'] ?? '') ?>"></label><label>Επιβεβαίωση email *<input type="email" name="client_email_confirm" required autocomplete="off" placeholder="Γράψτε ξανά το email"></label>
<label>Εκπρόσωπος<input name="client_contact_name" value="<?= e($data['client_contact_name'] ?? '') ?>"></label><label>Ιδιότητα<input name="client_contact_title" value="<?= e($data['client_contact_title'] ?? '') ?>"></label>
<label>Profile<select name="profile"><option value="project" <?= ($data['profile']??'')==='project'?'selected':'' ?>>Full Project (10–15 pages)</option><option value="team" <?= ($data['profile']??'')==='team'?'selected':'' ?>>Dedicated Team (6–8 pages)</option></select></label><label>Delivery model<input name="engagement_model" value="<?= e($data['engagement_model'] ?? '') ?>"></label>
<label>Indicative start<input type="date" name="start_date" value="<?= e($data['start_date'] ?? '') ?>"></label><label>Duration<input name="duration" value="<?= e($data['duration'] ?? '') ?>" placeholder="π.χ. 6 months"></label>
</div></section>
<section class="card form-section"><header><h2>2. Requirement → Scope → Deliverables</h2></header><div class="form-grid">
<label class="field-full">Executive summary<textarea name="executive_summary"><?= e($data['executive_summary'] ?? '') ?></textarea></label>
<label class="field-full">Main deliverables — μία γραμμή ανά item<textarea name="main_deliverables"><?= e($data['main_deliverables'] ?? '') ?></textarea></label>
<label class="field-full">Understanding of requirement<textarea name="requirement"><?= e($data['requirement'] ?? '') ?></textarea></label>
<label class="field-full">In-scope services — μία γραμμή ανά activity<textarea name="scope"><?= e($data['scope'] ?? '') ?></textarea></label>
<label class="field-full">Deliverables table — Deliverable | Description | Acceptance basis<textarea data-document-rows name="deliverables_table"><?= e($data['deliverables_table'] ?? '') ?></textarea></label>
<label class="field-full">Out of scope — μία γραμμή ανά εξαίρεση<textarea name="out_of_scope"><?= e($data['out_of_scope'] ?? '') ?></textarea></label>
</div></section>
<section class="card form-section"><header><h2>3. Team → Technology → Timeline</h2></header><div class="form-grid">
<label class="field-full">Team — Role | Seniority | Allocation | Responsibility<textarea data-document-rows name="team"><?= e($data['team'] ?? '') ?></textarea></label>
<label class="field-full">Technology environment<textarea name="technology_environment"><?= e($data['technology_environment'] ?? '') ?></textarea></label>
<label class="field-full">Timeline — Phase | Duration | Target completion<textarea data-document-rows name="timeline"><?= e($data['timeline'] ?? '') ?></textarea></label>
<label class="field-full">Communication model<textarea name="communication_model"><?= e($data['communication_model'] ?? '') ?></textarea></label>
<label class="field-full">Client responsibilities — μία γραμμή ανά item<textarea name="client_responsibilities"><?= e($data['client_responsibilities'] ?? '') ?></textarea></label>
<label class="field-full">Assumptions — μία γραμμή ανά item<textarea name="assumptions"><?= e($data['assumptions'] ?? '') ?></textarea></label>
<label class="field-full">Dependencies — μία γραμμή ανά item<textarea name="dependencies"><?= e($data['dependencies'] ?? '') ?></textarea></label>
</div></section>
<section class="card form-section"><header><h2>4. Pricing → Payment → Next Steps</h2></header><div class="form-grid">
<label>Pricing model<select name="pricing_model"><option>Fixed Price</option><option <?= ($data['pricing_model']??'')==='Time & Materials'?'selected':'' ?>>Time & Materials</option><option <?= ($data['pricing_model']??'')==='Dedicated Team'?'selected':'' ?>>Dedicated Team</option><option <?= ($data['pricing_model']??'')==='Capped T&M'?'selected':'' ?>>Capped T&M</option><option <?= ($data['pricing_model']??'')==='Monthly Retainer'?'selected':'' ?>>Monthly Retainer</option></select></label><label>Total / monthly value<input name="total_value" value="<?= e($data['total_value'] ?? '') ?>" placeholder="π.χ. 100,000"></label>
<label class="field-full">Pricing details<textarea name="pricing_details"><?= e($data['pricing_details'] ?? '') ?></textarea></label>
<label class="field-full">Payment terms<textarea name="payment_terms"><?= e($data['payment_terms'] ?? '') ?></textarea></label>
<label class="field-full">Purchase Order requirements<textarea name="purchase_order"><?= e($data['purchase_order'] ?? '') ?></textarea></label>
<label>Acceptance period<input name="acceptance_days" value="<?= e($data['acceptance_days'] ?? '5-10') ?>"></label>
<label class="field-full">Next steps — μία γραμμή ανά item<textarea name="next_steps"><?= e($data['next_steps'] ?? '') ?></textarea></label>
</div></section>
<section class="card form-section"><header><h2>5. Sections τελικού εγγράφου</h2><p>Τα 1, 16 και 34 είναι υποχρεωτικά. Επιλέξτε μόνο όσα χρειάζεται το συγκεκριμένο deal.</p></header><div class="proposal-section-picker"><?php foreach ($sectionLabels as $number=>$label): ?><label><input type="checkbox" name="selected_sections[]" value="<?= $number ?>" <?= in_array($number,$selected,true)?'checked':'' ?> <?= in_array($number,[1,16,34],true)?'required':'' ?>><span><?= $number ?>. <?= e($label) ?></span></label><?php endforeach; ?></div><div class="actions"><button class="button primary" type="submit"><?= $proposal?'Αποθήκευση νέας draft έκδοσης':'Δημιουργία πρότασης' ?></button></div></section>
</form>

<?php if ($proposal): ?>
<section class="proposal-workflow <?= $contractPrerequisites?'contract-flow-locked':'' ?>">
<article class="card signing-step <?= $proposal['final_signed_pdf']?'is-complete':'is-current' ?>"><div class="step-number">1</div><div><h2>Τελικό PDF προς έγκριση</h2><p>Ανοίξτε «Προβολή / Save PDF», αποθηκεύστε την πρόταση ως PDF και ανεβάστε εδώ το ακριβές αρχείο που θα σταλεί.</p><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="upload_final_pdf"><input type="file" name="final_pdf" accept="application/pdf,.pdf" required><button class="button primary" <?= $proposal['approved_at']?'disabled':'' ?>>Αποθήκευση PDF προς έγκριση</button></form></div></article>
<article class="card signing-step <?= $proposal['approved_at']?'is-complete':($proposal['final_signed_pdf']?'is-current':'is-locked') ?>"><div class="step-number">2</div><div><h2>CEO Approval του συγκεκριμένου PDF</h2><p>Ο κωδικός αποστέλλεται αποκλειστικά στο <strong><?= e($approvalEmail) ?></strong>. Μετά την έγκριση το PDF κλειδώνει.</p><?php if (!$proposal['approved_at']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="request_approval"><button class="button primary" <?= !$proposal['final_signed_pdf']?'disabled':'' ?>>Αποστολή εξαψήφιου κωδικού</button></form><form method="post" class="otp-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="verify_approval"><input name="approval_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required><button class="button primary" <?= !$proposal['final_signed_pdf']?'disabled':'' ?>>Επιβεβαίωση CEO</button></form><?php else: ?><div class="step-success">✓ Το PDF εγκρίθηκε <?= e(format_datetime($proposal['approved_at'])) ?></div><?php endif; ?></div></article>
<article class="card signing-step <?= $proposal['final_signed_pdf']?($proposal['document_status']==='sent'?'is-complete':'is-current'):'is-locked' ?>"><div class="step-number">3</div><div><h2>Αποστολή στον πελάτη</h2><p>Για αποφυγή λάθους, πληκτρολογήστε ξανά: <strong><?= e($data['client_email'] ?? '') ?></strong></p><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="send_final"><input type="email" name="send_email_confirm" required placeholder="Επιβεβαίωση email"><button class="button primary" <?= !$proposal['final_signed_pdf']?'disabled':'' ?>>Αποστολή εγκεκριμένου PDF</button></form></div></article>
<article class="card signing-step"><div class="step-number">4</div><div><h2>Τελική κατάσταση</h2><div class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><button class="button primary" name="action" value="mark_accepted">Accepted</button><button class="button" name="action" value="mark_rejected">Rejected</button><button class="button" name="action" value="mark_expired">Expired</button></form></div></div></article>
</section>
<?php endif; ?>
<link rel="stylesheet" href="<?= e(crm_url('assets/document-rows.css?v=20260916')) ?>">
<script src="<?= e(crm_url('assets/document-rows.js?v=20260916')) ?>" defer></script>
<?php render_footer(); ?>
