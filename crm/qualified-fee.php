<?php
declare(strict_types=1);
if (!defined('CRM_ROOT')) { http_response_code(403); exit; }

function qualified_lead_fee(): int { return 80; }

/** Paid legacy rows remain valid. Unpaid rows must use the current approved fee. */
function qualified_fee_valid(array $row): bool
{
    $amount = (string)$row['commission_amount'];
    return in_array($row['status'], ['payable','paid'], true)
        && ((float)$amount === 80.0 || ($row['status'] === 'paid' && (float)$amount === 60.0));
}

function migrate_qualified_fee_v26(PDO $pdo): void
{
    // DDL must finish before the data transaction (MariaDB implicitly commits DDL).
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_fee_migration_audit (
        commission_id CHAR(36) PRIMARY KEY, migration_key VARCHAR(80) NOT NULL,
        before_json LONGTEXT NOT NULL, after_amount DECIMAL(14,2) NOT NULL,
        migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO crm_settings(setting_key,setting_value) VALUES('qualified_fee_v26','pending')");
    $pdo->beginTransaction();
    try {
        $state = $pdo->query("SELECT setting_value FROM crm_settings WHERE setting_key='qualified_fee_v26' FOR UPDATE")->fetchColumn();
        if ($state === 'complete') { $pdo->commit(); return; }
        $rows = $pdo->query("SELECT * FROM lead_commissions WHERE commission_type='qualified_lead'
            AND commission_amount=60 AND status IN ('earned','payable') AND paid_at IS NULL
            AND payout_date IS NULL AND COALESCE(payout_reference,'')='' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $audit = $pdo->prepare('INSERT INTO crm_fee_migration_audit(commission_id,migration_key,before_json,after_amount) VALUES(?,?,?,80)');
        $update = $pdo->prepare("UPDATE lead_commissions SET commission_amount=80,basis_amount=80,rate=80 WHERE id=?");
        foreach ($rows as $row) {
            $audit->execute([$row['id'],'qualified_fee_v26',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $update->execute([$row['id']]);
        }
        $pdo->exec("UPDATE crm_settings SET setting_value='complete' WHERE setting_key='qualified_fee_v26'");
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
