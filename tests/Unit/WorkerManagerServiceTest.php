<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MetricsCollector;
use App\Services\WorkerManagerService;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class WorkerManagerServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function test_worker_manager_status_contains_all_four_workers(): void
    {
        $mockRedis = new class {
            public function get(string $key)
            {
                if ($key === 'worker:heartbeat:flusher') {
                    return (string) time();
                }
                return null;
            }

            public function sCard(string $key): int
            {
                return 0;
            }

            public function lLen(string $key): int
            {
                return 0;
            }

            public function info(): array
            {
                return [
                    'used_memory' => 50000000,
                    'connected_clients' => 5,
                    'instantaneous_ops_per_sec' => 100,
                    'keyspace_hits' => 10,
                    'keyspace_misses' => 2,
                ];
            }
        };

        $collector = new MetricsCollector($this->db, $mockRedis);
        $manager = new WorkerManagerService($this->db, $mockRedis, $collector);

        $status = $manager->getStatus();

        $this->assertIsArray($status);
        $this->assertArrayHasKey('workers', $status);
        $this->assertArrayHasKey('flusher', $status['workers']);
        $this->assertArrayHasKey('finalizer', $status['workers']);
        $this->assertArrayHasKey('scheduler', $status['workers']);
        $this->assertArrayHasKey('jobs', $status['workers']);

        $this->assertTrue($status['workers']['flusher']['healthy']);
        $this->assertFalse($status['workers']['finalizer']['healthy']);
    }

    public function test_run_once_executes_safely(): void
    {
        $mockRedis = new class {
            public function sPop(string $key, int $limit = 500)
            {
                return [];
            }
            public function lPop(string $key)
            {
                return null;
            }
            public function setEx(string $key, int $ttl, mixed $val): bool
            {
                return true;
            }
            public function sMembers(string $key): array
            {
                return [];
            }
            public function get(string $key)
            {
                return null;
            }
            public function sCard(string $key): int
            {
                return 0;
            }
            public function lLen(string $key): int
            {
                return 0;
            }
            public function info(): array
            {
                return [];
            }
        };

        $collector = new MetricsCollector($this->db, $mockRedis);
        $manager = new WorkerManagerService($this->db, $mockRedis, $collector);

        $resFlusher = $manager->runOnce('flusher');
        $this->assertTrue($resFlusher['success']);
        $this->assertStringContainsString('Flusher pass complete', $resFlusher['message']);

        $resFinalizer = $manager->runOnce('finalizer');
        $this->assertTrue($resFinalizer['success']);
        $this->assertStringContainsString('Finalizer completed single pass', $resFinalizer['message']);

        $resJobs = $manager->runOnce('jobs');
        $this->assertTrue($resJobs['success']);
        $this->assertStringContainsString('Jobs worker completed single pass', $resJobs['message']);
    }

    public function test_get_logs_returns_string(): void
    {
        $manager = new WorkerManagerService($this->db);
        $logs = $manager->getLogs('flusher', 10);
        $this->assertIsString($logs);
        $this->assertNotEmpty($logs);
    }
}
