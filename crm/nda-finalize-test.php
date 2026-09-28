<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login();

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) {
    http_response_code(404);
    exit('Το NDA δεν βρέθηκε.');
}

redirect_to('document-finalize.php?id=' . urlencode($id));
