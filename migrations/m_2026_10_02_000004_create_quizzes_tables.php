<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_02_000004_create_quizzes_tables extends MigrationBase
{
    public function up(): void
    {
        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS quizzes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(26) NOT NULL UNIQUE,
    code VARCHAR(12) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    instructions TEXT NULL,
    duration_seconds INT UNSIGNED NOT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    settings JSON NOT NULL,
    current_version INT UNSIGNED NOT NULL DEFAULT 0,
    window_closed_at DATETIME NULL,
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_status_window (status, start_at, end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );

        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id BIGINT UNSIGNED NOT NULL,
    question_text TEXT NOT NULL,
    display_order INT NOT NULL,
    KEY idx_quiz_order (quiz_id, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );

        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS answer_options (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNSIGNED NOT NULL,
    option_text TEXT NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    display_order INT NOT NULL,
    KEY idx_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS answer_options;");
        $this->exec("DROP TABLE IF EXISTS questions;");
        $this->exec("DROP TABLE IF EXISTS quizzes;");
    }
}
