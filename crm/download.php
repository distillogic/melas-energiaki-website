<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login();
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) {
    http_response_code(404);
    exit('Το αρχείο δεν βρέθηκε.');
}
$statement = db()->prepare('SELECT d.file_name, d.mime_type, d.size_bytes, d.content FROM website_enquiry_documents d JOIN website_enquiries w ON w.id = d.website_enquiry_id JOIN communications c ON c.id = w.communication_id WHERE d.id = ? AND c.deleted_at IS NULL LIMIT 1');
$statement->execute([$id]);
$document = $statement->fetch();
if (!$document) {
    http_response_code(404);
    exit('Το αρχείο δεν βρέθηκε.');
}
header('Content-Type: ' . $document['mime_type']);
header('Content-Length: ' . (int)$document['size_bytes']);
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($document['file_name']));
header('X-Content-Type-Options: nosniff');
echo $document['content'];
