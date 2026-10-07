<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * DataPurgeService – safely deletes data by scope.
 *
 * Constructor-injected dependencies make this fully unit-testable with fakes.
 *
 * Scope manifest is a declarative array constant so it can be asserted in tests
 * without instantiating the class.
 */
class DataPurgeService
{
    // -----------------------------------------------------------------------
    // MANIFEST
    // -----------------------------------------------------------------------

    /**
     * Hard-coded scope manifest.
     *
     * Keys: scope key string
     * Values:
     *   label        – human-readable name
     *   mysql        – tables to TRUNCATE, in order
     *   mysql_nullify – [table => [col, ...]] columns to NULL instead of truncate
     *   redis_patterns – glob patterns for SCAN + UNLINK
     *   redis_exact    – exact keys to DEL
     *   file_dirs      – [realpath-base => glob] files to delete
     *   log_dirs       – directories whose *.log files are truncated in-place
     *   confirm_phrase – phrase the admin must type exactly
     *   needs_force    – whether this scope requires the force checkbox for live quizzes
     *   post_reseed_qstat – whether to re-seed qstat hashes after purge
     *
     * NEVER-TOUCH list (enforced by this manifest's omissions):
     *   administrators, migrations, worker:heartbeat:*, revoked:*
     */
    public const MANIFEST = [
        'employees' => [
            'label'           => 'Employees and their submissions',
            'confirm_phrase'  => 'DELETE EMPLOYEES',
            'needs_force'     => true,
            'mysql'           => [
                'attempt_answers',
                'attempts',
                'employee_groups',
                'employees',
                'groups',
                'import_jobs',
            ],
            'mysql_nullify'      => [],
            'redis_patterns'     => ['att:*', 'ans:*', 'qa:*', 'qstat:*', 'rl:*', 'dash:*'],
            'redis_exact'        => ['deadlines', 'fq', 'dirty', 'dirty_att'],
            'file_dirs'          => [
                'storage/imports'  => '*',
                'storage/reports'  => 'import_*',
            ],
            'log_dirs'           => [],
            'post_reseed_qstat'  => true,
        ],
        'quizzes' => [
            'label'           => 'Quizzes, questions and their submissions',
            'confirm_phrase'  => 'DELETE QUIZZES',
            'needs_force'     => true,
            'mysql'           => [
                'attempt_answers',
                'attempts',
                'quiz_snapshots',
                'answer_options',
                'questions',
                'quizzes',
            ],
            'mysql_nullify'      => [],
            'redis_patterns'     => [
                'quiz:*', 'key:*', 'struct:*',
                'qa:*', 'qstat:*', 'att:*', 'ans:*', 'dash:*',
            ],
            'redis_exact'        => ['deadlines', 'fq', 'dirty', 'dirty_att'],
            'file_dirs'          => [
                'storage/bundles'          => '*',
                'public/uploads/questions' => '*',
            ],
            'log_dirs'           => [],
            'post_reseed_qstat'  => false,
        ],
        'reports' => [
            'label'           => 'Reports and exports',
            'confirm_phrase'  => 'DELETE REPORTS',
            'needs_force'     => false,
            'mysql'           => ['export_jobs'],
            'mysql_nullify'      => [
                'import_jobs' => ['report_path'],
            ],
            'redis_patterns'     => [],
            'redis_exact'        => [],
            'file_dirs'          => [
                'storage/exports' => '*',
                'storage/reports' => '*',
            ],
            'log_dirs'           => [],
            'post_reseed_qstat'  => false,
        ],
        'logs' => [
            'label'           => 'Logs',
            'confirm_phrase'  => 'DELETE LOGS',
            'needs_force'     => false,
            'mysql'           => ['audit_logs'],
            'mysql_nullify'      => [],
            'redis_patterns'     => [],
            'redis_exact'        => [],
            'file_dirs'          => [],
            'log_dirs'           => [
                'storage/logs',
                // /var/log/corpquiz is appended at runtime from $this->extraLogDirs
            ],
            'post_reseed_qstat'  => false,
        ],
        'cache' => [
            'label'           => 'Cache only',
            'confirm_phrase'  => 'CLEAR CACHE',
            'needs_force'     => false,
            'mysql'           => [],
            'mysql_nullify'      => [],
            'redis_patterns'     => ['dash:*', 'rl:*'],
            'redis_exact'        => [],
            'file_dirs'          => [
                'storage/cache' => '*',
            ],
            'log_dirs'           => [],
            'post_reseed_qstat'  => false,
        ],
        'everything' => [
            'label'           => 'Factory reset',
            'confirm_phrase'  => 'RESET EVERYTHING',
            'needs_force'     => true,
            'mysql'           => [
                'attempt_answers',
                'attempts',
                'employee_groups',
                'employees',
                'groups',
                'import_jobs',
                'quiz_snapshots',
                'answer_options',
                'questions',
                'quizzes',
                'export_jobs',
                'audit_logs',
            ],
            'mysql_nullify'      => [],
            'redis_patterns'     => [
                'att:*', 'ans:*', 'qa:*', 'qstat:*', 'rl:*', 'dash:*',
                'quiz:*', 'key:*', 'struct:*',
            ],
            'redis_exact'        => ['deadlines', 'fq', 'dirty', 'dirty_att'],
            'file_dirs'          => [
                'storage/imports'          => '*',
                'storage/reports'          => '*',
                'storage/bundles'          => '*',
                'public/uploads/questions' => '*',
                'storage/exports'          => '*',
                'storage/cache'            => '*',
            ],
            'log_dirs'           => [
                'storage/logs',
            ],
            'post_reseed_qstat'  => false,
        ],
    ];

    /** Allowed scan prefixes – used for safety assertions */
    private const SAFE_REDIS_PREFIXES = [
        'att:', 'ans:', 'qa:', 'qstat:', 'quiz:', 'key:', 'struct:',
        'dash:', 'rl:',
    ];

    /** Exact keys that may be deleted */
    private const SAFE_REDIS_EXACT = ['deadlines', 'fq', 'dirty', 'dirty_att'];

    /** Forbidden prefixes – must NEVER appear in any pattern */
    private const FORBIDDEN_PREFIXES = ['worker:heartbeat:', 'revoked:'];

    /** Tables that must NEVER be touched */
    private const PROTECTED_TABLES = ['administrators', 'migrations'];

    private const SCAN_COUNT   = 1000;
    private const SCAN_CAP     = 100_000;
    private const LOCK_NAME    = 'corpquiz_purge';

    // -----------------------------------------------------------------------
    // INJECTABLE DEPENDENCIES
    // -----------------------------------------------------------------------

    private PDO $pdo;
    /** @var mixed */
    private $redis;
    private string $basePath;
    /** @var list<string> extra log directories (e.g. /var/log/corpquiz) */
    private array $extraLogDirs;
    /** @var callable():int clock */
    private $clock;

    /**
     * @param mixed          $redis        phpredis instance or null
     * @param list<string>   $extraLogDirs e.g. ['/var/log/corpquiz']
     * @param callable():int $clock        returns current unix timestamp
     */
    public function __construct(
        PDO $pdo,
        $redis,
        string $basePath,
        array $extraLogDirs = [],
        callable $clock = null
    ) {
        $this->pdo          = $pdo;
        $this->redis        = $redis;
        $this->basePath     = rtrim($basePath, '/\\');
        $this->extraLogDirs = $extraLogDirs;
        $this->clock        = $clock ?? static fn(): int => time();
    }

    // -----------------------------------------------------------------------
    // PUBLIC API
    // -----------------------------------------------------------------------

    /**
     * Return live counts without deleting anything.
     *
     * @return array<string, mixed>
     */
    public function preview(string $scope): array
    {
        $manifest = $this->scopeManifest($scope);
        $result   = ['scope' => $scope, 'mysql' => [], 'redis' => [], 'files' => []];

        foreach ($manifest['mysql'] as $table) {
            try {
                $cnt = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                $result['mysql'][$table] = $cnt;
            } catch (\Throwable $e) {
                $result['mysql'][$table] = 'error: ' . $e->getMessage();
            }
        }
        foreach ($manifest['mysql_nullify'] as $table => $cols) {
            foreach ($cols as $col) {
                $key = "{$table}.{$col}";
                try {
                    $cnt = (int) $this->pdo
                        ->query("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NOT NULL")
                        ->fetchColumn();
                    $result['mysql'][$key] = $cnt;
                } catch (\Throwable $e) {
                    $result['mysql'][$key] = 'error: ' . $e->getMessage();
                }
            }
        }

        if ($this->redis !== null) {
            foreach ($manifest['redis_patterns'] as $pattern) {
                $result['redis'][$pattern] = $this->scanCount($pattern);
            }
            foreach ($manifest['redis_exact'] as $key) {
                try {
                    $result['redis'][$key] = (int) $this->redis->exists($key);
                } catch (\Throwable) {
                    $result['redis'][$key] = 0;
                }
            }
        }

        $allLogDirs = array_merge($manifest['log_dirs'], $this->extraLogDirs);
        foreach ($manifest['file_dirs'] as $relDir => $glob) {
            $result['files'][$relDir] = $this->countFiles($relDir, $glob);
        }
        foreach ($allLogDirs as $logDir) {
            $result['files'][$logDir . ' (logs)'] = $this->countLogFiles($logDir);
        }

        // APCu
        if ($scope === 'cache' || $scope === 'everything') {
            $result['apcu'] = function_exists('apcu_cache_info');
        }

        return $result;
    }

    /**
     * Execute a purge and return per-step results.
     *
     * @param  array<string, mixed>  $context  ['admin_id', 'ip', 'ua']
     * @return array<string, mixed>
     */
    public function execute(string $scope, array $context = []): array
    {
        $manifest = $this->scopeManifest($scope);
        $steps    = [];

        // ── Single-flight lock ─────────────────────────────────────────────
        $lockResult = $this->pdo
            ->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")
            ->fetchColumn();
        if ((int) $lockResult !== 1) {
            throw new RuntimeException('Another purge is already running. Please wait and try again.');
        }

        try {
            // ── Redis sweep 1 ──────────────────────────────────────────────
            $steps['redis_sweep_1'] = $this->sweepRedis($manifest);

            // ── MySQL ──────────────────────────────────────────────────────
            $steps['mysql'] = $this->truncateTables($manifest);

            // ── Redis sweep 2 (catch re-created keys) ─────────────────────
            $steps['redis_sweep_2'] = $this->sweepRedis($manifest);

            // ── Files ──────────────────────────────────────────────────────
            $allLogDirs = array_merge($manifest['log_dirs'], $this->extraLogDirs);
            $steps['files'] = $this->purgeFiles($manifest, $allLogDirs);

            // ── APCu ───────────────────────────────────────────────────────
            if ($scope === 'cache' || $scope === 'everything') {
                if (function_exists('apcu_clear_cache')) {
                    apcu_clear_cache();
                    $steps['apcu'] = ['cleared' => true];
                }
            }

            // ── Post-steps: re-seed qstat for published quizzes ────────────
            if ($manifest['post_reseed_qstat'] && $this->redis !== null) {
                $steps['qstat_reseed'] = $this->reseedQstat();
            }

            // ── Audit row (written last) ───────────────────────────────────
            $meta = [
                'steps'      => $steps,
                'ip'         => $context['ip'] ?? '',
                'user_agent' => $context['ua'] ?? '',
            ];
            // NOTE: passcode must NEVER appear in meta
            $this->writeAuditLog($scope, $context['admin_id'] ?? 0, $meta);

            error_log(sprintf(
                '[DataPurge] scope=%s actor=%s ip=%s tables=%d redis=%d files=%d',
                $scope,
                $context['admin_id'] ?? '?',
                $context['ip'] ?? '?',
                array_sum(array_column($steps['mysql']['tables'] ?? [], 'deleted')),
                ($steps['redis_sweep_1']['deleted'] ?? 0) + ($steps['redis_sweep_2']['deleted'] ?? 0),
                $steps['files']['deleted'] ?? 0
            ));
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }

        return $steps;
    }

    /**
     * Check whether any attempt is currently IN_PROGRESS or any quiz window
     * is open right now.
     *
     * @return array{live_attempts: bool, open_windows: bool, deadlines_count: int}
     */
    public function checkLiveActivity(): array
    {
        $liveAttempts = false;
        $deadlinesCount = 0;

        // Redis ZCARD deadlines
        if ($this->redis !== null) {
            try {
                if (method_exists($this->redis, 'zCard')) {
                    $deadlinesCount = (int) $this->redis->zCard('deadlines');
                    $liveAttempts   = $deadlinesCount > 0;
                }
            } catch (\Throwable) {
                // Redis down; fall back to MySQL check below
            }
        }

        // MySQL fallback
        if (!$liveAttempts) {
            try {
                $cnt = (int) $this->pdo
                    ->query("SELECT COUNT(*) FROM attempts WHERE status = 'IN_PROGRESS'")
                    ->fetchColumn();
                $liveAttempts = $cnt > 0;
            } catch (\Throwable) {
                // ignore
            }
        }

        // Open quiz windows
        $openWindows = false;
        try {
            $sql = "SELECT COUNT(*) FROM quizzes "
                . "WHERE status = 'published' AND start_at <= NOW() AND end_at > NOW()";
            $cnt = (int) $this->pdo->query($sql)->fetchColumn();
            $openWindows = $cnt > 0;
        } catch (\Throwable) {
            // ignore
        }

        return [
            'live_attempts'  => $liveAttempts,
            'open_windows'   => $openWindows,
            'deadlines_count' => $deadlinesCount,
        ];
    }

    /**
     * Return worker heartbeat ages (seconds since last heartbeat, or -1).
     *
     * @return array<string, int>
     */
    public function workerHeartbeats(): array
    {
        $workers = ['flusher', 'finalizer', 'scheduler', 'jobs'];
        $now     = ($this->clock)();
        $result  = [];

        foreach ($workers as $w) {
            $result[$w] = -1;
            if ($this->redis === null) {
                continue;
            }
            try {
                $ts = $this->redis->get("worker:heartbeat:{$w}");
                if ($ts !== false && $ts !== null) {
                    $result[$w] = $now - (int) $ts;
                }
            } catch (\Throwable) {
                // Redis unavailable
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // PRIVATE HELPERS
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function scopeManifest(string $scope): array
    {
        if (!isset(self::MANIFEST[$scope])) {
            throw new \InvalidArgumentException("Unknown scope: {$scope}");
        }
        return self::MANIFEST[$scope];
    }

    /**
     * SCAN + UNLINK all keys matching the scope's patterns and exact keys.
     * Throws if Redis client lacks `scan`.
     *
     * @param  array<string, mixed> $manifest
     * @return array{deleted: int, failed: int, errors: list<string>}
     */
    private function sweepRedis(array $manifest): array
    {
        $deleted = 0;
        $failed  = 0;
        $errors  = [];

        if ($this->redis === null) {
            return ['deleted' => 0, 'failed' => 0, 'errors' => ['Redis not available']];
        }

        if (!method_exists($this->redis, 'scan')) {
            throw new RuntimeException(
                'Redis client does not support SCAN. phpredis extension is required for Data Reset.'
            );
        }

        // Pattern-based scan
        foreach ($manifest['redis_patterns'] as $pattern) {
            $this->assertSafePattern($pattern);
            $cursor = null;
            do {
                try {
                    $keys = $this->redis->scan($cursor, ['match' => $pattern, 'count' => self::SCAN_COUNT]);
                    if (!is_array($keys) || empty($keys)) {
                        continue;
                    }
                    if (method_exists($this->redis, 'unlink')) {
                        $cnt = (int) $this->redis->unlink(...$keys);
                    } else {
                        $cnt = (int) $this->redis->del(...$keys);
                    }
                    $deleted += $cnt;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = "pattern={$pattern}: " . $e->getMessage();
                }
            } while ($cursor !== null && $cursor !== false && $cursor !== 0);
        }

        // Exact key deletions
        foreach ($manifest['redis_exact'] as $key) {
            $this->assertSafeExact($key);
            try {
                if ((bool) $this->redis->del($key)) {
                    $deleted++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "exact={$key}: " . $e->getMessage();
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * TRUNCATE tables (and nullify columns) as declared in the manifest.
     *
     * @param  array<string, mixed> $manifest
     * @return array{
     *     tables: array<string, array{deleted: int, failed: int, error: string|null}>,
     *     nullified: array<string, int>
     * }
     */
    private function truncateTables(array $manifest): array
    {
        $tables   = [];
        $nullified = [];

        foreach ($manifest['mysql'] as $table) {
            $this->assertSafeTable($table);
            $entry = ['deleted' => 0, 'failed' => 0, 'error' => null];
            try {
                $before = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                $this->pdo->exec("TRUNCATE TABLE `{$table}`");
                $after  = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                $entry['deleted'] = $before - $after;
            } catch (\Throwable $e) {
                $entry['failed'] = 1;
                $entry['error']  = $e->getMessage();
            }
            $tables[$table] = $entry;
        }

        foreach ($manifest['mysql_nullify'] as $table => $cols) {
            $this->assertSafeTable($table);
            foreach ($cols as $col) {
                $key = "{$table}.{$col}";
                try {
                    $cnt = $this->pdo->exec("UPDATE `{$table}` SET `{$col}` = NULL WHERE `{$col}` IS NOT NULL");
                    $nullified[$key] = (int) $cnt;
                } catch (\Throwable $e) {
                    $nullified[$key] = -1;
                }
            }
        }

        return ['tables' => $tables, 'nullified' => $nullified];
    }

    /**
     * Delete files in declared directories; truncate log files.
     *
     * @param  array<string, mixed> $manifest
     * @param  list<string>          $allLogDirs
     * @return array{deleted: int, truncated: int, failed: int, errors: list<string>}
     */
    private function purgeFiles(array $manifest, array $allLogDirs): array
    {
        $deleted   = 0;
        $truncated = 0;
        $failed    = 0;
        $errors    = [];

        foreach ($manifest['file_dirs'] as $relDir => $glob) {
            $baseAbs = realpath($this->basePath . DIRECTORY_SEPARATOR . $relDir);
            if ($baseAbs === false) {
                $errors[] = "{$relDir}: directory not found";
                continue;
            }

            $pattern = $baseAbs . DIRECTORY_SEPARATOR . $glob;
            $matches = glob($pattern, GLOB_NOSORT);
            if ($matches === false) {
                continue;
            }

            foreach ($matches as $path) {
                $realPath = realpath($path);
                if ($realPath === false) {
                    // symlink pointing nowhere – skip
                    continue;
                }
                // Safety: must stay inside the whitelisted base directory
                if (strncmp($realPath, $baseAbs, strlen($baseAbs)) !== 0) {
                    $errors[] = "Refused path outside base: {$realPath}";
                    $failed++;
                    continue;
                }
                // Never follow symlinks
                if (is_link($path)) {
                    $errors[] = "Refused symlink: {$path}";
                    $failed++;
                    continue;
                }
                if (!is_file($realPath)) {
                    // Preserve directories and .gitkeep (directories skipped)
                    continue;
                }
                if (basename($realPath) === '.gitkeep') {
                    continue;
                }
                if (!is_writable($realPath)) {
                    $errors[] = "Not writable: {$realPath}";
                    $failed++;
                    continue;
                }
                try {
                    if (unlink($realPath)) {
                        $deleted++;
                    } else {
                        $failed++;
                        $errors[] = "unlink failed: {$realPath}";
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = "Error deleting {$realPath}: " . $e->getMessage();
                }
            }
        }

        // Log files: truncate in-place (daemons hold them open)
        foreach ($allLogDirs as $logDirRel) {
            $logDirAbs = realpath($this->basePath . DIRECTORY_SEPARATOR . $logDirRel)
                ?: realpath($logDirRel) // absolute paths like /var/log/corpquiz
                ?: null;

            if ($logDirAbs === null || !is_dir($logDirAbs)) {
                // Silently skip missing system log dirs
                continue;
            }

            $logFiles = glob($logDirAbs . DIRECTORY_SEPARATOR . '*.log', GLOB_NOSORT);
            if (!is_array($logFiles)) {
                continue;
            }

            foreach ($logFiles as $logFile) {
                $realLogFile = realpath($logFile);
                if ($realLogFile === false || is_link($logFile)) {
                    $errors[] = "Refused symlink/missing log: {$logFile}";
                    $failed++;
                    continue;
                }
                // Must remain inside the log dir
                if (strncmp($realLogFile, $logDirAbs, strlen($logDirAbs)) !== 0) {
                    $errors[] = "Refused log path outside dir: {$realLogFile}";
                    $failed++;
                    continue;
                }
                if (!is_writable($realLogFile)) {
                    $errors[] = "Log not writable: {$realLogFile}";
                    $failed++;
                    continue;
                }
                try {
                    $fh = fopen($realLogFile, 'c');
                    if ($fh === false) {
                        $failed++;
                        $errors[] = "Cannot open log: {$realLogFile}";
                        continue;
                    }
                    if (!ftruncate($fh, 0)) {
                        $failed++;
                        $errors[] = "ftruncate failed: {$realLogFile}";
                    } else {
                        $truncated++;
                    }
                    fclose($fh);
                    clearstatcache(true, $realLogFile);
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = "Error truncating {$realLogFile}: " . $e->getMessage();
                }
            }
        }

        return [
            'deleted'   => $deleted,
            'truncated' => $truncated,
            'failed'    => $failed,
            'errors'    => $errors,
        ];
    }

    /**
     * Re-create zero-valued qstat:{quizId} hashes for every still-published quiz.
     *
     * Called after the employees scope so the scheduler does not erroneously
     * believe quizzes are warm when their rosters are now empty.
     *
     * @return array{reseeded: int, errors: list<string>}
     */
    private function reseedQstat(): array
    {
        $reseeded = 0;
        $errors   = [];

        try {
            $stmt = $this->pdo->query("SELECT id FROM quizzes WHERE status = 'published'");
            $quizIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return ['reseeded' => 0, 'errors' => ['DB query failed: ' . $e->getMessage()]];
        }

        foreach ($quizIds as $quizId) {
            $key = "qstat:{$quizId}";
            try {
                $this->redis->hMSet($key, [
                    'started'      => '0',
                    'in_progress'  => '0',
                    'completed'    => '0',
                    'absent'       => '0',
                    'sum_score'    => '0',
                    'sum_accuracy' => '0',
                    'sum_time_s'   => '0',
                ]);
                $reseeded++;
            } catch (\Throwable $e) {
                $errors[] = "qstat:{$quizId}: " . $e->getMessage();
            }
        }

        return ['reseeded' => $reseeded, 'errors' => $errors];
    }

    /** Write a row to audit_logs. Must be called last so it survives a logs purge. */
    private function writeAuditLog(string $scope, int $adminId, array $meta): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO audit_logs (actor_id, actor_role, action, entity, entity_id, meta, created_at)
                 VALUES (:actor_id, :actor_role, :action, :entity, NULL, :meta, :created_at)'
            );
            $stmt->execute([
                'actor_id'   => $adminId,
                'actor_role' => 'admin',
                'action'     => 'data_reset',
                'entity'     => $scope,
                'meta'       => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[DataPurge] audit_log write failed: ' . $e->getMessage());
        }
    }

    /**
     * Count keys matching a pattern (capped at SCAN_CAP).
     *
     * @return int|string  integer count or "100k+" string
     */
    private function scanCount(string $pattern)
    {
        if ($this->redis === null || !method_exists($this->redis, 'scan')) {
            return 0;
        }

        $count  = 0;
        $cursor = null;

        do {
            try {
                $keys = $this->redis->scan($cursor, ['match' => $pattern, 'count' => self::SCAN_COUNT]);
                if (is_array($keys)) {
                    $count += count($keys);
                }
            } catch (\Throwable) {
                break;
            }

            if ($count >= self::SCAN_CAP) {
                return '100k+';
            }
        } while ($cursor !== null && $cursor !== false && $cursor !== 0);

        return $count;
    }

    /** Count files in a relative directory with a glob pattern. */
    private function countFiles(string $relDir, string $glob): array
    {
        $baseAbs = realpath($this->basePath . DIRECTORY_SEPARATOR . $relDir);
        if ($baseAbs === false) {
            return ['count' => 0, 'bytes' => 0, 'error' => 'directory not found'];
        }
        $matches = glob($baseAbs . DIRECTORY_SEPARATOR . $glob, GLOB_NOSORT);
        if (!is_array($matches)) {
            return ['count' => 0, 'bytes' => 0];
        }
        $count = 0;
        $bytes = 0;
        foreach ($matches as $f) {
            if (is_file($f) && basename($f) !== '.gitkeep') {
                $count++;
                $bytes += (int) filesize($f);
            }
        }
        return ['count' => $count, 'bytes' => $bytes];
    }

    /** Count log files (*.log) in a directory. */
    private function countLogFiles(string $logDirRel): array
    {
        $abs = realpath($this->basePath . DIRECTORY_SEPARATOR . $logDirRel)
            ?: realpath($logDirRel)
            ?: null;

        if ($abs === null || !is_dir($abs)) {
            return ['count' => 0, 'bytes' => 0];
        }
        $files = glob($abs . DIRECTORY_SEPARATOR . '*.log', GLOB_NOSORT);
        if (!is_array($files)) {
            return ['count' => 0, 'bytes' => 0];
        }
        $count = 0;
        $bytes = 0;
        foreach ($files as $f) {
            if (is_file($f)) {
                $count++;
                $bytes += (int) filesize($f);
            }
        }
        return ['count' => $count, 'bytes' => $bytes];
    }

    // -----------------------------------------------------------------------
    // SAFETY ASSERTIONS
    // -----------------------------------------------------------------------

    private function assertSafePattern(string $pattern): void
    {
        foreach (self::FORBIDDEN_PREFIXES as $fp) {
            if (str_starts_with($pattern, $fp)) {
                throw new RuntimeException("Refused dangerous Redis pattern: {$pattern}");
            }
        }
    }

    private function assertSafeExact(string $key): void
    {
        if (!in_array($key, self::SAFE_REDIS_EXACT, true)) {
            throw new RuntimeException("Refused unsafe exact Redis key: {$key}");
        }
    }

    private function assertSafeTable(string $table): void
    {
        if (in_array($table, self::PROTECTED_TABLES, true)) {
            throw new RuntimeException("Refused to touch protected table: {$table}");
        }
    }
}
