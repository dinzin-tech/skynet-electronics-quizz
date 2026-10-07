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

        // 1. Recover unfinalized processing items on startup
        $this->recoverProcessingQueue($redis);

        // 2. Re-enqueue completed attempts missing grading on startup
        $this->sweepUngradedCompletedAttempts($redis);

        $lastRecoveryTime = time();

        do {
            try {
                $redis->setEx('worker:heartbeat:finalizer', 30, (string) time());
            } catch (\Throwable $e) {
                // Ignore transient heartbeat failure
            }

            // Periodically check processing recovery and sweep ungraded completed attempts every 30 seconds
            if (time() - $lastRecoveryTime >= 30) {
                $this->recoverProcessingQueue($redis);
                $this->sweepUngradedCompletedAttempts($redis);
                $lastRecoveryTime = time();
            }

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
            $aid = $this->popFromFq($redis);
            if (!$aid) {
                break;
            }

            try {
                $att = $redis->hGetAll("att:{$aid}");
                if (empty($att)) {
                    $this->removeFromProcessingQueue($redis, $aid);
                    continue;
                }

                if (($att['graded'] ?? '') === '1') {
                    $this->removeFromProcessingQueue($redis, $aid);
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
                    if ($stmt) {
                        $stmt->execute(['qid' => $quizId, 'ver' => $ver]);
                        $rawKey = $stmt->fetchColumn();
                        $answerKey = $rawKey ? json_decode((string) $rawKey, true) : [];
                    }
                }

                // Fetch scoring settings from quiz:{code} or MySQL
                $code = $redis->hGet("att:{$aid}", 'code') ?: '';
                $quizMeta = $code ? $redis->hGetAll("quiz:{$code}") : [];
                $settingsJson = $quizMeta['settings'] ?? '';

                if (!$settingsJson) {
                    $stmt = $this->db->prepare('SELECT settings FROM quizzes WHERE id = :id');
                    if ($stmt) {
                        $stmt->execute(['id' => $quizId]);
                        $settingsJson = (string) $stmt->fetchColumn();
                    }
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

            // Successfully graded, remove from processing queue
                $this->removeFromProcessingQueue($redis, $aid);

                $graded++;
            } catch (\Throwable $e) {
                // Re-enqueue to fq on failure so item is not lost
                if (method_exists($redis, 'rPush')) {
                    $redis->rPush('fq', $aid);
                }
                $this->removeFromProcessingQueue($redis, $aid);
                throw $e;
            }
        }

        return $graded;
    }

    private function popFromFq($redis): ?string
    {
        if (method_exists($redis, 'lMove')) {
            $aid = $redis->lMove('fq', 'fq:processing', 'LEFT', 'RIGHT');
            return is_string($aid) && $aid !== '' ? $aid : null;
        }

        if (method_exists($redis, 'rpoplpush')) {
            $aid = $redis->rpoplpush('fq', 'fq:processing');
            return is_string($aid) && $aid !== '' ? $aid : null;
        }

        $aid = method_exists($redis, 'lPop') ? $redis->lPop('fq') : null;
        if ($aid && method_exists($redis, 'rPush')) {
            $redis->rPush('fq:processing', (string) $aid);
        }

        return is_string($aid) && $aid !== '' ? $aid : null;
    }

    private function removeFromProcessingQueue($redis, string $aid): void
    {
        if (method_exists($redis, 'lRem')) {
            $redis->lRem('fq:processing', $aid, 0);
        }
    }

/**
 * Recover unfinalized attempts stuck in fq:processing queue due to worker crash.
 *
 * @param mixed $redis
 */
    public function recoverProcessingQueue($redis): int
    {
        if (!method_exists($redis, 'lRange') || !method_exists($redis, 'lRem')) {
            return 0;
        }

        $items = $redis->lRange('fq:processing', 0, -1) ?: [];
        $recovered = 0;

        foreach ($items as $aid) {
            $aid = (string) $aid;
            $att = method_exists($redis, 'hGetAll') ? $redis->hGetAll("att:{$aid}") : [];
            $isGraded = ($att['graded'] ?? '') === '1';

            if ($isGraded) {
                $redis->lRem('fq:processing', $aid, 0);
            } else {
                if (method_exists($redis, 'rPush')) {
                    $redis->rPush('fq', $aid);
                }
                $redis->lRem('fq:processing', $aid, 0);
                $recovered++;
            }
        }

        return $recovered;
    }

/**
 * Sweep attempts that are marked COMPLETED in MySQL or Redis but lack graded_at / grading.
 * Re-enqueues them into fq queue so they are guaranteed to be graded.
 *
 * @param mixed $redis
 */
    public function sweepUngradedCompletedAttempts($redis, int $limit = 100): int
    {
        $stmt = $this->db->prepare(
            'SELECT public_id, quiz_id FROM attempts ' .
            'WHERE status = "COMPLETED" AND (graded_at IS NULL OR score IS NULL) ' .
            'LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pending)) {
            return 0;
        }

        $inFq = [];
        if (method_exists($redis, 'lRange')) {
            $fqItems = $redis->lRange('fq', 0, -1) ?: [];
            $procItems = $redis->lRange('fq:processing', 0, -1) ?: [];
            $inFq = array_flip(array_merge($fqItems, $procItems));
        }

        $reEnqueued = 0;
        foreach ($pending as $row) {
            $aid = (string) $row['public_id'];
            if (isset($inFq[$aid])) {
                continue;
            }

            $isRedisGraded = false;
            if (method_exists($redis, 'hGet')) {
                $isRedisGraded = ($redis->hGet("att:{$aid}", 'graded') === '1');
            }

            if ($isRedisGraded) {
                if (method_exists($redis, 'sAdd')) {
                    $redis->sAdd('dirty_att', $aid);
                }
                continue;
            }

            if (method_exists($redis, 'rPush')) {
                $redis->rPush('fq', $aid);
                $inFq[$aid] = true;
                $reEnqueued++;
            }
        }

        return $reEnqueued;
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
