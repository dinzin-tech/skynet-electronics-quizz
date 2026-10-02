<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_02_000002_create_administrators_table extends MigrationBase
{
    public function up(): void
    {
        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS administrators (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS administrators;");
    }
}
