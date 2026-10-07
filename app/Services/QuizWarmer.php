<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class QuizWarmer
{
    private PDO $db;
    /** @var mixed */
    private $redis;

    /**
     * @param mixed $redis \Redis instance or null
     */
    public function __construct(?PDO $db = null, $redis = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->redis = $redis;
    }

    /**
     * Warm Redis cache for a given quiz ID.
     *
     * @return array{warmed: bool, quiz_id: int, code: string, version: int, attempts_warmed: int, message: string}
     */
    public function warm(int $quizId): array
    {
        // Fetch quiz
        $qStmt = $this->db->prepare('SELECT * FROM quizzes WHERE id = :id');
        $qStmt->execute(['id' => $quizId]);
        $quiz = $qStmt->fetch(PDO::FETCH_ASSOC);

        if (!$quiz) {
            throw new InvalidArgumentException("Quiz ID {$quizId} not found");
        }

        if ($quiz['status'] !== 'published') {
            return [
                'warmed' => false,
                'quiz_id' => $quizId,
                'code' => $quiz['code'],
                'version' => (int) $quiz['current_version'],
                'attempts_warmed' => 0,
                'message' => "Quiz is in status '{$quiz['status']}'. Only published quizzes can be warmed in Redis.",
            ];
        }

        $version = (int) $quiz['current_version'];
        if ($version === 0) {
            return [
                'warmed' => false,
                'quiz_id' => $quizId,
                'code' => $quiz['code'],
                'version' => 0,
                'attempts_warmed' => 0,
                'message' => 'Quiz has version 0 (unpublished). Please publish the quiz to generate a snapshot.',
            ];
        }

        // Fetch latest snapshot
        $sStmt = $this->db->prepare('SELECT * FROM quiz_snapshots WHERE quiz_id = :qid AND version = :ver');
        $sStmt->execute(['qid' => $quizId, 'ver' => $version]);
        $snapshot = $sStmt->fetch(PDO::FETCH_ASSOC);

        if (!$snapshot) {
            return [
                'warmed' => false,
                'quiz_id' => $quizId,
                'code' => $quiz['code'],
                'version' => $version,
                'attempts_warmed' => 0,
                'message' => "No snapshot found for quiz ID {$quizId} version {$version}. Please re-publish.",
            ];
        }

        $answerKey = json_decode((string) $snapshot['answer_key'], true) ?? [];
        $redis = $this->getRedis();
        if (!$redis) {
            return [
                'warmed' => false,
                'quiz_id' => $quizId,
                'code' => $quiz['code'],
                'version' => $version,
                'attempts_warmed' => 0,
                'message' => 'Redis connection not available. Warm-up skipped.',
            ];
        }

        $startMs = (new DateTimeImmutable($quiz['start_at'], new DateTimeZone('UTC')))->getTimestamp() * 1000;
        $endMs = (new DateTimeImmutable($quiz['end_at'], new DateTimeZone('UTC')))->getTimestamp() * 1000;

        // 1. Warm quiz:{code}
        $quizMeta = [
            'id' => (string) $quiz['id'],
            'public_id' => (string) $quiz['public_id'],
            'code' => (string) $quiz['code'],
            'version' => (string) $version,
            'status' => (string) $quiz['status'],
            'start_ms' => (string) $startMs,
            'end_ms' => (string) $endMs,
            'duration_s' => (string) $quiz['duration_seconds'],
            'title' => (string) $quiz['title'],
            'instructions' => (string) ($quiz['instructions'] ?? ''),
            'feedback_question' => (string) ($quiz['feedback_question'] ?? ''),
            'settings' => (string) $quiz['settings'],
            'total_questions' => (string) $snapshot['question_count'],
            'bundle_sha256' => (string) $snapshot['bundle_sha256'],
            'bundle_path' => (string) $snapshot['bundle_path'],
        ];
        $redis->hMSet("quiz:{$quiz['code']}", $quizMeta);

        // 2. Warm key:{quizId}:{ver} and struct:{quizId}:{ver}
        if (!empty($answerKey)) {
            $keyMap = [];
            foreach ($answerKey as $qId => $optId) {
                $keyMap[(string) $qId] = (string) $optId;
            }
            $redis->hMSet("key:{$quizId}:{$version}", $keyMap);
        }

        if (!empty($snapshot['structure'])) {
            $redis->set("struct:{$quizId}:{$version}", (string) $snapshot['structure']);
        }

        // 3. Warm qstat:{quizId} if not exists
        if (!$redis->exists("qstat:{$quizId}")) {
            $redis->hMSet("qstat:{$quizId}", [
                'started' => '0',
                'in_progress' => '0',
                'completed' => '0',
                'absent' => '0',
                'sum_score' => '0',
                'sum_accuracy' => '0',
                'sum_time_s' => '0',
            ]);
        }

        // 4. Warm qa:{quizId} and att:{aid}
        $attStmt = $this->db->prepare(
            'SELECT id, public_id, employee_id, status, started_at, deadline_at, submitted_at, ' .
            'submit_reason, layout, max_seq, total_questions, correct_count, score, accuracy, completion_time_s ' .
            'FROM attempts WHERE quiz_id = :qid AND quiz_version = :ver'
        );
        $attStmt->execute(['qid' => $quizId, 'ver' => $version]);
        $attempts = $attStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalAttempts = count($attempts);
        $qaMap = [];

        // Pipeline Redis writes in chunks of 500
        $chunkSize = 500;
        $chunks = array_chunk($attempts, $chunkSize);

        foreach ($chunks as $chunk) {
            $pipe = method_exists($redis, 'multi') ? $redis->multi(\Redis::PIPELINE) : $redis;

            foreach ($chunk as $att) {
                $aid = $att['public_id'];
                $eid = (string) $att['employee_id'];
                $qaMap[$eid] = $aid;

                $startMs = $att['started_at']
                    ? (new DateTimeImmutable($att['started_at'], new DateTimeZone('UTC')))->getTimestamp() * 1000
                    : 0;
                $deadlineMs = $att['deadline_at']
                    ? (new DateTimeImmutable($att['deadline_at'], new DateTimeZone('UTC')))->getTimestamp() * 1000
                    : 0;
                $submittedMs = $att['submitted_at']
                    ? (new DateTimeImmutable($att['submitted_at'], new DateTimeZone('UTC')))->getTimestamp() * 1000
                    : 0;

                $attData = [
                    'id' => (string) $att['id'],
                    'eid' => $eid,
                    'quiz_id' => (string) $quizId,
                    'ver' => (string) $version,
                    'status' => (string) $att['status'],
                    'started_ms' => (string) $startMs,
                    'deadline_ms' => (string) $deadlineMs,
                    'submitted_ms' => (string) $submittedMs,
                    'reason' => (string) ($att['submit_reason'] ?? ''),
                    'layout' => (string) ($att['layout'] ?? ''),
                    'max_seq' => (string) ($att['max_seq'] ?? 0),
                    'total' => (string) ($att['total_questions'] ?? $snapshot['question_count']),
                    'score' => (string) ($att['score'] ?? 0),
                    'correct' => (string) ($att['correct_count'] ?? 0),
                    'accuracy' => (string) ($att['accuracy'] ?? 0),
                    'time_s' => (string) ($att['completion_time_s'] ?? 0),
                    'graded' => $att['status'] === 'COMPLETED' ? '1' : '0',
                ];

                if (method_exists($pipe, 'hMSet')) {
                    $pipe->hMSet("att:{$aid}", $attData);
                }
            }

            if (method_exists($redis, 'multi') && method_exists($pipe, 'exec')) {
                $pipe->exec();
            }
        }

        // Set qa:{quizId} in chunks if large
        if (!empty($qaMap)) {
            $qaChunks = array_chunk($qaMap, 1000, true);
            foreach ($qaChunks as $qaChunk) {
                $redis->hMSet("qa:{$quizId}", $qaChunk);
            }
        }

        return [
            'warmed' => true,
            'quiz_id' => $quizId,
            'code' => $quiz['code'],
            'version' => $version,
            'attempts_warmed' => $totalAttempts,
            'message' => "Successfully warmed quiz:{$quiz['code']}, key:{$quizId}:{$version}, " .
                         "qa:{$quizId} ({$totalAttempts} entries), and attempt keys.",
        ];
    }

    /**
     * Get or initialize Redis client.
     *
     * @return mixed
     */
    private function getRedis()
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        if (class_exists(\App\Hot\Redis::class)) {
            try {
                $this->redis = \App\Hot\Redis::connection();
                return $this->redis;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
