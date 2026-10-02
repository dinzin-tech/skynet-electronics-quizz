<?php

declare(strict_types=1);

namespace App\Commands;

use App\Hot\Redis as HotRedis;
use Core\Database;
use PDO;

class QuizFinalizerCommand
{
    private PDO $db;
    /** @var mixed */
    private $redis;
    private bool $running = true;

    public function __construct(?PDO $db = null, $redis = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->redis = $redis;
    }

    public function execute(array $args = []): void
    {
        $once = in_array('--once', $args, true);
        $redis = $this->getRedis();

        if (!$redis) {
            echo "Redis is not available. Finalizer cannot run.\n";
            return;
        }

        echo "QuizFinalizer started" . ($once ? " (single pass)" : " (daemon mode)") . "...\n";

        do {
            $gradedCount = $this->finalizeBatch($redis, 100);
            if ($gradedCount > 0) {
                echo "[Finalizer] Graded and finalized {$gradedCount} attempt(s).\n";
            }

            if ($once) {
                break;
            }

            usleep(200000); // 200ms
        } while ($this->running);
    }

    /**
     * Finalize and grade a batch of attempts from fq queue.
     *
     * @param mixed $redis
     */
    public function finalizeBatch($redis, int $limit = 100): int
    {
        $graded = 0;

        for ($i = 0; $i < $limit; $i++) {
            $aid = method_exists($redis, 'lPop') ? $redis->lPop('fq') : null;
            if (!$aid) {
                break;
            }

            $att = $redis->hGetAll("att:{$aid}");
            if (empty($att)) {
                continue;
            }

            if (($att['graded'] ?? '') === '1') {
                continue;
            }

            $quizId = $att['quiz_id'] ?? '0';
            $ver = $att['ver'] ?? '1';

            // Fetch answer key from Redis: key:{quizId}:{ver}
            $answerKey = $redis->hGetAll("key:{$quizId}:{$ver}") ?: [];
            if (empty($answerKey)) {
                // Fallback to snapshot from MySQL
                $stmt = $this->db->prepare(
                    'SELECT answer_key FROM quiz_snapshots WHERE quiz_id = :qid AND version = :ver'
                );
                $stmt->execute(['qid' => $quizId, 'ver' => $ver]);
                $rawKey = $stmt->fetchColumn();
                $answerKey = $rawKey ? json_decode((string) $rawKey, true) : [];
            }

            // Fetch scoring settings from quiz:{code} or MySQL
            $code = $redis->hGet("att:{$aid}", 'code') ?: '';
            $quizMeta = $code ? $redis->hGetAll("quiz:{$code}") : [];
            $settingsJson = $quizMeta['settings'] ?? '';

            if (!$settingsJson) {
                $stmt = $this->db->prepare('SELECT settings FROM quizzes WHERE id = :id');
                $stmt->execute(['id' => $quizId]);
                $settingsJson = (string) $stmt->fetchColumn();
            }

            $settings = json_decode($settingsJson, true) ?? [];
            $scoring = $settings['scoring'] ?? [];

            $marksPerCorrect = (float) ($scoring['marks_per_correct'] ?? 1.0);
            $negativeMarks = (float) ($scoring['negative_marks_per_wrong'] ?? 0.0);
            $unansweredPenalty = (float) ($scoring['unanswered_penalty'] ?? 0.0);

            // Fetch user answers: ans:{aid}
            $userAnswers = $redis->hGetAll("ans:{$aid}") ?: [];

            $totalQuestions = count($answerKey) > 0 ? count($answerKey) : (int) ($att['total'] ?? 0);
            $correctCount = 0;
            $score = 0.0;

            foreach ($answerKey as $qid => $correctOid) {
                $ansRecord = $userAnswers[(string) $qid] ?? null;
                if ($ansRecord !== null) {
                    $parts = explode('|', $ansRecord);
                    $selectedOid = (int) ($parts[0] ?? 0);
                    if ($selectedOid === (int) $correctOid) {
                        $correctCount++;
                        $score += $marksPerCorrect;
                    } else {
                        $score -= $negativeMarks;
                    }
                } else {
                    $score -= $unansweredPenalty;
                }
            }

            $accuracy = $totalQuestions > 0 ? round(($correctCount / $totalQuestions) * 100, 2) : 0.0;

            $startedMs = (int) ($att['started_ms'] ?? 0);
            $submittedMs = (int) ($att['submitted_ms'] ?? 0);
            $completionTimeS = ($startedMs > 0 && $submittedMs >= $startedMs)
                ? (int) (($submittedMs - $startedMs) / 1000)
                : 0;

            // Update att:{aid} in Redis
            $redis->hMSet("att:{$aid}", [
                'correct' => (string) $correctCount,
                'score' => (string) $score,
                'accuracy' => (string) $accuracy,
                'time_s' => (string) $completionTimeS,
                'graded' => '1',
            ]);

            // Update qstat running sums if key exists
            if (method_exists($redis, 'exists') && $redis->exists("qstat:{$quizId}")) {
                $redis->hIncrByFloat("qstat:{$quizId}", 'sum_score', (float) $score);
                $redis->hIncrByFloat("qstat:{$quizId}", 'sum_accuracy', (float) $accuracy);
                $redis->hIncrBy("qstat:{$quizId}", 'sum_time_s', (int) $completionTimeS);
            }

            // Mark attempt dirty for MySQL write-behind
            $redis->sAdd('dirty_att', $aid);
            $graded++;
        }

        return $graded;
    }

    private function getRedis()
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
