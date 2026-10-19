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
     * Build reusable WHERE clause fragments for attempts and employees based on filters.
     *
     * @param array<string, mixed> $filters
     * @return array{where_sql: string, params: array<string, mixed>}
     */
    public function buildFilterConditions(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        // Exclude test accounts if requested
        $excludeTest = !empty($filters['exclude_test_accounts']);
        if ($excludeTest) {
            $where[] = "(e.employee_code NOT LIKE 'TEST%' AND e.email NOT LIKE 'test_%' AND e.email NOT LIKE 'test@%')";
        }

        // Zone filter
        if (!empty($filters['zone'])) {
            $where[] = 'e.zone_region = :f_zone';
            $params['f_zone'] = (string) $filters['zone'];
        }

        // Designation filter
        if (!empty($filters['designation'])) {
            $where[] = 'e.designation = :f_desig';
            $params['f_desig'] = (string) $filters['designation'];
        }

        return [
            'where_sql' => implode(' AND ', $where),
            'params' => $params,
        ];
    }

    /**
     * Get Overall Summary metrics for a quiz, excluding test accounts and respecting filters.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getOverallSummary(int $quizId, array $filters = []): array
    {
        $cond = $this->buildFilterConditions($filters);
        $whereSql = $cond['where_sql'];
        $params = array_merge(['qid' => $quizId], $cond['params']);

        $sql = "SELECT "
            . "COUNT(*) AS eligible_count, "
            . "COUNT(CASE WHEN a.status = 'NOT_STARTED' THEN 1 END) AS not_started_count, "
            . "COUNT(CASE WHEN a.status IN ('IN_PROGRESS', 'COMPLETED') THEN 1 END) AS started_count, "
            . "COUNT(CASE WHEN a.status = 'IN_PROGRESS' THEN 1 END) AS in_progress_count, "
            . "COUNT(CASE WHEN a.status = 'COMPLETED' THEN 1 END) AS completed_count, "
            . "COUNT(CASE WHEN a.status = 'ABSENT' THEN 1 END) AS absent_count, "
            . "AVG(CASE WHEN a.status = 'COMPLETED' THEN a.score END) AS avg_score, "
            . "AVG(CASE WHEN a.status = 'COMPLETED' THEN a.accuracy END) AS avg_accuracy, "
            . "AVG(CASE WHEN a.status = 'COMPLETED' THEN a.completion_time_s END) AS avg_time_s "
            . "FROM attempts a "
            . "JOIN employees e ON e.id = a.employee_id "
            . "WHERE a.quiz_id = :qid AND {$whereSql}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $eligible = (int) ($row['eligible_count'] ?? 0);
        $started = (int) ($row['started_count'] ?? 0);
        $completed = (int) ($row['completed_count'] ?? 0);
        $inProgress = (int) ($row['in_progress_count'] ?? 0);
        $notStarted = (int) ($row['not_started_count'] ?? 0);
        $absent = (int) ($row['absent_count'] ?? 0);

        $participationPct = $eligible > 0 ? round(($completed / $eligible) * 100, 1) : 0.0;
        $startedPct = $eligible > 0 ? round(($started / $eligible) * 100, 1) : 0.0;
        $avgScore = round((float) ($row['avg_score'] ?? 0.0), 2);
        $avgAccuracy = round((float) ($row['avg_accuracy'] ?? 0.0), 1);
        $avgTimeS = (int) round((float) ($row['avg_time_s'] ?? 0.0));

        // Pass / Fail breakdown
        $passRate = $this->calculatePassRate($quizId, $completed);

        return [
            'quiz_id' => $quizId,
            'eligible_count' => $eligible,
            'started_count' => $started,
            'started_pct' => $startedPct,
            'completed_count' => $completed,
            'participation_pct' => $participationPct,
            'in_progress_count' => $inProgress,
            'not_started_count' => $notStarted,
            'absent_count' => $absent,
            'avg_score' => $avgScore,
            'avg_accuracy' => $avgAccuracy,
            'avg_time_s' => $avgTimeS,
            'pass_rate' => $passRate,
        ];
    }

    /**
     * Get Zone-wise summary for a quiz with participation statuses, percentage, and avg score.
     * Includes a consolidated TOTAL row at the bottom.
     *
     * @param array<string, mixed> $filters
     * @return array{zones: array<int, array<string, mixed>>, total: array<string, mixed>}
     */
    public function getZoneSummary(int $quizId, array $filters = []): array
    {
        $cond = $this->buildFilterConditions($filters);
        $whereSql = $cond['where_sql'];
        $params = array_merge(['qid' => $quizId], $cond['params']);

        $sql = "SELECT "
            . "COALESCE(NULLIF(TRIM(e.zone_region), ''), 'Unassigned') AS zone_name, "
            . "COUNT(*) AS eligible_count, "
            . "COUNT(CASE WHEN a.status = 'NOT_STARTED' THEN 1 END) AS not_started_count, "
            . "COUNT(CASE WHEN a.status = 'IN_PROGRESS' THEN 1 END) AS in_progress_count, "
            . "COUNT(CASE WHEN a.status = 'COMPLETED' THEN 1 END) AS completed_count, "
            . "COUNT(CASE WHEN a.status = 'ABSENT' THEN 1 END) AS absent_count, "
            . "AVG(CASE WHEN a.status = 'COMPLETED' THEN a.score END) AS avg_score, "
            . "AVG(CASE WHEN a.status = 'COMPLETED' THEN a.accuracy END) AS avg_accuracy "
            . "FROM attempts a "
            . "JOIN employees e ON e.id = a.employee_id "
            . "WHERE a.quiz_id = :qid AND {$whereSql} "
            . "GROUP BY zone_name "
            . "ORDER BY zone_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $zones = [];
        $totEligible = 0;
        $totNotStarted = 0;
        $totInProgress = 0;
        $totCompleted = 0;
        $totAbsent = 0;
        $sumScoreWeighted = 0.0;
        $sumAccuracyWeighted = 0.0;

        foreach ($rows as $r) {
            $el = (int) ($r['eligible_count'] ?? 0);
            $comp = (int) ($r['completed_count'] ?? 0);
            $ns = (int) ($r['not_started_count'] ?? 0);
            $ip = (int) ($r['in_progress_count'] ?? 0);
            $ab = (int) ($r['absent_count'] ?? 0);
            $sc = round((float) ($r['avg_score'] ?? 0.0), 2);
            $acc = round((float) ($r['avg_accuracy'] ?? 0.0), 1);
            $partPct = $el > 0 ? round(($comp / $el) * 100, 1) : 0.0;

            $zones[] = [
                'zone_name' => (string) $r['zone_name'],
                'eligible_count' => $el,
                'not_started_count' => $ns,
                'in_progress_count' => $ip,
                'completed_count' => $comp,
                'absent_count' => $ab,
                'participation_pct' => $partPct,
                'avg_score' => $sc,
                'avg_accuracy' => $acc,
            ];

            $totEligible += $el;
            $totNotStarted += $ns;
            $totInProgress += $ip;
            $totCompleted += $comp;
            $totAbsent += $ab;
            $sumScoreWeighted += ($sc * $comp);
            $sumAccuracyWeighted += ($acc * $comp);
        }

        $overallPartPct = $totEligible > 0 ? round(($totCompleted / $totEligible) * 100, 1) : 0.0;
        $overallAvgScore = $totCompleted > 0 ? round($sumScoreWeighted / $totCompleted, 2) : 0.0;
        $overallAvgAccuracy = $totCompleted > 0 ? round($sumAccuracyWeighted / $totCompleted, 1) : 0.0;

        $total = [
            'zone_name' => 'Consolidated Total',
            'eligible_count' => $totEligible,
            'not_started_count' => $totNotStarted,
            'in_progress_count' => $totInProgress,
            'completed_count' => $totCompleted,
            'absent_count' => $totAbsent,
            'participation_pct' => $overallPartPct,
            'avg_score' => $overallAvgScore,
            'avg_accuracy' => $overallAvgAccuracy,
        ];

        return [
            'zones' => $zones,
            'total' => $total,
        ];
    }

    /**
     * Get question-wise summary with accurate randomized assignment detection.
     * Separates Assigned vs Not Assigned based on attempts.layout['q'].
     * Computes MCQ Answer Accuracy = Correct Answers / Answered Count.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getQuestionWiseSummary(int $quizId, array $filters = []): array
    {
        $cond = $this->buildFilterConditions($filters);
        $whereSql = $cond['where_sql'];
        $params = array_merge(['qid' => $quizId], $cond['params']);

        // 1. Fetch completed attempts for the quiz
        $attStmt = $this->db->prepare(
            "SELECT a.id, a.layout, COALESCE(NULLIF(TRIM(e.zone_region), ''), 'Unassigned') AS zone_region "
            . "FROM attempts a "
            . "JOIN employees e ON e.id = a.employee_id "
            . "WHERE a.quiz_id = :qid AND a.status = 'COMPLETED' AND {$whereSql}"
        );
        $attStmt->execute($params);
        $completedAttempts = $attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalCompleted = count($completedAttempts);

        // 2. Fetch all questions for this quiz (direct or via question bank)
        $qStmt = $this->db->prepare(
            'SELECT DISTINCT q.id, q.display_order, q.question_text, '
            . "COALESCE(q.type, 'mcq') AS question_type, q.image_path "
            . 'FROM questions q '
            . 'WHERE q.quiz_id = :qid '
            . '   OR (q.question_bank_id IS NOT NULL AND q.question_bank_id = (SELECT question_bank_id FROM quizzes WHERE id = :qid2)) '
            . '   OR q.id IN (SELECT aa.question_id FROM attempt_answers aa JOIN attempts a ON a.id = aa.attempt_id WHERE a.quiz_id = :qid3) '
            . 'ORDER BY q.display_order ASC, q.id ASC'
        );
        $qStmt->execute(['qid' => $quizId, 'qid2' => $quizId, 'qid3' => $quizId]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($questions)) {
            return [];
        }

        // Fetch correct option text for each question
        $optStmt = $this->db->query(
            "SELECT question_id, option_text FROM answer_options WHERE is_correct = 1"
        );
        $correctOptions = [];
        while ($opt = $optStmt->fetch(PDO::FETCH_ASSOC)) {
            $correctOptions[(int) $opt['question_id']] = (string) $opt['option_text'];
        }

        // 3. Map assignment counts using layout['q']
        $assignedMap = [];
        $allQuestionIds = array_map(fn($q) => (int) $q['id'], $questions);

        foreach ($completedAttempts as $att) {
            $layoutRaw = $att['layout'] ?? null;
            $layout = is_string($layoutRaw) ? json_decode($layoutRaw, true) : $layoutRaw;

            if (is_array($layout) && !empty($layout['q']) && is_array($layout['q'])) {
                foreach ($layout['q'] as $qid) {
                    $qidInt = (int) $qid;
                    $assignedMap[$qidInt] = ($assignedMap[$qidInt] ?? 0) + 1;
                }
            } else {
                // If layout is not stored or flat, question is assigned to all attempts
                foreach ($allQuestionIds as $qidInt) {
                    $assignedMap[$qidInt] = ($assignedMap[$qidInt] ?? 0) + 1;
                }
            }
        }

        // 4. Query answers for completed attempts
        $ansSql = "SELECT "
            . "aa.question_id, "
            . "COUNT(CASE WHEN aa.selected_option_id IS NOT NULL OR (aa.text_answer IS NOT NULL AND TRIM(aa.text_answer) != '') THEN 1 END) AS answered_count, "
            . "SUM(CASE WHEN aa.is_correct = 1 THEN 1 ELSE 0 END) AS correct_count, "
            . "SUM(CASE WHEN aa.is_correct = 0 AND (aa.selected_option_id IS NOT NULL OR (aa.text_answer IS NOT NULL AND TRIM(aa.text_answer) != '')) THEN 1 ELSE 0 END) AS incorrect_count "
            . "FROM attempt_answers aa "
            . "JOIN attempts a ON a.id = aa.attempt_id "
            . "JOIN employees e ON e.id = a.employee_id "
            . "WHERE a.quiz_id = :qid AND a.status = 'COMPLETED' AND {$whereSql} "
            . "GROUP BY aa.question_id";

        $ansStmt = $this->db->prepare($ansSql);
        $ansStmt->execute($params);
        $ansStats = [];
        while ($row = $ansStmt->fetch(PDO::FETCH_ASSOC)) {
            $ansStats[(int) $row['question_id']] = $row;
        }

        $summary = [];
        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $stat = $ansStats[$qid] ?? null;

            $assigned = $assignedMap[$qid] ?? 0;
            // If quiz has no randomized bank or 0 assigned recorded, fallback to total completed
            if ($assigned === 0 && empty($assignedMap)) {
                $assigned = $totalCompleted;
            }
            $notAssigned = max(0, $totalCompleted - $assigned);

            $answered = $stat ? (int) ($stat['answered_count'] ?? 0) : 0;
            $correct = $stat ? (int) ($stat['correct_count'] ?? 0) : 0;
            $incorrect = $stat ? (int) ($stat['incorrect_count'] ?? 0) : 0;
            $assignedUnanswered = max(0, $assigned - $answered);

            // MCQ Accuracy = correct answers / recorded MCQ answers
            $correctPct = $answered > 0 ? round(($correct / $answered) * 100, 1) : 0.0;
            $incorrectPct = $answered > 0 ? round(($incorrect / $answered) * 100, 1) : 0.0;
            $unansweredPct = $assigned > 0 ? round(($assignedUnanswered / $assigned) * 100, 1) : 0.0;

            $qType = (string) $q['question_type'];
            if (!empty($q['image_path'])) {
                $qType = 'picture';
            }

            $summary[] = [
                'question_id' => $qid,
                'display_order' => (int) $q['display_order'],
                'question_text' => (string) $q['question_text'],
                'question_type' => $qType,
                'image_path' => $q['image_path'] ?? null,
                'correct_answer' => $correctOptions[$qid] ?? '—',
                'total_attempts' => $totalCompleted,
                'assigned_count' => $assigned,
                'not_assigned_count' => $notAssigned,
                'answered_count' => $answered,
                'correct_count' => $correct,
                'correct_pct' => $correctPct,
                'incorrect_count' => $incorrect,
                'incorrect_pct' => $incorrectPct,
                'unanswered_count' => $assignedUnanswered,
                'unanswered_pct' => $unansweredPct,
            ];
        }

        return $summary;
    }

    /**
     * Get Score Summary metrics including weighted score out of 50.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getScoreSummary(int $quizId, array $filters = []): array
    {
        $overall = $this->getOverallSummary($quizId, $filters);
        $totalCompleted = $overall['completed_count'];

        // Fetch quiz scoring settings and question count
        $stmt = $this->db->prepare(
            'SELECT settings, '
            . '(SELECT COUNT(*) FROM questions WHERE quiz_id = :qid OR (question_bank_id IS NOT NULL AND question_bank_id = (SELECT question_bank_id FROM quizzes WHERE id = :qid2))) AS total_questions '
            . 'FROM quizzes WHERE id = :qid3'
        );
        $stmt->execute(['qid' => $quizId, 'qid2' => $quizId, 'qid3' => $quizId]);
        $qRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $settings = json_decode((string) ($qRow['settings'] ?? '{}'), true) ?? [];
        $scoring = $settings['scoring'] ?? [];

        $marksPerCorrect = (float) ($scoring['marks_per_correct'] ?? 1.0);
        $totalQ = max(1, (int) ($qRow['total_questions'] ?? 10));
        $maxMarks = max(1.0, $marksPerCorrect * $totalQ);

        // Fetch zero-scored slots and score distribution
        $cond = $this->buildFilterConditions($filters);
        $whereSql = $cond['where_sql'];
        $params = array_merge(['qid' => $quizId], $cond['params']);

        $sql = "SELECT "
            . "COUNT(CASE WHEN a.score <= 0 THEN 1 END) AS zero_scored_count, "
            . "COUNT(CASE WHEN a.score > 0 AND a.score <= 10 THEN 1 END) AS bracket_0_10, "
            . "COUNT(CASE WHEN a.score > 10 AND a.score <= 20 THEN 1 END) AS bracket_10_20, "
            . "COUNT(CASE WHEN a.score > 20 AND a.score <= 30 THEN 1 END) AS bracket_20_30, "
            . "COUNT(CASE WHEN a.score > 30 AND a.score <= 40 THEN 1 END) AS bracket_30_40, "
            . "COUNT(CASE WHEN a.score > 40 THEN 1 END) AS bracket_40_50 "
            . "FROM attempts a "
            . "JOIN employees e ON e.id = a.employee_id "
            . "WHERE a.quiz_id = :qid AND a.status = 'COMPLETED' AND {$whereSql}";

        $distStmt = $this->db->prepare($sql);
        $distStmt->execute($params);
        $dist = $distStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $avgRaw = $overall['avg_score'];
        $avgWeighted50 = round(($avgRaw / $maxMarks) * 50.0, 2);

        return [
            'total_completed' => $totalCompleted,
            'max_marks' => $maxMarks,
            'avg_raw_score' => $avgRaw,
            'avg_weighted_50' => $avgWeighted50,
            'zero_scored_count' => (int) ($dist['zero_scored_count'] ?? 0),
            'scoring_formula' => 'Weighted Score = (Raw Score / ' . $maxMarks . ') × 50',
            'brackets' => [
                '0-10' => (int) ($dist['bracket_0_10'] ?? 0),
                '11-20' => (int) ($dist['bracket_10_20'] ?? 0),
                '21-30' => (int) ($dist['bracket_20_30'] ?? 0),
                '31-40' => (int) ($dist['bracket_30_40'] ?? 0),
                '41-50' => (int) ($dist['bracket_40_50'] ?? 0),
            ],
        ];
    }

    /**
     * Get Granular Participant Results for the selected quiz.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getParticipantResults(int $quizId, array $filters = [], int $limit = 5000): array
    {
        $cond = $this->buildFilterConditions($filters);
        $whereSql = $cond['where_sql'];
        $params = array_merge(['qid' => $quizId], $cond['params']);

        // Rank subquery
        $rankSubquery = "SELECT id, DENSE_RANK() OVER ( "
            . "ORDER BY COALESCE(score, 0) DESC, "
            . "COALESCE(completion_time_s, 999999) ASC, "
            . "COALESCE(accuracy, 0) DESC, "
            . "started_at ASC, id ASC "
            . ") AS `rank` "
            . "FROM attempts WHERE quiz_id = :rank_qid AND status = 'COMPLETED'";

        $sql = "SELECT a.id, a.status, a.score, a.accuracy, a.completion_time_s, "
            . "a.started_at, a.submitted_at, "
            . "e.employee_code, e.name AS employee_name, e.email, "
            . "COALESCE(NULLIF(TRIM(e.zone_region), ''), 'Unassigned') AS zone_region, "
            . "COALESCE(NULLIF(TRIM(e.designation), ''), 'Staff') AS designation, "
            . "COALESCE(NULLIF(TRIM(e.department), ''), 'General') AS department, "
            . "r.`rank` "
            . "FROM attempts a "
            . "JOIN employees e ON e.id = a.employee_id "
            . "LEFT JOIN ({$rankSubquery}) r ON r.id = a.id "
            . "WHERE a.quiz_id = :qid AND {$whereSql} "
            . "ORDER BY CASE WHEN r.`rank` IS NOT NULL THEN 0 ELSE 1 END ASC, "
            . "r.`rank` ASC, a.id ASC "
            . "LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge($params, ['rank_qid' => $quizId]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fetch max marks for 50 scaling
        $qStmt = $this->db->prepare(
            'SELECT settings, '
            . '(SELECT COUNT(*) FROM questions WHERE quiz_id = :qid OR (question_bank_id IS NOT NULL AND question_bank_id = (SELECT question_bank_id FROM quizzes WHERE id = :qid2))) AS total_questions '
            . 'FROM quizzes WHERE id = :qid3'
        );
        $qStmt->execute(['qid' => $quizId, 'qid2' => $quizId, 'qid3' => $quizId]);
        $qRow = $qStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $settings = json_decode((string) ($qRow['settings'] ?? '{}'), true) ?? [];
        $marksPerCorrect = (float) ($settings['scoring']['marks_per_correct'] ?? 1.0);
        $maxMarks = max(1.0, $marksPerCorrect * max(1, (int) ($qRow['total_questions'] ?? 10)));

        $results = [];
        foreach ($rows as $row) {
            $rawScore = $row['score'] !== null ? (float) $row['score'] : null;
            $weighted50 = $rawScore !== null ? round(($rawScore / $maxMarks) * 50.0, 2) : null;

            $results[] = [
                'rank' => $row['rank'] !== null ? (int) $row['rank'] : null,
                'employee_code' => (string) $row['employee_code'],
                'employee_name' => (string) $row['employee_name'],
                'email' => (string) $row['email'],
                'zone' => (string) $row['zone_region'],
                'designation' => (string) $row['designation'],
                'department' => (string) $row['department'],
                'status' => (string) $row['status'],
                'raw_score' => $rawScore,
                'weighted_score_50' => $weighted50,
                'accuracy' => $row['accuracy'] !== null ? (float) $row['accuracy'] : null,
                'completion_time_s' => $row['completion_time_s'] !== null ? (int) $row['completion_time_s'] : null,
                'started_at' => $row['started_at'] ?? null,
                'submitted_at' => $row['submitted_at'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Get Written Survey & Common Feedback Responses for a quiz.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getWrittenResponses(int $quizId, array $filters = []): array
    {
        $cond = $this->buildFilterConditions($filters);
        $whereSql = $cond['where_sql'];
        $params = array_merge(['qid' => $quizId], $cond['params']);

        // 1. Written questions answers
        $sql = "SELECT "
            . "e.employee_code, e.name AS employee_name, "
            . "COALESCE(NULLIF(TRIM(e.zone_region), ''), 'Unassigned') AS zone_region, "
            . "COALESCE(NULLIF(TRIM(e.designation), ''), 'Staff') AS designation, "
            . "q.id AS question_id, q.display_order, q.question_text, "
            . "'Written Survey' AS section_label, "
            . "aa.text_answer AS response_text, "
            . "a.submitted_at "
            . "FROM attempt_answers aa "
            . "JOIN attempts a ON a.id = aa.attempt_id "
            . "JOIN employees e ON e.id = a.employee_id "
            . "JOIN questions q ON q.id = aa.question_id "
            . "WHERE a.quiz_id = :qid AND q.type = 'written' AND aa.text_answer IS NOT NULL AND {$whereSql} "
            . "ORDER BY e.employee_code ASC, q.display_order ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $writtenRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2. Common feedback answers
        $fbSql = "SELECT "
            . "e.employee_code, e.name AS employee_name, "
            . "COALESCE(NULLIF(TRIM(e.zone_region), ''), 'Unassigned') AS zone_region, "
            . "COALESCE(NULLIF(TRIM(e.designation), ''), 'Staff') AS designation, "
            . "0 AS question_id, 999 AS display_order, "
            . "COALESCE(qz.feedback_question, 'General Feedback') AS question_text, "
            . "'Common Feedback' AS section_label, "
            . "a.feedback AS response_text, "
            . "a.submitted_at "
            . "FROM attempts a "
            . "JOIN employees e ON e.id = a.employee_id "
            . "JOIN quizzes qz ON qz.id = a.quiz_id "
            . "WHERE a.quiz_id = :qid AND a.feedback IS NOT NULL AND TRIM(a.feedback) != '' AND {$whereSql} "
            . "ORDER BY e.employee_code ASC";

        $fbStmt = $this->db->prepare($fbSql);
        $fbStmt->execute($params);
        $fbRows = $fbStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_merge($writtenRows, $fbRows);
    }
}
