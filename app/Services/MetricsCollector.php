<?php

declare(strict_types=1);

namespace App\Services;

use App\Hot\Redis as HotRedis;
use Core\Database;
use PDO;

/**
 * High-performance Metrics Collector for CorpQuiz.
 * Gathers system, queue, worker, and database metrics with zero framework overhead.
 */
class MetricsCollector
{
    private ?PDO $db;
    /** @var mixed */
    private $redis;

    public function __construct(?PDO $db = null, $redis = null)
    {
        $this->db = $db;
        $this->redis = $redis;
    }

    /**
     * Collect all metrics into a structured array.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $now = time();
        $metrics = [
            'timestamp' => $now,
            'workers' => $this->collectWorkerMetrics($now),
            'queues' => $this->collectQueueMetrics(),
            'redis' => $this->collectRedisMetrics(),
            'database' => $this->collectDatabaseMetrics(),
        ];

        return $metrics;
    }

    /**
     * Format collected metrics into standard Prometheus text format.
     */
    public function toPrometheus(): string
    {
        $data = $this->collect();
        $lines = [];

        $lines[] = '# ==============================================================================';
        $lines[] = '# CorpQuiz Application & Worker Prometheus Metrics';
        $lines[] = '# ==============================================================================';
        $lines[] = '';

        // 1. Worker Heartbeats & Liveness
        $lines[] = '# HELP quiz_worker_status Worker liveness status (1 = healthy/running, 0 = down/hung)';
        $lines[] = '# TYPE quiz_worker_status gauge';
        foreach ($data['workers'] as $worker => $info) {
            $status = $info['healthy'] ? 1 : 0;
            $lines[] = "quiz_worker_status{worker=\"{$worker}\"} {$status}";
        }
        $lines[] = '';

        $lines[] = '# HELP quiz_worker_heartbeat_age_seconds Seconds since last recorded heartbeat';
        $lines[] = '# TYPE quiz_worker_heartbeat_age_seconds gauge';
        foreach ($data['workers'] as $worker => $info) {
            $age = $info['age_seconds'] >= 0 ? $info['age_seconds'] : 999999;
            $lines[] = "quiz_worker_heartbeat_age_seconds{worker=\"{$worker}\"} {$age}";
        }
        $lines[] = '';

        $lines[] = '# HELP quiz_worker_heartbeat_timestamp Epoch timestamp of last worker heartbeat';
        $lines[] = '# TYPE quiz_worker_heartbeat_timestamp gauge';
        foreach ($data['workers'] as $worker => $info) {
            $ts = $info['last_heartbeat'] ?? 0;
            $lines[] = "quiz_worker_heartbeat_timestamp{worker=\"{$worker}\"} {$ts}";
        }
        $lines[] = '';

        // 2. Queue Depths & Lag Indicators
        $lines[] = '# HELP quiz_queue_dirty_attempts Dirty attempts awaiting MySQL flush (write-behind lag)';
        $lines[] = '# TYPE quiz_queue_dirty_attempts gauge';
        $lines[] = 'quiz_queue_dirty_attempts ' . ($data['queues']['dirty_attempts'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP quiz_queue_dirty_answers Dirty answers awaiting MySQL flush (write-behind lag)';
        $lines[] = '# TYPE quiz_queue_dirty_answers gauge';
        $lines[] = 'quiz_queue_dirty_answers ' . ($data['queues']['dirty_answers'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP quiz_queue_finalizer_length Submissions queued for grading and finalization (fq)';
        $lines[] = '# TYPE quiz_queue_finalizer_length gauge';
        $lines[] = 'quiz_queue_finalizer_length ' . ($data['queues']['finalizer_queue'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP quiz_queue_dead_letter_length Items failed during write-behind or grading (dlq)';
        $lines[] = '# TYPE quiz_queue_dead_letter_length gauge';
        $lines[] = 'quiz_queue_dead_letter_length ' . ($data['queues']['dead_letter'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP quiz_warm_quizzes_count Active quizzes currently pre-warmed in Redis cache';
        $lines[] = '# TYPE quiz_warm_quizzes_count gauge';
        $lines[] = 'quiz_warm_quizzes_count ' . ($data['queues']['warm_quizzes'] ?? 0);
        $lines[] = '';

        // 3. Redis Engine Performance
        $redis = $data['redis'];
        $lines[] = '# HELP quiz_redis_up Redis connection availability (1 = connected, 0 = down)';
        $lines[] = '# TYPE quiz_redis_up gauge';
        $lines[] = 'quiz_redis_up ' . ($redis['connected'] ? 1 : 0);
        $lines[] = '';

        if ($redis['connected']) {
            $lines[] = '# HELP quiz_redis_used_memory_bytes Total allocated memory in bytes';
            $lines[] = '# TYPE quiz_redis_used_memory_bytes gauge';
            $lines[] = 'quiz_redis_used_memory_bytes ' . ($redis['used_memory'] ?? 0);
            $lines[] = '';

            $lines[] = '# HELP quiz_redis_connected_clients Number of client connections';
            $lines[] = '# TYPE quiz_redis_connected_clients gauge';
            $lines[] = 'quiz_redis_connected_clients ' . ($redis['connected_clients'] ?? 0);
            $lines[] = '';

            $lines[] = '# HELP quiz_redis_ops_per_sec Instantaneous operations processed per second';
            $lines[] = '# TYPE quiz_redis_ops_per_sec gauge';
            $lines[] = 'quiz_redis_ops_per_sec ' . ($redis['ops_per_sec'] ?? 0);
            $lines[] = '';

            $lines[] = '# HELP quiz_redis_hit_rate_ratio Ratio of keyspace hits to total lookups';
            $lines[] = '# TYPE quiz_redis_hit_rate_ratio gauge';
            $lines[] = 'quiz_redis_hit_rate_ratio ' . number_format((float) ($redis['hit_rate'] ?? 1.0), 4, '.', '');
            $lines[] = '';
        }

        // 4. Database & Attempt Aggregates
        $db = $data['database'];
        $lines[] = '# HELP quiz_db_up MySQL database connection availability (1 = up, 0 = down)';
        $lines[] = '# TYPE quiz_db_up gauge';
        $lines[] = 'quiz_db_up ' . ($db['connected'] ? 1 : 0);
        $lines[] = '';

        if ($db['connected']) {
            $lines[] = '# HELP quiz_attempts_total Total attempts in database grouped by status';
            $lines[] = '# TYPE quiz_attempts_total gauge';
            foreach ($db['attempt_counts'] ?? [] as $status => $count) {
                $statusUpper = strtoupper((string) $status);
                $lines[] = "quiz_attempts_total{status=\"{$statusUpper}\"} {$count}";
            }
            $lines[] = '';

            $lines[] = '# HELP quiz_mysql_threads_connected Active MySQL connected client threads';
            $lines[] = '# TYPE quiz_mysql_threads_connected gauge';
            $lines[] = 'quiz_mysql_threads_connected ' . ($db['threads_connected'] ?? 0);
            $lines[] = '';

            $lines[] = '# HELP quiz_mysql_threads_running Active MySQL threads executing queries';
            $lines[] = '# TYPE quiz_mysql_threads_running gauge';
            $lines[] = 'quiz_mysql_threads_running ' . ($db['threads_running'] ?? 0);
            $lines[] = '';

            $lines[] = '# HELP quiz_mysql_slow_queries_total Cumulative slow queries count';
            $lines[] = '# TYPE quiz_mysql_slow_queries_total counter';
            $lines[] = 'quiz_mysql_slow_queries_total ' . ($db['slow_queries'] ?? 0);
            $lines[] = '';

            $lines[] = '# HELP quiz_jobs_pending_total Pending background worker jobs';
            $lines[] = '# TYPE quiz_jobs_pending_total gauge';
            $lines[] = 'quiz_jobs_pending_total{type="import"} ' . ($db['pending_import_jobs'] ?? 0);
            $lines[] = 'quiz_jobs_pending_total{type="export"} ' . ($db['pending_export_jobs'] ?? 0);
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collectWorkerMetrics(int $now): array
    {
        $workers = ['flusher', 'finalizer', 'scheduler', 'jobs'];
        $res = [];
        $redis = $this->getRedis();

        foreach ($workers as $worker) {
            $ts = null;
            if ($redis) {
                try {
                    $val = $redis->get("worker:heartbeat:{$worker}");
                    if ($val !== false && $val !== null && $val !== '') {
                        $ts = (int) $val;
                    }
                } catch (\Throwable $e) {
                    $ts = null;
                }
            }

            if ($ts !== null && $ts > 0) {
                $age = max(0, $now - $ts);
                $healthy = $age <= 20; // 20s threshold for 200ms-500ms loop workers
            } else {
                $age = -1;
                $healthy = false;
            }

            $res[$worker] = [
                'healthy' => $healthy,
                'last_heartbeat' => $ts ?? 0,
                'age_seconds' => $age,
            ];
        }

        return $res;
    }

    /**
     * @return array<string, int>
     */
    private function collectQueueMetrics(): array
    {
        $res = [
            'dirty_attempts' => 0,
            'dirty_answers' => 0,
            'finalizer_queue' => 0,
            'dead_letter' => 0,
            'warm_quizzes' => 0,
        ];

        $redis = $this->getRedis();
        if (!$redis) {
            return $res;
        }

        try {
            if (method_exists($redis, 'sCard')) {
                $res['dirty_attempts'] = (int) $redis->sCard('dirty_att');
                $res['dirty_answers'] = (int) $redis->sCard('dirty');
                $res['warm_quizzes'] = (int) $redis->sCard('warm_quizzes');

                // Check dlq as set or list
                $dlqSet = (int) $redis->sCard('dlq');
                $res['dead_letter'] = $dlqSet;
            }

            if (method_exists($redis, 'lLen')) {
                $res['finalizer_queue'] = (int) $redis->lLen('fq');
                if ($res['dead_letter'] === 0) {
                    $res['dead_letter'] = (int) $redis->lLen('dlq');
                }
            }
        } catch (\Throwable $e) {
            // Keep zeros on error
        }

        return $res;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectRedisMetrics(): array
    {
        $redis = $this->getRedis();
        if (!$redis) {
            return ['connected' => false];
        }

        try {
            $info = [];
            if (method_exists($redis, 'info')) {
                $info = $redis->info();
            }

            $hits = (int) ($info['keyspace_hits'] ?? 0);
            $misses = (int) ($info['keyspace_misses'] ?? 0);
            $totalLookups = $hits + $misses;
            $hitRate = $totalLookups > 0 ? ($hits / $totalLookups) : 1.0;

            return [
                'connected' => true,
                'used_memory' => (int) ($info['used_memory'] ?? 0),
                'connected_clients' => (int) ($info['connected_clients'] ?? 0),
                'ops_per_sec' => (int) ($info['instantaneous_ops_per_sec'] ?? 0),
                'hit_rate' => $hitRate,
            ];
        } catch (\Throwable $e) {
            return ['connected' => false];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function collectDatabaseMetrics(): array
    {
        $db = $this->getDb();
        if (!$db) {
            return ['connected' => false];
        }

        try {
            // Group attempts by status
            $stmt = $db->query(
                'SELECT status, COUNT(*) AS cnt FROM attempts GROUP BY status'
            );
            $attemptCounts = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $attemptCounts[$row['status']] = (int) $row['cnt'];
            }

            // Pending job counts
            $pImport = (int) $db->query(
                'SELECT COUNT(*) FROM import_jobs WHERE status = "pending"'
            )->fetchColumn();

            $pExport = (int) $db->query(
                'SELECT COUNT(*) FROM export_jobs WHERE status = "pending"'
            )->fetchColumn();

            // MySQL status variables
            $statusVars = [];
            try {
                $statusStmt = $db->query(
                    "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Threads_running', 'Slow_queries')"
                );
                while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
                    $statusVars[$row['Variable_name']] = (int) $row['Value'];
                }
            } catch (\Throwable $e) {
                // Non-fatal if user lacks privilege
            }

            return [
                'connected' => true,
                'attempt_counts' => $attemptCounts,
                'pending_import_jobs' => $pImport,
                'pending_export_jobs' => $pExport,
                'threads_connected' => $statusVars['Threads_connected'] ?? 0,
                'threads_running' => $statusVars['Threads_running'] ?? 0,
                'slow_queries' => $statusVars['Slow_queries'] ?? 0,
            ];
        } catch (\Throwable $e) {
            return ['connected' => false];
        }
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

    private function getDb(): ?PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        try {
            $this->db = Database::getInstance()->getConnection();
            return $this->db;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
