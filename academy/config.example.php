<?php
declare(strict_types=1);
// Copy to academy/config.php, never over crm/config.php.
// Shared mode uses only academy_school_* tables, not legacy CRM academy_* tables.
return [
    // v23: use the current email/password from ../crm/config.php's database.
    // No copied hashes, extra key, or second user registration is needed.
    // Keep enabled. false is only for an explicitly planned legacy rollback.
    'crm_identity' => ['enabled' => true],
    'database' => [
        'mode' => 'shared', // Explicit opt-in. Existing v17/v18 configs default to dedicated.
        'host' => 'localhost', 'port' => 3306, 'name' => 'REPLACE_EXISTING_DATABASE_NAME',
        'user' => 'REPLACE_DATABASE_USER', 'password' => 'REPLACE_DATABASE_PASSWORD',
    ],
    'app' => [
        'origin' => 'https://melasenergiaki.gr', 'base_path' => '/academy',
        'local_login_enabled' => true, // Switch off only AFTER verified corporate login/linking.
        'timezone' => 'Europe/Athens', 'setup_token' => 'REPLACE_WITH_A_RANDOM_SECRET_OF_AT_LEAST_32_CHARACTERS',
        // Leave empty unless your host specifies the actual trusted reverse proxy IPs.
        'trusted_proxy_ips' => [],
    ],
    'sso' => [
        'enabled' => false,
        // Distillogic identity bridge: SAME new key in the dedicated connector config.
        // Existing Melas bridge users must not change issuer without a planned relink.
        // Do not reuse a password, setup token or the CRM setup token.
        'shared_key' => 'REPLACE_WITH_ANOTHER_RANDOM_SECRET_OF_AT_LEAST_64_CHARACTERS',
        'issuer' => 'distillogic-crm',
        'authorize_url' => 'https://distillogic.gr/crm/academy-connect.php',
    ],
];
