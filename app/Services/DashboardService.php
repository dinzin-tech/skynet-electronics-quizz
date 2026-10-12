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

    /**
     * Get question-wise summary showing how many employees answered correctly,
     * incorrectly, or left unanswered.
     *
     * @return array<int, array{
     *     question_id: int,
     *     display_order: int,
     *     question_text: string,
     *     question_type: string,
     *     total_attempts: int,
     *     answered_count: int,
     *     correct_count: int,
     *     correct_pct: float,
     *     incorrect_count: int,
     *     incorrect_pct: float,
     *     unanswered_count: int,
     *     unanswered_pct: float
     * }>
     */
    public function getQuestionWiseSummary(int $quizId): array
    {
        // 1. Fetch total completed attempts for the quiz
        $compStmt = $this->db->prepare('SELECT COUNT(*) FROM attempts WHERE quiz_id = :qid AND status = "COMPLETED"');
        $compStmt->execute(['qid' => $quizId]);
        $totalCompleted = (int) $compStmt->fetchColumn();

        // 2. Fetch all questions for this quiz
        $qStmt = $this->db->prepare(
            'SELECT id, display_order, question_text FROM questions WHERE quiz_id = :qid ORDER BY display_order ASC, id ASC'
        );
        $qStmt->execute(['qid' => $quizId]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($questions)) {
            return [];
        }

        // 3. Aggregate answers for completed attempts
        $ansStmt = $this->db->prepare(
            'SELECT '
            . 'aa.question_id, '
            . 'COUNT(CASE WHEN aa.selected_option_id IS NOT NULL THEN 1 END) AS answered_count, '
            . 'SUM(CASE WHEN aa.is_correct = 1 THEN 1 ELSE 0 END) AS correct_count, '
            . 'SUM(CASE WHEN aa.is_correct = 0 AND aa.selected_option_id IS NOT NULL THEN 1 ELSE 0 END) AS incorrect_count '
            . 'FROM attempt_answers aa '
            . 'JOIN attempts a ON a.id = aa.attempt_id '
            . 'WHERE a.quiz_id = :qid AND a.status = "COMPLETED" '
            . 'GROUP BY aa.question_id'
        );
        $ansStmt->execute(['qid' => $quizId]);
        $ansStats = [];
        while ($row = $ansStmt->fetch(PDO::FETCH_ASSOC)) {
            $ansStats[(int) $row['question_id']] = $row;
        }

        $summary = [];
        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $stat = $ansStats[$qid] ?? null;

            $answered = $stat ? (int) ($stat['answered_count'] ?? 0) : 0;
            $correct = $stat ? (int) ($stat['correct_count'] ?? 0) : 0;
            $incorrect = $stat ? (int) ($stat['incorrect_count'] ?? 0) : 0;
            $unanswered = max(0, $totalCompleted - $answered);

            $correctPct = $totalCompleted > 0 ? round(($correct / $totalCompleted) * 100, 1) : 0.0;
            $incorrectPct = $totalCompleted > 0 ? round(($incorrect / $totalCompleted) * 100, 1) : 0.0;
            $unansweredPct = $totalCompleted > 0 ? round(($unanswered / $totalCompleted) * 100, 1) : 0.0;

            $summary[] = [
                'question_id' => $qid,
                'display_order' => (int) $q['display_order'],
                'question_text' => (string) $q['question_text'],
                'question_type' => 'mcq',
                'total_attempts' => $totalCompleted,
                'answered_count' => $answered,
                'correct_count' => $correct,
                'correct_pct' => $correctPct,
                'incorrect_count' => $incorrect,
                'incorrect_pct' => $incorrectPct,
                'unanswered_count' => $unanswered,
                'unanswered_pct' => $unansweredPct,
            ];
        }

        return $summary;
    }
}
