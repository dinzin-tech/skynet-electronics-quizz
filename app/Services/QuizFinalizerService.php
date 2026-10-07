<?php

declare(strict_types=1);

namespace App\Services;

use App\Hot\Redis as HotRedis;
use Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

class QuizFinalizerService
{
    private PDO $db;
    /** @var mixed */
    private mixed $redis;

    public function __construct(?PDO $db = null, mixed $redis = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->redis = $redis;
    }

    /**
     * Get the count of submissions still pending grading for a specific quiz.
     */
    public function getUngradedCount(int $quizId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM attempts a ' .
            'JOIN quizzes q ON q.id = a.quiz_id ' .
            'WHERE a.quiz_id = :qid ' .
            'AND (a.graded_at IS NULL OR a.score IS NULL) ' .
            'AND (' .
            '    a.status = "COMPLETED" ' .
            '    OR (a.status = "IN_PROGRESS" AND (a.deadline_at <= NOW() OR q.end_at <= NOW()))' .
            ')'
        );
        $stmt->execute(['qid' => $quizId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Finalize and grade a batch of ungraded attempts for a quiz.
     *
     * @return array{
     *     success: bool,
     *     batch_graded: int,
     *     remaining: int
     * }
     */
    public function finalizeBatch(int $quizId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $redis = $this->getRedis();
        $now = gmdate('Y-m-d H:i:s');

        // 1. Fetch Quiz Meta & Scoring Settings
        $quizStmt = $this->db->prepare(
            'SELECT id, code, settings, current_version FROM quizzes WHERE id = :id'
        );
        $quizStmt->execute(['id' => $quizId]);
        $quiz = $quizStmt->fetch(PDO::FETCH_ASSOC);

        if (!$quiz) {
            throw new InvalidArgumentException("Quiz #{$quizId} not found.");
        }

        $settings = json_decode((string) ($quiz['settings'] ?? ''), true) ?: [];
        $scoring = $settings['scoring'] ?? [];
        $marksPerCorrect = (float) ($scoring['marks_per_correct'] ?? 1.0);
        $negativeMarks = (float) ($scoring['negative_marks_per_wrong'] ?? 0.0);
        $unansweredPenalty = (float) ($scoring['unanswered_penalty'] ?? 0.0);

        // 2. Fetch Snapshots for Answer Keys
        $snapStmt = $this->db->prepare(
            'SELECT version, answer_key FROM quiz_snapshots WHERE quiz_id = :qid'
        );
        $snapStmt->execute(['qid' => $quizId]);
        $snapshots = [];
        while ($snap = $snapStmt->fetch(PDO::FETCH_ASSOC)) {
            $snapshots[(int) $snap['version']] = json_decode((string) $snap['answer_key'], true) ?: [];
        }

        // Fallback answer key from answer_options table if no snapshot found
        $fallbackAnswerKey = null;

        // 3. Select pending attempts
        $attStmt = $this->db->prepare(
            'SELECT a.id, a.public_id, a.quiz_id, a.quiz_version, a.employee_id, a.status, ' .
            'a.started_at, a.deadline_at, a.submitted_at, a.total_questions, ' .
            'q.end_at AS quiz_end_at, q.code AS quiz_code ' .
            'FROM attempts a ' .
            'JOIN quizzes q ON q.id = a.quiz_id ' .
            'WHERE a.quiz_id = :qid ' .
            'AND (a.graded_at IS NULL OR a.score IS NULL) ' .
            'AND (' .
            '    a.status = "COMPLETED" ' .
            '    OR (a.status = "IN_PROGRESS" AND (a.deadline_at <= NOW() OR q.end_at <= NOW()))' .
            ') ' .
            'ORDER BY a.id ASC ' .
            'LIMIT :limit'
        );
        $attStmt->bindValue(':qid', $quizId, PDO::PARAM_INT);
        $attStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $attStmt->execute();
        $attempts = $attStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($attempts)) {
            return [
                'success' => true,
                'batch_graded' => 0,
                'remaining' => 0,
            ];
        }

        $updateAttStmt = $this->db->prepare(
            'UPDATE attempts SET ' .
            'status = "COMPLETED", ' .
            'submitted_at = IFNULL(submitted_at, :subat), ' .
            'correct_count = :correct, ' .
            'score = :score, ' .
            'accuracy = :accuracy, ' .
            'completion_time_s = :time_s, ' .
            'graded_at = NOW(3) ' .
            'WHERE id = :id'
        );

        $fetchAnsStmt = $this->db->prepare(
            'SELECT question_id, selected_option_id, seq, answered_at ' .
            'FROM attempt_answers WHERE attempt_id = :aid'
        );

        $updateAnsStmt = $this->db->prepare(
            'UPDATE attempt_answers SET is_correct = :is_correct ' .
            'WHERE attempt_id = :aid AND question_id = :qid'
        );

        $insertAnsStmt = $this->db->prepare(
            'INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id, seq, answered_at, is_correct) ' .
            'VALUES (:aid, :qid, :oid, :seq, :at, :is_correct) ' .
            'ON DUPLICATE KEY UPDATE ' .
            'selected_option_id = VALUES(selected_option_id), ' .
            'is_correct = VALUES(is_correct)'
        );

        $gradedCount = 0;

        foreach ($attempts as $att) {
            $numericId = (int) $att['id'];
            $aid = (string) $att['public_id'];
            $ver = (int) ($att['quiz_version'] ?? 1);

            // Fetch answer key (Versioned snapshot -> Redis -> MySQL snapshot -> table query)
            $answerKey = $snapshots[$ver] ?? [];
            if (empty($answerKey) && $redis) {
                $answerKey = $redis->hGetAll("key:{$quizId}:{$ver}") ?: [];
            }
            if (empty($answerKey)) {
                if ($fallbackAnswerKey === null) {
                    $optStmt = $this->db->prepare(
                        'SELECT ao.question_id, ao.id FROM answer_options ao ' .
                        'JOIN questions q ON q.id = ao.question_id ' .
                        'WHERE q.quiz_id = :qid AND ao.is_correct = 1'
                    );
                    $optStmt->execute(['qid' => $quizId]);
                    $fallbackAnswerKey = $optStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
                }
                $answerKey = $fallbackAnswerKey;
            }

            // Fetch candidate answers
            $selectedAnswers = [];
            $answerMeta = [];

            // 1) From MySQL attempt_answers
            $fetchAnsStmt->execute(['aid' => $numericId]);
            $mysqlAnswers = $fetchAnsStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($mysqlAnswers as $mAns) {
                $qid = (int) $mAns['question_id'];
                $selectedAnswers[$qid] = $mAns['selected_option_id'] ? (int) $mAns['selected_option_id'] : null;
            }

            // 2) From Redis ans:{$aid}
            if ($redis) {
                $redisAnswers = $redis->hGetAll("ans:{$aid}") ?: [];
                foreach ($redisAnswers as $qidStr => $recordStr) {
                    $qid = (int) $qidStr;
                    $parts = explode('|', (string) $recordStr);
                    $oid = (int) ($parts[0] ?? 0);
                    $seq = (int) ($parts[1] ?? 0);
                    $tsMs = (int) ($parts[2] ?? 0);

                    if ($oid > 0) {
                        $selectedAnswers[$qid] = $oid;
                    }
                    $answerMeta[$qid] = ['oid' => $oid > 0 ? $oid : null, 'seq' => $seq, 'ts' => $tsMs];
                }
            }

            // Grade answers
            $totalQuestions = count($answerKey) > 0 ? count($answerKey) : (int) ($att['total_questions'] ?? 0);
            $correctCount = 0;
            $score = 0.0;
            $correctnessMap = [];

            foreach ($answerKey as $qid => $correctOid) {
                $qid = (int) $qid;
                $correctOid = (int) $correctOid;
                $selectedOid = $selectedAnswers[$qid] ?? null;

                if ($selectedOid !== null && $selectedOid > 0) {
                    if ($selectedOid === $correctOid) {
                        $correctCount++;
                        $score += $marksPerCorrect;
                        $correctnessMap[$qid] = 1;
                    } else {
                        $score -= $negativeMarks;
                        $correctnessMap[$qid] = 0;
                    }
                } else {
                    $score -= $unansweredPenalty;
                    $correctnessMap[$qid] = null;
                }
            }

            $accuracy = $totalQuestions > 0 ? round(($correctCount / $totalQuestions) * 100, 2) : 0.0;

            // Compute completion time
            $submittedAt = $att['submitted_at'] ?? ($att['deadline_at'] ?? $now);
            $startedAt = $att['started_at'];
            $completionTimeS = 0;
            if ($startedAt && $submittedAt) {
                $startTs = strtotime($startedAt);
                $subTs = strtotime($submittedAt);
                if ($startTs !== false && $subTs !== false && $subTs >= $startTs) {
                    $completionTimeS = $subTs - $startTs;
                }
            }

            // Update MySQL attempts
            $updateAttStmt->execute([
                'subat' => $submittedAt,
                'correct' => $correctCount,
                'score' => $score,
                'accuracy' => $accuracy,
                'time_s' => $completionTimeS,
                'id' => $numericId,
            ]);

            // Sync is_correct flags in MySQL attempt_answers
            foreach ($correctnessMap as $qid => $isCorrect) {
                if ($isCorrect !== null) {
                    if (isset($selectedAnswers[$qid])) {
                        $updateAnsStmt->execute([
                            'is_correct' => $isCorrect,
                            'aid' => $numericId,
                            'qid' => $qid,
                        ]);
                    }
                }
            }

            // If Redis had answers that are not yet in MySQL attempt_answers, insert them
            if (!empty($answerMeta)) {
                foreach ($answerMeta as $qid => $meta) {
                    $atDate = $meta['ts'] > 0
                        ? (new DateTimeImmutable('@' . (int) ($meta['ts'] / 1000)))->format('Y-m-d H:i:s.v')
                        : $now;
                    $insertAnsStmt->execute([
                        'aid' => $numericId,
                        'qid' => $qid,
                        'oid' => $meta['oid'],
                        'seq' => $meta['seq'],
                        'at' => $atDate,
                        'is_correct' => $correctnessMap[$qid] ?? null,
                    ]);
                }
            }

            // Sync Redis hot data if available
            if ($redis) {
                $redis->hMSet("att:{$aid}", [
                    'status' => 'COMPLETED',
                    'correct' => (string) $correctCount,
                    'score' => (string) $score,
                    'accuracy' => (string) $accuracy,
                    'time_s' => (string) $completionTimeS,
                    'graded' => '1',
                ]);

                if (method_exists($redis, 'sRem')) {
                    $redis->sRem('dirty_att', $aid);
                }
                if (method_exists($redis, 'lRem')) {
                    $redis->lRem('fq', $aid, 0);
                }

                if (method_exists($redis, 'exists') && $redis->exists("qstat:{$quizId}")) {
                    $redis->hIncrByFloat("qstat:{$quizId}", 'sum_score', (float) $score);
                    $redis->hIncrByFloat("qstat:{$quizId}", 'sum_accuracy', (float) $accuracy);
                    $redis->hIncrBy("qstat:{$quizId}", 'sum_time_s', (int) $completionTimeS);
                }
            }

            $gradedCount++;
        }

        $remaining = $this->getUngradedCount($quizId);

        return [
            'success' => true,
            'batch_graded' => $gradedCount,
            'remaining' => $remaining,
        ];
    }

    private function getRedis(): mixed
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        try {
            $this->redis = HotRedis::connection();
            return $this->redis;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
