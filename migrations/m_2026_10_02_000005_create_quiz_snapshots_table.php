<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_02_000005_create_quiz_snapshots_table extends MigrationBase
{
    public function up(): void
    {
        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS quiz_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    question_count SMALLINT UNSIGNED NOT NULL,
    bundle_path VARCHAR(255) NOT NULL,
    bundle_sha256 CHAR(64) NOT NULL,
    answer_key JSON NOT NULL,
    structure JSON NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_quiz_version (quiz_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS quiz_snapshots;");
    }
}
