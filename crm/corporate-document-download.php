<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
ensure_customer_workspace_schema();

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) {
    http_response_code(404);
    exit('Το έγγραφο δεν βρέθηκε.');
}

$statement = db()->prepare('SELECT access_level, file_name, mime_type, size_bytes, content FROM corporate_documents WHERE id = ? LIMIT 1');
$statement->execute([$id]);
$document = $statement->fetch();
if (!$document) {
    http_response_code(404);
    exit('Το έγγραφο δεν βρέθηκε.');
}
if ($document['access_level'] === 'management' && !in_array($user['role'], ['admin', 'manager'], true)) {
    http_response_code(403);
    exit('Δεν έχετε δικαίωμα πρόσβασης σε αυτό το εμπιστευτικό έγγραφο.');
}

$fileName = preg_replace('/[^\pL\pN._ -]+/u', '-', (string)$document['file_name']) ?: 'document';
$disposition = isset($_GET['download']) ? 'attachment' : 'inline';
header('Content-Type: ' . ((string)$document['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . (string)(int)$document['size_bytes']);
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
echo $document['content'];
