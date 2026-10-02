<?php

declare(strict_types=1);

namespace App\Services;

use App\Hot\Redis as HotRedis;
use Core\Database;
use PDO;

class DashboardService
{
    private PDO $db;
    /** @var mixed */
    private $redis;

    public function __construct(?PDO $db = null, $redis = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->redis = $redis;
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

    /**
     * Get system-wide 9 KPIs, cached in Redis for 10 seconds to avoid MySQL load.
     *
     * @return array<string, mixed>
     */
    public function getGlobalKpis(): array
    {
        $redis = $this->getRedis();
        $cacheKey = 'dash:global_kpis';

        if ($redis) {
            try {
                $cached = $redis->get($cacheKey);
                if ($cached !== false && is_string($cached)) {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore Redis read error and fallback to DB
            }
        }

        // Compute KPIs from MySQL
        $totalEmployees = (int) $this->db->query(
            'SELECT COUNT(*) FROM employees WHERE status = "active"'
        )->fetchColumn();

        $totalQuizzes = (int) $this->db->query(
            'SELECT COUNT(*) FROM quizzes'
        )->fetchColumn();

        $publishedQuizzes = (int) $this->db->query(
            'SELECT COUNT(*) FROM quizzes WHERE status = "published"'
        )->fetchColumn();

        $attemptStats = $this->db->query(
            'SELECT '
            . 'COUNT(CASE WHEN status = "COMPLETED" THEN 1 END) AS completed_count, '
            . 'COUNT(CASE WHEN status = "IN_PROGRESS" THEN 1 END) AS in_progress_count, '
            . 'COUNT(CASE WHEN status = "ABSENT" THEN 1 END) AS absent_count, '
            . 'AVG(CASE WHEN status = "COMPLETED" THEN score END) AS avg_score, '
            . 'AVG(CASE WHEN status = "COMPLETED" THEN accuracy END) AS avg_accuracy, '
            . 'AVG(CASE WHEN status = "COMPLETED" THEN completion_time_s END) AS avg_time '
            . 'FROM attempts'
        )->fetch(PDO::FETCH_ASSOC);

        $kpis = [
            'total_employees' => $totalEmployees,
            'total_quizzes' => $totalQuizzes,
            'published_quizzes' => $publishedQuizzes,
            'completed_attempts' => (int) ($attemptStats['completed_count'] ?? 0),
            'in_progress_attempts' => (int) ($attemptStats['in_progress_count'] ?? 0),
            'absent_employees' => (int) ($attemptStats['absent_count'] ?? 0),
            'avg_score' => round((float) ($attemptStats['avg_score'] ?? 0), 2),
            'avg_accuracy' => round((float) ($attemptStats['avg_accuracy'] ?? 0), 2),
            'avg_completion_time_s' => (int) round((float) ($attemptStats['avg_time'] ?? 0)),
        ];

        if ($redis) {
            try {
                $redis->setEx($cacheKey, 10, json_encode($kpis));
            } catch (\Throwable $e) {
                // Ignore Redis write error
            }
        }

        return $kpis;
    }

    /**
     * Get quiz-specific KPIs: reads live from Redis qstat:{quizId} or aggregates MySQL.
     *
     * @return array<string, mixed>
     */
    public function getQuizKpis(int $quizId): array
    {
        $redis = $this->getRedis();

        // 1. Try reading live from Redis qstat:{quizId}
        if ($redis) {
            try {
                $qstat = $redis->hGetAll("qstat:{$quizId}");
                if (!empty($qstat) && isset($qstat['started'])) {
                    return $this->formatRedisQuizKpis($quizId, $qstat);
                }
            } catch (\Throwable $e) {
                // Fallback to MySQL
            }
        }

        // 2. Fallback to MySQL aggregates cached for 10s
        $cacheKey = "dash:quiz_kpis:{$quizId}";
        if ($redis) {
            try {
                $cached = $redis->get($cacheKey);
                if ($cached !== false && is_string($cached)) {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        $kpis = $this->computeQuizKpisFromDb($quizId);

        if ($redis) {
            try {
                $redis->setEx($cacheKey, 10, json_encode($kpis));
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        return $kpis;
    }

    /**
     * Format KPIs from Redis qstat hash.
     *
     * @param array<string, string> $qstat
     * @return array<string, mixed>
     */
    private function formatRedisQuizKpis(int $quizId, array $qstat): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM attempts WHERE quiz_id = :qid'
        );
        $stmt->execute(['qid' => $quizId]);
        $totalEligible = (int) $stmt->fetchColumn();

        $started = (int) ($qstat['started'] ?? 0);
        $inProgress = (int) ($qstat['in_progress'] ?? 0);
        $completed = (int) ($qstat['completed'] ?? 0);
        $absent = (int) ($qstat['absent'] ?? 0);

        $notStarted = max(0, $totalEligible - $started - $absent);

        $sumScore = (float) ($qstat['sum_score'] ?? 0.0);
        $sumAccuracy = (float) ($qstat['sum_accuracy'] ?? 0.0);
        $sumTimeS = (int) ($qstat['sum_time_s'] ?? 0);

        $avgScore = $completed > 0 ? round($sumScore / $completed, 2) : 0.0;
        $avgAccuracy = $completed > 0 ? round($sumAccuracy / $completed, 2) : 0.0;
        $avgTime = $completed > 0 ? (int) round($sumTimeS / $completed) : 0;

        // Fetch pass mark if available
        $passRate = $this->calculatePassRate($quizId, $completed);

        return [
            'quiz_id' => $quizId,
            'source' => 'redis',
            'total_eligible' => $totalEligible,
            'not_started' => $notStarted,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'absent' => $absent,
            'avg_score' => $avgScore,
            'avg_accuracy' => $avgAccuracy,
            'avg_completion_time_s' => $avgTime,
            'pass_rate' => $passRate,
        ];
    }

    /**
     * Compute quiz KPIs directly from MySQL attempts table.
     *
     * @return array<string, mixed>
     */
    private function computeQuizKpisFromDb(int $quizId): array
    {
        $stmt = $this->db->prepare(
            'SELECT '
            . 'COUNT(*) AS total_eligible, '
            . 'COUNT(CASE WHEN status = "NOT_STARTED" THEN 1 END) AS not_started_count, '
            . 'COUNT(CASE WHEN status = "IN_PROGRESS" THEN 1 END) AS in_progress_count, '
            . 'COUNT(CASE WHEN status = "COMPLETED" THEN 1 END) AS completed_count, '
            . 'COUNT(CASE WHEN status = "ABSENT" THEN 1 END) AS absent_count, '
            . 'AVG(CASE WHEN status = "COMPLETED" THEN score END) AS avg_score, '
            . 'AVG(CASE WHEN status = "COMPLETED" THEN accuracy END) AS avg_accuracy, '
            . 'AVG(CASE WHEN status = "COMPLETED" THEN completion_time_s END) AS avg_time '
            . 'FROM attempts WHERE quiz_id = :qid'
        );
        $stmt->execute(['qid' => $quizId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $completed = (int) ($row['completed_count'] ?? 0);
        $passRate = $this->calculatePassRate($quizId, $completed);

        return [
            'quiz_id' => $quizId,
            'source' => 'mysql',
            'total_eligible' => (int) ($row['total_eligible'] ?? 0),
            'not_started' => (int) ($row['not_started_count'] ?? 0),
            'in_progress' => (int) ($row['in_progress_count'] ?? 0),
            'completed' => $completed,
            'absent' => (int) ($row['absent_count'] ?? 0),
            'avg_score' => round((float) ($row['avg_score'] ?? 0), 2),
            'avg_accuracy' => round((float) ($row['avg_accuracy'] ?? 0), 2),
            'avg_completion_time_s' => (int) round((float) ($row['avg_time'] ?? 0)),
            'pass_rate' => $passRate,
        ];
    }

    /**
     * Calculate pass rate percentage for completed attempts.
     */
    private function calculatePassRate(int $quizId, int $completed): float
    {
        if ($completed === 0) {
            return 0.0;
        }

        $stmt = $this->db->prepare('SELECT settings FROM quizzes WHERE id = :qid');
        $stmt->execute(['qid' => $quizId]);
        $settingsJson = (string) $stmt->fetchColumn();

        $settings = json_decode($settingsJson, true) ?? [];
        $passMark = isset($settings['scoring']['pass_mark']) ? (float) $settings['scoring']['pass_mark'] : null;

        if ($passMark === null) {
            return 100.0;
        }

        $stmtPass = $this->db->prepare(
            'SELECT COUNT(*) FROM attempts '
            . 'WHERE quiz_id = :qid AND status = "COMPLETED" AND score >= :pmark'
        );
        $stmtPass->execute([
            'qid' => $quizId,
            'pmark' => $passMark,
        ]);
        $passedCount = (int) $stmtPass->fetchColumn();

        return round(($passedCount / $completed) * 100, 2);
    }
}
