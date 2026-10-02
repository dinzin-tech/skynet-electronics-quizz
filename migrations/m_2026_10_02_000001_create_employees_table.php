<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_02_000001_create_employees_table extends MigrationBase
{
    public function up(): void
    {
        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS employees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(26) NOT NULL UNIQUE,
    employee_code VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL UNIQUE,
    username VARCHAR(100) NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_status_name (status, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS employees;");
    }
}
