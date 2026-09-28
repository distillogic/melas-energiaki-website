<?php
declare(strict_types=1);
if (!defined('ACADEMY_ROOT')) { http_response_code(403); exit; }
return [
    "CREATE TABLE IF NOT EXISTS ".academy_table('settings')." (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS ".academy_table('users')." (
        id CHAR(36) PRIMARY KEY, name VARCHAR(150) NOT NULL, email VARCHAR(254) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'learner', active TINYINT NOT NULL DEFAULT 1,
        password_hash VARCHAR(255) NULL, must_change_password TINYINT NOT NULL DEFAULT 0,
        session_version INT NOT NULL DEFAULT 1,
        sso_issuer VARCHAR(80) NULL, sso_subject VARCHAR(100) NULL,
        crm_user_id CHAR(36) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY academy_email_unique(email), UNIQUE KEY academy_identity_unique(sso_issuer,sso_subject), UNIQUE KEY academy_crm_identity_unique(crm_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS ".academy_table('auth_limits')." (
        limit_key CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS ".academy_table('sso_uses')." (
        token_id CHAR(64) PRIMARY KEY, used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS ".academy_table('admin_events')." (
        id CHAR(36) PRIMARY KEY, actor_id CHAR(36) NOT NULL, target_id CHAR(36) NULL,
        action VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
