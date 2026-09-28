<?php
declare(strict_types=1);

const CRM_ROOT = __DIR__;
require_once __DIR__.'/contract-safety.php';
require_once __DIR__.'/personnel-workflow.php';
require_once __DIR__.'/activity-log.php';
require_once __DIR__.'/lead-workflow.php';

function crm_config(): array
{
    static $config;
    if (is_array($config)) {
        return $config;
    }

    $path = CRM_ROOT . '/config.php';
    if (!is_file($path)) {
        http_response_code(503);
        exit('CRM configuration is missing. Create crm/config.php from config.example.php.');
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('Invalid CRM configuration.');
    }
    $config = $loaded;
    date_default_timezone_set((string)($config['app']['timezone'] ?? 'Europe/Athens'));
    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = crm_config()['database'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $database['host'],
        $database['port'],
        $database['name'],
        $database['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $database['user'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function ensure_customer_workspace_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo = db();
    $changes = [
        "CREATE TABLE IF NOT EXISTS crm_settings (
          setting_key VARCHAR(100) PRIMARY KEY,
          setting_value TEXT NOT NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS corporate_documents (
          id CHAR(36) PRIMARY KEY,
          uploaded_by CHAR(36) NULL,
          title VARCHAR(255) NOT NULL,
          document_reference VARCHAR(100) NULL,
          category VARCHAR(100) NOT NULL DEFAULT 'other',
          access_level VARCHAR(30) NOT NULL DEFAULT 'all',
          description TEXT NULL,
          version_label VARCHAR(50) NULL,
          document_date DATE NULL,
          file_name VARCHAR(255) NOT NULL,
          mime_type VARCHAR(150) NOT NULL,
          size_bytes BIGINT UNSIGNED NOT NULL,
          content LONGBLOB NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          CONSTRAINT corporate_documents_user_fk FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
          UNIQUE KEY corporate_documents_reference_unique (document_reference),
          KEY corporate_documents_category_idx (category, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS company_documents (
          id CHAR(36) PRIMARY KEY,
          company_id BIGINT UNSIGNED NOT NULL,
          created_by CHAR(36) NULL,
          document_type VARCHAR(40) NOT NULL,
          document_reference VARCHAR(80) NOT NULL,
          title VARCHAR(255) NOT NULL,
          file_name VARCHAR(255) NOT NULL,
          mime_type VARCHAR(150) NOT NULL DEFAULT 'text/html; charset=UTF-8',
          content LONGBLOB NOT NULL,
          data_json LONGTEXT NULL,
          effective_date DATE NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          CONSTRAINT company_documents_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
          CONSTRAINT company_documents_user_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
          UNIQUE KEY company_documents_reference_unique (document_reference),
          KEY company_documents_company_idx (company_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS engineering_profiles (
          id CHAR(36) PRIMARY KEY,
          company_id BIGINT UNSIGNED NULL,
          created_by CHAR(36) NULL,
          profile_id VARCHAR(100) NOT NULL,
          internal_name VARCHAR(180) NOT NULL,
          primary_role VARCHAR(180) NOT NULL,
          seniority VARCHAR(60) NOT NULL,
          profile_status VARCHAR(30) NOT NULL DEFAULT 'draft',
          data_json LONGTEXT NOT NULL,
          content LONGBLOB NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY engineering_profiles_profile_id_unique (profile_id),
          KEY engineering_profiles_company_idx (company_id, updated_at),
          KEY engineering_profiles_status_idx (profile_status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE corporate_documents ADD COLUMN IF NOT EXISTS access_level VARCHAR(30) NOT NULL DEFAULT 'all' AFTER category",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS legal_name VARCHAR(255) NULL AFTER name",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS trading_name VARCHAR(255) NULL AFTER legal_name",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS legal_form VARCHAR(120) NULL AFTER trading_name",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS country VARCHAR(120) NULL AFTER city",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS postal_code VARCHAR(30) NULL AFTER country",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS registration_number VARCHAR(120) NULL AFTER postal_code",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS vat_number VARCHAR(120) NULL AFTER registration_number",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS contact_name VARCHAR(150) NULL AFTER vat_number",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS contact_title VARCHAR(150) NULL AFTER contact_name",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS dpa_required TINYINT(1) NOT NULL DEFAULT 0 AFTER contact_title",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS purchase_order_required TINYINT(1) NOT NULL DEFAULT 0 AFTER dpa_required",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS purchase_order_reference VARCHAR(150) NULL AFTER purchase_order_required",
        "ALTER TABLE companies ADD COLUMN IF NOT EXISTS purchase_order_received_at DATETIME NULL AFTER purchase_order_reference",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS document_status VARCHAR(40) NOT NULL DEFAULT 'draft' AFTER document_type",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS client_signed_file_name VARCHAR(255) NULL AFTER content",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS client_signed_pdf LONGBLOB NULL AFTER client_signed_file_name",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS client_signed_at DATETIME NULL AFTER client_signed_pdf",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS approval_code_hash VARCHAR(255) NULL AFTER client_signed_at",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS approval_code_expires_at DATETIME NULL AFTER approval_code_hash",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS approval_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER approval_code_expires_at",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS approval_requested_at DATETIME NULL AFTER approval_attempts",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS approval_requested_by CHAR(36) NULL AFTER approval_requested_at",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER approval_requested_by",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS approval_confirmed_by CHAR(36) NULL AFTER approved_at",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS final_signed_file_name VARCHAR(255) NULL AFTER approval_confirmed_by",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS final_signed_pdf LONGBLOB NULL AFTER final_signed_file_name",
        "ALTER TABLE company_documents ADD COLUMN IF NOT EXISTS final_signed_at DATETIME NULL AFTER final_signed_pdf",
        "ALTER TABLE engineering_profiles ADD COLUMN IF NOT EXISTS company_id BIGINT UNSIGNED NULL AFTER id",
        "CREATE TABLE IF NOT EXISTS company_document_deliveries (
          id CHAR(36) PRIMARY KEY,
          document_id CHAR(36) NOT NULL,
          requested_by CHAR(36) NULL,
          recipient_email VARCHAR(320) NOT NULL,
          recipient_role VARCHAR(40) NOT NULL,
          delivery_status VARCHAR(30) NOT NULL DEFAULT 'pending',
          sent_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          CONSTRAINT document_deliveries_document_fk FOREIGN KEY (document_id) REFERENCES company_documents(id) ON DELETE CASCADE,
          CONSTRAINT document_deliveries_user_fk FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
          UNIQUE KEY document_deliveries_recipient_unique (document_id, recipient_email),
          KEY document_deliveries_status_idx (document_id, delivery_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($changes as $statement) {
        $pdo->exec($statement);
    }
    $ready = true;
}

function crm_setting(string $key, string $default = ''): string
{
    $statement = db()->prepare('SELECT setting_value FROM crm_settings WHERE setting_key = ? LIMIT 1');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function set_crm_setting(string $key, string $value): void
{
    $statement = db()->prepare('INSERT INTO crm_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $statement->execute([$key, $value]);
}

function distillogic_company_profile(): array
{
    $company = crm_config()['company'] ?? [];
    return [
        'legal_name' => trim((string)($company['legal_name'] ?? 'MELAS ENERGIAKI')),
        'address' => trim((string)($company['address'] ?? '')),
        'vat_number' => trim((string)($company['vat_number'] ?? '')),
        'gemi_number' => trim((string)($company['gemi_number'] ?? '')),
    ];
}

function crm_approval_email(): string
{
    return trim((string)(crm_config()['mail']['approval_email'] ?? 'info@melasenergiaki.gr'));
}

function contract_document_complete(?array $document, string $type): bool
{
    if (!$document) return false;
    $status = (string)($document['document_status'] ?? '');
    if (in_array($status, ['rejected','expired','terminated','fully_signed_test'], true)) return false;
    if ($type === 'proposal') return $status === 'accepted' && !empty($document['approved_at']) && !empty($document['final_signed_pdf']);
    if ($type === 'nda') return !empty($document['client_signed_at']) && !empty($document['approved_at']) && !empty($document['final_signed_pdf']) && !empty($document['final_signed_at']);
    if (in_array($type, ['msa', 'dpa'], true)) {
        return !empty($document['client_signed_at']) && !empty($document['approved_at']) && !empty($document['final_signed_pdf']);
    }
    if ($type === 'sow') {
        return !empty($document['approved_at']) && !empty($document['final_signed_pdf'])
            && !empty($document['client_signed_at']);
    }
    return false;
}

function company_contract_flow(int $companyId): array
{
    $companyStatement = db()->prepare('SELECT dpa_required, purchase_order_required, purchase_order_reference, purchase_order_received_at FROM companies WHERE id=? AND deleted_at IS NULL LIMIT 1');
    $companyStatement->execute([$companyId]);
    $company = $companyStatement->fetch() ?: [];
    $documentsStatement = db()->prepare("SELECT id, document_type, document_status, document_reference, title, client_signed_at, approved_at, final_signed_at, (OCTET_LENGTH(final_signed_pdf)>0) AS final_signed_pdf, updated_at FROM company_documents WHERE company_id=? AND document_type IN ('nda','proposal','msa','dpa','sow') ORDER BY updated_at DESC, created_at DESC, id DESC");
    $documentsStatement->execute([$companyId]);
    $latest = [];
    foreach ($documentsStatement->fetchAll() as $document) {
        $type = (string)$document['document_type'];
        if (!isset($latest[$type]) || (!contract_document_complete($latest[$type], $type) && contract_document_complete($document, $type))) $latest[$type] = $document;
    }
    $dpaRequired = !empty($company['dpa_required']);
    $poRequired = !empty($company['purchase_order_required']);
    $complete = [];
    foreach (['nda','proposal','msa','dpa','sow'] as $type) $complete[$type] = contract_document_complete($latest[$type] ?? null, $type);
    $complete['dpa'] = !$dpaRequired || $complete['dpa'];
    $complete['po'] = !$poRequired || (!empty($company['purchase_order_reference']) && !empty($company['purchase_order_received_at']));
    return ['company'=>$company, 'latest'=>$latest, 'complete'=>$complete, 'dpa_required'=>$dpaRequired, 'po_required'=>$poRequired];
}

function contract_prerequisite_errors(int $companyId, string $target): array
{
    // The Melas Energiaki CRM currently uses proposals as a standalone workflow.
    return [];
}

function contract_action_requires_prerequisites(string $action): bool
{
    return in_array($action, ['upload_client_signed','upload_final_pdf','request_approval','verify_approval','send_final','mark_accepted','mark_active'], true);
}

function embed_nda_logo(string $html): string
{
    $logoPath = CRM_ROOT . '/assets/melas-energiaki-logo.png';
    $logo = is_file($logoPath) ? file_get_contents($logoPath) : false;
    if ($logo === false) {
        return $html;
    }
    $dataUrl = 'data:image/png;base64,' . base64_encode($logo);
    return (string)preg_replace(
        '~(<div class="brand"><img\s+)src=("|\')[^"\']+\2~i',
        '$1src="' . $dataUrl . '"',
        $html
    );
}

function normalize_document_brand_html(string $html): string
{
    if (strncmp($html, '%PDF', 4) === 0) {
        return $html;
    }

    $html = str_ireplace(
        ['AUTHORISED SIGNATURE PLACEHOLDER', 'COMPANY STAMP PLACEHOLDER'],
        ['', ''],
        $html
    );

    if (stripos($html, 'id="distillogic-document-brand"') !== false) {
        return $html;
    }

    $brandStyles = '<style id="distillogic-document-brand">'
        . '.hero,section>h2,.section>h2,.section>h3,.proposal-section>h2,.sow-section>h2,'
        . '.msa-section>h2,.dpa-section>h2,.signature-card>h3,.signatures>article>h3,'
        . 'table th,.table-row:first-of-type{background:#176afc!important;color:#fff!important}'
        . '</style>';

    if (stripos($html, '</head>') !== false) {
        return (string)preg_replace('/<\/head>/i', $brandStyles . '</head>', $html, 1);
    }

    return $brandStyles . $html;
}

function start_document_brand_output_normalizer(): void
{
    static $started = false;
    if ($started) {
        return;
    }

    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $documentScripts = [
        'company-document.php',
        'proposal-view.php', 'proposal-download.php',
        'sow-view.php', 'sow-download.php',
        'msa-view.php', 'msa-download.php',
        'dpa-view.php', 'dpa-download.php',
        'intake-view.php', 'intake-download.php',
        'profile-view.php', 'profile-download.php',
    ];

    if (in_array($script, $documentScripts, true)) {
        ob_start('normalize_document_brand_html');
        $started = true;
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function crm_url(string $path = ''): string
{
    $base = rtrim((string)(crm_config()['app']['base_path'] ?? '/crm'), '/');
    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function redirect_to(string $path): never
{
    header('Location: ' . crm_url($path), true, 302);
    exit;
}

function uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function request_is_https(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $forwarded === 'https';
}

function start_crm_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $config = crm_config();
    $days = max(1, (int)($config['app']['session_days'] ?? 14));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('melas_energiaki_crm_v1');
    session_set_cookie_params([
        'lifetime' => $days * 86400,
        'path' => rtrim((string)$config['app']['base_path'], '/') . '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
}

function login_form_token(): string
{
    $issuedAt = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $payload = $issuedAt . '.' . $nonce;
    $config = crm_config();
    $secret = (string)($config['app']['setup_token'] ?? '') . '|' . (string)($config['database']['password'] ?? '');
    return $payload . '.' . hash_hmac('sha256', $payload, $secret);
}

function verify_login_form_token(string $token): bool
{
    $parts = explode('.', $token);
    if (count($parts) !== 3 || !ctype_digit($parts[0]) || !preg_match('/^[0-9a-f]{32}$/', $parts[1]) || !preg_match('/^[0-9a-f]{64}$/', $parts[2])) {
        return false;
    }
    $issuedAt = (int)$parts[0];
    if ($issuedAt > time() + 300 || time() - $issuedAt > 7200) {
        return false;
    }
    $payload = $parts[0] . '.' . $parts[1];
    $config = crm_config();
    $secret = (string)($config['app']['setup_token'] ?? '') . '|' . (string)($config['database']['password'] ?? '');
    return hash_equals(hash_hmac('sha256', $payload, $secret), $parts[2]);
}

function account_session_generation(string $id): int
{
    static $ready=false;
    if(!$ready){
        db()->exec("CREATE TABLE IF NOT EXISTS user_access_state (user_id CHAR(36) PRIMARY KEY, generation BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ready=true;
    }
    $query=db()->prepare('SELECT generation FROM user_access_state WHERE user_id=?');
    $query->execute([$id]);
    return (int)$query->fetchColumn();
}

function obsolete_melas_account_emails(): array
{
    return ['sophianos@melasenenrgiaki.gr', 'sophianos@melasenergiaki.gr'];
}

function cleanup_obsolete_melas_accounts(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $select = db()->prepare('SELECT id FROM users WHERE LOWER(email)=?');
    $delete = db()->prepare('DELETE FROM users WHERE id=?');
    $disable = db()->prepare('UPDATE users SET active=0 WHERE id=?');
    foreach (obsolete_melas_account_emails() as $email) {
        $select->execute([$email]);
        foreach ($select->fetchAll() as $row) {
            try {
                $delete->execute([$row['id']]);
            } catch (Throwable) {
                // Preserve referenced audit/business history, but permanently remove access.
                $disable->execute([$row['id']]);
            }
        }
    }
}

function current_user(): ?array
{
    start_crm_session();
    cleanup_obsolete_melas_accounts();
    $id = $_SESSION['user_id'] ?? null;
    if (!is_string($id) || $id === '') {
        return null;
    }
    $statement = db()->prepare('SELECT id, name, email, role, active FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$id]);
    $user = $statement->fetch();
    if (!$user || !(bool)$user['active'] || (int)($_SESSION['access_generation']??0)!==account_session_generation($id) || !personnel_access_allowed($user)) {
        $_SESSION = [];
        return null;
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        if(onboarding_session_user())redirect_to('onboarding.php');
        redirect_to('login.php');
    }
    header('Cache-Control: private, no-store');
    activity_start($user);
    start_document_brand_output_normalizer();
    if (crm_sales_restricted($user)) {
        $allowed = ['sales-portal.php','sales-accounting.php','leads.php','new-lead.php','edit-lead.php','lead.php','lead-operations.php','reminders.php','lead-attachment.php','lead-receipt.php','commissions.php','sales-guide.php','academy.php','logout.php','operations.php','settlements.php','toolkit-files.php','recurring-agreement.php'];
        $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        if (!in_array($current, $allowed, true)) redirect_to('sales-portal.php');
    }
    return $user;
}

function can_manage_accounts(array $user): bool
{
    return !empty($user['active']) && in_array(($user['role'] ?? ''), ['admin', 'manager'], true);
}

function can_view_all_sales_financials(array $user): bool
{
    if (empty($user['active'])) return false;
    return in_array(strtolower(trim((string)($user['email'] ?? ''))), [
        'melas@distillogic.gr',
        'sophianos@distillogic.gr',
    ], true);
}

function require_roles(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Δεν έχετε δικαίωμα πρόσβασης σε αυτή τη λειτουργία.');
    }
    return $user;
}

function csrf_token(): string
{
    start_crm_session();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    start_crm_session();
    $provided = (string)($_POST['csrf'] ?? '');
    if ($provided === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $provided)) {
        http_response_code(419);
        exit('Η συνεδρία έληξε. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.');
    }
}

function flash(string $type, string $message): void
{
    if ($type === 'success') $GLOBALS['activity_success_message'] = $message;
    if (in_array($type, ['success','error'], true)) $GLOBALS['crm_activity_result'] = $type === 'success' ? 'app_success' : 'app_error';
    start_crm_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    start_crm_session();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }
    return (new DateTimeImmutable($value))->format('d/m/Y, H:i');
}

function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / (1024 * 1024), 1) . ' MB';
}

function role_label(string $role): string
{
    return [
        'admin' => 'Ιδιοκτήτης επιχείρησης',
        'manager' => 'Διαχειριστής',
        'technical' => 'Τεχνική υποστήριξη',
        'employee' => 'Χρήστης',
        'partner' => 'Sales Partner',
    ][$role] ?? $role;
}

function status_label(string $status): string
{
    return [
        'new' => 'Νέο',
        'contacted' => 'Έγινε επικοινωνία',
        'qualified' => 'Αξιολογημένο',
        'proposal' => 'Στάλθηκε πρόταση',
        'won' => 'Κερδήθηκε',
        'lost' => 'Δεν προχώρησε',
        'archived' => 'Αρχειοθετημένο',
    ][$status] ?? $status;
}

function user_initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $initials !== '' ? $initials : 'D';
}

function crm_icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'companies' => '<path d="M3 21h18"/><path d="M5 21V7a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v14"/><path d="M13 11h4a2 2 0 0 1 2 2v8"/><path d="M8 9h2M8 13h2M8 17h2M16 15h.01M16 18h.01"/>',
        'accounts' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'documents' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8M8 9h2"/>',
        'qualification' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3.5V2h6v1.5M8 9l1.5 1.5L12 8M8 15l1.5 1.5L12 14M14 9h2M14 15h2"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M6.35 17.65l-1.42 1.42M19.07 4.93l-1.42 1.42"/>',
        'logout' => '<path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3M16 17l5-5-5-5M21 12H9"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    ];
    return '<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function enforce_rate_limit(string $scope, int $maximum, int $windowSeconds = 900): void
{
    $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = hash('sha256', $scope . '|' . $address);
    $pdo = db();
    $statement = $pdo->prepare('SELECT attempts, window_started FROM request_limits WHERE limit_key = ? LIMIT 1');
    $statement->execute([$key]);
    $row = $statement->fetch();
    $windowStart = $row ? strtotime((string)$row['window_started']) : false;
    if ($row && $windowStart !== false && time() - $windowStart < $windowSeconds && (int)$row['attempts'] >= $maximum) {
        http_response_code(429);
        throw new RuntimeException('Πολλές προσπάθειες. Δοκιμάστε ξανά σε λίγα λεπτά.');
    }
    if (!$row || $windowStart === false || time() - $windowStart >= $windowSeconds) {
        $reset = $pdo->prepare('INSERT INTO request_limits (limit_key, attempts, window_started) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE attempts = 1, window_started = NOW()');
        $reset->execute([$key]);
    } else {
        $increment = $pdo->prepare('UPDATE request_limits SET attempts = attempts + 1 WHERE limit_key = ?');
        $increment->execute([$key]);
    }
}

function clear_rate_limit(string $scope): void
{
    $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $statement = db()->prepare('DELETE FROM request_limits WHERE limit_key = ?');
    $statement->execute([hash('sha256', $scope . '|' . $address)]);
}

require_once __DIR__.'/interface-v38.php';

function render_header(string $title, array $user): void
{
    $flash = take_flash();
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $companySection = in_array($current, ['communications.php', 'communication.php', 'new-communication.php', 'customers.php', 'customer.php', 'new-customer.php'], true);
    $proposalSection = in_array($current, ['proposals.php', 'proposal.php', 'proposal-view.php', 'proposal-download.php'], true);
    $companySection = $companySection || $proposalSection;
    $accountSection = in_array($current, ['accounts.php','activity.php'], true);
    $leadSection = in_array($current, ['leads.php','new-lead.php','edit-lead.php','lead.php','lead-attachment.php'], true);
    $notificationCount = 0;
    try {
        $count = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $count->execute([$user['id']]);
        $notificationCount = (int)$count->fetchColumn();
    } catch (Throwable) {
        $notificationCount = 0;
    }
    ?>
<!doctype html>
<html lang="el" data-scheme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= e($title) ?> | MELAS ENERGIAKI CRM</title>
  <link rel="stylesheet" href="<?= e(crm_url('assets/crm.css')) ?>">
  <link rel="stylesheet" href="<?= e(crm_url('assets/workspace-ui.css?v=20260916-4')) ?>">
  <link rel="stylesheet" href="<?= e(crm_url('assets/workspace-studio.css?v=20260922-26')) ?>">
  <link rel="stylesheet" href="<?= e(crm_url('assets/workspace-font.css?v=20260916')) ?>">
  <link rel="stylesheet" href="<?= e(crm_url('assets/crm-switch.css?v=20260922')) ?>">
  <link rel="stylesheet" href="<?= e(crm_url('lead-workflow-v11.css?v=20260917-v15')) ?>">
  <link rel="stylesheet" href="<?= e(crm_url('portal-ui-v26.css?v=26')) ?>">
  <link rel="stylesheet" href="<?= e(crm_url('assets/experience-v38.css?v=38')) ?>">
  <?php if ($current === 'academy.php'): ?><link rel="stylesheet" href="<?= e(crm_url('academy-v16.css?v=20260917')) ?>"><?php endif; ?>
</head>
<body>
<a class="skip-link" href="#workspace-content">Μετάβαση στο περιεχόμενο</a>
<div class="app-shell">
  <aside class="sidebar">
    <a class="brand" href="<?= e(crm_url()) ?>">
      <span class="brand-mark brand-mark-image"><img src="<?= e(crm_url('assets/melas-energiaki-logo.png')) ?>" alt=""></span>
      <span>Melas Energiaki CRM<small>Energy Operations</small></span>
    </a>
    <details class="crm-workspace-switch">
      <summary><span><small>ΧΩΡΟΣ ΕΡΓΑΣΙΑΣ</small><strong>Melas Energiaki CRM</strong></span><span aria-hidden="true">⇄</span></summary>
      <div class="crm-workspace-options"><span class="crm-workspace-current" aria-current="true">Melas Energiaki CRM · Τρέχον</span><a href="https://distillogic.gr/crm" referrerpolicy="no-referrer">Distillogic CRM<span aria-hidden="true">↗</span></a><small>Η σύνδεση διατηρείται εφόσον είναι ενεργή και στο άλλο CRM.</small></div>
    </details>
    <nav aria-label="Κύρια πλοήγηση">
      <span class="ux-nav-label">Καθημερινή εργασία</span>
      <a class="nav-main <?= $current === 'sales-portal.php' ? 'active' : '' ?>" href="<?= e(crm_url('sales-portal.php')) ?>"><span class="nav-icon"><?= crm_icon('dashboard') ?></span><span>Η εικόνα μου</span></a>
      <?php if (crm_sales_restricted($user)): ?><a class="nav-main <?= $leadSection ? 'active' : '' ?>" href="<?= e(crm_url('leads.php')) ?>"><span class="nav-icon"><?= crm_icon('dashboard') ?></span><span>Το pipeline μου</span></a><?php else: ?><a class="nav-main <?= $current === 'index.php' ? 'active' : '' ?>" href="<?= e(crm_url()) ?>"><span class="nav-icon"><?= crm_icon('dashboard') ?></span><span>Επισκόπηση</span></a><?php endif; ?>
      <?php if (!crm_sales_restricted($user)): ?><a class="nav-main <?= $leadSection ? 'active' : '' ?>" href="<?= e(crm_url('leads.php')) ?>"><span class="nav-icon"><?= crm_icon('qualification') ?></span><span>Εμπορικές ευκαιρίες</span></a><?php endif; ?>
      <a class="nav-main <?= $current === 'reminders.php' ? 'active' : '' ?>" href="<?= e(crm_url('reminders.php')) ?>"><span class="nav-icon"><?= crm_icon('qualification') ?></span><span>Οι υπενθυμίσεις μου</span></a>
      <a class="nav-main <?= $current === 'operations.php' ? 'active' : '' ?>" href="<?= e(crm_url('operations.php')) ?>"><span class="nav-icon"><?= crm_icon('qualification') ?></span><span>Εσωτερικές εργασίες</span></a>
      <span class="ux-nav-label">Οικονομική εικόνα</span>
      <a class="nav-main <?= $current === 'settlements.php' ? 'active' : '' ?>" href="<?= e(crm_url('settlements.php')) ?>"><span class="nav-icon"><?= crm_icon('documents') ?></span><span>Μηνιαίες εκκαθαρίσεις</span></a>
      <a class="nav-main <?= $current === 'commissions.php' ? 'active' : '' ?>" href="<?= e(crm_url('commissions.php')) ?>"><span class="nav-icon"><?= crm_icon('documents') ?></span><span>Προμήθειες</span></a>
      <?php if (can_view_all_sales_financials($user)): ?><a class="nav-main <?= $current === 'sales-analytics.php' ? 'active' : '' ?>" href="<?= e(crm_url('sales-analytics.php')) ?>"><span class="nav-icon"><?= crm_icon('dashboard') ?></span><span>Στατιστικά πελατών</span></a><?php endif; ?>
      <span class="ux-nav-label">Υλικό &amp; εκπαίδευση</span>
      <a class="nav-main <?= $current === 'toolkit-files.php' ? 'active' : '' ?>" href="<?= e(crm_url('toolkit-files.php')) ?>"><span class="nav-icon"><?= crm_icon('documents') ?></span><span>Εγκεκριμένο εταιρικό υλικό</span></a>
      <a class="nav-main <?= $current === 'sales-guide.php' ? 'active' : '' ?>" href="<?= e(crm_url('sales-guide.php')) ?>"><span class="nav-icon"><?= crm_icon('documents') ?></span><span>Οδηγός συνεργατών</span></a>
      <a class="nav-main" href="/academy/"><span class="nav-icon"><?= crm_icon('qualification') ?></span><span>Melas Sales Academy</span></a>
      <?php if (!crm_sales_restricted($user)): ?><span class="ux-nav-label">Εταιρική οργάνωση</span><div class="nav-group <?= $companySection ? 'open' : '' ?>">
        <div class="nav-group-label"><span class="nav-icon"><?= crm_icon('companies') ?></span><span>Εταιρείες - Συνεργασίες</span><span class="chevron"><?= crm_icon('chevron') ?></span></div>
        <div class="nav-children">
          <a class="<?= $current === 'new-communication.php' ? 'active' : '' ?>" href="<?= e(crm_url('new-communication.php')) ?>">Νέα τηλεφωνική επικοινωνία</a>
          <a class="<?= in_array($current, ['communications.php', 'communication.php'], true) ? 'active' : '' ?>" href="<?= e(crm_url('communications.php')) ?>">Επικοινωνίες ανά κατηγορία</a>
          <a class="<?= in_array($current, ['customers.php', 'customer.php', 'new-customer.php'], true) ? 'active' : '' ?>" href="<?= e(crm_url('customers.php')) ?>">Εταιρείες &amp; στοιχεία</a>
          <a class="<?= $proposalSection ? 'active' : '' ?>" href="<?= e(crm_url('proposals.php')) ?>">Επαγγελματικές προτάσεις</a>
        </div>
      </div><?php endif; ?>
      <?php if (can_manage_accounts($user) || in_array($user['role'], ['admin', 'technical'], true)): ?>
        <div class="nav-group <?= $accountSection ? 'open' : '' ?>">
          <div class="nav-group-label"><span class="nav-icon"><?= crm_icon('accounts') ?></span><span>Λογαριασμοί</span><span class="chevron"><?= crm_icon('chevron') ?></span></div>
          <div class="nav-children"><a class="<?= $accountSection ? 'active' : '' ?>" href="<?= e(crm_url('accounts.php')) ?>">Όλοι οι λογαριασμοί</a></div>
        </div>
      <?php endif; ?>
    </nav>
    <div class="sidebar-user">
      <span class="user-avatar"><?= e(user_initials($user['name'])) ?></span>
      <span class="user-copy"><strong><?= e($user['name']) ?></strong><small><?= e(role_label($user['role'])) ?></small></span>
    </div>
  </aside>
  <button class="sidebar-scrim" type="button" aria-label="Κλείσιμο μενού" data-menu-close></button>
  <main class="main">
    <header class="topbar">
      <button class="menu-button" type="button" aria-label="Μενού" data-menu><?= crm_icon('menu') ?></button>
      <nav class="breadcrumbs" aria-label="Διαδρομή"><span>Melas CRM</span><i>/</i><strong><?= e($title) ?></strong></nav>
      <div class="topbar-actions">
        <button class="icon-button" type="button" aria-label="Αλλαγή εμφάνισης" title="Αλλαγή εμφάνισης" data-theme-toggle><?= crm_icon('sun') ?></button>
        <?php if (!crm_sales_restricted($user)): ?><a class="search-button" href="<?= e(crm_url('customers.php')) ?>" data-customer-search="<?= e(crm_url('customer-search.php')) ?>" aria-label="Αναζήτηση πελάτη ή σελίδας"><?= crm_icon('search') ?><span>Αναζήτηση</span><kbd>⌘K</kbd></a><?php else: ?><a class="button" href="<?= e(crm_url('leads.php')) ?>"><?= crm_icon('search') ?> Οι ευκαιρίες μου</a><?php endif; ?>
        <span class="top-account"><span class="user-avatar"><?= e(user_initials($user['name'])) ?></span><span><?= e($user['name']) ?></span></span>
        <a class="icon-button" href="<?= e(crm_url('logout.php')) ?>" aria-label="Αποσύνδεση" title="Αποσύνδεση"><?= crm_icon('logout') ?></a>
      </div>
    </header>
    <div class="page" id="workspace-content" tabindex="-1">
      <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>
<?php
}

function render_footer(): void
{
    ?>
    </div>
  </main>
</div>
<script>
const closeMenu=()=>document.body.classList.remove('menu-open');
document.querySelector('[data-menu]')?.addEventListener('click',()=>document.body.classList.toggle('menu-open'));
document.querySelector('[data-menu-close]')?.addEventListener('click',closeMenu);
document.querySelectorAll('.sidebar a').forEach(link=>link.addEventListener('click',closeMenu));
document.querySelector('[data-theme-toggle]')?.addEventListener('click',()=>{
  const root=document.documentElement;
  const next=root.dataset.scheme==='dark'?'light':'dark';
  root.dataset.scheme=next;
  try { localStorage.setItem('distillogic-crm-scheme',next); } catch (_) {}
});
let savedScheme=null;
try { savedScheme=localStorage.getItem('distillogic-crm-scheme'); } catch (_) {}
if(savedScheme==='dark'||savedScheme==='light')document.documentElement.dataset.scheme=savedScheme;
document.querySelectorAll('[data-confirm]').forEach(button=>button.addEventListener('click',event=>{if(!confirm(button.dataset.confirm))event.preventDefault()}));
let activeRowMenu=null;
const closeRowMenu=()=>{
  if(!activeRowMenu)return;
  activeRowMenu.panel.hidden=true;
  activeRowMenu.trigger.setAttribute('aria-expanded','false');
  activeRowMenu=null;
};
document.querySelectorAll('[data-row-menu-trigger]').forEach(trigger=>trigger.addEventListener('click',event=>{
  event.stopPropagation();
  const panel=trigger.nextElementSibling;
  if(!(panel instanceof HTMLElement))return;
  if(activeRowMenu?.trigger===trigger){closeRowMenu();return;}
  closeRowMenu();
  document.body.append(panel);
  panel.hidden=false;
  panel.style.visibility='hidden';
  const box=trigger.getBoundingClientRect();
  const width=panel.offsetWidth;
  const height=panel.offsetHeight;
  panel.style.left=Math.max(12,Math.min(innerWidth-width-12,box.right-width))+'px';
  panel.style.top=(box.bottom+6+height<=innerHeight-12?box.bottom+6:Math.max(12,box.top-height-6))+'px';
  panel.style.visibility='visible';
  trigger.setAttribute('aria-expanded','true');
  activeRowMenu={trigger,panel};
}));
document.addEventListener('click',event=>{if(activeRowMenu&&!activeRowMenu.panel.contains(event.target))closeRowMenu()});
document.addEventListener('keydown',event=>{if(event.key==='Escape')closeRowMenu()});
addEventListener('resize',closeRowMenu);
addEventListener('scroll',closeRowMenu,true);
</script>
<script src="<?= e(crm_url('assets/workspace-ui.js?v=20260916-search')) ?>" defer></script>
<script src="<?= e(crm_url('assets/experience-v38.js?v=38')) ?>" defer></script>
</body>
</html>
<?php
}

function send_crm_email(string $to, string $subject, string $html, array $attachments = []): bool
{
    $mail = crm_config()['mail'] ?? [];
    $fromEmail = (string)($mail['from_email'] ?? 'info@melasenergiaki.gr');
    $fromName = (string)($mail['from_name'] ?? 'MELAS ENERGIAKI');
    $apiKey = trim((string)($mail['resend_api_key'] ?? ''));

    if ($apiKey !== '' && function_exists('curl_init')) {
        $payload = [
            'from' => $fromName . ' <' . $fromEmail . '>',
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
            'text' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ["\n", "\n", "\n", "\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ];
        if ($attachments !== []) {
            $payload['attachments'] = array_map(static fn(array $attachment): array => [
                'filename' => (string)$attachment['filename'],
                'content' => base64_encode((string)$attachment['content']),
            ], $attachments);
        }
        $curl = curl_init('https://api.resend.com/emails');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return $status >= 200 && $status < 300;
    }

    if ($attachments === []) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
        ];
        return mail($to, $subject, $html, implode("\r\n", $headers));
    }

    $boundary = '=_distillogic_' . bin2hex(random_bytes(18));
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'From: ' . $fromName . ' <' . $fromEmail . '>',
    ];
    $body = '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n";
    foreach ($attachments as $attachment) {
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$attachment['filename']) ?: 'document';
        $mime = preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', (string)($attachment['mime'] ?? ''))
            ? (string)$attachment['mime']
            : 'application/octet-stream';
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: ' . $mime . "; name*=UTF-8''" . rawurlencode($filename) . "\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename) . "\r\n\r\n";
        $body .= chunk_split(base64_encode((string)$attachment['content'])) . "\r\n";
    }
    $body .= '--' . $boundary . "--\r\n";
    return mail($to, $subject, $body, implode("\r\n", $headers));
}
