<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$user = require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$reference = strtoupper(trim((string)($_GET['q'] ?? '')));
if (!preg_match('/^DL-[A-F0-9]{8}$/D', $reference)) {
    echo json_encode(['results' => []]);
    exit;
}
$statement = db()->prepare('SELECT DISTINCT co.id, co.name FROM website_enquiries we JOIN communications c ON c.id = we.communication_id JOIN companies co ON co.id = c.company_id WHERE we.reference = ? AND c.deleted_at IS NULL AND co.deleted_at IS NULL LIMIT 10');
$statement->execute([$reference]);
$results = [];
foreach ($statement->fetchAll() as $company) {
    $results[] = ['label' => (string)$company['name'], 'reference' => $reference, 'href' => crm_url('customer.php?id=' . (int)$company['id'])];
}
echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
