<?php
declare(strict_types=1);

return [
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'melasenergiaki_crm',
        'user' => 'melas_crm',
        'password' => 'REPLACE_WITH_THE_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_path' => '/crm',
        'site_url' => 'https://melasenergiaki.gr',
        'timezone' => 'Europe/Athens',
        'setup_token' => 'REPLACE_WITH_A_LONG_RANDOM_SETUP_TOKEN',
        'session_days' => 14,
    ],
    'company' => [
        'legal_name' => 'MELAS ENERGIAKI',
        'address' => 'REPLACE_WITH_COMPANY_ADDRESS',
        'vat_number' => 'REPLACE_WITH_VAT_NUMBER',
        'gemi_number' => 'REPLACE_WITH_GEMI_NUMBER',
    ],
    'mail' => [
        'from_email' => 'info@melasenergiaki.gr',
        'from_name' => 'MELAS ENERGIAKI',
        'approval_email' => 'info@melasenergiaki.gr',
        'resend_api_key' => '',
    ],
];
