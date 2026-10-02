<?php

declare(strict_types=1);

namespace Migrations;

use Core\MigrationBase;

class m_2026_10_02_000006_create_attempts_tables extends MigrationBase
{
    public function up(): void
    {
        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(26) NOT NULL UNIQUE,
    quiz_id BIGINT UNSIGNED NOT NULL,
    quiz_version INT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    attempt_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('NOT_STARTED', 'IN_PROGRESS', 'COMPLETED', 'ABSENT') NOT NULL DEFAULT 'NOT_STARTED',
    started_at DATETIME(3) NULL,
    deadline_at DATETIME(3) NULL,
    last_saved_at DATETIME(3) NULL,
    submitted_at DATETIME(3) NULL,
    submit_reason ENUM('manual', 'timeout', 'admin') NULL,
    layout JSON NULL,
    max_seq INT UNSIGNED NOT NULL DEFAULT 0,
    total_questions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    correct_count SMALLINT UNSIGNED NULL,
    score DECIMAL(8, 2) NULL,
    accuracy DECIMAL(5, 2) NULL,
    completion_time_s INT UNSIGNED NULL,
    graded_at DATETIME(3) NULL,
    UNIQUE KEY uq_attempt (quiz_id, employee_id, attempt_no),
    KEY idx_quiz_status (quiz_id, status),
    KEY idx_status_deadline (status, deadline_at),
    KEY idx_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );

        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS attempt_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    selected_option_id BIGINT UNSIGNED NULL,
    is_correct TINYINT(1) NULL,
    seq INT UNSIGNED NOT NULL DEFAULT 0,
    answered_at DATETIME(3) NOT NULL,
    UNIQUE KEY uq_answer (attempt_id, question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS attempt_answers;");
        $this->exec("DROP TABLE IF EXISTS attempts;");
    }
}
