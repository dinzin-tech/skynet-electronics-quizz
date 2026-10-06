<?php

declare(strict_types=1);

namespace App\Services;

use App\Commands\JobsWorkerCommand;
use App\Commands\QuizFinalizerCommand;
use App\Commands\QuizFlusherCommand;
use App\Commands\QuizSchedulerCommand;
use App\Hot\Redis as HotRedis;
use Core\Database;
use PDO;

/**
 * Service to inspect, manage, and execute actions on CorpQuiz background workers.
 */
class WorkerManagerService
{
    private PDO $db;
    /** @var mixed */
    private $redis;
    private MetricsCollector $metricsCollector;

    /** @var array<string, array{name: string, service: string, description: string}> */
    private const WORKERS = [
        'flusher' => [
            'name' => 'Quiz Flusher',
            'service' => 'quiz-flusher',
            'description' => 'Batches dirty attempts and answers from Redis into MySQL (write-behind)',
        ],
        'finalizer' => [
            'name' => 'Quiz Finalizer',
            'service' => 'quiz-finalizer',
            'description' => 'Grades submitted attempts from finalizer queue (fq) and writes scores',
        ],
        'scheduler' => [
            'name' => 'Quiz Scheduler',
            'service' => 'quiz-scheduler',
            'description' => 'Sweeps expired deadlines, flips absent attempts, and pre-warms quizzes',
        ],
        'jobs' => [
            'name' => 'Jobs Worker',
            'service' => 'quiz-jobs',
            'description' => 'Processes async bulk employee imports and report exports',
        ],
    ];

    public function __construct(?PDO $db = null, $redis = null, ?MetricsCollector $collector = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->redis = $redis;
        $this->metricsCollector = $collector ?? new MetricsCollector($this->db, $this->redis);
    }

    /**
     * Get real-time status of all workers, queues, Redis, and MySQL.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        $metrics = $this->metricsCollector->collect();
        $workersStatus = [];

        foreach (self::WORKERS as $key => $meta) {
            $workerInfo = $metrics['workers'][$key] ?? [
                'healthy' => false,
                'last_heartbeat' => 0,
                'age_seconds' => -1,
            ];

            // Inspect systemd service status if on Linux
            $systemdState = $this->getSystemdStatus($meta['service']);

            // Determine health:
            // - Only trust systemd when it gives a definitive answer ('active' or 'failed').
            // - For all other states ('n/a', 'unknown', 'inactive', '') — which includes
            //   services not installed on the current host (e.g. CI runners) — fall back
            //   to the Redis heartbeat so a live heartbeat is never wrongly suppressed.
            if ($systemdState === 'active') {
                $healthy = true;
            } elseif ($systemdState === 'failed') {
                $healthy = false;
            } else {
                $healthy = $workerInfo['healthy'];
            }

            $workersStatus[$key] = [
                'key' => $key,
                'name' => $meta['name'],
                'service' => $meta['service'],
                'description' => $meta['description'],
                'healthy' => $healthy,
                'last_heartbeat' => $workerInfo['last_heartbeat'],
                'age_seconds' => $workerInfo['age_seconds'],
                'systemd_state' => $systemdState,
            ];
        }

        return [
            'timestamp' => $metrics['timestamp'],
            'workers' => $workersStatus,
            'queues' => $metrics['queues'],
            'redis' => $metrics['redis'],
            'database' => $metrics['database'],
        ];
    }

    /**
     * Trigger a single on-demand pass of a worker (Run Once).
     * Works both in production and in local environments without systemd.
     *
     * @return array{success: bool, message: string, details?: mixed}
     */
    public function runOnce(string $worker): array
    {
        $redis = $this->getRedis();

        try {
            switch ($worker) {
                case 'flusher':
                    if (!$redis) {
                        return ['success' => false, 'message' => 'Redis is not connected'];
                    }
                    $flusher = new QuizFlusherCommand($this->db, $redis);
                    $ans = $flusher->flushAnswers($redis, 500);
                    $att = $flusher->flushAttempts($redis, 500);
                    return [
                        'success' => true,
                        'message' => "Flusher pass complete: flushed {$ans} answers, {$att} attempts to MySQL.",
                        'details' => ['answers' => $ans, 'attempts' => $att],
                    ];

                case 'finalizer':
                    if (!$redis) {
                        return ['success' => false, 'message' => 'Redis is not connected'];
                    }
                    $finalizer = new QuizFinalizerCommand($this->db, $redis);
                    $graded = $finalizer->finalizeBatch($redis, 100);
                    return [
                        'success' => true,
                        'message' => "Finalizer completed single pass: graded and finalized {$graded} attempt(s).",
                        'details' => ['graded' => $graded],
                    ];

                case 'scheduler':
                    if (!$redis) {
                        return ['success' => false, 'message' => 'Redis is not connected'];
                    }
                    $scheduler = new QuizSchedulerCommand($this->db, $redis);
                    ob_start();
                    $scheduler->execute(['--once']);
                    $output = trim((string) ob_get_clean());
                    return [
                        'success' => true,
                        'message' => 'Scheduler completed single pass.',
                        'details' => $output,
                    ];

                case 'jobs':
                    $jobsWorker = new JobsWorkerCommand($this->db, null, null, $redis);
                    ob_start();
                    $jobsWorker->execute(['--once']);
                    $output = trim((string) ob_get_clean());
                    return [
                        'success' => true,
                        'message' => 'Jobs worker completed single pass.',
                        'details' => $output,
                    ];

                default:
                    return ['success' => false, 'message' => "Unknown worker: {$worker}"];
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => "Execution failed: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Restart a background systemd worker service.
     *
     * @return array{success: bool, message: string}
     */
    public function restartWorker(string $worker): array
    {
        return $this->executeSystemctl($worker, 'restart');
    }

    /**
     * Start a background systemd worker service.
     *
     * @return array{success: bool, message: string}
     */
    public function startWorker(string $worker): array
    {
        return $this->executeSystemctl($worker, 'start');
    }

    /**
     * Stop a background systemd worker service.
     *
     * @return array{success: bool, message: string}
     */
    public function stopWorker(string $worker): array
    {
        return $this->executeSystemctl($worker, 'stop');
    }

    /**
     * Start all four background workers at once.
     *
     * @return array{success: bool, message: string, results: array<string, array{success: bool, message: string}>}
     */
    public function startAll(): array
    {
        $results = [];
        $allOk = true;

        foreach (array_keys(self::WORKERS) as $worker) {
            $r = $this->startWorker($worker);
            $results[$worker] = $r;
            if (!$r['success']) {
                $allOk = false;
            }
        }

        return [
            'success' => $allOk,
            'message' => $allOk
                ? 'All workers started successfully.'
                : 'Some workers failed to start — see results for details.',
            'results' => $results,
        ];
    }

    /**
     * Stop all four background workers at once.
     *
     * @return array{success: bool, message: string, results: array<string, array{success: bool, message: string}>}
     */
    public function stopAll(): array
    {
        $results = [];
        $allOk = true;

        foreach (array_keys(self::WORKERS) as $worker) {
            $r = $this->stopWorker($worker);
            $results[$worker] = $r;
            if (!$r['success']) {
                $allOk = false;
            }
        }

        return [
            'success' => $allOk,
            'message' => $allOk
                ? 'All workers stopped.'
                : 'Some workers failed to stop — see results for details.',
            'results' => $results,
        ];
    }

    /**
     * Get recent log lines for a worker.
     */
    public function getLogs(string $worker, int $lines = 50): string
    {
        $logFiles = [
            "/var/log/corpquiz/{$worker}.log",
            "/var/log/corpquiz/quiz-{$worker}.log",
            "/var/log/corpquiz/{$worker}_error.log",
            dirname(__DIR__, 2) . "/storage/logs/{$worker}.log",
        ];

        foreach ($logFiles as $file) {
            if (file_exists($file) && is_readable($file)) {
                $content = @file_get_contents($file);
                if ($content !== false && $content !== '') {
                    $allLines = explode("\n", trim($content));
                    $tail = array_slice($allLines, -$lines);
                    return implode("\n", $tail);
                }
            }
        }

        // Try reading via journalctl if on systemd
        $service = self::WORKERS[$worker]['service'] ?? "quiz-{$worker}";
        if ($this->isSystemctlAvailable()) {
            $cmd = "journalctl -u " . escapeshellarg($service) . " -n " . (int) $lines . " --no-pager 2>&1";
            $output = @shell_exec($cmd);
            if ($output && trim($output) !== '') {
                return trim($output);
            }
        }

        return "No recent logs found for worker [{$worker}]. Checked /var/log/corpquiz/ and journalctl.";
    }

    private function executeSystemctl(string $worker, string $action): array
    {
        if (!isset(self::WORKERS[$worker])) {
            return ['success' => false, 'message' => "Invalid worker: {$worker}"];
        }

        $service = self::WORKERS[$worker]['service'];

        if (!$this->isSystemctlAvailable()) {
            return [
                'success' => false,
                'message' => "Cannot {$action} {$service}: systemctl is not available in this environment. "
                    . "Run this action on the production server via SSH.",
            ];
        }

        $allowedActions = ['start', 'stop', 'restart'];
        if (!in_array($action, $allowedActions, true)) {
            return ['success' => false, 'message' => "Invalid action: {$action}"];
        }

        $cmd = "sudo /usr/bin/systemctl {$action} " . escapeshellarg($service) . " 2>&1";
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode === 0) {
            return [
                'success' => true,
                'message' => "Successfully executed '{$action}' on {$service}.",
            ];
        }

        $errMsg = !empty($output) ? implode(' ', $output) : "Exit code: {$exitCode}";
        return [
            'success' => false,
            'message' => "Failed to {$action} {$service}: {$errMsg}",
        ];
    }

    private function getSystemdStatus(string $service): string
    {
        if (!$this->isSystemctlAvailable()) {
            return 'n/a';
        }

        $cmd = "systemctl is-active " . escapeshellarg($service) . " 2>/dev/null";
        $state = trim((string) @shell_exec($cmd));

        return $state !== '' ? $state : 'unknown';
    }

    private function isSystemctlAvailable(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $output = @shell_exec('which systemctl 2>/dev/null');
        return $output !== null && trim($output) !== '';
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
