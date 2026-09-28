<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();
if (!in_array($user['role'], ['admin', 'manager'], true)) {
    http_response_code(403);
    exit('Η πρόσβαση στα client-specific packs επιτρέπεται μόνο στη διοίκηση.');
}

$types = [
    'vendor_response' => 'Vendor / Procurement Response',
    'security_questionnaire' => 'Security Questionnaire / Annex',
    'client_information_pack' => 'Client-specific Information Pack',
];
$companies = db()->query("SELECT id,name,legal_name FROM companies WHERE deleted_at IS NULL ORDER BY name")->fetchAll();
$companyId = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $type = (string)($_POST['pack_type'] ?? '');
    $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 255));
    $upload = $_FILES['document'] ?? null;
    if ($companyId < 1) $errors[] = 'Επιλέξτε πελάτη.';
    if (!array_key_exists($type, $types)) $errors[] = 'Επιλέξτε έγκυρο τύπο pack.';
    if ($title === '') $errors[] = 'Ο τίτλος είναι υποχρεωτικός.';
    if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $errors[] = 'Επιλέξτε έγκυρο αρχείο.';

    $content = '';$fileName = '';$mime = '';
    if (!$errors && is_array($upload)) {
        $fileName = basename((string)$upload['name']);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $size = (int)($upload['size'] ?? 0);
        if (!in_array($extension, ['pdf','doc','docx','xls','xlsx','txt'], true)) $errors[] = 'Επιτρέπονται PDF, Word, Excel και TXT αρχεία.';
        if ($size < 1 || $size > 12 * 1024 * 1024) $errors[] = 'Το αρχείο πρέπει να είναι έως 12 MB.';
        if (!$errors) {
            $temporaryPath = (string)$upload['tmp_name'];
            $content = (string)file_get_contents($temporaryPath);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($temporaryPath);
            if ($content === '') $errors[] = 'Το αρχείο είναι κενό.';
        }
    }

    if (!$errors) {
        $reference = 'DL-PACK-' . date('Ymd') . '-' . $companyId . '-' . strtoupper(bin2hex(random_bytes(2)));
        $data = ['pack_type'=>$type, 'notes'=>trim((string)($_POST['notes'] ?? ''))];
        $statement = db()->prepare("INSERT INTO company_documents (id,company_id,created_by,document_type,document_status,document_reference,title,file_name,mime_type,content,data_json) VALUES (?,?,?,?,'shareable',?,?,?,?,?,?)");
        $documentId = uuid_v4();
        $statement->execute([$documentId,$companyId,$user['id'],$type,$reference,$title,$fileName,$mime ?: 'application/octet-stream',$content,json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        flash('success', 'Το client-specific pack αποθηκεύτηκε στην καρτέλα του πελάτη.');
        redirect_to('client-packs.php?company_id=' . $companyId);
    }
}

$where = "d.document_type IN ('vendor_response','security_questionnaire','client_information_pack')";
$parameters = [];
if ($companyId > 0) {$where .= ' AND d.company_id=?';$parameters[]=$companyId;}
$statement = db()->prepare("SELECT d.id,d.company_id,d.document_type,d.document_reference,d.title,d.file_name,d.mime_type,d.created_at,c.name AS company_name,c.legal_name FROM company_documents d JOIN companies c ON c.id=d.company_id AND c.deleted_at IS NULL WHERE {$where} ORDER BY d.updated_at DESC");
$statement->execute($parameters);$packs=$statement->fetchAll();
$profileSql = 'SELECT p.id,p.profile_id,p.primary_role,p.seniority,p.profile_status,p.updated_at,p.company_id,c.name AS company_name,c.legal_name FROM engineering_profiles p LEFT JOIN companies c ON c.id=p.company_id AND c.deleted_at IS NULL';
$profileParameters=[];if($companyId>0){$profileSql.=' WHERE p.company_id=?';$profileParameters[]=$companyId;}$profileSql.=' ORDER BY p.updated_at DESC';
$profileStatement=db()->prepare($profileSql);$profileStatement->execute($profileParameters);$profiles=$profileStatement->fetchAll();

render_header('Client-specific Packs', $user);
?>
<div class="page-heading"><div><p class="eyebrow">CLIENT-FACING DOCUMENTS</p><h1>Client-specific Packs</h1><p>Engineering Profiles, vendor responses και security questionnaires συνδεδεμένα με συγκεκριμένο πελάτη. Δεν επηρεάζουν τη συμβατική ροή.</p></div><div class="actions"><a class="button" href="<?= e(crm_url('corporate-documents.php')) ?>">Εταιρικά έντυπα</a><a class="button primary" href="#new-client-pack">+ Νέο pack</a></div></div>
<?php if($errors): ?><div class="alert error"><?= e(implode(' ',array_unique($errors))) ?></div><?php endif; ?>
<form class="card corporate-document-filters client-pack-filter" method="get"><label>Πελάτης<select name="company_id"><option value="0">Όλοι οι πελάτες</option><?php foreach($companies as$company): ?><option value="<?= (int)$company['id'] ?>" <?= $companyId===(int)$company['id']?'selected':'' ?>><?= e($company['legal_name']?:$company['name']) ?></option><?php endforeach; ?></select></label><button class="button primary">Εφαρμογή</button><?php if($companyId): ?><a class="button" href="<?= e(crm_url('client-packs.php')) ?>">Καθαρισμός</a><?php endif; ?></form>

<section class="card workspace-section client-pack-section"><header><p class="eyebrow">ENGINEERING PROFILES</p><h2>Profiles για παρουσίαση σε πελάτη</h2><p>Το εσωτερικό όνομα του engineer δεν εμφανίζεται σε αυτή τη λίστα ούτε στο client-facing PDF.</p></header><div class="document-grid"><?php if(!$profiles): ?><p class="empty-state client-pack-empty">Δεν υπάρχουν συνδεδεμένα Engineering Profiles.</p><?php endif; ?><?php foreach($profiles as$profile): ?><article class="saved-document"><div class="document-mark">EP</div><div><strong><?= e($profile['profile_id']) ?> · <?= e($profile['primary_role']) ?></strong><small><?= e($profile['legal_name']?:($profile['company_name']?:'Γενικό profile')) ?> · <?= e($profile['seniority']) ?></small><span class="proposal-status proposal-status-<?= e($profile['profile_status']) ?>"><?= e($profile['profile_status']) ?></span></div><div class="actions"><a class="button compact" href="<?= e(crm_url('profile.php?id='.urlencode((string)$profile['id']))) ?>">Άνοιγμα</a><a class="button compact primary" target="_blank" href="<?= e(crm_url('profile-view.php?id='.urlencode((string)$profile['id']))) ?>">Προβολή / PDF</a></div></article><?php endforeach; ?></div><div class="actions client-pack-section-actions"><a class="button primary" href="<?= e(crm_url('profile.php'.($companyId?'?company_id='.$companyId:''))) ?>">+ Νέο Engineering Profile</a></div></section>

<section class="card workspace-section client-pack-section"><header><p class="eyebrow">PROCUREMENT &amp; SECURITY</p><h2>Vendor responses και security questionnaires</h2><p>Αποθηκεύονται ανά πελάτη και παραμένουν εκτός του signing flow.</p></header><div class="document-grid"><?php if(!$packs): ?><p class="empty-state client-pack-empty">Δεν υπάρχουν ακόμη client-specific αρχεία.</p><?php endif; ?><?php foreach($packs as$pack): ?><article class="saved-document"><div class="document-mark">PACK</div><div><strong><?= e($pack['title']) ?></strong><small><?= e($pack['legal_name']?:$pack['company_name']) ?> · <?= e($types[$pack['document_type']]??$pack['document_type']) ?> · <?= e($pack['document_reference']) ?></small></div><div class="actions"><a class="button compact primary" href="<?= e(crm_url('client-pack-download.php?id='.urlencode((string)$pack['id']))) ?>">Λήψη</a></div></article><?php endforeach; ?></div></section>

<section class="card form-section" id="new-client-pack"><header><h2>Προσθήκη client-specific pack</h2><p>Για συμπληρωμένο vendor pack, procurement response, security questionnaire ή άλλο ειδικό αρχείο πελάτη.</p></header><form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?><div class="form-grid"><label>Πελάτης *<select name="company_id" required><option value="">Επιλέξτε</option><?php foreach($companies as$company): ?><option value="<?= (int)$company['id'] ?>" <?= $companyId===(int)$company['id']?'selected':'' ?>><?= e($company['legal_name']?:$company['name']) ?></option><?php endforeach; ?></select></label><label>Τύπος *<select name="pack_type" required><?php foreach($types as$value=>$label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><label>Τίτλος *<input name="title" required maxlength="255"></label><label>Αρχείο *<input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.txt"></label><label class="field-full">Εσωτερικές σημειώσεις<textarea name="notes"></textarea></label></div><div class="actions"><button class="button primary">Αποθήκευση στο client pack</button></div></form></section>
<?php render_footer(); ?>
