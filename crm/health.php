<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$checks = [
    'php' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'mbstring' => extension_loaded('mbstring'),
    'fileinfo' => extension_loaded('fileinfo'),
    'session' => extension_loaded('session'),
    'curl_or_mail' => extension_loaded('curl') || function_exists('mail'),
];

try {
    $checks['database'] = (int)db()->query('SELECT 1')->fetchColumn() === 1;
} catch (Throwable) {
    $checks['database'] = false;
}

$ok = !in_array(false, $checks, true);
http_response_code($ok ? 200 : 503);
echo json_encode(['ok' => $ok, 'service' => 'distillogic-crm', 'checks' => $checks], JSON_UNESCAPED_SLASHES);
