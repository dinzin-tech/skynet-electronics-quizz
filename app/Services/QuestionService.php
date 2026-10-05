<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class QuestionService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Add a question with options to a quiz.
     *
     * @param array<int, array{text: string, is_correct: bool}> $options
     */
    public function addQuestion(int $quizId, string $questionText, array $options, int $displayOrder = 0, ?string $imagePath = null): array
    {
        $questionText = trim($questionText);
        if ($questionText === '') {
            throw new InvalidArgumentException('Question text cannot be empty');
        }
        $this->validateOptions($options);

        // Check if quiz has started attempts
        $this->assertQuizNotStarted($quizId);

        if ($displayOrder <= 0) {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(MAX(display_order), 0) + 1 FROM questions WHERE quiz_id = :qid'
            );
            $stmt->execute(['qid' => $quizId]);
            $displayOrder = (int) $stmt->fetchColumn();
        }

        $this->db->beginTransaction();
        try {
            $qStmt = $this->db->prepare(
                'INSERT INTO questions (quiz_id, question_text, image_path, display_order) VALUES (:qid, :text, :img, :ord)'
            );
            $qStmt->execute([
                'qid' => $quizId,
                'text' => $questionText,
                'img' => $imagePath,
                'ord' => $displayOrder,
            ]);
            $questionId = (int) $this->db->lastInsertId();

            $optStmt = $this->db->prepare(
                'INSERT INTO answer_options (question_id, option_text, is_correct, display_order) VALUES (?, ?, ?, ?)'
            );

            foreach ($options as $idx => $opt) {
                $isCorrect = !empty($opt['is_correct']) ? 1 : 0;
                $optStmt->execute([$questionId, trim($opt['text']), $isCorrect, $idx + 1]);
            }

            $this->db->commit();
            return $this->getQuestion($questionId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing question and its options.
     */
    public function updateQuestion(int $questionId, string $questionText, array $options): array
    {
        $questionText = trim($questionText);
        if ($questionText === '') {
            throw new InvalidArgumentException('Question text cannot be empty');
        }
        $this->validateOptions($options);

        $q = $this->getQuestion($questionId);
        if (!$q) {
            throw new InvalidArgumentException("Question {$questionId} not found");
        }

        $this->assertQuizNotStarted((int) $q['quiz_id']);

        $this->db->beginTransaction();
        try {
            $updateQ = $this->db->prepare('UPDATE questions SET question_text = :text WHERE id = :id');
            $updateQ->execute(['text' => $questionText, 'id' => $questionId]);

            // Replace options
            $this->db->prepare('DELETE FROM answer_options WHERE question_id = :qid')->execute(['qid' => $questionId]);

            $optStmt = $this->db->prepare(
                'INSERT INTO answer_options (question_id, option_text, is_correct, display_order) VALUES (?, ?, ?, ?)'
            );

            foreach ($options as $idx => $opt) {
                $isCorrect = !empty($opt['is_correct']) ? 1 : 0;
                $optStmt->execute([$questionId, trim($opt['text']), $isCorrect, $idx + 1]);
            }

            $this->db->commit();
            return $this->getQuestion($questionId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a question.
     */
    public function deleteQuestion(int $questionId): bool
    {
        $q = $this->getQuestion($questionId);
        if (!$q) {
            return false;
        }
        $this->assertQuizNotStarted((int) $q['quiz_id']);

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM answer_options WHERE question_id = :qid')->execute(['qid' => $questionId]);
            $this->db->prepare('DELETE FROM questions WHERE id = :id')->execute(['id' => $questionId]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Reorder questions for a quiz.
     *
     * @param array<int, int> $questionIdsInOrder
     */
    public function reorderQuestions(int $quizId, array $questionIdsInOrder): void
    {
        $this->assertQuizNotStarted($quizId);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE questions SET display_order = :ord WHERE id = :id AND quiz_id = :qid');
            foreach ($questionIdsInOrder as $order => $qid) {
                $stmt->execute(['ord' => $order + 1, 'id' => (int) $qid, 'qid' => $quizId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Validate full integrity of a quiz before publish:
     * - Must have >= 1 question
     * - Every question must have >= 2 options
     * - Exactly 1 option per question must be marked is_correct
     *
     * @return array{valid: bool, errors: string[], question_count: int}
     */
    public function validateQuizIntegrity(int $quizId): array
    {
        $errors = [];

        $qStmt = $this->db->prepare(
            'SELECT id, question_text, display_order FROM questions WHERE quiz_id = :qid ORDER BY display_order ASC'
        );
        $qStmt->execute(['qid' => $quizId]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($questions)) {
            $errors[] = 'Quiz must contain at least one question';
            return ['valid' => false, 'errors' => $errors, 'question_count' => 0];
        }

        $optStmt = $this->db->prepare(
            'SELECT id, option_text, is_correct FROM answer_options WHERE question_id = :qid ORDER BY display_order ASC'
        );

        foreach ($questions as $q) {
            $optStmt->execute(['qid' => $q['id']]);
            $options = $optStmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($options) < 2) {
                $errors[] = "Question #{$q['display_order']} must have at least 2 answer options";
            }

            $correctCount = 0;
            foreach ($options as $opt) {
                if ((int) $opt['is_correct'] === 1) {
                    $correctCount++;
                }
            }

            if ($correctCount !== 1) {
                $errors[] = "Question #{$q['display_order']} must have exactly one correct option " .
                            "(found {$correctCount})";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'question_count' => count($questions),
        ];
    }

    public function getQuestion(int $questionId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM questions WHERE id = :id');
        $stmt->execute(['id' => $questionId]);
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$q) {
            throw new InvalidArgumentException("Question {$questionId} not found");
        }

        $optStmt = $this->db->prepare(
            'SELECT id, option_text, is_correct, display_order FROM answer_options ' .
            'WHERE question_id = :qid ORDER BY display_order ASC'
        );
        $optStmt->execute(['qid' => $questionId]);
        $q['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);

        return $q;
    }

    /**
     * Get all questions with options for a given quiz.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getQuestionsByQuiz(int $quizId): array
    {
        $qStmt = $this->db->prepare(
            'SELECT id, question_text, image_path, display_order FROM questions WHERE quiz_id = :qid ORDER BY display_order ASC, id ASC'
        );
        $qStmt->execute(['qid' => $quizId]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($questions)) {
            return [];
        }

        $optStmt = $this->db->prepare(
            'SELECT id, option_text, is_correct, display_order FROM answer_options ' .
            'WHERE question_id = :qid ORDER BY display_order ASC, id ASC'
        );

        foreach ($questions as &$q) {
            $optStmt->execute(['qid' => $q['id']]);
            $q['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($q);

        return $questions;
    }

    private function validateOptions(array $options): void
    {
        if (count($options) < 2) {
            throw new InvalidArgumentException('Every question must have at least 2 options');
        }

        $correctCount = 0;
        foreach ($options as $opt) {
            if (empty(trim($opt['text'] ?? ''))) {
                throw new InvalidArgumentException('Option text cannot be empty');
            }
            if (!empty($opt['is_correct'])) {
                $correctCount++;
            }
        }

        if ($correctCount !== 1) {
            throw new InvalidArgumentException('Every question must have exactly one correct option');
        }
    }

    private function assertQuizNotStarted(int $quizId): void
    {
        // Allow admin editing at all times
        return;
    }
}
