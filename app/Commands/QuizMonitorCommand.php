<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\MetricsCollector;

/**
 * Real-time CLI Monitor Dashboard for Assessment Day Operations.
 * Runs in terminal and refreshes every 1-2 seconds with colorized status indicators.
 */
class QuizMonitorCommand
{
    private MetricsCollector $collector;

    public function __construct(?MetricsCollector $collector = null)
    {
        $this->collector = $collector ?? new MetricsCollector();
    }

    public function execute(array $args = []): void
    {
        $once = in_array('--once', $args, true);

        // Terminal ANSI color codes
        $cReset = "\033[0m";
        $cBold = "\033[1m";
        $cGreen = "\033[32m";
        $cRed = "\033[31m";
        $cYellow = "\033[33m";
        $cCyan = "\033[36m";
        $cGray = "\033[90m";

        do {
            $data = $this->collector->collect();
            $nowStr = gmdate('Y-m-d H:i:s') . ' UTC';

            // Clear screen unless single run
            if (!$once) {
                echo "\033[2J\033[H";
            }

            echo "{$cBold}=============================================================================={$cReset}\n";
            echo "{$cBold}{$cCyan} CORPQUIZ LIVE SYSTEM & ASSESSMENT MONITOR {$cReset}  [ {$nowStr} ]\n";
            echo "{$cBold}=============================================================================={$cReset}\n\n";

            // 1. Worker Daemons
            echo "{$cBold}>>> WORKER DAEMONS (Systemd Background Services):{$cReset}\n";
            foreach ($data['workers'] as $worker => $info) {
                $workerName = str_pad(ucfirst($worker), 12);
                if ($info['healthy']) {
                    $statusStr = "{$cGreen}[RUNNING]{$cReset} (heartbeat: {$info['age_seconds']}s ago)";
                } else {
                    $ageText = $info['age_seconds'] >= 0 ? "{$info['age_seconds']}s ago" : 'never';
                    $statusStr = "{$cRed}[DOWN / HUNG]{$cReset} (heartbeat: {$ageText})";
                }
                echo "  - {$workerName} : {$statusStr}\n";
            }
            echo "\n";

            // 2. Queues & Write-Behind Lags
            $q = $data['queues'];
            echo "{$cBold}>>> CRITICAL QUEUES & WRITE-BEHIND LAGS:{$cReset}\n";

            $dirtyAttColor = ($q['dirty_attempts'] > 500) ? $cRed : (($q['dirty_attempts'] > 100) ? $cYellow : $cGreen);
            $dirtyAnsColor = ($q['dirty_answers'] > 1000) ? $cRed : (($q['dirty_answers'] > 200) ? $cYellow : $cGreen);
            $fqColor = ($q['finalizer_queue'] > 200) ? $cRed : (($q['finalizer_queue'] > 50) ? $cYellow : $cGreen);
            $dlqColor = ($q['dead_letter'] > 0) ? $cRed : $cGreen;

            echo "  - Dirty Attempts (dirty_att) : {$dirtyAttColor}" . number_format($q['dirty_attempts']) .
                "{$cReset} (MySQL write lag)\n";
            echo "  - Dirty Answers (dirty)     : {$dirtyAnsColor}" . number_format($q['dirty_answers']) .
                "{$cReset} (MySQL answers lag)\n";
            echo "  - Finalizer Queue (fq)      : {$fqColor}" . number_format($q['finalizer_queue']) .
                "{$cReset} (Pending grading)\n";
            echo "  - Dead Letter Queue (dlq)   : {$dlqColor}" . number_format($q['dead_letter']) . "{$cReset}\n";
            echo "  - Pre-warmed Quizzes        : {$cCyan}" . number_format($q['warm_quizzes']) . "{$cReset}\n\n";

            // 3. Redis Health
            $r = $data['redis'];
            echo "{$cBold}>>> REDIS IN-MEMORY ENGINE:{$cReset}\n";
            if ($r['connected']) {
                $memMb = round(($r['used_memory'] ?? 0) / (1024 * 1024), 2);
                $memColor = ($memMb > 800) ? $cRed : (($memMb > 500) ? $cYellow : $cGreen);
                $hitRatePct = round(($r['hit_rate'] ?? 1.0) * 100, 1);

                echo "  - Status             : {$cGreen}Connected{$cReset}\n";
                echo "  - Memory Allocated   : {$memColor}{$memMb} MB{$cReset} / 1024 MB cap\n";
                echo "  - Connected Clients  : {$cCyan}" . ($r['connected_clients'] ?? 0) . "{$cReset}\n";
                $opsFormatted = number_format($r['ops_per_sec'] ?? 0);
                echo "  - Current Throughput : {$cCyan}{$opsFormatted} ops/sec{$cReset}\n";
                echo "  - Cache Hit Rate     : {$cCyan}{$hitRatePct}%{$cReset}\n\n";
            } else {
                echo "  - Status : {$cRed}DISCONNECTED / ERROR{$cReset}\n\n";
            }

            // 4. MySQL & Database State
            $db = $data['database'];
            echo "{$cBold}>>> MYSQL DATABASE:{$cReset}\n";
            if ($db['connected']) {
                $thdColor = ($db['threads_connected'] > 80) ? $cRed : $cGreen;
                echo "  - Status             : {$cGreen}Connected{$cReset}\n";
                echo "  - Connected Threads  : {$thdColor}" . ($db['threads_connected'] ?? 0) .
                    "{$cReset} (active running: " . ($db['threads_running'] ?? 0) . ")\n";
                echo "  - Slow Queries Total : " .
                    ($db['slow_queries'] > 0 ? "{$cYellow}{$db['slow_queries']}{$cReset}" : "0") . "\n";

                if (!empty($db['attempt_counts'])) {
                    echo "  - Attempts by Status : ";
                    $attParts = [];
                    foreach ($db['attempt_counts'] as $st => $cnt) {
                        $attParts[] = "{$st}: " . number_format($cnt);
                    }
                    echo implode(' | ', $attParts) . "\n";
                }
                echo "\n";
            } else {
                echo "  - Status : {$cRed}DISCONNECTED / ERROR{$cReset}\n\n";
            }

            echo "{$cGray}Press Ctrl+C to exit. Updates every 1s.{$cReset}\n";

            if ($once) {
                break;
            }

            sleep(1);
        } while (true);
    }
}
