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
}
