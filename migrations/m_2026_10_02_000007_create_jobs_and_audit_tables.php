<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_02_000007_create_jobs_and_audit_tables extends MigrationBase
{
    public function up(): void
    {
        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS import_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    file_path VARCHAR(255) NOT NULL,
    total INT UNSIGNED NOT NULL DEFAULT 0,
    imported INT UNSIGNED NOT NULL DEFAULT 0,
    failed INT UNSIGNED NOT NULL DEFAULT 0,
    report_path VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );

        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS export_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_type VARCHAR(50) NOT NULL,
    filters JSON NULL,
    format ENUM('csv', 'xlsx') NOT NULL DEFAULT 'csv',
    status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    file_path VARCHAR(255) NULL,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );

        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id BIGINT UNSIGNED NOT NULL,
    actor_role VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity VARCHAR(100) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    meta JSON NULL,
    created_at DATETIME NOT NULL,
    KEY idx_actor (actor_id, actor_role),
    KEY idx_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS audit_logs;");
        $this->exec("DROP TABLE IF EXISTS export_jobs;");
        $this->exec("DROP TABLE IF EXISTS import_jobs;");
    }
}
