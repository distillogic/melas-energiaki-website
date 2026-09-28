<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();

$canManage = in_array($user['role'], ['admin', 'manager'], true);
$errors = [];
$categories = [
    'corporate' => 'Εταιρική παρουσίαση',
    'legal' => 'Νομικά & συμμόρφωση',
    'policies' => 'Πολιτικές & διαδικασίες',
    'templates' => 'Πρότυπα εταιρείας',
    'certifications' => 'Πιστοποιήσεις',
    'financial' => 'Οικονομικά',
    'other' => 'Άλλο',
];

// Add the approved company templates once. Their source PDFs ship with this CRM update.
$seedDocuments = [
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Corporate-Capability-Statement-v1.0.pdf',
        'title' => 'Corporate Capability Statement',
        'reference' => 'DL-CS-001',
        'category' => 'corporate',
        'access_level' => 'all',
        'description' => 'Επίσημη εταιρική παρουσίαση δυνατοτήτων και μοντέλων συνεργασίας της DISTILLOGIC TECHNOLOGIES.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Mutual-NDA-Template-for-Legal-Review-v1.0.pdf',
        'title' => 'Mutual NDA Template',
        'reference' => 'DL-NDA-001',
        'category' => 'templates',
        'access_level' => 'all',
        'description' => 'Κενό εταιρικό πρότυπο NDA με placeholders. Τα εξατομικευμένα και υπογεγραμμένα NDA παραμένουν στην καρτέλα του αντίστοιχου πελάτη.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Pricing-and-Commercial-Policy-v1.0.pdf',
        'title' => 'Pricing & Commercial Policy',
        'reference' => 'DL-COM-001',
        'category' => 'financial',
        'access_level' => 'management',
        'description' => 'Εσωτερική πολιτική τιμολόγησης, περιθωρίων, εκπτώσεων, εμπορικών εγκρίσεων και διαχείρισης κινδύνου. Πρόσβαση μόνο στη διοίκηση.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Professional-Services-Rate-Card-2026-v1.0.pdf',
        'title' => 'Professional Services Rate Card',
        'reference' => 'DL-RC-001',
        'category' => 'financial',
        'access_level' => 'management',
        'description' => 'Επίσημο Rate Card με ενδεικτικά εμπορικά εύρη, όρους ευελιξίας και μοντέλα συνεργασίας. Για ελεγχόμενη αποστολή σε υποψήφιους πελάτες από τη διοίκηση.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Payment-Terms-and-Billing-Policy-v1.0.pdf',
        'title' => 'Payment Terms & Billing Policy',
        'reference' => 'DL-PAY-001',
        'category' => 'financial',
        'access_level' => 'management',
        'description' => 'Master πλαίσιο όρων πληρωμής, τιμολόγησης, προκαταβολών, milestones, καθυστερήσεων και εμπορικών εξαιρέσεων. Ελεγχόμενη διανομή μόνο από τη διοίκηση.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Commercial-and-Technical-Proposal-Master-Template-v1.0.pdf',
        'title' => 'Commercial & Technical Proposal — Master Template',
        'reference' => 'DL-PRO-TEMPLATE',
        'category' => 'templates',
        'access_level' => 'management',
        'description' => 'Εσωτερικό master template 35 ενοτήτων. Δεν αποστέλλεται αυτούσιο: από την ενότητα «Προσφορές» δημιουργείται ξεχωριστή, προσαρμοσμένη πρόταση για κάθε πελάτη και engagement.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Statement-of-Work-Master-Template-v1.0.pdf',
        'title' => 'Statement of Work — Master Template',
        'reference' => 'DL-SOW-TEMPLATE',
        'category' => 'templates',
        'access_level' => 'management',
        'description' => 'Πλήρες master SOW framework 48 ενοτήτων. Τα client-specific SOW δημιουργούνται από την ξεχωριστή ενότητα «Statements of Work» και αποθηκεύονται στην καρτέλα κάθε πελάτη.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Master-Services-Agreement-Template-v1.0.pdf',
        'title' => 'Master Services Agreement - Master Template',
        'reference' => 'DL-MSA-TEMPLATE',
        'category' => 'templates',
        'access_level' => 'management',
        'description' => 'Πλήρες master MSA 73 ενοτήτων και schedules. Δεν αποστέλλεται αυτούσιο: δημιουργείται ξεχωριστό MSA ανά πελάτη από την ενότητα «Master Agreements». Απαιτεί νομικό έλεγχο πριν από την πρώτη υπογραφή.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Data-Processing-Agreement-Template-v1.0.pdf',
        'title' => 'Data Processing Agreement - Master Template',
        'reference' => 'DL-DPA-TEMPLATE',
        'category' => 'templates',
        'access_level' => 'management',
        'description' => 'Πλήρες GDPR DPA 52 ενοτήτων και 5 annexes. Τα client-specific DPA δημιουργούνται από την ενότητα «Data Processing» και απαιτούν privacy/legal review πριν από την υπογραφή.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Engineering-and-Information-Security-Practices-v1.0.pdf',
        'title' => 'Engineering & Information Security Practices',
        'reference' => 'DL-SEC-001',
        'category' => 'policies',
        'access_level' => 'management',
        'description' => 'Ελεγχόμενης διανομής security και engineering framework για Procurement, Information Security και supplier assessments. Περιγράφει πραγματικές πρακτικές χωρίς ισχυρισμούς για πιστοποιήσεις που δεν έχουν αποκτηθεί.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Vendor-and-Supplier-Information-Pack-v1.0.pdf',
        'title' => 'Vendor & Supplier Information Pack',
        'reference' => 'DL-VEN-001',
        'category' => 'corporate',
        'access_level' => 'management',
        'description' => 'Master vendor-onboarding και procurement pack. Περιλαμβάνει εταιρικά, φορολογικά, τραπεζικά, compliance, security και supporting-document στοιχεία. Είναι draft και πρέπει να επαληθεύεται πριν από κάθε εξωτερική αποστολή.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Project-Intake-and-RFP-Qualification-Form-v1.0.pdf',
        'title' => 'Project Intake & RFP Qualification Form',
        'reference' => 'DL-INT-001',
        'category' => 'templates',
        'access_level' => 'management',
        'description' => 'Αυστηρά εσωτερικό master qualification form πριν από κάθε προσφορά. Καταγράφει scope, decision makers, budget, procurement, payment exposure, security, risks και την απόφαση bid / no-bid.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Business-Development-Outreach-and-Meeting-Playbook-v1.0.pdf',
        'title' => 'Business Development Outreach & Meeting Playbook',
        'reference' => 'DL-BD-001',
        'category' => 'policies',
        'access_level' => 'management',
        'description' => 'Εσωτερικό playbook 66 ενοτήτων για cold outreach, LinkedIn, τηλεφωνικές κλήσεις, discovery meetings, procurement, pricing discussions, follow-ups και μετάβαση από lead σε proposal. Δεν αποστέλλεται αυτούσιο σε πελάτες.',
    ],
    [
        'path' => __DIR__ . '/assets/documents/DISTILLOGIC-Engineering-Profile-Master-Template-v1.0.pdf',
        'title' => 'Engineering Profile — Master Template',
        'reference' => 'DL-EP-TEMPLATE',
        'category' => 'templates',
        'access_level' => 'management',
        'description' => 'Master CV/profile framework για τη δημιουργία σύντομων client-facing engineering profiles 2–4 σελίδων. Περιλαμβάνει privacy, factual-experience και verification controls και δεν αποστέλλεται αυτούσιο.',
    ],
];
foreach ($seedDocuments as $seedDocument) {
    if (!is_file($seedDocument['path'])) continue;
    try {
        $exists = db()->prepare('SELECT id, size_bytes FROM corporate_documents WHERE document_reference = ? LIMIT 1');
        $exists->execute([$seedDocument['reference']]);
        $existing = $exists->fetch();
        $seed = file_get_contents($seedDocument['path']);
        if (is_string($seed) && $seed !== '') {
            if (!$existing) {
                $insert = db()->prepare('INSERT INTO corporate_documents (id, uploaded_by, title, document_reference, category, access_level, description, version_label, document_date, file_name, mime_type, size_bytes, content) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([
                    uuid_v4(), $user['id'], $seedDocument['title'], $seedDocument['reference'], $seedDocument['category'], $seedDocument['access_level'],
                    $seedDocument['description'], '1.0', date('Y-m-d'), basename($seedDocument['path']),
                    'application/pdf', strlen($seed), $seed,
                ]);
            } elseif ((int)$existing['size_bytes'] !== strlen($seed)) {
                $update = db()->prepare('UPDATE corporate_documents SET title = ?, category = ?, access_level = ?, description = ?, version_label = ?, document_date = ?, file_name = ?, mime_type = ?, size_bytes = ?, content = ? WHERE id = ?');
                $update->execute([
                    $seedDocument['title'], $seedDocument['category'], $seedDocument['access_level'], $seedDocument['description'],
                    '1.0', date('Y-m-d'), basename($seedDocument['path']), 'application/pdf', strlen($seed), $seed, $existing['id'],
                ]);
            }
        }
    } catch (Throwable $exception) {
        error_log('Corporate document seed failed: ' . $seedDocument['reference'] . ' - ' . $exception->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canManage) {
        http_response_code(403);
        exit('Δεν έχετε δικαίωμα διαχείρισης εταιρικών εγγράφων.');
    }

    $action = (string)($_POST['action'] ?? 'upload');
    if ($action !== 'upload') {
        http_response_code(405);
        exit('Η ενέργεια δεν επιτρέπεται. Τα εταιρικά έγγραφα διατηρούνται μόνιμα.');
    }

        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 255));
        $reference = trim(mb_substr((string)($_POST['document_reference'] ?? ''), 0, 100));
        $category = (string)($_POST['category'] ?? 'other');
        $accessLevel = (string)($_POST['access_level'] ?? 'all');
        $description = trim(mb_substr((string)($_POST['description'] ?? ''), 0, 3000));
        $version = trim(mb_substr((string)($_POST['version_label'] ?? ''), 0, 50));
        $documentDate = trim((string)($_POST['document_date'] ?? ''));
        $upload = $_FILES['document'] ?? null;

        if ($title === '') $errors[] = 'Ο τίτλος του εγγράφου είναι υποχρεωτικός.';
        if (!array_key_exists($category, $categories)) $errors[] = 'Η κατηγορία δεν είναι έγκυρη.';
        if (!in_array($accessLevel, ['all', 'management'], true)) $errors[] = 'Το επίπεδο πρόσβασης δεν είναι έγκυρο.';
        if ($documentDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) $errors[] = 'Η ημερομηνία δεν είναι έγκυρη.';
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Επιλέξτε ένα αρχείο.';
        } elseif ((int)$upload['error'] !== UPLOAD_ERR_OK) {
            $errors[] = in_array((int)$upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'Το αρχείο ξεπερνά το επιτρεπόμενο μέγεθος του server.'
                : 'Η μεταφόρτωση του αρχείου απέτυχε.';
        }

        $content = '';
        $mime = '';
        $fileName = '';
        $size = 0;
        if (!$errors && is_array($upload)) {
            $fileName = basename((string)$upload['name']);
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
            $size = (int)($upload['size'] ?? 0);
            if (!in_array($extension, $allowedExtensions, true)) $errors[] = 'Επιτρέπονται PDF, Word, Excel, PowerPoint και TXT αρχεία.';
            if ($size < 1 || $size > 12 * 1024 * 1024) $errors[] = 'Το αρχείο πρέπει να είναι έως 12 MB.';
            if (!$errors) {
                $temporaryPath = (string)($upload['tmp_name'] ?? '');
                $content = (string)file_get_contents($temporaryPath);
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$finfo->file($temporaryPath);
                if ($content === '') $errors[] = 'Το αρχείο είναι κενό ή δεν μπορεί να διαβαστεί.';
            }
        }

        if (!$errors) {
            try {
                $insert = db()->prepare('INSERT INTO corporate_documents (id, uploaded_by, title, document_reference, category, access_level, description, version_label, document_date, file_name, mime_type, size_bytes, content) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([
                    uuid_v4(), $user['id'], $title, $reference !== '' ? $reference : null, $category, $accessLevel,
                    $description !== '' ? $description : null, $version !== '' ? $version : null,
                    $documentDate !== '' ? $documentDate : null, $fileName, $mime ?: 'application/octet-stream', $size, $content,
                ]);
                flash('success', 'Το εταιρικό έγγραφο αποθηκεύτηκε με ασφάλεια.');
                redirect_to('corporate-documents.php');
            } catch (PDOException $exception) {
                error_log('Corporate document upload failed: ' . $exception->getMessage());
                $errors[] = $exception->getCode() === '23000'
                    ? 'Υπάρχει ήδη έγγραφο με αυτή την αναφορά.'
                    : 'Το έγγραφο δεν αποθηκεύτηκε. Δοκιμάστε ξανά.';
            }
        }
}

$selectedCategory = (string)($_GET['category'] ?? '');
if ($selectedCategory !== '' && !array_key_exists($selectedCategory, $categories)) $selectedCategory = '';
$query = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 120));
$conditions = [];
$parameters = [];
if (!$canManage) $conditions[] = "d.access_level = 'all'";
if ($selectedCategory !== '') {
    $conditions[] = 'd.category = ?';
    $parameters[] = $selectedCategory;
}
if ($query !== '') {
    $conditions[] = '(d.title LIKE ? OR d.document_reference LIKE ? OR d.description LIKE ?)';
    $needle = '%' . $query . '%';
    array_push($parameters, $needle, $needle, $needle);
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$statement = db()->prepare("SELECT d.id, d.title, d.document_reference, d.category, d.access_level, d.description, d.version_label, d.document_date, d.file_name, d.mime_type, d.size_bytes, d.created_at, u.name AS uploaded_by_name FROM corporate_documents d LEFT JOIN users u ON u.id = d.uploaded_by {$where} ORDER BY COALESCE(d.document_date, DATE(d.created_at)) DESC, d.created_at DESC");
$statement->execute($parameters);
$documents = $statement->fetchAll();
$totalDocuments = $canManage
    ? (int)db()->query('SELECT COUNT(*) FROM corporate_documents')->fetchColumn()
    : (int)db()->query("SELECT COUNT(*) FROM corporate_documents WHERE access_level = 'all'")->fetchColumn();

render_header('Εταιρικά Έγγραφα', $user);
?>
<div class="page-heading"><div><p class="eyebrow">CONTROLLED MASTER DOCUMENTS</p><h1>Εταιρικά έντυπα</h1><p>Κεντρική βιβλιοθήκη των σταθερών master documents της DISTILLOGIC, με ελεγχόμενη πρόσβαση και διανομή.</p></div><div class="actions"><?php if ($canManage): ?><a class="button" href="<?= e(crm_url('client-packs.php')) ?>">Client-specific packs</a><a class="button primary" href="#new-corporate-document">Προσθήκη εγγράφου</a><?php endif; ?></div></div>

<div class="alert info"><strong>Το κενό NDA Template βρίσκεται σε αυτή τη βιβλιοθήκη.</strong> Τα εξατομικευμένα και υπογεγραμμένα NDA δημιουργούνται και παρακολουθούνται ξεχωριστά στην καρτέλα κάθε πελάτη.</div>
<div class="client-document-map"><article class="card"><span class="badge">MASTER</span><h2>Εταιρικά έντυπα προς αποστολή</h2><p>Capability Statement, Rate Card, Payment Terms, Security Practices και Vendor Pack. Παραμένουν ελεγχόμενες εκδόσεις και αποστέλλονται επιλεκτικά.</p></article><article class="card"><span class="badge">PER CLIENT</span><h2>Client-specific packs</h2><p>Engineering Profiles, συμπληρωμένα vendor responses και security questionnaires. Συνδέονται με πελάτη, αλλά δεν μπλοκάρουν τη συμβατική ροή.</p><?php if($canManage): ?><a class="button compact" href="<?= e(crm_url('client-packs.php')) ?>">Άνοιγμα client packs</a><?php endif; ?></article></div>
<?php if ($errors): ?><div class="alert error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<section class="corporate-document-summary">
  <article class="card stat"><span>Σύνολο εγγράφων</span><strong><?= $totalDocuments ?></strong><small>διαθέσιμα άμεσα στο CRM</small></article>
  <article class="card corporate-document-note"><strong>Ελεγχόμενη πρόσβαση</strong><p>Κάθε έγγραφο έχει επίπεδο πρόσβασης. Τα εμπορικά και οικονομικά απόρρητα μπορούν να περιορίζονται αποκλειστικά στη διοίκηση.</p></article>
</section>

<form class="card corporate-document-filters" method="get">
  <label>Αναζήτηση<input name="q" value="<?= e($query) ?>" placeholder="Τίτλος, αναφορά ή περιγραφή"></label>
  <label>Κατηγορία<select name="category"><option value="">Όλες οι κατηγορίες</option><?php foreach ($categories as $value => $label): ?><option value="<?= e($value) ?>" <?= $selectedCategory === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
  <button class="button" type="submit">Αναζήτηση</button>
  <?php if ($query !== '' || $selectedCategory !== ''): ?><a class="button ghost" href="<?= e(crm_url('corporate-documents.php')) ?>">Καθαρισμός</a><?php endif; ?>
</form>

<section class="corporate-document-library">
<?php if (!$documents): ?><div class="card empty-state">Δεν βρέθηκαν εταιρικά έγγραφα.</div><?php endif; ?>
<?php foreach ($documents as $document):
    $isPdf = strtolower((string)$document['mime_type']) === 'application/pdf' || strtolower(pathinfo((string)$document['file_name'], PATHINFO_EXTENSION)) === 'pdf';
    $extension = strtoupper(pathinfo((string)$document['file_name'], PATHINFO_EXTENSION) ?: 'FILE');
?>
  <article class="card corporate-document-card">
    <div class="corporate-document-icon"><?= e(mb_substr($extension, 0, 4)) ?></div>
    <div class="corporate-document-copy">
      <span class="badge"><?= e($categories[$document['category']] ?? 'Άλλο') ?></span>
      <?php if ($document['access_level'] === 'management'): ?><span class="badge restricted">Management Only</span><?php endif; ?>
      <h2><?= e($document['title']) ?></h2>
      <?php if ($document['description']): ?><p><?= e($document['description']) ?></p><?php endif; ?>
      <dl><div><dt>Αναφορά</dt><dd><?= e($document['document_reference'] ?: '—') ?></dd></div><div><dt>Έκδοση</dt><dd><?= e($document['version_label'] ?: '—') ?></dd></div><div><dt>Ημερομηνία</dt><dd><?= e($document['document_date'] ? (new DateTimeImmutable($document['document_date']))->format('d/m/Y') : '—') ?></dd></div><div><dt>Μέγεθος</dt><dd><?= e(format_bytes((int)$document['size_bytes'])) ?></dd></div></dl>
    </div>
    <div class="corporate-document-actions">
      <?php if ($isPdf): ?><a class="button" target="_blank" rel="noopener" href="<?= e(crm_url('corporate-document-download.php?id=' . urlencode($document['id']))) ?>">Προβολή</a><?php endif; ?>
      <a class="button primary" href="<?= e(crm_url('corporate-document-download.php?id=' . urlencode($document['id']) . '&download=1')) ?>">Λήψη</a>
    </div>
  </article>
<?php endforeach; ?>
</section>

<?php if ($canManage): ?>
<section class="card form-section corporate-document-upload" id="new-corporate-document">
  <header><h2>Προσθήκη εταιρικού εγγράφου</h2><p>Το αρχείο αποθηκεύεται μέσα στη βάση του CRM και είναι διαθέσιμο μόνο μετά από σύνδεση.</p></header>
  <form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="MAX_FILE_SIZE" value="12582912">
    <div class="form-grid">
      <label>Τίτλος *<input name="title" required maxlength="255" value="<?= e($_POST['title'] ?? '') ?>" placeholder="π.χ. Information Security Policy"></label>
      <label>Κατηγορία *<select name="category" required><?php foreach ($categories as $value => $label): ?><option value="<?= e($value) ?>" <?= (string)($_POST['category'] ?? 'corporate') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label>Πρόσβαση *<select name="access_level" required><option value="all" <?= (string)($_POST['access_level'] ?? 'all') === 'all' ? 'selected' : '' ?>>Όλοι οι χρήστες CRM</option><option value="management" <?= (string)($_POST['access_level'] ?? '') === 'management' ? 'selected' : '' ?>>Μόνο Ιδιοκτήτης / Διαχειριστής</option></select></label>
      <label>Αναφορά εγγράφου<input name="document_reference" maxlength="100" value="<?= e($_POST['document_reference'] ?? '') ?>" placeholder="π.χ. DL-POL-001"></label>
      <label>Έκδοση<input name="version_label" maxlength="50" value="<?= e($_POST['version_label'] ?? '') ?>" placeholder="π.χ. 1.0"></label>
      <label>Ημερομηνία εγγράφου<input type="date" name="document_date" value="<?= e($_POST['document_date'] ?? '') ?>"></label>
      <label>Αρχείο *<input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"><small class="field-note">PDF, Word, Excel, PowerPoint ή TXT · έως 12 MB</small></label>
      <label class="field-full">Περιγραφή<textarea name="description" maxlength="3000" placeholder="Σύντομη περιγραφή και χρήση του εγγράφου"><?= e($_POST['description'] ?? '') ?></textarea></label>
    </div>
    <div class="actions"><button class="button primary" type="submit">Αποθήκευση εγγράφου</button></div>
  </form>
</section>
<?php endif; ?>
<?php render_footer(); ?>
