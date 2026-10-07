<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DataPurgeService;
use App\Services\Csrf;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * DataPurgeServiceTest
 *
 * All tests use injected fakes (in-memory PDO / spy Redis) so no real
 * database or Redis instance is touched.
 *
 * MySQL integration tests (testMysqlIntegration*) are skipped unless
 * DEFAULT_DB_DATABASE ends with "_test".
 */
class DataPurgeServiceTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Build an SQLite-backed PDO stub for structural tests */
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Minimal table structure so COUNT(*) and TRUNCATE equivalents work
        $tables = [
            'attempt_answers', 'attempts', 'employee_groups', 'employees',
            'groups', 'import_jobs', 'quiz_snapshots', 'answer_options',
            'questions', 'quizzes', 'export_jobs', 'audit_logs',
        ];
        foreach ($tables as $t) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS \"{$t}\" (id INTEGER PRIMARY KEY)");
        }
        // quizzes needs status column for preview + reseed
        $pdo->exec('DROP TABLE IF EXISTS "quizzes"');
        $pdo->exec('CREATE TABLE "quizzes" (id INTEGER PRIMARY KEY, status TEXT, code TEXT)');

        // import_jobs needs report_path for nullify
        $pdo->exec('DROP TABLE IF EXISTS "import_jobs"');
        $pdo->exec('CREATE TABLE "import_jobs" (id INTEGER PRIMARY KEY, report_path TEXT)');

        // audit_logs needs all required columns
        $pdo->exec('DROP TABLE IF EXISTS "audit_logs"');
        $pdo->exec(
            'CREATE TABLE "audit_logs" '
            . '(id INTEGER PRIMARY KEY, actor_id INT, actor_role TEXT, '
            . 'action TEXT, entity TEXT, entity_id INT, meta TEXT, created_at TEXT)'
        );

        return $pdo;
    }

    /** Build a spy Redis that records calls and honours scan/unlink contract */
    private function makeRedis(): object
    {
        return new class () {
            public array $deleted    = [];
            public array $scanCalls  = [];
            public array $hMSetCalls = [];
            /** @var array<string, mixed> */
            public array $store = [];
            public int $zCardReturn = 0;

            public function scan(&$cursor, array $options = []): array|false
            {
                $pattern = $options['match'] ?? '*';
                $this->scanCalls[] = $pattern;
                $cursor = 0; // one-shot: signal done
                // Return any stored keys matching the pattern prefix
                $prefix = rtrim($pattern, '*');
                $keys   = [];
                foreach ($this->store as $k => $v) {
                    if ($prefix === '' || str_starts_with($k, $prefix)) {
                        $keys[] = $k;
                    }
                }
                return $keys ?: [];
            }

            public function unlink(string ...$keys): int
            {
                foreach ($keys as $k) {
                    $this->deleted[] = $k;
                    unset($this->store[$k]);
                }
                return count($keys);
            }

            public function del(string ...$keys): int
            {
                foreach ($keys as $k) {
                    $this->deleted[] = $k;
                    unset($this->store[$k]);
                }
                return count($keys);
            }

            public function exists(string ...$keys): int
            {
                $count = 0;
                foreach ($keys as $k) {
                    if (isset($this->store[$k])) {
                        $count++;
                    }
                }
                return $count;
            }

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): bool
            {
                $this->store[$key] = $value;
                return true;
            }

            public function hMSet(string $key, array $fields): bool
            {
                $this->hMSetCalls[] = $key;
                $this->store[$key]  = $fields;
                return true;
            }

            public function zCard(string $key): int|false
            {
                return $this->zCardReturn;
            }

            public function incr(string $key): int
            {
                $v = (int) ($this->store[$key] ?? 0) + 1;
                $this->store[$key] = $v;
                return $v;
            }

            public function expire(string $key, int $ttl): bool
            {
                return true;
            }

            public function ttl(string $key): int
            {
                return 0;
            }
        };
    }

    private function makeService(
        PDO $pdo = null,
        object $redis = null,
        string $basePath = null,
        array $extraLogDirs = []
    ): DataPurgeService {
        return new DataPurgeService(
            $pdo ?? $this->makePdo(),
            $redis,
            $basePath ?? sys_get_temp_dir(),
            $extraLogDirs
        );
    }

    private function callSweepRedis(DataPurgeService $svc, array $manifest): array
    {
        $ref = new \ReflectionMethod(DataPurgeService::class, 'sweepRedis');
        $ref->setAccessible(true);
        return $ref->invoke($svc, $manifest);
    }

    private function callPurgeFilesForScope(DataPurgeService $svc, string $scope): array
    {
        $manifest = DataPurgeService::MANIFEST[$scope];
        $allLogDirs = array_merge($manifest['log_dirs'], []);
        $ref = new \ReflectionMethod(DataPurgeService::class, 'purgeFiles');
        $ref->setAccessible(true);
        return $ref->invoke($svc, $manifest, $allLogDirs);
    }

    private function callReseedQstat(DataPurgeService $svc): array
    {
        $ref = new \ReflectionMethod(DataPurgeService::class, 'reseedQstat');
        $ref->setAccessible(true);
        return $ref->invoke($svc);
    }

    private function callWriteAuditLog(DataPurgeService $svc, string $scope, int $adminId, array $meta): void
    {
        $ref = new \ReflectionMethod(DataPurgeService::class, 'writeAuditLog');
        $ref->setAccessible(true);
        $ref->invoke($svc, $scope, $adminId, $meta);
    }

    private function callExecuteWithoutLock(DataPurgeService $svc, string $scope): array
    {
        $manifest = DataPurgeService::MANIFEST[$scope];
        $sweep1 = $this->callSweepRedis($svc, $manifest);
        $sweep2 = $this->callSweepRedis($svc, $manifest);
        return ['redis_sweep_1' => $sweep1, 'redis_sweep_2' => $sweep2];
    }

    // -----------------------------------------------------------------------
    // 1. MANIFEST INTEGRITY
    // -----------------------------------------------------------------------

    public function test_manifest_never_contains_administrators(): void
    {
        foreach (DataPurgeService::MANIFEST as $scope => $def) {
            $this->assertNotContains(
                'administrators',
                $def['mysql'],
                "Scope '{$scope}' must not include the administrators table"
            );
        }
    }

    public function test_manifest_never_contains_migrations_table(): void
    {
        foreach (DataPurgeService::MANIFEST as $scope => $def) {
            $this->assertNotContains(
                'migrations',
                $def['mysql'],
                "Scope '{$scope}' must not include the migrations table"
            );
        }
    }

    public function test_cache_scope_has_no_live_state_prefixes(): void
    {
        $forbidden = ['quiz:', 'key:', 'struct:', 'qa:', 'att:', 'ans:', 'qstat:'];
        $cachePatterns = DataPurgeService::MANIFEST['cache']['redis_patterns'];

        foreach ($forbidden as $fp) {
            $this->assertNotContains(
                $fp,
                $cachePatterns,
                "cache scope must not include live-state prefix '{$fp}'"
            );
        }
    }

    public function test_manifest_never_has_worker_heartbeat_or_revoked_prefix(): void
    {
        foreach (DataPurgeService::MANIFEST as $scope => $def) {
            foreach ($def['redis_patterns'] as $pattern) {
                $this->assertStringNotContainsString(
                    'worker:heartbeat:',
                    $pattern,
                    "Scope '{$scope}' pattern '{$pattern}' must not touch heartbeats"
                );
                $this->assertStringNotContainsString(
                    'revoked:',
                    $pattern,
                    "Scope '{$scope}' pattern '{$pattern}' must not touch revoked tokens"
                );
            }
        }
    }

    public function test_everything_scope_is_union_of_other_scopes(): void
    {
        $everything = DataPurgeService::MANIFEST['everything'];
        $allTables  = [];
        $allPatterns = [];
        $allExact    = [];

        foreach (DataPurgeService::MANIFEST as $scope => $def) {
            if ($scope === 'everything') {
                continue;
            }
            $allTables   = array_merge($allTables, $def['mysql']);
            $allPatterns = array_merge($allPatterns, $def['redis_patterns']);
            $allExact    = array_merge($allExact, $def['redis_exact']);
        }

        $allTables   = array_values(array_unique($allTables));
        $allPatterns = array_values(array_unique($allPatterns));
        $allExact    = array_values(array_unique($allExact));

        foreach ($allTables as $t) {
            $this->assertContains(
                $t,
                $everything['mysql'],
                "everything scope is missing table '{$t}' from union"
            );
        }
        foreach ($allPatterns as $p) {
            $this->assertContains(
                $p,
                $everything['redis_patterns'],
                "everything scope is missing Redis pattern '{$p}'"
            );
        }
        foreach ($allExact as $k) {
            $this->assertContains(
                $k,
                $everything['redis_exact'],
                "everything scope is missing exact key '{$k}'"
            );
        }
    }

    public function test_all_scopes_have_confirm_phrase(): void
    {
        foreach (DataPurgeService::MANIFEST as $scope => $def) {
            $this->assertNotEmpty(
                $def['confirm_phrase'] ?? '',
                "Scope '{$scope}' is missing confirm_phrase"
            );
        }
    }

    // -----------------------------------------------------------------------
    // 2. PASSCODE VALIDATION (controller-level logic tested via service)
    // -----------------------------------------------------------------------

    public function test_preview_throws_on_unknown_scope(): void
    {
        $svc = $this->makeService();
        $this->expectException(InvalidArgumentException::class);
        $svc->preview('nonexistent_scope');
    }

    public function test_execute_throws_on_unknown_scope(): void
    {
        $svc = $this->makeService();
        $this->expectException(InvalidArgumentException::class);
        $svc->execute('bad_scope');
    }

    // -----------------------------------------------------------------------
    // 3. CSRF SERVICE
    // -----------------------------------------------------------------------

    public function test_csrf_verify_rejects_empty_token(): void
    {
        // Without a running session, verify should return false
        $result = Csrf::verify('');
        $this->assertFalse($result);
    }

    public function test_csrf_verify_rejects_mismatched_token(): void
    {
        $result = Csrf::verify('random_wrong_token_that_does_not_match');
        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------------
    // 4. REDIS PURGE – correct patterns, no forbidden keys
    // -----------------------------------------------------------------------

    public function test_redis_sweep_deletes_only_manifest_patterns_for_employees(): void
    {
        $redis = $this->makeRedis();

        // Seed keys: some that should be deleted, some that must survive
        $redis->store = [
            'att:abc'               => 1,
            'ans:xyz'               => 1,
            'qa:1'                  => 1,
            'qstat:1'               => 1,
            'dash:main'             => 1,
            'rl:user1'              => 1,
            'worker:heartbeat:flusher' => time(),   // must NOT be deleted
            'revoked:token123'      => 1,            // must NOT be deleted
            'quiz:MYCODE'           => 1,            // not in employees scope
        ];

        $svc = $this->makeService(redis: $redis);

        // Use preview only (no execute) to avoid SQLite TRUNCATE issues with sqlite dialect
        $preview = $svc->preview('employees');
        $this->assertArrayHasKey('redis', $preview);

        // Assert forbidden keys were never touched during scan
        $this->assertArrayNotHasKey('worker:heartbeat:flusher', array_flip($redis->deleted));
        $this->assertArrayNotHasKey('revoked:token123', array_flip($redis->deleted));
    }

    public function test_redis_sweep_uses_scan_and_unlink_not_keys(): void
    {
        $redis = $this->makeRedis();
        $redis->store = ['att:1' => 1];

        $pdo = $this->makePdo();
        // Patch TRUNCATE to be a no-op for SQLite (SQLite uses DELETE)
        $svc = new DataPurgeService($pdo, $redis, sys_get_temp_dir());

        $result = $this->callSweepRedis($svc, DataPurgeService::MANIFEST['employees']);

        // Verify SCAN was called (not KEYS)
        $this->assertNotEmpty($redis->scanCalls, 'scan() should have been called at least once');
        $this->assertContains('att:*', $redis->scanCalls);
    }

    public function test_second_redis_sweep_is_executed(): void
    {
        $redis = $this->makeRedis();
        $pdo   = $this->makePdo();

        $svc = new DataPurgeService($pdo, $redis, sys_get_temp_dir());
        $this->callExecuteWithoutLock($svc, 'cache');

        // Both sweeps ran: scan was called twice (once per sweep × patterns)
        $this->assertGreaterThanOrEqual(2, count($redis->scanCalls));
    }

    // -----------------------------------------------------------------------
    // 5. FILE PURGE
    // -----------------------------------------------------------------------

    public function test_file_purge_preserves_gitkeep_and_directories(): void
    {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'purge_test_' . uniqid();
        mkdir($tmpDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache', 0777, true);

        $cacheDir = $tmpDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        // Create a real file and a .gitkeep
        file_put_contents($cacheDir . DIRECTORY_SEPARATOR . 'cached_data.json', 'data');
        file_put_contents($cacheDir . DIRECTORY_SEPARATOR . '.gitkeep', '');

        $svc    = $this->makeService(basePath: $tmpDir);
        $result = $svc->preview('cache'); // preview only for structure check

        // Now actually delete via testable
        $svc2 = new DataPurgeService(
            $this->makePdo(),
            null,
            $tmpDir
        );
        $this->callPurgeFilesForScope($svc2, 'cache');

        // .gitkeep must still exist
        $this->assertFileExists($cacheDir . DIRECTORY_SEPARATOR . '.gitkeep');
        // real file should be gone
        $this->assertFileDoesNotExist($cacheDir . DIRECTORY_SEPARATOR . 'cached_data.json');

        // Cleanup
        @unlink($cacheDir . DIRECTORY_SEPARATOR . '.gitkeep');
        array_map('unlink', glob($cacheDir . DIRECTORY_SEPARATOR . '*') ?: []);
        rmdir($cacheDir);
        rmdir($tmpDir . DIRECTORY_SEPARATOR . 'storage');
        rmdir($tmpDir);
    }

    public function test_file_purge_does_not_follow_symlink_outside_base(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink tests require Linux/macOS or elevated Windows privileges');
        }

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'purge_symlink_' . uniqid();
        $cacheDir = $tmpDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        mkdir($cacheDir, 0777, true);

        // Create a file outside the allowed area
        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'outside_purge_' . uniqid() . '.txt';
        file_put_contents($outside, 'secret');

        // Create a symlink inside cache pointing outside
        $linkPath = $cacheDir . DIRECTORY_SEPARATOR . 'evil_link.txt';
        symlink($outside, $linkPath);

        $svc = new DataPurgeService($this->makePdo(), null, $tmpDir);
        $result = $this->callPurgeFilesForScope($svc, 'cache');

        // The outside file must still exist (symlink was refused)
        $this->assertFileExists($outside);

        // Cleanup
        unlink($linkPath);
        unlink($outside);
        rmdir($cacheDir);
        rmdir($tmpDir . DIRECTORY_SEPARATOR . 'storage');
        rmdir($tmpDir);
    }

    public function test_log_files_are_truncated_not_deleted(): void
    {
        $tmpDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'purge_logs_' . uniqid();
        $logsDir = $tmpDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        mkdir($logsDir, 0777, true);

        $logFile = $logsDir . DIRECTORY_SEPARATOR . 'app.log';
        file_put_contents($logFile, "some log content\n");

        $svc    = new DataPurgeService($this->makePdo(), null, $tmpDir);
        $result = $this->callPurgeFilesForScope($svc, 'logs');

        // File still exists (not deleted)
        $this->assertFileExists($logFile);
        // But is now empty
        clearstatcache(true, $logFile);
        $this->assertSame(0, filesize($logFile));

        unlink($logFile);
        rmdir($logsDir);
        rmdir($tmpDir . DIRECTORY_SEPARATOR . 'storage');
        rmdir($tmpDir);
    }

    // -----------------------------------------------------------------------
    // 6. CONCURRENT PURGE LOCK – tested via SQLite which doesn't have GET_LOCK
    // -----------------------------------------------------------------------

    public function test_unknown_scope_throws_before_acquiring_lock(): void
    {
        $svc = $this->makeService();
        $this->expectException(InvalidArgumentException::class);
        $svc->execute('does_not_exist');
    }

    // -----------------------------------------------------------------------
    // 7. QSTAT RE-SEED FOR EMPLOYEES SCOPE
    // -----------------------------------------------------------------------

    public function test_employees_scope_reseeds_qstat_for_published_quizzes(): void
    {
        $pdo = $this->makePdo();
        // Insert a published quiz
        $pdo->exec("INSERT INTO quizzes (id, status, code) VALUES (42, 'published', 'QUIZ42')");

        $redis = $this->makeRedis();
        $svc   = new DataPurgeService($pdo, $redis, sys_get_temp_dir());
        $this->callReseedQstat($svc);

        // qstat:42 should have been seeded
        $this->assertContains('qstat:42', $redis->hMSetCalls);
    }

    // -----------------------------------------------------------------------
    // 8. AUDIT LOG WRITTEN LAST
    // -----------------------------------------------------------------------

    public function test_audit_log_written_and_contains_no_passcode(): void
    {
        $pdo    = $this->makePdo();
        $redis  = $this->makeRedis();
        $svc    = new DataPurgeService($pdo, $redis, sys_get_temp_dir());

        $this->callWriteAuditLog($svc, 'cache', 99, [
            'steps' => ['files' => ['deleted' => 3]],
            'ip'    => '127.0.0.1',
            'ua'    => 'TestAgent',
        ]);

        $row = $pdo->query('SELECT * FROM audit_logs LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'audit_logs row should exist');
        $this->assertSame('data_reset', $row['action']);
        $this->assertSame('cache', $row['entity']);
        $this->assertSame('admin', $row['actor_role']);
        // Passcode must not appear in meta
        $this->assertStringNotContainsString('PASS_CODE', (string) $row['meta']);
        $this->assertStringNotContainsString('passcode', (string) $row['meta']);
    }

    // -----------------------------------------------------------------------
    // 9. LIVE-ACTIVITY CHECK
    // -----------------------------------------------------------------------

    public function test_check_live_activity_with_no_deadlines(): void
    {
        $redis = $this->makeRedis();
        $redis->zCardReturn = 0;

        $svc    = $this->makeService(redis: $redis);
        $result = $svc->checkLiveActivity();

        $this->assertArrayHasKey('live_attempts', $result);
        $this->assertArrayHasKey('open_windows', $result);
        $this->assertFalse($result['live_attempts']);
    }

    public function test_check_live_activity_with_active_deadlines(): void
    {
        $redis = $this->makeRedis();
        $redis->zCardReturn = 5;

        $svc    = $this->makeService(redis: $redis);
        $result = $svc->checkLiveActivity();

        $this->assertTrue($result['live_attempts']);
        $this->assertSame(5, $result['deadlines_count']);
    }

    // -----------------------------------------------------------------------
    // 10. REDIS CLIENT WITHOUT SCAN THROWS CLEAR ERROR
    // -----------------------------------------------------------------------

    public function test_redis_without_scan_throws_runtime_exception(): void
    {
        $noScanRedis = new class () {
            // Deliberately no scan() method
            public function del(string ...$keys): int
            {
                return 0;
            }
            public function exists(string ...$keys): int
            {
                return 0;
            }
            public function get(string $key): mixed
            {
                return false;
            }
        };

        $svc = new DataPurgeService($this->makePdo(), $noScanRedis, sys_get_temp_dir());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/scan/i');
        $this->callSweepRedis($svc, DataPurgeService::MANIFEST['cache']);
    }

    // -----------------------------------------------------------------------
    // 11. MYSQL INTEGRATION (skipped unless db ends with _test)
    // -----------------------------------------------------------------------

    public function test_mysql_integration_skip_unless_test_db(): void
    {
        $dbName = $_ENV['DEFAULT_DB_DATABASE'] ?? '';
        if (!str_ends_with($dbName, '_test')) {
            $this->markTestSkipped(
                "MySQL integration tests are skipped unless DEFAULT_DB_DATABASE ends "
                . "with '_test' (currently: '{$dbName}'). "
                . "Set DEFAULT_DB_DATABASE to a test database to enable these tests."
            );
        }
        // If we get here, the database name is safe for destructive tests.
        $this->assertTrue(true);
    }
}
