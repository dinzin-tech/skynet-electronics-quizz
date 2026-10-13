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
     * Add a question with options directly to a Question Bank.
     *
     * @param array<int, array{text: string, is_correct: bool}> $options
     */
    public function addQuestionToBank(
        int $bankId,
        string $questionText,
        array $options = [],
        int $displayOrder = 0,
        ?string $imagePath = null,
        string $type = 'mcq'
    ): array {
        $questionText = trim($questionText);
        if ($questionText === '') {
            throw new InvalidArgumentException('Question text cannot be empty');
        }
        $type = in_array(strtolower($type), ['written', 'text'], true) ? 'written' : 'mcq';
        if ($type === 'mcq') {
            $this->validateOptions($options);
        }

        $this->assertBankNotLocked($bankId);

        if ($displayOrder <= 0) {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(MAX(display_order), 0) + 1 FROM questions WHERE question_bank_id = :bid'
            );
            $stmt->execute(['bid' => $bankId]);
            $displayOrder = (int) $stmt->fetchColumn();
        }

        $this->db->beginTransaction();
        try {
            $qStmt = $this->db->prepare(
                'INSERT INTO questions (question_bank_id, quiz_id, question_text, type, image_path, display_order) ' .
                'VALUES (:bid, NULL, :text, :type, :img, :ord)'
            );
            $qStmt->execute([
                'bid' => $bankId,
                'text' => $questionText,
                'type' => $type,
                'img' => $imagePath,
                'ord' => $displayOrder,
            ]);
            $questionId = (int) $this->db->lastInsertId();

            if ($type === 'mcq' && !empty($options)) {
                $optStmt = $this->db->prepare(
                    'INSERT INTO answer_options (question_id, option_text, is_correct, display_order) VALUES (?, ?, ?, ?)'
                );

                foreach ($options as $idx => $opt) {
                    $isCorrect = !empty($opt['is_correct']) ? 1 : 0;
                    $optStmt->execute([$questionId, trim($opt['text']), $isCorrect, $idx + 1]);
                }
            }

            $this->db->commit();
            return $this->getQuestion($questionId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Add a question with options to a quiz (legacy support).
     *
     * @param array<int, array{text: string, is_correct: bool}> $options
     */
    public function addQuestion(
        int $quizId,
        string $questionText,
        array $options = [],
        int $displayOrder = 0,
        ?string $imagePath = null,
        string $type = 'mcq'
    ): array {
        $qQuiz = $this->db->prepare('SELECT question_bank_id FROM quizzes WHERE id = :id');
        $qQuiz->execute(['id' => $quizId]);
        $bankId = $qQuiz->fetchColumn();

        if ($bankId) {
            return $this->addQuestionToBank((int) $bankId, $questionText, $options, $displayOrder, $imagePath, $type);
        }

        $questionText = trim($questionText);
        if ($questionText === '') {
            throw new InvalidArgumentException('Question text cannot be empty');
        }
        $type = in_array(strtolower($type), ['written', 'text'], true) ? 'written' : 'mcq';
        if ($type === 'mcq') {
            $this->validateOptions($options);
        }

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
                'INSERT INTO questions (quiz_id, question_text, type, image_path, display_order) VALUES (:qid, :text, :type, :img, :ord)'
            );
            $qStmt->execute([
                'qid' => $quizId,
                'text' => $questionText,
                'type' => $type,
                'img' => $imagePath,
                'ord' => $displayOrder,
            ]);
            $questionId = (int) $this->db->lastInsertId();

            if ($type === 'mcq' && !empty($options)) {
                $optStmt = $this->db->prepare(
                    'INSERT INTO answer_options (question_id, option_text, is_correct, display_order) VALUES (?, ?, ?, ?)'
                );

                foreach ($options as $idx => $opt) {
                    $isCorrect = !empty($opt['is_correct']) ? 1 : 0;
                    $optStmt->execute([$questionId, trim($opt['text']), $isCorrect, $idx + 1]);
                }
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
    public function updateQuestion(
        int $questionId,
        string $questionText,
        array $options = [],
        ?string $imagePath = null,
        bool $removeImage = false,
        ?string $type = null
    ): array {
        $questionText = trim($questionText);
        if ($questionText === '') {
            throw new InvalidArgumentException('Question text cannot be empty');
        }

        $q = $this->getQuestion($questionId);
        if (!$q) {
            throw new InvalidArgumentException("Question {$questionId} not found");
        }

        if (!empty($q['question_bank_id'])) {
            $this->assertBankNotLocked((int) $q['question_bank_id']);
        } elseif (!empty($q['quiz_id'])) {
            $this->assertQuizNotStarted((int) $q['quiz_id']);
        }

        $effectiveType = $type !== null
            ? (in_array(strtolower($type), ['written', 'text'], true) ? 'written' : 'mcq')
            : (string) ($q['type'] ?? 'mcq');

        if ($effectiveType === 'mcq') {
            $this->validateOptions($options);
        }

        $this->db->beginTransaction();
        try {
            if ($imagePath !== null) {
                $updateQ = $this->db->prepare('UPDATE questions SET question_text = :text, type = :type, image_path = :img WHERE id = :id');
                $updateQ->execute(['text' => $questionText, 'type' => $effectiveType, 'img' => $imagePath, 'id' => $questionId]);
            } elseif ($removeImage) {
                $updateQ = $this->db->prepare('UPDATE questions SET question_text = :text, type = :type, image_path = NULL WHERE id = :id');
                $updateQ->execute(['text' => $questionText, 'type' => $effectiveType, 'id' => $questionId]);
            } else {
                $updateQ = $this->db->prepare('UPDATE questions SET question_text = :text, type = :type WHERE id = :id');
                $updateQ->execute(['text' => $questionText, 'type' => $effectiveType, 'id' => $questionId]);
            }

            // Replace options
            $this->db->prepare('DELETE FROM answer_options WHERE question_id = :qid')->execute(['qid' => $questionId]);

            if ($effectiveType === 'mcq' && !empty($options)) {
                $optStmt = $this->db->prepare(
                    'INSERT INTO answer_options (question_id, option_text, is_correct, display_order) VALUES (?, ?, ?, ?)'
                );

                foreach ($options as $idx => $opt) {
                    $isCorrect = !empty($opt['is_correct']) ? 1 : 0;
                    $optStmt->execute([$questionId, trim($opt['text']), $isCorrect, $idx + 1]);
                }
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

        if (!empty($q['question_bank_id'])) {
            $this->assertBankNotLocked((int) $q['question_bank_id']);
        } elseif (!empty($q['quiz_id'])) {
            $this->assertQuizNotStarted((int) $q['quiz_id']);
        }

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
     * Reorder questions for a question bank.
     *
     * @param array<int, int> $questionIdsInOrder
     */
    public function reorderBankQuestions(int $bankId, array $questionIdsInOrder): void
    {
        $this->assertBankNotLocked($bankId);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE questions SET display_order = :ord WHERE id = :id AND question_bank_id = :bid');
            foreach ($questionIdsInOrder as $order => $qid) {
                $stmt->execute(['ord' => $order + 1, 'id' => (int) $qid, 'bid' => $bankId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Reorder questions for a quiz (legacy).
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
     * Validate full integrity of a question bank:
     * - Must have >= 1 question
     * - Every MCQ question must have >= 2 options
     * - Exactly 1 option per MCQ question must be marked is_correct
     *
     * @return array{valid: bool, errors: string[], question_count: int}
     */
    public function validateBankIntegrity(int $bankId): array
    {
        $errors = [];

        $qStmt = $this->db->prepare(
            'SELECT id, question_text, display_order, type FROM questions WHERE question_bank_id = :bid ORDER BY display_order ASC, id ASC'
        );
        $qStmt->execute(['bid' => $bankId]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($questions)) {
            $errors[] = 'Question bank must contain at least one question';
            return ['valid' => false, 'errors' => $errors, 'question_count' => 0];
        }

        $optStmt = $this->db->prepare(
            'SELECT id, option_text, is_correct FROM answer_options WHERE question_id = :qid ORDER BY display_order ASC'
        );

        foreach ($questions as $q) {
            $qType = $q['type'] ?? 'mcq';
            if ($qType === 'written') {
                continue;
            }

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
                $errors[] = "Question #{$q['display_order']} must have exactly one correct option (found {$correctCount})";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'question_count' => count($questions),
        ];
    }

    /**
     * Validate full integrity of a quiz before publish:
     * - If linked to a Question Bank, validates the bank and verifies random_question_count constraint.
     * - Otherwise validates questions directly attached to the quiz.
     *
     * @return array{valid: bool, errors: string[], question_count: int}
     */
    public function validateQuizIntegrity(int $quizId): array
    {
        $qStmt = $this->db->prepare('SELECT id, question_bank_id, random_question_count FROM quizzes WHERE id = :id');
        $qStmt->execute(['id' => $quizId]);
        $quiz = $qStmt->fetch(PDO::FETCH_ASSOC);

        if (!$quiz) {
            return ['valid' => false, 'errors' => ['Quiz not found'], 'question_count' => 0];
        }

        if (!empty($quiz['question_bank_id'])) {
            $bankValidation = $this->validateBankIntegrity((int) $quiz['question_bank_id']);
            $errors = $bankValidation['errors'];
            $bankQCount = $bankValidation['question_count'];

            $randomCount = !empty($quiz['random_question_count']) ? (int) $quiz['random_question_count'] : 0;
            if ($randomCount > 0 && $randomCount > $bankQCount) {
                $errors[] = "Quiz requires {$randomCount} random questions, but the linked Question Bank only contains {$bankQCount} questions.";
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'question_count' => $randomCount > 0 ? $randomCount : $bankQCount,
            ];
        }

        // Legacy fallback: check direct questions
        $errors = [];
        $directQStmt = $this->db->prepare(
            'SELECT id, question_text, display_order, type FROM questions WHERE quiz_id = :qid ORDER BY display_order ASC'
        );
        $directQStmt->execute(['qid' => $quizId]);
        $questions = $directQStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($questions)) {
            $errors[] = 'Quiz must be linked to a Question Bank or contain at least one question';
            return ['valid' => false, 'errors' => $errors, 'question_count' => 0];
        }

        $optStmt = $this->db->prepare(
            'SELECT id, option_text, is_correct FROM answer_options WHERE question_id = :qid ORDER BY display_order ASC'
        );

        foreach ($questions as $q) {
            $qType = $q['type'] ?? 'mcq';
            if ($qType === 'written') {
                continue;
            }

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
                $errors[] = "Question #{$q['display_order']} must have exactly one correct option (found {$correctCount})";
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
     * Get all questions with options for a given Question Bank.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getQuestionsByBank(int $bankId): array
    {
        $qStmt = $this->db->prepare(
            'SELECT id, question_bank_id, quiz_id, question_text, type, image_path, display_order ' .
            'FROM questions WHERE question_bank_id = :bid ORDER BY display_order ASC, id ASC'
        );
        $qStmt->execute(['bid' => $bankId]);
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

    /**
     * Get all questions with options for a given quiz (legacy).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getQuestionsByQuiz(int $quizId): array
    {
        // First check if quiz is linked to a question bank
        $qQuiz = $this->db->prepare('SELECT question_bank_id FROM quizzes WHERE id = :id');
        $qQuiz->execute(['id' => $quizId]);
        $bankId = $qQuiz->fetchColumn();

        if ($bankId) {
            return $this->getQuestionsByBank((int) $bankId);
        }

        $qStmt = $this->db->prepare(
            'SELECT id, question_bank_id, quiz_id, question_text, type, image_path, display_order ' .
            'FROM questions WHERE quiz_id = :qid ORDER BY display_order ASC, id ASC'
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

    public function assertBankNotLocked(int $bankId): void
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
        if (((int) $stmt->fetchColumn()) > 0) {
            throw new RuntimeException(
                'Cannot modify questions in this Question Bank because an active quiz linked to it is currently open or in progress.'
            );
        }
    }

    private function assertQuizNotStarted(int $quizId): void
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM attempts WHERE quiz_id = :qid AND status != 'NOT_STARTED'");
        $stmt->execute(['qid' => $quizId]);
        if (((int) $stmt->fetchColumn()) > 0) {
            throw new RuntimeException('Cannot modify questions or options after quiz attempts have started');
        }
    }
}
