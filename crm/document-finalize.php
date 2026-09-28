<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) { http_response_code(404); exit('Το έγγραφο δεν βρέθηκε.'); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    if(!contract_lock($id)){http_response_code(409);exit('Το έγγραφο ενημερώνεται. Ανοίξτε το ξανά.');}
}
$query = "SELECT d.*, c.name AS company_name FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=? AND d.document_type IN ('nda','msa','dpa','sow') AND c.deleted_at IS NULL LIMIT 1";
$statement = db()->prepare($query);
$statement->execute([$id]);
$document = $statement->fetch();
if (!$document) { http_response_code(404); exit('Το έγγραφο δεν βρέθηκε.'); }
$type = (string)$document['document_type'];
if(in_array($type,['msa','dpa','sow'],true)){http_response_code(409);exit('Το ιστορικό έγγραφο παραμένει διαθέσιμο για λήψη. Η οριστικοποίηση του παλιού προτύπου έχει ανασταλεί μέχρι έγκριση κειμένου MELAS ENERGEIAKI.');}
$returnPath = ($type === 'nda' ? 'nda-signing.php' : $type . '.php') . '?id=' . urlencode($id);
$prerequisites=contract_prerequisite_errors((int)$document['company_id'],$type);
if($prerequisites || in_array($document['document_status'],['expired','terminated','rejected'],true)){
    flash('error', $prerequisites?implode(' ',$prerequisites):'Το έγγραφο δεν είναι ενεργό για υπογραφή.');redirect_to($returnPath);
}
if (!$document['approved_at'] || !$document['client_signed_pdf']) {
    flash('error', 'Απαιτείται πρώτα PDF πελάτη και επιβεβαιωμένη έγκριση CEO.');
    redirect_to($returnPath);
}
$sentQuery = db()->prepare("SELECT COUNT(*) FROM company_document_deliveries WHERE document_id=? AND delivery_status='sent'");
$sentQuery->execute([$id]);
$sent = (int)$sentQuery->fetchColumn() > 0 || in_array($document['document_status'],['sent','accepted','active'],true);
$replace = !$sent && ((string)($_GET['replace'] ?? '') === '1' || $document['document_status'] === 'fully_signed_test');
if ($document['final_signed_pdf'] && !$replace) { redirect_to($returnPath); }
$approvalDate = (new DateTimeImmutable((string)$document['approved_at']))->format('d/m/Y');
$snapshot = hash('sha256', (string)$document['client_signed_pdf'] . '|' . $document['approved_at'] . '|' . (string)$document['final_signed_pdf']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!hash_equals($snapshot, (string)($_POST['snapshot'] ?? '')) || ($_POST['reviewed'] ?? '') !== '1') {
        $errors[] = 'Ανανεώστε τη σελίδα και ελέγξτε ξανά την τελική προεπισκόπηση.';
    }
    $file = $_FILES['final_pdf'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > 15 * 1024 * 1024) {
        $errors[] = 'Η τελική έκδοση PDF δεν παραλήφθηκε σωστά.';
    }
    if (!$errors) {
        $tmp = (string)$file['tmp_name'];
        $content = is_uploaded_file($tmp) ? file_get_contents($tmp) : false;
        if ($content === false || !str_starts_with($content, '%PDF-') || (new finfo(FILEINFO_MIME_TYPE))->file($tmp) !== 'application/pdf') {
            $errors[] = 'Το αρχείο δεν είναι έγκυρο PDF.';
        } else {
            db()->beginTransaction();
            try {
                $lock = db()->prepare($query . ' FOR UPDATE');
                $lock->execute([$id]);
                $current = $lock->fetch();
                $sentQuery->execute([$id]);
                $nowSent = (int)$sentQuery->fetchColumn() > 0 || in_array($current['document_status']??'', ['sent','accepted','active'],true);
                $currentSnapshot = $current ? hash('sha256', (string)$current['client_signed_pdf'] . '|' . $current['approved_at'] . '|' . (string)$current['final_signed_pdf']) : '';
                if (!$current || !hash_equals($snapshot, $currentSnapshot) || ($current['final_signed_pdf'] && (!$replace || $nowSent))) {
                    throw new RuntimeException('Το έγγραφο άλλαξε ή έχει ήδη αποσταλεί. Ανοίξτε το ξανά.');
                }
                $name = preg_replace('/[^A-Za-z0-9._-]/', '-', (string)$document['document_reference']) . '-FULLY-SIGNED.pdf';
                db()->prepare("UPDATE company_documents SET document_status='approved_pdf',final_signed_file_name=?,final_signed_pdf=?,final_signed_at=NOW() WHERE id=?")->execute([$name, $content, $id]);
                db()->commit();
                flash('success', 'Η ελεγμένη τελική έκδοση αποθηκεύτηκε.');
                redirect_to($returnPath);
            } catch (Throwable $error) {
                if (db()->inTransaction()) db()->rollBack();
                $errors[] = 'Η έκδοση δεν αποθηκεύτηκε. Ανοίξτε ξανά το έγγραφο και ελέγξτε την κατάστασή του.';
            }
        }
    }
}

$asset = static function (string $name): string {
    $bytes = file_get_contents(__DIR__ . '/private-assets/signatures/' . $name);
    if ($bytes === false) { throw new RuntimeException('Company signing asset missing'); }
    return 'data:image/png;base64,' . base64_encode($bytes);
};
$options = [
    'source' => crm_url('client-signed-download.php?id=' . urlencode($id)),
    'signature' => $asset('distillogic-signature.png'),
    'stamp' => $asset('distillogic-stamp.png'),
    'date' => $approvalDate,
];
header('Cache-Control: private, no-store');
render_header('Τελική υπογραφή ' . strtoupper($type), $user);
?>
<link rel="stylesheet" href="<?= e(crm_url('assets/document-placement.css?v=20260914')) ?>">
<div class="page-heading"><div><h1>Τελική υπογραφή <?= e(strtoupper($type)) ?></h1><p><?= e($document['company_name']) ?> · <?= e($document['document_reference']) ?></p></div><a class="button" href="<?= e(crm_url($returnPath)) ?>">Πίσω</a></div>
<?php if ($errors): ?><div class="alert error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<section class="card placement-panel">
  <h2>Υπογραφή και σφραγίδα DISTILLOGIC</h2>
  <p>Ελέγξτε τα επιλεγμένα πλαίσια στην πλευρά της DISTILLOGIC. Για διόρθωση, επιλέξτε ένα πεδίο και σχεδιάστε το πλαίσιό του μέσα στην αντίστοιχη διακεκομμένη περιοχή. Για την ημερομηνία επιλέξτε τον χώρο πάνω από τη γραμμή.</p>
  <p>Ημερομηνία CEO approval: <strong><?= e($approvalDate) ?></strong></p>
  <div class="placement-tools">
    <label>Σελίδα <select id="placement-page" disabled></select></label>
    <button type="button" class="button" data-field="signature">1. Υπογραφή</button>
    <button type="button" class="button" data-field="date">2. Ημερομηνία</button>
    <button type="button" class="button" data-field="stamp">3. Σφραγίδα</button>
    <button type="button" class="button" id="placement-auto" disabled>Εντοπισμός πεδίων</button>
  </div>
  <p id="placement-status" role="status" aria-live="polite">Φόρτωση του PDF πελάτη…</p>
  <div id="placement-stage"><canvas id="placement-source"></canvas><div id="placement-overlay" aria-label="Επιλογή περιοχών υπογραφής"></div></div>
  <button type="button" class="button primary" id="placement-preview" disabled>Προεπισκόπηση τελικού PDF</button>
</section>
<section class="card placement-panel" id="placement-review" hidden>
  <h2>Έλεγχος τελικής έκδοσης</h2>
  <p>Αυτή είναι η πραγματική σελίδα του παραγόμενου PDF. Ελέγξτε ότι η υπογραφή, η ημερομηνία και η σφραγίδα βρίσκονται μέσα στα πεδία και ότι όλες οι πληροφορίες διαβάζονται.</p>
  <canvas id="placement-result"></canvas>
  <label class="placement-confirm"><input type="checkbox" id="placement-reviewed"> Έλεγξα τη θέση και την αναγνωσιμότητα των τριών πεδίων.</label>
  <button type="button" class="button primary" id="placement-save" disabled>Αποθήκευση τελικής έκδοσης</button>
</section>
<form id="final-upload" method="post" enctype="multipart/form-data" hidden>
  <?= csrf_field() ?><input type="hidden" name="snapshot" value="<?= e($snapshot) ?>"><input type="hidden" name="reviewed" value="1"><input type="file" name="final_pdf" id="final-pdf">
</form>
<script type="application/json" id="placement-options"><?= json_encode($options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(crm_url('assets/vendor/pdf-lib.min.js')) ?>"></script>
<script type="module" src="<?= e(crm_url('assets/document-placement.js?v=20260914')) ?>"></script>
<?php render_footer(); ?>
