<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MetricsCollector;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class MetricsCollectorTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function test_metrics_collector_collects_arrays_and_prometheus_format(): void
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
                if ($key === 'dirty_att') {
                    return 42;
                }
                if ($key === 'dirty') {
                    return 150;
                }
                if ($key === 'warm_quizzes') {
                    return 2;
                }
                return 0;
            }

            public function lLen(string $key): int
            {
                if ($key === 'fq') {
                    return 5;
                }
                return 0;
            }

            public function info(): array
            {
                return [
                    'used_memory' => 104857600,
                    'connected_clients' => 12,
                    'instantaneous_ops_per_sec' => 350,
                    'keyspace_hits' => 900,
                    'keyspace_misses' => 100,
                ];
            }
        };

        $collector = new MetricsCollector($this->db, $mockRedis);
        $data = $collector->collect();

        $this->assertIsArray($data);
        $this->assertTrue($data['workers']['flusher']['healthy']);
        $this->assertFalse($data['workers']['finalizer']['healthy']);
        $this->assertSame(42, $data['queues']['dirty_attempts']);
        $this->assertSame(150, $data['queues']['dirty_answers']);
        $this->assertSame(5, $data['queues']['finalizer_queue']);
        $this->assertSame(2, $data['queues']['warm_quizzes']);
        $this->assertTrue($data['redis']['connected']);
        $this->assertSame(104857600, $data['redis']['used_memory']);

        $promText = $collector->toPrometheus();
        $this->assertStringContainsString('quiz_worker_status{worker="flusher"} 1', $promText);
        $this->assertStringContainsString('quiz_worker_status{worker="finalizer"} 0', $promText);
        $this->assertStringContainsString('quiz_queue_dirty_attempts 42', $promText);
        $this->assertStringContainsString('quiz_queue_dirty_answers 150', $promText);
        $this->assertStringContainsString('quiz_queue_finalizer_length 5', $promText);
        $this->assertStringContainsString('quiz_redis_used_memory_bytes 104857600', $promText);
        $this->assertStringContainsString('quiz_db_up 1', $promText);
    }
}
