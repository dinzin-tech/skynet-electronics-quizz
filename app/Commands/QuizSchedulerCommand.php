<?php

declare(strict_types=1);

namespace App\Commands;

use App\Hot\Clock;
use App\Hot\Lua;
use App\Hot\Redis as HotRedis;
use App\Services\QuizWarmer;
use Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

class QuizSchedulerCommand
{
    private PDO $db;
    /** @var mixed */
    private $redis;
    private QuizWarmer $warmer;
    private bool $running = true;

    public function __construct(?PDO $db = null, $redis = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->redis = $redis;
        $this->warmer = new QuizWarmer($this->db, $this->redis);
    }

    public function execute(array $args = []): void
    {
        $once = in_array('--once', $args, true);
        $redis = $this->getRedis();

        if (!$redis) {
            echo "Redis is not available. Scheduler cannot run.\n";
            return;
        }

        echo "QuizScheduler started" . ($once ? " (single pass)" : " (daemon mode)") . "...\n";

        do {
            try {
                $redis->setEx('worker:heartbeat:scheduler', 30, (string) time());
            } catch (\Throwable $e) {
                // Ignore transient heartbeat failure
            }

            $nowMs = Clock::nowMs();

            // 1. Sweep expired attempt deadlines
            $expiredCount = $this->sweepExpiredDeadlines($redis, $nowMs);
            if ($expiredCount > 0) {
                echo "[Scheduler] Auto-submitted {$expiredCount} expired attempt(s).\n";
            }

            // 2. Sweep closed quiz windows for ABSENT flip
            $absentCount = $this->sweepAbsentQuizzes($redis, $nowMs);
            if ($absentCount > 0) {
                echo "[Scheduler] Flipped {$absentCount} unstarted attempt(s) to ABSENT.\n";
            }

            // 3. Pre-warm upcoming quizzes within T-10m
            $warmedCount = $this->preWarmUpcomingQuizzes($redis, $nowMs);
            if ($warmedCount > 0) {
                echo "[Scheduler] Pre-warmed {$warmedCount} upcoming quiz(zes).\n";
            }

            if ($once) {
                break;
            }

            sleep(1); // 1-second scheduler tick
        } while ($this->running);
    }

    /**
     * Auto-submit attempts whose deadline has passed.
     *
     * @param mixed $redis
     */
    public function sweepExpiredDeadlines($redis, int $nowMs): int
    {
        // Check deadlines zset up to nowMs + 5000 (5s grace)
        $expiredAids = method_exists($redis, 'zRangeByScore')
            ? $redis->zRangeByScore('deadlines', '-inf', (string) ($nowMs + 5000), ['limit' => [0, 200]])
            : [];

        if (empty($expiredAids)) {
            return 0;
        }

        $submitted = 0;
        foreach ($expiredAids as $aid) {
            $quizId = $redis->hGet("att:{$aid}", 'quiz_id') ?: '0';

            $rawRes = Lua::execute(
                $redis,
                'submit',
                [
                    "att:{$aid}",
                    'deadlines',
                    'dirty_att',
                    'fq',
                    "qstat:{$quizId}",
                ],
                [
                    $aid,
                    (string) $nowMs,
                    'timeout',
                ]
            );

            $res = is_string($rawRes) ? json_decode($rawRes, true) : $rawRes;
            if (!isset($res['error'])) {
                $submitted++;
            }
        }

        return $submitted;
    }

    /**
     * Flip unstarted attempts to ABSENT when quiz window + overtime (10m) has passed.
     *
     * @param mixed $redis
     */
    public function sweepAbsentQuizzes($redis, int $nowMs): int
    {
        $stmt = $this->db->prepare(
            'SELECT id, code, end_at FROM quizzes ' .
            'WHERE status = "published" AND window_closed_at IS NULL ' .
            'AND end_at <= DATE_SUB(NOW(), INTERVAL 605 SECOND)'
        );
        $stmt->execute();
        $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($quizzes)) {
            return 0;
        }

        $totalFlipped = 0;
        $upStmt = $this->db->prepare('UPDATE quizzes SET window_closed_at = NOW() WHERE id = :id');

        foreach ($quizzes as $q) {
            $quizId = (int) $q['id'];

            $rawRes = Lua::execute(
                $redis,
                'flip_absent',
                [
                    "qa:{$quizId}",
                    'dirty_att',
                    "qstat:{$quizId}",
                ],
                [
                    (string) $quizId,
                ]
            );

            $res = is_string($rawRes) ? json_decode($rawRes, true) : $rawRes;
            $flipped = (int) ($res['flipped_count'] ?? 0);
            $totalFlipped += $flipped;

            $upStmt->execute(['id' => $quizId]);
        }

        return $totalFlipped;
    }

    /**
     * Pre-warm quizzes starting in the next 10 minutes.
     *
     * @param mixed $redis
     */
    public function preWarmUpcomingQuizzes($redis, int $nowMs): int
    {
        $stmt = $this->db->prepare(
            'SELECT id, code FROM quizzes ' .
            'WHERE status = "published" ' .
            'AND start_at <= DATE_ADD(NOW(), INTERVAL 10 MINUTE) ' .
            'AND end_at > NOW()'
        );
        $stmt->execute();
        $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $warmedCount = 0;
        foreach ($quizzes as $q) {
            $code = $q['code'];
            if (!$redis->exists("quiz:{$code}")) {
                $res = $this->warmer->warm((int) $q['id']);
                if ($res['warmed']) {
                    $warmedCount++;
                }
            }
        }

        return $warmedCount;
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
