<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_02_000003_create_groups_tables extends MigrationBase
{
    public function up(): void
    {
        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `groups` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );

        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS employee_groups (
    employee_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (employee_id, group_id),
    KEY idx_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS employee_groups;");
        $this->exec("DROP TABLE IF EXISTS `groups`;");
    }
}
