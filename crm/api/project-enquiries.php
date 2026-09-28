<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_answer(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function field(string $name, int $max, bool $required = true): string
{
    $value = trim((string)($_POST[$name] ?? ''));
    if (($required && $value === '') || mb_strlen($value) > $max) {
        throw new InvalidArgumentException('Το πεδίο ' . $name . ' δεν είναι έγκυρο.');
    }
    return $value;
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    return match ($unit) {
        'g' => (int)($number * 1024 * 1024 * 1024),
        'm' => (int)($number * 1024 * 1024),
        'k' => (int)($number * 1024),
        default => (int)$number,
    };
}

function upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ένα από τα αρχεία υπερβαίνει το όριο μεγέθους του server.',
        UPLOAD_ERR_PARTIAL => 'Η μεταφόρτωση ενός αρχείου διακόπηκε. Παρακαλούμε δοκιμάστε ξανά.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Ο server δεν μπόρεσε να αποθηκεύσει ένα αρχείο. Παρακαλούμε δοκιμάστε ξανά.',
        default => 'Ένα από τα αρχεία δεν μεταφορτώθηκε σωστά.',
    };
}

function attachment_mime_is_allowed(string $extension, string $mime, string $temporaryPath, array $allowedMimes): bool
{
    if ($mime !== 'application/octet-stream' && in_array($mime, $allowedMimes[$extension] ?? [], true)) return true;
    $handle = @fopen($temporaryPath, 'rb');
    $prefix = $handle ? (string)fread($handle, 12) : '';
    if (is_resource($handle)) fclose($handle);
    if ($extension === 'pdf') return substr($prefix, 0, 5) === '%PDF-';
    if (in_array($extension, ['jpg', 'jpeg'], true)) return substr($prefix, 0, 3) === "\xFF\xD8\xFF";
    if ($extension === 'png') return substr($prefix, 0, 8) === "\x89PNG\r\n\x1A\n";
    if (in_array($extension, ['docx', 'xlsx'], true)) return substr($prefix, 0, 4) === "PK\x03\x04";
    if (in_array($extension, ['doc', 'xls'], true)) return substr($prefix, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    return false;
}

function email_domain_accepts_mail(string $email): bool
{
    $at = strrpos($email, '@');
    $domain = $at === false ? '' : strtolower(substr($email, $at + 1));
    if ($domain === '' || !str_contains($domain, '.')) return false;
    if (!function_exists('checkdnsrr')) return true;
    return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_answer(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postLimit = ini_bytes((string)ini_get('post_max_size'));
if ($postLimit > 0 && $contentLength > $postLimit) {
    api_answer(413, ['ok' => false, 'error' => 'Τα επιλεγμένα αρχεία υπερβαίνουν το συνολικό όριο μεταφόρτωσης του server.', 'failureEmailSent' => false]);
}

$origin = strtolower((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowedOrigins = [
    'http://distillogic.gr',
    'http://www.distillogic.gr',
    'https://distillogic.gr',
    'https://www.distillogic.gr',
];
if ($origin !== '' && !in_array(rtrim($origin, '/'), $allowedOrigins, true)) {
    api_answer(403, ['ok' => false, 'error' => 'Η προέλευση του αιτήματος δεν επιτρέπεται.']);
}
if (trim((string)($_POST['website'] ?? '')) !== '') {
    api_answer(202, ['ok' => true, 'stored' => true, 'reference' => 'DL-' . strtoupper(bin2hex(random_bytes(4))), 'confirmationEmailSent' => false]);
}

$emailForFailure = null;
try {
    enforce_rate_limit('website-enquiry', 20);
    if ((string)($_POST['privacyConsent'] ?? '') !== 'yes') {
        throw new InvalidArgumentException('Απαιτείται συγκατάθεση επεξεργασίας δεδομένων.');
    }
    $data = [
        'name' => field('name', 120), 'company' => field('company', 255),
        'email' => field('email', 255), 'emailConfirm' => field('emailConfirm', 255),
        'phone' => field('phone', 30, false),
        'country' => field('country', 100), 'service' => field('service', 80),
        'projectTitle' => field('projectTitle', 180), 'requirement' => field('requirement', 6000),
        'technologies' => field('technologies', 1000, false), 'projectStage' => field('projectStage', 80),
        'engagement' => field('engagement', 80), 'timeline' => field('timeline', 80),
        'requestNda' => strtolower(field('requestNda', 10, false)) === 'yes',
        'language' => in_array(($_POST['language'] ?? 'en'), ['en','el'], true) ? $_POST['language'] : 'en',
        'sourcePage' => field('sourcePage', 300, false),
    ];
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Το email δεν είναι έγκυρο.');
    }
    if (mb_strtolower($data['email']) !== mb_strtolower($data['emailConfirm'])) {
        throw new InvalidArgumentException('Οι δύο διευθύνσεις email δεν ταιριάζουν.');
    }
    if (!email_domain_accepts_mail($data['email'])) {
        throw new InvalidArgumentException('Το domain του email δεν φαίνεται να μπορεί να λάβει μηνύματα. Ελέγξτε τη διεύθυνση.');
    }
    $emailForFailure = $data['email'];
    $allowedMimes = [
        'pdf' => ['application/pdf', 'application/octet-stream'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];
    $files = [];
    if (isset($_FILES['documents']) && is_array($_FILES['documents'])) {
        $upload = $_FILES['documents'];
        $names = is_array($upload['name'] ?? null) ? $upload['name'] : [$upload['name'] ?? ''];
        $errors = is_array($upload['error'] ?? null) ? $upload['error'] : [$upload['error'] ?? UPLOAD_ERR_NO_FILE];
        $sizes = is_array($upload['size'] ?? null) ? $upload['size'] : [$upload['size'] ?? 0];
        $temps = is_array($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : [$upload['tmp_name'] ?? ''];
        if (count(array_filter($errors, static fn($error): bool => (int)$error !== UPLOAD_ERR_NO_FILE)) > 5) {
            throw new InvalidArgumentException('Μπορείτε να ανεβάσετε έως 5 αρχεία.');
        }
        foreach ($names as $index => $original) {
            $error = (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException(upload_error_message($error));
            }
            $size = (int)($sizes[$index] ?? 0);
            $temp = (string)($temps[$index] ?? '');
            $extension = strtolower(pathinfo((string)$original, PATHINFO_EXTENSION));
            if ($size <= 0 || $size > 8 * 1024 * 1024) {
                throw new InvalidArgumentException('Κάθε αρχείο πρέπει να είναι έως 8 MB.');
            }
            if (!array_key_exists($extension, $allowedMimes)) {
                throw new InvalidArgumentException('Επιτρέπονται μόνο PDF, Office, CSV, TXT, JPG και PNG αρχεία.');
            }
            if ($temp === '' || !is_uploaded_file($temp)) {
                throw new InvalidArgumentException('Η μεταφόρτωση ενός αρχείου δεν ολοκληρώθηκε.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temp) ?: 'application/octet-stream';
            if (!attachment_mime_is_allowed($extension, $mime, $temp, $allowedMimes)) {
                throw new InvalidArgumentException('Το περιεχόμενο του συνημμένου δεν είναι επιτρεπτό.');
            }
            $safeName = preg_replace('/[^\pL\pN._ -]+/u', '_', basename((string)$original));
            $safeName = trim((string)$safeName, " .\t\n\r\0\x0B");
            if ($safeName === '') $safeName = 'document.' . $extension;
            if (mb_strlen($safeName) > 180) {
                $safeName = mb_substr(pathinfo($safeName, PATHINFO_FILENAME), 0, 160) . '.' . $extension;
            }
            $files[] = ['original' => $safeName, 'temp' => $temp, 'size' => $size, 'mime' => $mime];
        }
        if (array_sum(array_column($files, 'size')) > 20 * 1024 * 1024) {
            throw new InvalidArgumentException('Τα αρχεία πρέπει να είναι έως 20 MB συνολικά.');
        }
    }

    $reference = 'DL-' . strtoupper(bin2hex(random_bytes(4)));
    $communicationId = uuid_v4();
    $enquiryId = uuid_v4();
    $pdo = db();
    $pdo->beginTransaction();
    $nameKey = mb_strtolower($data['company']);
    $companyQuery = $pdo->prepare('SELECT id FROM companies WHERE name_key = ? AND deleted_at IS NULL LIMIT 1');
    $companyQuery->execute([$nameKey]);
    $companyId = $companyQuery->fetchColumn();
    if (!$companyId) {
        $insertCompany = $pdo->prepare('INSERT INTO companies (name, name_key, email, phone) VALUES (?, ?, ?, ?)');
        $insertCompany->execute([$data['company'], $nameKey, $data['email'], $data['phone'] ?: null]);
        $companyId = $pdo->lastInsertId();
    }
    $insertCommunication = $pdo->prepare("INSERT INTO communications (id, company_id, source, status, contact_name, contact_role) VALUES (?, ?, 'website', 'new', ?, ?)");
    $insertCommunication->execute([$communicationId, $companyId, $data['name'], 'Αίτημα έργου ιστοσελίδας']);
    $brief = $pdo->prepare('INSERT INTO communication_project_briefs (communication_id, country, service, project_title, requirement, technologies, project_stage, engagement, timeline, request_nda) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $brief->execute([$communicationId, $data['country'], $data['service'], $data['projectTitle'], $data['requirement'], $data['technologies'] ?: null, $data['projectStage'], $data['engagement'], $data['timeline'], $data['requestNda'] ? 1 : 0]);
    $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $reference);
    $enquiry = $pdo->prepare('INSERT INTO website_enquiries (id, reference, communication_id, business_email, language, source_page, submitted_at, request_ip_hash) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)');
    $enquiry->execute([$enquiryId, $reference, $communicationId, $data['email'], $data['language'], $data['sourcePage'] ?: null, $ipHash]);

    $document = $pdo->prepare('INSERT INTO website_enquiry_documents (id, website_enquiry_id, file_name, mime_type, size_bytes, content) VALUES (?, ?, ?, ?, ?, ?)');
    $mailAttachments = [];
    foreach ($files as $file) {
        $content = file_get_contents($file['temp']);
        if ($content === false) throw new RuntimeException('Document storage failed.');
        $document->execute([uuid_v4(), $enquiryId, $file['original'], $file['mime'], $file['size'], $content]);
        $mailAttachments[] = ['filename' => $file['original'], 'mime' => $file['mime'], 'content' => $content];
    }
    $recipients = $pdo->query("SELECT id FROM users WHERE active = 1 AND role IN ('admin','technical')")->fetchAll();
    $notification = $pdo->prepare('INSERT INTO notifications (id, user_id, title, body, action_url) VALUES (?, ?, ?, ?, ?)');
    foreach ($recipients as $recipient) {
        $notification->execute([uuid_v4(), $recipient['id'], 'Νέο αίτημα έργου από την ιστοσελίδα', $data['company'] . ' — ' . $data['projectTitle'], '/crm/communication.php?id=' . $communicationId]);
    }
    $pdo->commit();

    $serviceLabels = [
        'full-project-delivery' => 'Full Project Delivery', 'work-package-delivery' => 'Work Package Delivery',
        'dedicated-engineering-team' => 'Dedicated Engineering Team', 'staff-augmentation' => 'Staff Augmentation',
        'application-development' => 'Application Development', 'systems-integration' => 'Systems Integration',
        'application-modernisation' => 'Application Modernisation', 'cloud-devops' => 'Cloud / DevOps',
        'data-ai' => 'Data & AI', 'quality-engineering' => 'Quality Engineering',
        'application-management' => 'Application Management', 'other' => 'Other',
    ];
    $stageLabels = [
        'idea-planning' => 'Idea / Initial Planning', 'requirements-defined' => 'Requirements Defined',
        'technical-design' => 'Technical Design Available', 'development-started' => 'Development Started',
        'existing-application' => 'Existing Application', 'project-recovery' => 'Project Recovery / Rescue',
        'procurement-rfp' => 'Procurement / RFP Stage', 'immediate-delivery' => 'Immediate Delivery Requirement',
    ];
    $engagementLabels = [
        'complete-project' => 'Complete Project', 'specific-work-package' => 'Specific Work Package',
        'application-module' => 'Application Module', 'dedicated-team' => 'Dedicated Team',
        'individual-specialists' => 'Individual Specialists', 'ongoing-support' => 'Ongoing Application Support',
        'not-defined' => 'Not Yet Defined',
    ];
    $timelineLabels = [
        'immediate' => 'Immediate', 'within-one-month' => 'Within 1 month', 'one-three-months' => '1–3 months',
        'three-six-months' => '3–6 months', 'six-plus-months' => '6+ months', 'discuss' => 'To be discussed',
    ];
    $service = $serviceLabels[$data['service']] ?? ucwords(str_replace('-', ' ', $data['service']));
    $stage = $stageLabels[$data['projectStage']] ?? ucwords(str_replace('-', ' ', $data['projectStage']));
    $engagement = $engagementLabels[$data['engagement']] ?? ucwords(str_replace('-', ' ', $data['engagement']));
    $timeline = $timelineLabels[$data['timeline']] ?? ucwords(str_replace('-', ' ', $data['timeline']));
    $fileList = $mailAttachments === []
        ? '<p style="margin:0;color:#526174">No supporting documents were attached.</p>'
        : '<ul style="margin:8px 0 0;padding-left:20px;color:#26364a">' . implode('', array_map(
            static fn(array $attachment): string => '<li style="margin:4px 0">' . e($attachment['filename']) . '</li>',
            $mailAttachments
        )) . '</ul><p style="margin:10px 0 0;color:#526174;font-size:13px">The files listed above are attached to this confirmation email.</p>';
    $ndaNotice = $data['requestNda']
        ? '<div style="margin:22px 0;padding:16px 18px;background:#eef5ff;border-left:4px solid #1769ff;border-radius:6px"><strong>NDA requested</strong><br><span style="color:#526174">You asked for a Non-Disclosure Agreement before sharing further confidential information. Our team will address this during the initial review.</span></div>'
        : '';
    $summary = '<div style="margin:0;background:#f3f6fa;padding:28px 12px;font-family:Arial,Helvetica,sans-serif;line-height:1.6;color:#071426">'
        . '<div style="max-width:700px;margin:0 auto;background:#ffffff;border:1px solid #dbe4ef;border-radius:12px;overflow:hidden">'
        . '<div style="background:#071a33;padding:26px 30px;color:#ffffff"><div style="font-size:13px;letter-spacing:2px;font-weight:700;color:#70a5ff">DISTILLOGIC TECHNOLOGIES</div><h1 style="margin:10px 0 0;font-size:27px;line-height:1.25;color:#ffffff">We received your project enquiry.</h1></div>'
        . '<div style="padding:30px">'
        . '<p style="margin-top:0">Dear ' . e($data['name']) . ',</p>'
        . '<p>Thank you for contacting DISTILLOGIC TECHNOLOGIES. Your project enquiry has been received successfully and registered for review by our delivery team.</p>'
        . '<div style="margin:22px 0;padding:18px;background:#f5f8fc;border:1px solid #dbe4ef;border-radius:8px"><span style="display:block;font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#526174;font-weight:700">Enquiry reference</span><strong style="display:block;margin-top:4px;font-size:22px;color:#1769ff">' . e($reference) . '</strong><span style="display:block;margin-top:5px;color:#526174;font-size:13px">Please quote this reference in any related correspondence.</span></div>'
        . '<h2 style="font-size:19px;margin:26px 0 8px">What happens next</h2>'
        . '<ol style="margin:0;padding-left:22px;color:#26364a"><li style="margin:7px 0">We will review the scope, technical requirements and expected delivery model.</li><li style="margin:7px 0">A member of our team may contact you if clarification or additional documentation is required.</li><li style="margin:7px 0">We will then propose the appropriate next step, such as an introductory discussion, NDA, technical assessment or commercial proposal.</li></ol>'
        . $ndaNotice
        . '<h2 style="font-size:19px;margin:28px 0 12px">Submission summary</h2>'
        . '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px">'
        . '<tr><td style="padding:9px 10px;border:1px solid #dbe4ef;background:#f5f8fc;font-weight:700;width:34%">Company</td><td style="padding:9px 10px;border:1px solid #dbe4ef">' . e($data['company']) . '</td></tr>'
        . '<tr><td style="padding:9px 10px;border:1px solid #dbe4ef;background:#f5f8fc;font-weight:700">Contact</td><td style="padding:9px 10px;border:1px solid #dbe4ef">' . e($data['name']) . '</td></tr>'
        . '<tr><td style="padding:9px 10px;border:1px solid #dbe4ef;background:#f5f8fc;font-weight:700">Business email</td><td style="padding:9px 10px;border:1px solid #dbe4ef">' . e($data['email']) . '</td></tr>'
        . '<tr><td style="padding:9px 10px;border:1px solid #dbe4ef;background:#f5f8fc;font-weight:700">Phone</td><td style="padding:9px 10px;border:1px solid #dbe4ef">' . e($data['phone'] ?: 'Not provided') . '</td></tr>'
        . '<tr><td style="padding:9px 10px;border:1px solid #dbe4ef;background:#f5f8fc;font-weight:700">Country</td><td style="padding:9px 10px;border:1px solid #dbe4ef">' . e($data['country']) . '</td></tr>'
        . '<tr><td style="padding:9px 10px;border:1px solid #dbe4ef;background:#f5f8fc;font-weight:700">Project title</td><td style="padding:9px 10px;border:1px solid #dbe4ef">' . e($data['projectTitle']) . '</td></tr>'
        . '<tr><td style="padding:9px 10px;border:1px solid #dbe4ef;background:#f5f8fc;font-weight:700">Requested support</td><td style="padding:9px 10px;border:1px solid #dbe4ef">' . e($service) . '</td></tr>'
        . '<tr><td style="padding:9px 10px;border:1px solid #dbe4ef;background:#f5f8fc;font-weight:700">Project stage</td><td style="padding:9px 10px;border:1px solid #dbe4ef">' . e($stage) . '</td></tr>'
        . '<tr><td style="padding:9px 10px;border:1px solid #dbe4ef;background:#f5f8fc;font-weight:700">Preferred engagement</td><td style="padding:9px 10px;border:1px solid #dbe4ef">' . e($engagement) . '</td></tr>'
        . '<tr><td style="padding:9px 10px;border:1px solid #dbe4ef;background:#f5f8fc;font-weight:700">Expected start</td><td style="padding:9px 10px;border:1px solid #dbe4ef">' . e($timeline) . '</td></tr>'
        . '<tr><td style="padding:9px 10px;border:1px solid #dbe4ef;background:#f5f8fc;font-weight:700">NDA</td><td style="padding:9px 10px;border:1px solid #dbe4ef">' . ($data['requestNda'] ? 'Requested' : 'Not requested') . '</td></tr>'
        . '</table>'
        . '<h3 style="font-size:16px;margin:24px 0 7px">Project requirement</h3><div style="padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;color:#26364a">' . nl2br(e($data['requirement'])) . '</div>'
        . '<h3 style="font-size:16px;margin:24px 0 7px">Current / required technologies</h3><p style="margin:0;color:#26364a">' . e($data['technologies'] ?: 'Not yet defined') . '</p>'
        . '<h3 style="font-size:16px;margin:24px 0 7px">Supporting documents</h3>' . $fileList
        . '<p style="margin:28px 0 0">If you need to add information, reply to this email or contact us at <a href="mailto:info@distillogic.gr" style="color:#1769ff">info@distillogic.gr</a>, quoting your enquiry reference.</p>'
        . '<p style="margin-bottom:0">Kind regards,<br><strong>DISTILLOGIC TECHNOLOGIES</strong><br><span style="color:#526174">Software Engineering &amp; Technology Delivery</span></p>'
        . '</div><div style="padding:16px 30px;background:#f5f8fc;color:#6b7788;font-size:12px;text-align:center">This is an automated confirmation that your enquiry was received. It does not constitute acceptance of a project, quotation or contractual commitment.</div>'
        . '</div></div>';
    $subject = 'We received your project enquiry — ' . $reference;
    $sent = send_crm_email($data['email'], $subject, $summary, $mailAttachments);
    clear_rate_limit('website-enquiry');
    api_answer(201, ['ok' => true, 'stored' => true, 'reference' => $reference, 'documentsStored' => count($files), 'confirmationEmailSent' => $sent]);
} catch (InvalidArgumentException $exception) {
    $failureSent = $emailForFailure ? send_crm_email($emailForFailure, 'Η υποβολή του αιτήματος δεν ολοκληρώθηκε', '<p>Η αίτησή σας δεν καταχωρίστηκε: ' . e($exception->getMessage()) . '</p><p>Διορθώστε το πρόβλημα και δοκιμάστε ξανά ή επικοινωνήστε στο info@distillogic.gr.</p>') : false;
    api_answer(400, ['ok' => false, 'error' => $exception->getMessage(), 'failureEmailSent' => $failureSent]);
} catch (Throwable $exception) {
    if (http_response_code() === 429) {
        api_answer(429, ['ok' => false, 'error' => $exception->getMessage(), 'failureEmailSent' => false]);
    }
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Website enquiry failed: ' . $exception->getMessage());
    $failureSent = $emailForFailure ? send_crm_email($emailForFailure, 'Αποτυχία υποβολής αιτήματος', '<p>Η αίτησή σας δεν καταχωρίστηκε. Παρακαλούμε δοκιμάστε ξανά ή επικοινωνήστε στο info@distillogic.gr.</p>') : false;
    api_answer(500, ['ok' => false, 'error' => 'Η αίτηση δεν καταχωρίστηκε. Παρακαλούμε δοκιμάστε ξανά.', 'failureEmailSent' => $failureSent]);
}
