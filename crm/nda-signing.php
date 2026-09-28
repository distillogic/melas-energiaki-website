<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) { http_response_code(404); exit('Το NDA δεν βρέθηκε.'); }

$loadDocument = static function (string $documentId): array {
    $statement = db()->prepare('SELECT d.*, c.name AS company_name, c.email AS company_email FROM company_documents d JOIN companies c ON c.id=d.company_id WHERE d.id=? AND d.document_type=\'nda\' AND c.deleted_at IS NULL LIMIT 1');
    $statement->execute([$documentId]);
    $document = $statement->fetch();
    if (!$document) { http_response_code(404); exit('Το NDA δεν βρέθηκε.'); }
    return $document;
};
$document = $loadDocument($id);
$errors = [];
$approvalEmail = 'melas@distillogic.gr';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    contract_post_preflight($action,$errors,$document,'nda',$user);

    if ($action === 'upload_client_signed') {
        $file = $_FILES['client_signed_pdf'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Επιλέξτε το PDF που υπέγραψε ο πελάτης.';
        } elseif ((int)$file['size'] < 100 || (int)$file['size'] > 10 * 1024 * 1024) {
            $errors[] = 'Το PDF πρέπει να είναι μικρότερο από 10 MB.';
        } else {
            $tmp = (string)$file['tmp_name'];
            $content = is_uploaded_file($tmp) ? file_get_contents($tmp) : false;
            $mime = $content === false ? '' : (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if ($content === false || $mime !== 'application/pdf' || !str_starts_with($content, '%PDF-')) {
                $errors[] = 'Το αρχείο πρέπει να είναι πραγματικό PDF.';
            } else {
                $safeName = preg_replace('/[^A-Za-z0-9._-]/', '-', basename((string)$file['name'])) ?: 'client-signed-nda.pdf';
                $statement = db()->prepare("UPDATE company_documents SET document_status='client_signed', client_signed_file_name=?, client_signed_pdf=?, client_signed_at=NOW(), approval_code_hash=NULL, approval_code_expires_at=NULL, approval_attempts=0, approval_requested_at=NULL, approval_requested_by=NULL, approved_at=NULL, approval_confirmed_by=NULL WHERE id=?");
                $statement->execute([$safeName, $content, $id]);
                flash('success', 'Το υπογεγραμμένο PDF του πελάτη αποθηκεύτηκε με ασφάλεια.');
                redirect_to('nda-signing.php?id=' . urlencode($id));
            }
        }
    }

    if ($action === 'request_approval') {
        if (!$document['client_signed_at'] || !$document['client_signed_pdf']) {
            $errors[] = 'Πρώτα ανεβάστε το PDF που υπέγραψε ο πελάτης.';
        } elseif ($document['approved_at']) {
            $errors[] = 'Η έγκριση CEO έχει ήδη επιβεβαιωθεί.';
        } elseif ($document['approval_requested_at'] && strtotime((string)$document['approval_requested_at']) > time() - 60) {
            $errors[] = 'Έχει ήδη σταλεί κωδικός. Περιμένετε ένα λεπτό πριν ζητήσετε νέο.';
        } else {
            $code = (string)random_int(100000, 999999);
            $expiresAt = (new DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');
            $pdfHash = hash('sha256', (string)$document['client_signed_pdf']);
            $emailHtml = '<div style="font-family:Arial,sans-serif;color:#10213a;max-width:620px"><h2 style="color:#075ee8">DISTILLOGIC NDA approval</h2><p>Ζητήθηκε έγκριση για το NDA <strong>' . e($document['document_reference']) . '</strong> του πελάτη <strong>' . e($document['company_name']) . '</strong>.</p><p>Ο μοναδικός κωδικός έγκρισης είναι:</p><p style="font-size:32px;font-weight:800;letter-spacing:8px;background:#eef5ff;padding:18px;text-align:center;border-radius:10px">' . e($code) . '</p><p>Ο κωδικός λήγει σε 10 λεπτά. Αν δεν εγκρίνετε την ενέργεια, μην κοινοποιήσετε τον κωδικό.</p><p style="color:#64748b;font-size:13px">Αρχείο: ' . e((string)$document['client_signed_file_name']) . '<br>SHA-256: ' . e($pdfHash) . '<br>Αίτημα από: ' . e($user['name']) . ' (' . e($user['email']) . ')</p></div>';
            if (send_crm_email($approvalEmail, 'DISTILLOGIC NDA approval code — ' . $document['document_reference'], $emailHtml)) {
                $statement = db()->prepare("UPDATE company_documents SET document_status='approval_pending', approval_code_hash=?, approval_code_expires_at=?, approval_attempts=0, approval_requested_at=NOW(), approval_requested_by=? WHERE id=?");
                $statement->execute([password_hash($code, PASSWORD_DEFAULT), $expiresAt, $user['id'], $id]);
                flash('success', 'Ο εξαψήφιος κωδικός στάλθηκε στο melas@distillogic.gr και ισχύει για 10 λεπτά.');
                redirect_to('nda-signing.php?id=' . urlencode($id));
            } else {
                $errors[] = 'Δεν ήταν δυνατή η αποστολή του κωδικού. Ελέγξτε τις ρυθμίσεις email του CRM.';
            }
        }
    }

    if ($action === 'verify_approval') {
        $code = preg_replace('/\D/', '', (string)($_POST['approval_code'] ?? ''));
        if (!is_string($code) || strlen($code) !== 6) {
            $errors[] = 'Ο κωδικός πρέπει να έχει έξι ψηφία.';
        } elseif (!$document['approval_code_hash'] || !$document['approval_code_expires_at']) {
            $errors[] = 'Ζητήστε πρώτα νέο κωδικό έγκρισης.';
        } elseif ((int)$document['approval_attempts'] >= 5) {
            $errors[] = 'Έγιναν πολλές αποτυχημένες προσπάθειες. Ζητήστε νέο κωδικό.';
        } elseif (new DateTimeImmutable((string)$document['approval_code_expires_at']) < new DateTimeImmutable()) {
            $errors[] = 'Ο κωδικός έληξε. Ζητήστε νέο κωδικό.';
        } elseif (!password_verify($code, (string)$document['approval_code_hash'])) {
            db()->prepare('UPDATE company_documents SET approval_attempts=approval_attempts+1 WHERE id=?')->execute([$id]);
            $errors[] = 'Ο κωδικός δεν είναι σωστός.';
        } else {
            $statement = db()->prepare("UPDATE company_documents SET document_status='ceo_approved', approved_at=NOW(), approval_confirmed_by=?, approval_code_hash=NULL, approval_code_expires_at=NULL, approval_attempts=0 WHERE id=?");
            $statement->execute([$user['id'], $id]);
            flash('success', 'Η έγκριση του CEO επιβεβαιώθηκε. Δημιουργήστε τώρα την πλήρως υπογεγραμμένη τελική έκδοση.');
            redirect_to('document-finalize.php?id=' . urlencode($id));
        }
    }

    if ($action === 'send_final') {
        if (!$document['approved_at'] || !$document['final_signed_pdf'] || $document['document_status'] === 'fully_signed_test') {
            $errors[] = 'Το τελικό PDF δεν είναι ακόμη έτοιμο για αποστολή.';
        } else {
            $ndaData = json_decode((string)$document['data_json'], true);
            $ndaData = is_array($ndaData) ? $ndaData : [];
            $candidateRecipients = [
                ['email' => trim((string)($ndaData['client_email'] ?? '')), 'role' => 'client', 'label' => 'εκπρόσωπος πελάτη'],
                ['email' => trim((string)($ndaData['distillogic_email'] ?? '')), 'role' => 'distillogic', 'label' => 'υπογράφων DISTILLOGIC'],
            ];
            $recipients = [];
            foreach ($candidateRecipients as $recipient) {
                if (filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
                    $recipients[mb_strtolower($recipient['email'])] = $recipient;
                } else {
                    $errors[] = 'Το email για ' . $recipient['label'] . ' δεν είναι έγκυρο.';
                }
            }
            if (!$errors) {
                $sentNow = 0;
                $alreadySent = 0;
                $failed = 0;
                $isTest = $document['document_status'] === 'fully_signed_test';
                foreach ($recipients as $recipient) {
                    $check = db()->prepare("SELECT delivery_status FROM company_document_deliveries WHERE document_id=? AND recipient_email=? LIMIT 1");
                    $check->execute([$id, $recipient['email']]);
                    if ($check->fetchColumn() === 'sent') { $alreadySent++; continue; }
                    $subjectPrefix = $isTest ? '[TEST] ' : '';
                    $warning = $isTest ? '<p style="padding:12px;background:#fff3d1;color:#7a4b00;border-radius:8px"><strong>TEST VERSION:</strong> This document contains test signature and stamp text and is not a legally executed signature.</p>' : '';
                    $emailHtml = '<div style="font-family:Arial,sans-serif;color:#10213a;max-width:640px"><h2 style="color:#075ee8">' . $subjectPrefix . 'Mutual Non-Disclosure Agreement</h2>' . $warning . '<p>Please find attached the completed Mutual Non-Disclosure Agreement between DISTILLOGIC TECHNOLOGIES and <strong>' . e($document['company_name']) . '</strong>.</p><p>Document reference: <strong>' . e($document['document_reference']) . '</strong></p><p>This copy has been sent separately to both contracting parties.</p><p style="color:#64748b;font-size:13px">DISTILLOGIC TECHNOLOGIES<br>Software Engineering &amp; Technology Delivery</p></div>';
                    $sent = send_crm_email($recipient['email'], $subjectPrefix . 'Completed NDA — ' . $document['document_reference'], $emailHtml, [[
                        'filename' => (string)($document['final_signed_file_name'] ?: 'fully-signed-nda.pdf'),
                        'mime' => 'application/pdf',
                        'content' => (string)$document['final_signed_pdf'],
                    ]]);
                    $delivery = db()->prepare("INSERT INTO company_document_deliveries (id, document_id, requested_by, recipient_email, recipient_role, delivery_status, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE requested_by=VALUES(requested_by), recipient_role=VALUES(recipient_role), delivery_status=VALUES(delivery_status), sent_at=VALUES(sent_at)");
                    $delivery->execute([uuid_v4(), $id, $user['id'], $recipient['email'], $recipient['role'], $sent ? 'sent' : 'failed', $sent ? date('Y-m-d H:i:s') : null]);
                    if ($sent) { $sentNow++; } else { $failed++; }
                }
                if ($failed === 0) {
                    flash('success', $sentNow > 0 ? 'Το τελικό NDA στάλθηκε ξεχωριστά και στους δύο συμβαλλομένους.' : 'Το NDA είχε ήδη σταλεί στους διαθέσιμους παραλήπτες.');
                    redirect_to('nda-signing.php?id=' . urlencode($id));
                }
                $errors[] = 'Ορισμένες αποστολές απέτυχαν. Πατήστε ξανά για επανάληψη μόνο στους παραλήπτες που δεν το έλαβαν.';
            }
        }
    }
    $document = $loadDocument($id);
}

$ndaData = json_decode((string)$document['data_json'], true);
$ndaData = is_array($ndaData) ? $ndaData : [];
$deliveryStatement = db()->prepare('SELECT recipient_email, recipient_role, delivery_status, sent_at FROM company_document_deliveries WHERE document_id=? ORDER BY recipient_role');
$deliveryStatement->execute([$id]);
$deliveries = $deliveryStatement->fetchAll();
$hasSentDelivery = count(array_filter($deliveries, static fn(array $delivery): bool => $delivery['delivery_status'] === 'sent')) > 0;
render_header('Υπογραφή NDA', $user);
?>
<div class="page-heading"><div><p class="eyebrow">SECURE NDA WORKFLOW</p><h1>Υπογραφή NDA</h1><p><?= e($document['company_name']) ?> · <?= e($document['document_reference']) ?></p></div><div class="actions"><a class="button" href="<?= e(crm_url('nda-view.php?id=' . urlencode($id))) ?>">View latest PDF</a><a class="button" href="<?= e(crm_url('customer.php?id=' . (int)$document['company_id'])) ?>">Πίσω στην καρτέλα</a></div></div>
<?php if ($errors): ?><div class="alert error"><?= e(implode(' ', array_unique($errors))) ?></div><?php endif; ?>
<div class="signing-steps">
  <section class="card signing-step <?= $document['client_signed_at'] ? 'is-complete' : 'is-current' ?>"><div class="step-number">1</div><div><h2>PDF υπογεγραμμένο από τον πελάτη</h2><p>Ανέβασε μόνο το PDF που επέστρεψε ο πελάτης με τη δική του υπογραφή και σφραγίδα.</p><?php if ($document['client_signed_at']): ?><div class="step-success">✓ Αποθηκεύτηκε <?= e(format_datetime($document['client_signed_at'])) ?> · <a href="<?= e(crm_url('client-signed-download.php?id=' . urlencode($id))) ?>">Λήψη αρχείου</a></div><?php endif; ?><form method="post" enctype="multipart/form-data" class="inline-upload"><?= csrf_field() ?><input type="hidden" name="action" value="upload_client_signed"><input type="file" name="client_signed_pdf" accept="application/pdf,.pdf" required><button class="button<?= $document['client_signed_at'] ? '' : ' primary' ?>" type="submit"><?= $document['client_signed_at'] ? 'Αντικατάσταση PDF' : 'Ανέβασμα PDF πελάτη' ?></button></form></div></section>
  <section class="card signing-step <?= $document['approved_at'] ? 'is-complete' : ($document['client_signed_at'] ? 'is-current' : 'is-locked') ?>"><div class="step-number">2</div><div><h2>Αίτημα έγκρισης CEO</h2><p>Ο κωδικός αποστέλλεται αποκλειστικά στο <strong>melas@distillogic.gr</strong>. Ισχύει για 10 λεπτά και επιτρέπει έως 5 προσπάθειες.</p><?php if (!$document['approved_at']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="request_approval"><button class="button primary" type="submit" <?= !$document['client_signed_at'] ? 'disabled' : '' ?>>Αποστολή εξαψήφιου κωδικού</button></form><?php else: ?><div class="step-success">✓ Η έγκριση CEO επιβεβαιώθηκε <?= e(format_datetime($document['approved_at'])) ?></div><?php endif; ?></div></section>
  <section class="card signing-step <?= $document['approved_at'] ? 'is-complete' : ($document['approval_requested_at'] ? 'is-current' : 'is-locked') ?>"><div class="step-number">3</div><div><h2>Επιβεβαίωση κωδικού</h2><p>Η εταιρική έγκριση καταγράφεται μόνο μετά την επιτυχή εισαγωγή του κωδικού.</p><?php if (!$document['approved_at']): ?><form method="post" class="otp-form"><?= csrf_field() ?><input type="hidden" name="action" value="verify_approval"><input name="approval_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required><button class="button primary" type="submit" <?= !$document['approval_requested_at'] ? 'disabled' : '' ?>>Επιβεβαίωση CEO</button></form><?php else: ?><div class="step-success">✓ Κωδικός επαληθευμένος — το ιστορικό έγκρισης έχει κλειδωθεί.</div><?php if (!$document['final_signed_pdf'] || $document['document_status'] === 'fully_signed_test'): ?><a class="button primary" href="<?= e(crm_url('document-finalize.php?id=' . urlencode($id))) ?>"><?= $document['document_status'] === 'fully_signed_test' ? 'Αντικατάσταση TEST με πραγματικές υπογραφές' : 'Δημιουργία Fully Signed PDF' ?></a><?php else: ?><a class="button primary" href="<?= e(crm_url('nda-view.php?id=' . urlencode($id))) ?>">View Fully Signed PDF</a><?php if (!$hasSentDelivery): ?><a class="button" href="<?= e(crm_url('document-finalize.php?id=' . urlencode($id) . '&replace=1')) ?>">Επαναδημιουργία πριν την αποστολή</a><?php endif; ?><?php endif; ?><?php endif; ?></div></section>
  <section class="card signing-step <?= $document['final_signed_pdf'] && $document['document_status'] !== 'fully_signed_test' ? 'is-current' : 'is-locked' ?>"><div class="step-number">4</div><div><h2>Αποστολή στους συμβαλλομένους</h2><p>Το τελικό PDF αποστέλλεται με ξεχωριστό email στον εκπρόσωπο του πελάτη και στον υπογράφοντα της DISTILLOGIC.</p><div class="delivery-recipients"><span><strong>Πελάτης</strong><?= e((string)($ndaData['client_email'] ?? 'Δεν έχει οριστεί')) ?></span><span><strong>DISTILLOGIC</strong><?= e((string)($ndaData['distillogic_email'] ?? 'Δεν έχει οριστεί')) ?></span></div><?php foreach ($deliveries as $delivery): ?><div class="<?= $delivery['delivery_status'] === 'sent' ? 'step-success' : 'alert error' ?>"><?= $delivery['delivery_status'] === 'sent' ? '✓ Στάλθηκε' : 'Απέτυχε' ?>: <?= e($delivery['recipient_email']) ?><?= $delivery['sent_at'] ? ' · ' . e(format_datetime($delivery['sent_at'])) : '' ?></div><?php endforeach; ?><?php if ($document['final_signed_pdf'] && $document['document_status'] !== 'fully_signed_test'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="send_final"><button class="button primary" type="submit" data-confirm="Να σταλεί το τελικό NDA και στους δύο συμβαλλομένους;">Αποστολή NDA και στους δύο</button></form><?php else: ?><button class="button" disabled>Αναμένει πραγματικό Fully Signed PDF</button><?php endif; ?></div></section>
</div>
<script>document.querySelectorAll('.signing-step')[3]?.setAttribute('id','send-to-parties');</script>
<div class="alert info"><strong>Εταιρική υπογραφή:</strong> η εγκεκριμένη υπογραφή και σφραγίδα της DISTILLOGIC τοποθετούνται μόνο μετά την επιτυχή επαλήθευση του κωδικού CEO. Ως ημερομηνία υπογραφής καταγράφεται η ημερομηνία CEO approval.</div>
<?php render_footer(); ?>
