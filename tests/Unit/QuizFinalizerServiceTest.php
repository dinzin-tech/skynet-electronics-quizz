<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\QuizFinalizerService;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class QuizFinalizerServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Ensure database transactions or cleanup
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function test_get_ungraded_count_and_finalize_batch_with_scoring_rules(): void
    {
        // 1. Create a quiz with scoring rules
        $quizSettings = json_encode([
            'scoring' => [
                'marks_per_correct' => 2.0,
                'negative_marks_per_wrong' => 0.5,
                'unanswered_penalty' => 0.25,
            ],
            'target_groups' => ['all'],
        ]);

        $this->db->prepare(
            'INSERT INTO quizzes (id, public_id, code, title, duration_seconds, start_at, end_at, status, settings, current_version, created_by, created_at, updated_at) ' .
            'VALUES (99901, "QZ_FIN_01", "TEST_FIN", "Finalizer Test Quiz", 600, NOW() - INTERVAL 1 HOUR, NOW() + INTERVAL 1 HOUR, "published", :settings, 1, 1, NOW(), NOW())'
        )->execute(['settings' => $quizSettings]);

        // 2. Create quiz snapshot with answer key for 3 questions
        $answerKey = [
            '1001' => '2001',
            '1002' => '2004',
            '1003' => '2007',
        ];
        $this->db->prepare(
            'INSERT INTO quiz_snapshots (quiz_id, version, question_count, bundle_path, bundle_sha256, structure, answer_key, created_at) ' .
            'VALUES (99901, 1, 3, "/tmp/bundle", "sha256mock", "{}", :key, NOW())'
        )->execute(['key' => json_encode($answerKey)]);

        // 3. Ensure employee exists
        $this->db->prepare(
            'INSERT IGNORE INTO employees (id, public_id, employee_code, name, email, status, created_at, updated_at) ' .
            'VALUES (88801, "EMP_FIN_01", "EMPFIN01", "Test Fin Employee", "fin@corp.test", "active", NOW(), NOW())'
        )->execute();

        // 4. Create an attempt that is COMPLETED but ungraded (graded_at is NULL, score is NULL)
        $this->db->prepare(
            'INSERT INTO attempts (id, public_id, quiz_id, quiz_version, employee_id, attempt_no, status, started_at, submitted_at, total_questions) ' .
            'VALUES (77701, "ATT_FIN_01", 99901, 1, 88801, 1, "COMPLETED", NOW() - INTERVAL 5 MINUTE, NOW() - INTERVAL 2 MINUTE, 3)'
        )->execute();

        // 5. Insert answers: Q1001 is correct (2001), Q1002 is wrong (2005), Q1003 is unanswered
        $this->db->prepare(
            'INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id, seq, answered_at) VALUES ' .
            '(77701, 1001, 2001, 1, NOW() - INTERVAL 4 MINUTE), ' .
            '(77701, 1002, 2005, 2, NOW() - INTERVAL 3 MINUTE)'
        )->execute();

        $service = new QuizFinalizerService($this->db, null);

        // Verify count
        $pending = $service->getUngradedCount(99901);
        $this->assertSame(1, $pending);

        // Run batch finalization
        $result = $service->finalizeBatch(99901, 10);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['batch_graded']);
        $this->assertSame(0, $result['remaining']);

        // Verify updated attempt row
        $stmt = $this->db->prepare('SELECT status, correct_count, score, accuracy, graded_at FROM attempts WHERE id = 77701');
        $stmt->execute();
        $att = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('COMPLETED', $att['status']);
        $this->assertSame(1, (int) $att['correct_count']);
        // Score: (+2.0 for correct) - (0.5 for wrong) - (0.25 for unanswered) = 1.25
        $this->assertEquals(1.25, (float) $att['score']);
        // Accuracy: 1 correct / 3 questions * 100 = 33.33%
        $this->assertEquals(33.33, (float) $att['accuracy']);
        $this->assertNotNull($att['graded_at']);

        // Verify attempt_answers is_correct flags
        $stmt = $this->db->prepare('SELECT question_id, is_correct FROM attempt_answers WHERE attempt_id = 77701 ORDER BY question_id ASC');
        $stmt->execute();
        $ans = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame(1, (int) $ans[1001]);
        $this->assertSame(0, (int) $ans[1002]);

        // Second pass should return 0 remaining
        $this->assertSame(0, $service->getUngradedCount(99901));
    }

    public function test_finalize_batch_with_redis_sync(): void
    {
        $quizSettings = json_encode(['scoring' => ['marks_per_correct' => 1.0]]);
        $this->db->prepare(
            'INSERT INTO quizzes (id, public_id, code, title, duration_seconds, start_at, end_at, status, settings, current_version, created_by, created_at, updated_at) ' .
            'VALUES (99902, "QZ_FIN_02", "TEST_REDIS", "Redis Test Quiz", 600, NOW() - INTERVAL 1 HOUR, NOW() + INTERVAL 1 HOUR, "published", :settings, 1, 1, NOW(), NOW())'
        )->execute(['settings' => $quizSettings]);

        $this->db->prepare(
            'INSERT INTO quiz_snapshots (quiz_id, version, question_count, bundle_path, bundle_sha256, structure, answer_key, created_at) ' .
            'VALUES (99902, 1, 1, "/tmp/bundle", "sha256mock", "{}", :key, NOW())'
        )->execute(['key' => json_encode(['1' => '10'])]);

        $this->db->prepare(
            'INSERT IGNORE INTO employees (id, public_id, employee_code, name, email, status, created_at, updated_at) ' .
            'VALUES (88802, "EMP_FIN_02", "EMPFIN02", "Redis Employee", "red@corp.test", "active", NOW(), NOW())'
        )->execute();

        $this->db->prepare(
            'INSERT INTO attempts (id, public_id, quiz_id, quiz_version, employee_id, attempt_no, status, started_at, submitted_at, total_questions) ' .
            'VALUES (77702, "ATT_REDIS_01", 99902, 1, 88802, 1, "COMPLETED", NOW() - INTERVAL 5 MINUTE, NOW() - INTERVAL 2 MINUTE, 1)'
        )->execute();

        // Create mock Redis
        $redisMock = new class {
            public array $att = [];
            public array $ans = ['ATT_REDIS_01' => ['1' => '10|1|1700000000']];
            public array $dirty = ['ATT_REDIS_01'];
            public array $fq = ['ATT_REDIS_01'];

            public function hGetAll(string $key): array
            {
                if ($key === 'ans:ATT_REDIS_01') {
                    return $this->ans['ATT_REDIS_01'];
                }
                return [];
            }

            public function hMSet(string $key, array $data): bool
            {
                $this->att[$key] = $data;
                return true;
            }

            public function sRem(string $key, string $val): int
            {
                $this->dirty = array_diff($this->dirty, [$val]);
                return 1;
            }

            public function lRem(string $key, string $val, int $count = 0): int
            {
                $this->fq = array_diff($this->fq, [$val]);
                return 1;
            }

            public function exists(string $key): bool
            {
                return false;
            }
        };

        $service = new QuizFinalizerService($this->db, $redisMock);
        $result = $service->finalizeBatch(99902, 10);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['batch_graded']);

        // Verify Redis mock state was updated
        $this->assertArrayHasKey('att:ATT_REDIS_01', $redisMock->att);
        $this->assertSame('1', $redisMock->att['att:ATT_REDIS_01']['graded']);
        $this->assertEquals(1.0, (float) $redisMock->att['att:ATT_REDIS_01']['score']);
        $this->assertNotContains('ATT_REDIS_01', $redisMock->dirty);
        $this->assertNotContains('ATT_REDIS_01', $redisMock->fq);
    }
}
