<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Redis as HotRedis;
use App\Services\DashboardService;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class DashboardServiceTest extends TestCase
{
    private PDO $db;
    private DashboardService $service;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->service = new DashboardService($this->db);
    }

    public function test_get_global_kpis_returns_all_nine_metrics(): void
    {
        $kpis = $this->service->getGlobalKpis();

        $this->assertArrayHasKey('total_employees', $kpis);
        $this->assertArrayHasKey('total_quizzes', $kpis);
        $this->assertArrayHasKey('published_quizzes', $kpis);
        $this->assertArrayHasKey('completed_attempts', $kpis);
        $this->assertArrayHasKey('in_progress_attempts', $kpis);
        $this->assertArrayHasKey('absent_employees', $kpis);
        $this->assertArrayHasKey('avg_score', $kpis);
        $this->assertArrayHasKey('avg_accuracy', $kpis);
        $this->assertArrayHasKey('avg_completion_time_s', $kpis);

        $this->assertGreaterThanOrEqual(0, $kpis['total_employees']);
        $this->assertGreaterThanOrEqual(0, $kpis['total_quizzes']);
    }

    public function test_get_quiz_kpis_from_mysql(): void
    {
        // Get an existing quiz ID from DB
        $stmt = $this->db->query('SELECT id FROM quizzes LIMIT 1');
        $quizId = (int) $stmt->fetchColumn();

        if ($quizId === 0) {
            $this->markTestSkipped('No quiz found in database.');
        }

        $kpis = $this->service->getQuizKpis($quizId);

        $this->assertSame($quizId, $kpis['quiz_id']);
        $this->assertArrayHasKey('total_eligible', $kpis);
        $this->assertArrayHasKey('not_started', $kpis);
        $this->assertArrayHasKey('in_progress', $kpis);
        $this->assertArrayHasKey('completed', $kpis);
        $this->assertArrayHasKey('absent', $kpis);
        $this->assertArrayHasKey('avg_score', $kpis);
        $this->assertArrayHasKey('avg_accuracy', $kpis);
        $this->assertArrayHasKey('avg_completion_time_s', $kpis);
        $this->assertArrayHasKey('pass_rate', $kpis);
    }

    public function test_get_quiz_kpis_from_redis_qstat(): void
    {
        try {
            $redis = HotRedis::connection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis connection not available');
        }

        $fakeQuizId = 99998;
        $redis->hMSet("qstat:{$fakeQuizId}", [
            'started' => '50',
            'in_progress' => '20',
            'completed' => '30',
            'absent' => '5',
            'sum_score' => '240.0',
            'sum_accuracy' => '2400.0',
            'sum_time_s' => '3600',
        ]);

        $kpis = $this->service->getQuizKpis($fakeQuizId);

        $this->assertSame($fakeQuizId, $kpis['quiz_id']);
        $this->assertSame('redis', $kpis['source']);
        $this->assertSame(20, $kpis['in_progress']);
        $this->assertSame(30, $kpis['completed']);
        $this->assertSame(5, $kpis['absent']);
        $this->assertSame(8.0, $kpis['avg_score']); // 240 / 30 = 8.0
        $this->assertSame(80.0, $kpis['avg_accuracy']); // 2400 / 30 = 80.0
        $this->assertSame(120, $kpis['avg_completion_time_s']); // 3600 / 30 = 120

        // Clean up
        $redis->del("qstat:{$fakeQuizId}");
    }

    public function test_overall_summary_retains_employees_with_null_email_when_excluding_test(): void
    {
        $stmt = $this->db->query('SELECT id FROM quizzes LIMIT 1');
        $quizId = (int) $stmt->fetchColumn();

        if ($quizId === 0) {
            $this->markTestSkipped('No quiz found in database.');
        }

        $summary = $this->service->getOverallSummary($quizId, ['exclude_test_accounts' => true]);

        $this->assertNotNull($summary);
        $this->assertArrayHasKey('eligible_employees', $summary);
        $this->assertGreaterThan(0, $summary['eligible_employees'], 'Eligible employees must not be zero due to NULL email filter trap');
    }

    public function test_score_summary_weighted_benchmarks(): void
    {
        $stmt = $this->db->query('SELECT id FROM quizzes LIMIT 1');
        $quizId = (int) $stmt->fetchColumn();

        if ($quizId === 0) {
            $this->markTestSkipped('No quiz found in database.');
        }

        $scoreSummary = $this->service->getScoreSummary($quizId, ['exclude_test_accounts' => true]);

        $this->assertNotNull($scoreSummary);
        $this->assertArrayHasKey('raw_average', $scoreSummary);
        $this->assertArrayHasKey('weighted_average_50', $scoreSummary);
        $this->assertArrayHasKey('max_possible_score', $scoreSummary);
        $this->assertArrayHasKey('zero_scored_count', $scoreSummary);
        $this->assertArrayHasKey('distribution', $scoreSummary);
        $this->assertArrayHasKey('bracket_lt_20', $scoreSummary['distribution']);
        $this->assertArrayHasKey('bracket_20_29', $scoreSummary['distribution']);
        $this->assertArrayHasKey('bracket_30_39', $scoreSummary['distribution']);
        $this->assertArrayHasKey('bracket_40_50', $scoreSummary['distribution']);
    }

    public function test_question_wise_summary_assignment_counts(): void
    {
        $stmt = $this->db->query('SELECT id FROM quizzes LIMIT 1');
        $quizId = (int) $stmt->fetchColumn();

        if ($quizId === 0) {
            $this->markTestSkipped('No quiz found in database.');
        }

        $questions = $this->service->getQuestionWiseSummary($quizId, ['exclude_test_accounts' => true]);

        if (empty($questions)) {
            $this->markTestSkipped('No questions found for quiz.');
        }

        foreach ($questions as $q) {
            $this->assertArrayHasKey('assigned_count', $q);
            $this->assertArrayHasKey('answered_count', $q);
            $this->assertArrayHasKey('correct_count', $q);
            $this->assertArrayHasKey('incorrect_count', $q);
            $this->assertArrayHasKey('not_assigned', $q);
            $this->assertGreaterThanOrEqual(0, $q['assigned_count']);
        }
    }
}
