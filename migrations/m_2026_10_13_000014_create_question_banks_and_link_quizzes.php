<?php

declare(strict_types=1);

namespace Migrations;

use Core\Database;
use Core\MigrationBase;
use PDO;

class m_2026_10_13_000014_create_question_banks_and_link_quizzes extends MigrationBase
{
    public function up(): void
    {
        // 1. Create question_banks table
        $this->exec(<<<SQL
CREATE TABLE IF NOT EXISTS question_banks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );

        // 2. Add question_bank_id to questions table
        $this->exec("ALTER TABLE questions ADD COLUMN question_bank_id BIGINT UNSIGNED NULL AFTER id;");
        $this->exec("ALTER TABLE questions MODIFY quiz_id BIGINT UNSIGNED NULL;");
        $this->exec("ALTER TABLE questions ADD KEY idx_bank_order (question_bank_id, display_order);");

        // 3. Add question_bank_id and random_question_count to quizzes table
        $this->exec("ALTER TABLE quizzes ADD COLUMN question_bank_id BIGINT UNSIGNED NULL AFTER instructions;");
        $this->exec("ALTER TABLE quizzes ADD COLUMN random_question_count INT UNSIGNED NULL DEFAULT NULL AFTER question_bank_id;");
        $this->exec("ALTER TABLE quizzes ADD KEY idx_question_bank (question_bank_id);");

        // 4. Data Migration: Create Question Banks for existing quizzes with questions and link them
        try {
            $db = Database::getInstance()->getConnection();
            $quizzesStmt = $db->query('SELECT DISTINCT quiz_id FROM questions WHERE quiz_id IS NOT NULL');
            $quizIds = $quizzesStmt ? $quizzesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

            foreach ($quizIds as $qid) {
                $qStmt = $db->prepare('SELECT id, title, description, created_by, created_at FROM quizzes WHERE id = :id');
                $qStmt->execute(['id' => (int) $qid]);
                $quiz = $qStmt->fetch(PDO::FETCH_ASSOC);

                if ($quiz) {
                    $insertBank = $db->prepare(
                        'INSERT INTO question_banks (title, description, created_by, created_at, updated_at) ' .
                        'VALUES (:title, :description, :created_by, :created_at, NOW())'
                    );
                    $insertBank->execute([
                        'title' => $quiz['title'] . ' (Bank)',
                        'description' => $quiz['description'] ?: 'Migrated question bank from ' . $quiz['title'],
                        'created_by' => (int) ($quiz['created_by'] ?? 1),
                        'created_at' => $quiz['created_at'] ?: date('Y-m-d H:i:s'),
                    ]);
                    $bankId = (int) $db->lastInsertId();

                    // Link questions to the newly created bank
                    $db->prepare('UPDATE questions SET question_bank_id = :bid WHERE quiz_id = :qid')->execute([
                        'bid' => $bankId,
                        'qid' => (int) $qid,
                    ]);

                    // Link quiz to the newly created bank
                    $db->prepare('UPDATE quizzes SET question_bank_id = :bid WHERE id = :qid')->execute([
                        'bid' => $bankId,
                        'qid' => (int) $qid,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Log or ignore if data migration encounters edge cases
        }
    }

    public function down(): void
    {
        $this->exec("ALTER TABLE quizzes DROP COLUMN random_question_count;");
        $this->exec("ALTER TABLE quizzes DROP COLUMN question_bank_id;");
        $this->exec("ALTER TABLE questions DROP KEY idx_bank_order;");
        $this->exec("ALTER TABLE questions DROP COLUMN question_bank_id;");
        $this->exec("DROP TABLE IF EXISTS question_banks;");
    }
}
