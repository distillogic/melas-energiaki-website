<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
$canDeleteCustomers = can_manage_accounts($user);
ensure_customer_workspace_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $companyId = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT);
    if ((string)($_POST['action'] ?? '') === 'delete_company' && $companyId) {
        if (!$canDeleteCustomers) {
            http_response_code(403);
            exit('Δεν έχετε δικαίωμα διαγραφής πελατών ή εταιρειών.');
        }
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $communications = $pdo->prepare('UPDATE communications SET deleted_at = NOW() WHERE company_id = ? AND deleted_at IS NULL');
            $communications->execute([$companyId]);
            $company = $pdo->prepare("UPDATE companies SET name_key = CONCAT(name_key, '#deleted#', id, '#', UNIX_TIMESTAMP()), deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $company->execute([$companyId]);
            $pdo->commit();
            flash('success', 'Η εταιρεία και οι επικοινωνίες της διαγράφηκαν.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Company delete failed: ' . $exception->getMessage());
            flash('error', 'Η εταιρεία δεν ήταν δυνατό να διαγραφεί.');
        }
        redirect_to('customers.php');
    }
}

$search = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 160));
$customerSql = "SELECT co.id, co.name, co.legal_name, co.email, co.phone, co.industry, co.city,
            COUNT(c.id) AS communications, MAX(c.created_at) AS last_contact,
            (SELECT lc.contact_name FROM communications lc WHERE lc.company_id = co.id AND lc.deleted_at IS NULL ORDER BY lc.created_at DESC LIMIT 1) AS last_contact_name,
            (SELECT lc.contact_role FROM communications lc WHERE lc.company_id = co.id AND lc.deleted_at IS NULL ORDER BY lc.created_at DESC LIMIT 1) AS last_contact_role
       FROM companies co
       LEFT JOIN communications c ON c.company_id = co.id AND c.deleted_at IS NULL
      WHERE co.deleted_at IS NULL";
$customerParameters = [];
if ($search !== '') {
    $customerSql .= ' AND (co.name LIKE ? OR co.email LIKE ? OR co.phone LIKE ? OR co.industry LIKE ? OR co.city LIKE ? OR EXISTS (SELECT 1 FROM communications rc JOIN website_enquiries we ON we.communication_id = rc.id WHERE rc.company_id = co.id AND rc.deleted_at IS NULL AND we.reference = ?))';
    $term = '%' . $search . '%';
    $customerParameters = [$term, $term, $term, $term, $term, strtoupper($search)];
}
$customerSql .= "
      GROUP BY co.id, co.name, co.legal_name, co.email, co.phone, co.industry, co.city
      ORDER BY co.name ASC";
$customerStatement = db()->prepare($customerSql);
$customerStatement->execute($customerParameters);
$customers = $customerStatement->fetchAll();
render_header('Πελάτες', $user);
?>
<div class="page-heading"><div><h1>Πελάτες και στοιχεία επικοινωνίας</h1><p>Κάθε πελάτης διαθέτει πλήρη καρτέλα, ιστορικό επικοινωνιών και εταιρικά έγγραφα.</p></div><div class="actions"><a class="button" href="<?= e(crm_url('new-communication.php')) ?>">Νέα επικοινωνία</a><a class="button primary" href="<?= e(crm_url('new-customer.php')) ?>">Νέος πελάτης</a></div></div>
<form class="card search-card" method="get"><label>Αναζήτηση πελάτη<input type="search" name="q" value="<?= e($search) ?>" placeholder="Επωνυμία, κωδικός DL-…, email, τηλέφωνο ή πόλη…"></label><button class="button" type="submit">Αναζήτηση</button><?php if ($search !== ''): ?><a class="button ghost" href="<?= e(crm_url('customers.php')) ?>">Καθαρισμός</a><?php endif; ?></form>
<section class="catalog-card"><header><h2>Κατάλογος πελατών</h2><span><?= count($customers) ?></span></header><div class="table-wrap"><table><thead><tr><th>Εταιρεία</th><th>Επαφή</th><th>Κλάδος / πόλη</th><th>Email</th><th>Τηλέφωνο</th><th><span class="sr-only">Ενέργειες</span></th></tr></thead><tbody>
<?php if (!$customers): ?><tr><td colspan="6" class="empty">Δεν υπάρχουν ακόμη πελάτες.</td></tr><?php endif; ?>
<?php foreach ($customers as $company): ?><tr><td><a class="customer-name-link" href="<?= e(crm_url('customer.php?id=' . (int)$company['id'])) ?>"><strong><?= e($company['name']) ?></strong></a><?php if ($company['legal_name'] && $company['legal_name'] !== $company['name']): ?><br><span class="muted"><?= e($company['legal_name']) ?></span><?php endif; ?><br><span class="muted"><?= (int)$company['communications'] ?> επικοινωνίες</span></td><td><?= e($company['last_contact_name'] ?: '—') ?><br><span class="muted"><?= e($company['last_contact_role'] ?: '') ?></span></td><td><?= e(implode(' · ', array_filter([$company['industry'], $company['city']]))) ?: '—' ?></td><td><?php if ($company['email']): ?><a class="accent-link" href="mailto:<?= e($company['email']) ?>"><?= e($company['email']) ?></a><?php else: ?>—<?php endif; ?></td><td><?php if ($company['phone']): ?><a class="accent-link" href="tel:<?= e($company['phone']) ?>"><?= e($company['phone']) ?></a><?php else: ?>—<?php endif; ?></td><td class="row-actions"><div class="actions"><a class="button compact" href="<?= e(crm_url('customer.php?id=' . (int)$company['id'])) ?>">Άνοιγμα</a><?php if ($canDeleteCustomers): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_company"><input type="hidden" name="company_id" value="<?= (int)$company['id'] ?>"><button class="button danger compact" data-confirm="Να διαγραφεί η εταιρεία <?= e($company['name']) ?> και οι επικοινωνίες της από τον ενεργό κατάλογο; Τα έγγραφα διατηρούνται.">Διαγραφή</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php render_footer(); ?>
