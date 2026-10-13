<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class QuestionBankService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Get all question banks with question counts, linked quiz counts, and locked status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $sql = <<<SQL
SELECT 
    qb.id,
    qb.title,
    qb.description,
    qb.created_by,
    qb.created_at,
    qb.updated_at,
    (SELECT COUNT(*) FROM questions q WHERE q.question_bank_id = qb.id) AS question_count,
    (SELECT COUNT(*) FROM quizzes qz WHERE qz.question_bank_id = qb.id) AS linked_quizzes_count,
    (SELECT COUNT(*) 
     FROM quizzes qz 
     WHERE qz.question_bank_id = qb.id 
       AND qz.status = 'published'
       AND (
           (qz.start_at <= NOW() AND qz.end_at >= NOW())
           OR EXISTS (
               SELECT 1 FROM attempts a 
               WHERE a.quiz_id = qz.id 
                 AND a.status IN ('IN_PROGRESS', 'COMPLETED')
           )
       )
    ) AS active_quizzes_count
FROM question_banks qb
ORDER BY qb.id DESC
SQL;

        $stmt = $this->db->query($sql);
        $banks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($banks as &$bank) {
            $bank['question_count'] = (int) $bank['question_count'];
            $bank['linked_quizzes_count'] = (int) $bank['linked_quizzes_count'];
            $bank['active_quizzes_count'] = (int) $bank['active_quizzes_count'];
            $bank['is_locked'] = $bank['active_quizzes_count'] > 0;
        }

        return $banks;
    }

    /**
     * Get a specific question bank by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        $sql = <<<SQL
SELECT 
    qb.id,
    qb.title,
    qb.description,
    qb.created_by,
    qb.created_at,
    qb.updated_at,
    (SELECT COUNT(*) FROM questions q WHERE q.question_bank_id = qb.id) AS question_count,
    (SELECT COUNT(*) FROM quizzes qz WHERE qz.question_bank_id = qb.id) AS linked_quizzes_count,
    (SELECT COUNT(*) 
     FROM quizzes qz 
     WHERE qz.question_bank_id = qb.id 
       AND qz.status = 'published'
       AND (
           (qz.start_at <= NOW() AND qz.end_at >= NOW())
           OR EXISTS (
               SELECT 1 FROM attempts a 
               WHERE a.quiz_id = qz.id 
                 AND a.status IN ('IN_PROGRESS', 'COMPLETED')
           )
       )
    ) AS active_quizzes_count
FROM question_banks qb
WHERE qb.id = :id
LIMIT 1
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $bank = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bank) {
            return null;
        }

        $bank['question_count'] = (int) $bank['question_count'];
        $bank['linked_quizzes_count'] = (int) $bank['linked_quizzes_count'];
        $bank['active_quizzes_count'] = (int) $bank['active_quizzes_count'];
        $bank['is_locked'] = $bank['active_quizzes_count'] > 0;

        return $bank;
    }

    /**
     * Check if a question bank is locked from modification.
     * Locked when any quiz linked to it is currently active or has in-progress/completed attempts.
     */
    public function isBankLocked(int $bankId): bool
    {
        $stmt = $this->db->prepare(<<<SQL
SELECT COUNT(*) 
FROM quizzes qz 
WHERE qz.question_bank_id = :bank_id 
  AND qz.status = 'published'
  AND (
      (qz.start_at <= NOW() AND qz.end_at >= NOW())
      OR EXISTS (
          SELECT 1 FROM attempts a 
          WHERE a.quiz_id = qz.id 
            AND a.status IN ('IN_PROGRESS', 'COMPLETED')
      )
  )
SQL
        );
        $stmt->execute(['bank_id' => $bankId]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Create a new question bank.
     */
    public function create(array $data, int $adminId = 1): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Question Bank title is required.');
        }

        $description = trim((string) ($data['description'] ?? ''));

        $stmt = $this->db->prepare(
            'INSERT INTO question_banks (title, description, created_by, created_at, updated_at) ' .
            'VALUES (:title, :description, :created_by, NOW(), NOW())'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'created_by' => $adminId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update question bank title and description.
     */
    public function update(int $id, array $data): bool
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Question Bank title is required.');
        }

        $description = trim((string) ($data['description'] ?? ''));

        $stmt = $this->db->prepare(
            'UPDATE question_banks SET title = :title, description = :description, updated_at = NOW() WHERE id = :id'
        );
        return $stmt->execute([
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'id' => $id,
        ]);
    }

    /**
     * Delete a question bank and its questions.
     */
    public function delete(int $id): bool
    {
        if ($this->isBankLocked($id)) {
            throw new RuntimeException('Cannot delete this question bank because one or more active quizzes are linked to it.');
        }

        // Check if any published quiz is linked
        $checkStmt = $this->db->prepare("SELECT COUNT(*) FROM quizzes WHERE question_bank_id = :id AND status = 'published'");
        $checkStmt->execute(['id' => $id]);
        if (((int) $checkStmt->fetchColumn()) > 0) {
            throw new RuntimeException('Cannot delete a question bank that is linked to a published quiz.');
        }

        $this->db->beginTransaction();
        try {
            // Unlink draft quizzes
            $this->db->prepare('UPDATE quizzes SET question_bank_id = NULL WHERE question_bank_id = :id')->execute(['id' => $id]);

            // Delete answer options of questions in this bank
            $this->db->prepare(
                'DELETE ao FROM answer_options ao ' .
                'JOIN questions q ON q.id = ao.question_id ' .
                'WHERE q.question_bank_id = :id'
            )->execute(['id' => $id]);

            // Delete questions in this bank
            $this->db->prepare('DELETE FROM questions WHERE question_bank_id = :id')->execute(['id' => $id]);

            // Delete question bank
            $this->db->prepare('DELETE FROM question_banks WHERE id = :id')->execute(['id' => $id]);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get quizzes linked to a question bank.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLinkedQuizzes(int $bankId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, title, code, status, start_at, end_at, random_question_count ' .
            'FROM quizzes WHERE question_bank_id = :bank_id ORDER BY id DESC'
        );
        $stmt->execute(['bank_id' => $bankId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
