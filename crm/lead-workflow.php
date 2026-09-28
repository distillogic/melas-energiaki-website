<?php
declare(strict_types=1);

if (!defined('CRM_ROOT')) { http_response_code(403); exit; }
require_once __DIR__.'/lead-protection.php';
require_once __DIR__.'/service-compensation.php';
require_once __DIR__.'/qualified-fee.php';
require_once __DIR__.'/sales-portal-workflow.php';

function ensure_lead_schema(): void
{
    static $ready = false;
    if ($ready) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_leads (
      id CHAR(36) PRIMARY KEY,
      lead_reference VARCHAR(40) NOT NULL UNIQUE,
      submitted_by CHAR(36) NOT NULL,
      assigned_user_id CHAR(36) NULL,
      company_id BIGINT UNSIGNED NULL,
      status VARCHAR(40) NOT NULL DEFAULT 'submitted',
      follow_up_at DATETIME NULL,
      follow_up_note VARCHAR(500) NULL,
      opportunity_type VARCHAR(50) NOT NULL,
      company_name VARCHAR(255) NOT NULL,
      website VARCHAR(500) NULL,
      country_region VARCHAR(255) NOT NULL,
      contact_name VARCHAR(180) NOT NULL,
      contact_title VARCHAR(180) NOT NULL,
      contact_phone VARCHAR(80) NOT NULL,
      contact_email VARCHAR(320) NOT NULL,
      conversation_summary TEXT NOT NULL,
      next_step VARCHAR(60) NOT NULL,
      next_step_other VARCHAR(500) NULL,
      contact_consent TINYINT(1) NOT NULL DEFAULT 0,
      lead_source VARCHAR(60) NOT NULL,
      source_other VARCHAR(255) NULL,
      opportunity_data_json LONGTEXT NOT NULL,
      qualification_check_json LONGTEXT NULL,
      qualification_reviewed_at DATETIME NULL,
      qualification_reviewed_by CHAR(36) NULL,
      review_note TEXT NULL,
      qualified_at DATETIME NULL,
      qualified_by CHAR(36) NULL,
      qualified_fee_approved_at DATETIME NULL,
      qualified_fee_approved_by CHAR(36) NULL,
      agreed_panel_quantity DECIMAL(14,2) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX lead_status_idx(status, updated_at),
      INDEX lead_submitter_idx(submitted_by, created_at),
      INDEX lead_company_idx(company_id, created_at),
      CONSTRAINT sales_leads_submitter_fk FOREIGN KEY(submitted_by) REFERENCES users(id),
      CONSTRAINT sales_leads_assignee_fk FOREIGN KEY(assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT sales_leads_company_fk FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("ALTER TABLE sales_leads ADD COLUMN IF NOT EXISTS qualification_check_json LONGTEXT NULL AFTER opportunity_data_json");
    $pdo->exec("ALTER TABLE sales_leads ADD COLUMN IF NOT EXISTS qualification_reviewed_at DATETIME NULL AFTER qualification_check_json");
    $pdo->exec("ALTER TABLE sales_leads ADD COLUMN IF NOT EXISTS qualification_reviewed_by CHAR(36) NULL AFTER qualification_reviewed_at");
    $pdo->exec("ALTER TABLE sales_leads ADD COLUMN IF NOT EXISTS follow_up_at DATETIME NULL AFTER status");
    $pdo->exec("ALTER TABLE sales_leads ADD COLUMN IF NOT EXISTS follow_up_note VARCHAR(500) NULL AFTER follow_up_at");
    $pdo->exec("ALTER TABLE sales_leads ADD COLUMN IF NOT EXISTS agreed_panel_quantity DECIMAL(14,2) NULL AFTER qualified_fee_approved_by");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_status_history (
      id CHAR(36) PRIMARY KEY,
      lead_id CHAR(36) NOT NULL,
      changed_by CHAR(36) NOT NULL,
      old_status VARCHAR(40) NULL,
      new_status VARCHAR(40) NOT NULL,
      note TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX lead_history_idx(lead_id,created_at),
      CONSTRAINT lead_history_lead_fk FOREIGN KEY(lead_id) REFERENCES sales_leads(id) ON DELETE CASCADE,
      CONSTRAINT lead_history_user_fk FOREIGN KEY(changed_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_attachments (
      id CHAR(36) PRIMARY KEY,
      lead_id CHAR(36) NOT NULL,
      uploaded_by CHAR(36) NOT NULL,
      file_name VARCHAR(255) NOT NULL,
      mime_type VARCHAR(120) NOT NULL,
      size_bytes INT UNSIGNED NOT NULL,
      file_content LONGBLOB NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX lead_attachment_idx(lead_id, created_at),
      CONSTRAINT lead_attachment_lead_fk FOREIGN KEY(lead_id) REFERENCES sales_leads(id) ON DELETE CASCADE,
      CONSTRAINT lead_attachment_user_fk FOREIGN KEY(uploaded_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_commissions (
      id CHAR(36) PRIMARY KEY,
      lead_id CHAR(36) NOT NULL,
      beneficiary_user_id CHAR(36) NOT NULL,
      commission_type VARCHAR(40) NOT NULL,
      basis_quantity DECIMAL(14,2) NULL,
      basis_amount DECIMAL(14,2) NULL,
      rate DECIMAL(10,4) NOT NULL,
      commission_amount DECIMAL(14,2) NOT NULL,
      payment_reference VARCHAR(255) NULL,
      notes TEXT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'payable',
      approved_by CHAR(36) NOT NULL,
      approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      paid_at DATETIME NULL,
      earned_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      cancellation_reason TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX lead_commission_idx(lead_id, created_at),
      INDEX beneficiary_commission_idx(beneficiary_user_id, status),
      CONSTRAINT lead_commission_lead_fk FOREIGN KEY(lead_id) REFERENCES sales_leads(id) ON DELETE CASCADE,
      CONSTRAINT lead_commission_beneficiary_fk FOREIGN KEY(beneficiary_user_id) REFERENCES users(id),
      CONSTRAINT lead_commission_approver_fk FOREIGN KEY(approved_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("ALTER TABLE lead_commissions ADD COLUMN IF NOT EXISTS earned_at DATETIME NULL AFTER paid_at");
    $pdo->exec("ALTER TABLE lead_commissions ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER earned_at");
    $pdo->exec("ALTER TABLE lead_commissions ADD COLUMN IF NOT EXISTS cancellation_reason TEXT NULL AFTER cancelled_at");
    $pdo->exec("UPDATE lead_commissions SET earned_at=approved_at WHERE earned_at IS NULL AND status IN ('earned','payable','paid')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS panel_purchase_payments (
      id CHAR(36) PRIMARY KEY,
      lead_id CHAR(36) NOT NULL,
      commission_id CHAR(36) NOT NULL,
      panel_quantity DECIMAL(14,2) NOT NULL,
      seller_payment_amount DECIMAL(14,2) NOT NULL,
      seller_paid_at DATE NOT NULL,
      payment_reference VARCHAR(255) NOT NULL,
      notes TEXT NULL,
      confirmed_by CHAR(36) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX panel_payment_lead_idx(lead_id,seller_paid_at),
      CONSTRAINT panel_payment_lead_fk FOREIGN KEY(lead_id) REFERENCES sales_leads(id) ON DELETE CASCADE,
      CONSTRAINT panel_payment_commission_fk FOREIGN KEY(commission_id) REFERENCES lead_commissions(id) ON DELETE CASCADE,
      CONSTRAINT panel_payment_confirmer_fk FOREIGN KEY(confirmed_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_revenue_receipts (
      id CHAR(36) PRIMARY KEY,
      lead_id CHAR(36) NOT NULL,
      commission_id CHAR(36) NOT NULL,
      received_amount DECIMAL(14,2) NOT NULL,
      received_at DATE NOT NULL,
      receipt_reference VARCHAR(255) NOT NULL,
      notes TEXT NULL,
      confirmed_by CHAR(36) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX service_receipt_lead_idx(lead_id,received_at),
      CONSTRAINT service_receipt_lead_fk FOREIGN KEY(lead_id) REFERENCES sales_leads(id) ON DELETE CASCADE,
      CONSTRAINT service_receipt_commission_fk FOREIGN KEY(commission_id) REFERENCES lead_commissions(id) ON DELETE CASCADE,
      CONSTRAINT service_receipt_confirmer_fk FOREIGN KEY(confirmed_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensure_service_compensation_schema($pdo);
    migrate_qualified_fee_v26($pdo);
    ensure_lead_protection_schema($pdo);
    ensure_sales_portal_schema($pdo);
    $ready = true;
}

function can_review_leads(array $user): bool
{
    return in_array((string)($user['role'] ?? ''), ['admin','manager'], true);
}

function can_access_lead(array $user, array $lead): bool
{
    return can_review_leads($user) || lead_is_owner($user,$lead);
}

function lead_status_labels(): array
{
    return [
        'prospect'=>'Prospect', 'contacted'=>'Contacted', 'decision_maker_found'=>'Decision Maker Found',
        'need_identified'=>'Interest / Need Identified', 'qualification_in_progress'=>'Qualification In Progress',
        'submitted'=>'Pending / Submitted', 'under_review'=>'Under Review', 'more_information'=>'More Information Required',
        'qualified'=>'Qualified Lead', 'fee_approved'=>'Αμοιβή εγκεκριμένη', 'commercial_discussion'=>'Commercial Discussion',
        'won'=>'Won', 'lost'=>'Lost', 'rejected'=>'Rejected',
    ];
}

function service_category_labels(): array
{
    return [
        '01'=>'01 · Χωροθέτηση και Έλεγχος Καταλληλότητας',
        '02'=>'02 · Μελέτες και Αδειοδοτήσεις',
        '03'=>'03 · Διαμόρφωση και Προετοιμασία Χώρου',
        '04'=>'04 · Προμήθεια και Τοποθέτηση Εξοπλισμού',
        '05'=>'05 · Ηλεκτρολογικές και Τεχνικές Εργασίες',
        '06'=>'06 · Τεχνική Συντήρηση και Επισκευές',
        '07'=>'07 · Καθαρισμός και Συντήρηση Πάρκου',
        '08'=>'08 · Έλεγχος Απόδοσης και Repowering',
        '09'=>'09 · Αντικατάσταση Πάνελ και Εξοπλισμού',
        '10'=>'10 · Αγοραπωλησίες Φωτοβολταϊκών Πάρκων',
        '11'=>'11 · Οικιακά Φωτοβολταϊκά Συστήματα',
    ];
}

function opportunity_type_labels(): array
{
    return [
        'used_panels'=>'Πώληση μεταχειρισμένων πάνελ προς MELAS ENERGIAKI',
        'repowering'=>'Προγραμματισμένο repowering',
        'equipment_replacement'=>'Αποξήλωση / αντικατάσταση εξοπλισμού',
        'solar_service'=>'Υπηρεσία φωτοβολταϊκού πάρκου',
    ];
}

function lead_reference(): string
{
    return 'ME-LEAD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function load_lead(string $id): array
{
    ensure_lead_schema();
    $query = db()->prepare('SELECT l.*,u.name AS submitter_name,u.email AS submitter_email FROM sales_leads l JOIN users u ON u.id=l.submitted_by WHERE l.id=? LIMIT 1');
    $query->execute([$id]);
    return $query->fetch() ?: [];
}

function lead_field_label(string $key): string
{
    return [
        'seller_authority'=>'Ιδιότητα πωλητή','sale_intent_confirmed'=>'Πρόθεση πώλησης','panel_quantity'=>'Ποσότητα πάνελ','panel_watts'=>'Ισχύς (W)','panel_brand'=>'Κατασκευαστής','panel_model'=>'Μοντέλο','panel_age'=>'Ηλικία','panel_technology'=>'Τεχνολογία','panel_condition'=>'Κατάσταση','panel_location'=>'Τοποθεσία πάνελ','availability_date'=>'Ημερομηνία διαθεσιμότητας','public_region'=>'Γενική περιφέρεια','asking_price'=>'Ζητούμενη τιμή ανά πάνελ (€)','asking_total_price'=>'Ζητούμενη συνολική τιμή (€)','service_category'=>'Κατηγορία υπηρεσίας','service_type'=>'Υπηρεσία','park_project'=>'Πάρκο / έργο','park_size'=>'Μέγεθος πάρκου','service_location'=>'Τοποθεσία υπηρεσίας','service_timing'=>'Χρονοδιάγραμμα','decision_maker'=>'Υπεύθυνος απόφασης','rfq_available'=>'RFQ διαθέσιμο','incumbent_partner'=>'Υφιστάμενος συνεργάτης',
    ][$key] ?? $key;
}

function commission_type_label(string $type): string
{
    return ['qualified_lead'=>'Qualified lead','panel_purchase'=>'Αγορά πάνελ (€0,60/πάνελ)','service_revenue'=>'Υπηρεσία (% εισπραχθέντος εσόδου)'][$type] ?? $type;
}

function commission_status_label(string $status): string
{
    return [
        'earned'=>'Δεδουλευμένη',
        'payable'=>'Πληρωτέα',
        'paid'=>'Πληρωμένη',
        'cancelled'=>'Ακυρωμένη',
    ][$status] ?? $status;
}

function panel_qualification_labels(): array
{
    return [
        'authorized_seller'=>'Υπάρχει πραγματικός ιδιοκτήτης ή εξουσιοδοτημένος πωλητής.',
        'sale_intent'=>'Έχει επιβεβαιωθεί πραγματική πρόθεση πώλησης.',
        'specific_quantity'=>'Υπάρχει συγκεκριμένη ποσότητα ή σοβαρή εκτίμηση.',
        'equipment_basics'=>'Υπάρχουν τα βασικά στοιχεία εξοπλισμού, ηλικίας/κατάστασης και τοποθεσίας.',
        'commercial_quantity'=>'Η ποσότητα βρίσκεται μέσα στα εμπορικά κριτήρια της MELAS ENERGIAKI.',
        'contact_expected'=>'Ο πωλητής γνωρίζει και περιμένει περαιτέρω επικοινωνία από τη MELAS ENERGIAKI.',
        'company_verified'=>'Εξέτασα τα ανώνυμα στοιχεία και τις διευκρινίσεις του συνεργάτη και επιβεβαιώνω την εμπορική ευκαιρία.',
        'new_opportunity'=>'Πρόκειται για νέα διακριτή ευκαιρία, όχι για ήδη γνωστό ενεργό έργο. Υπάρχων πελάτης μπορεί να έχει διαφορετικό έργο, υπηρεσία, site ή timing.',
    ];
}

function service_qualification_labels(): array
{
    return [
        'specific_business_need'=>'Έχει προσδιοριστεί συγκεκριμένη πραγματική ανάγκη υπηρεσίας.',
        'correct_decision_maker'=>'Η επαφή είναι ο σωστός decision maker ή αρμόδιος υπεύθυνος.',
        'real_timeline'=>'Υπάρχει πραγματικός χρονικός ορίζοντας και όχι απλώς «ίσως στο μέλλον».',
        'contact_expected'=>'Ο πελάτης συμφώνησε να συζητήσει και περιμένει επικοινωνία από τη MELAS ENERGIAKI.',
        'company_verified'=>'Εξέτασα τα ανώνυμα στοιχεία και τις διευκρινίσεις του συνεργάτη και επιβεβαιώνω την εμπορική ευκαιρία.',
        'new_opportunity'=>'Πρόκειται για νέα διακριτή ευκαιρία, όχι για ήδη γνωστό ενεργό έργο. Υπάρχων πελάτης μπορεί να έχει διαφορετικό έργο, υπηρεσία, site ή timing.',
    ];
}

function qualification_labels_for(string $type): array
{
    return $type==='solar_service' ? service_qualification_labels() : panel_qualification_labels();
}

function is_panel_opportunity(string $type): bool
{
    return in_array($type, ['used_panels','repowering','equipment_replacement'], true);
}

function synchronize_panel_prices(array &$data): void
{
    $quantity = (float)($data['panel_quantity'] ?? 0);
    $unitPrice = (float)($data['asking_price'] ?? 0);
    $totalPrice = (float)($data['asking_total_price'] ?? 0);
    if ($quantity <= 0) return;
    if ($unitPrice > 0 && $totalPrice <= 0) {
        $data['asking_total_price'] = number_format($quantity * $unitPrice, 2, '.', '');
    } elseif ($totalPrice > 0 && $unitPrice <= 0) {
        $data['asking_price'] = number_format($totalPrice / $quantity, 2, '.', '');
    }
}

function complete_panel_qualification(array $lead): bool
{
    if (!is_panel_opportunity((string)$lead['opportunity_type']) && (string)$lead['opportunity_type']!=='solar_service') return true;
    $checks=json_decode((string)($lead['qualification_check_json']??''),true)?:[];
    foreach(array_keys(qualification_labels_for((string)$lead['opportunity_type'])) as $key) if(empty($checks[$key])) return false;
    return !empty($lead['qualification_reviewed_at']) && !empty($lead['qualification_reviewed_by']);
}
