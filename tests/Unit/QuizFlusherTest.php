<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Commands\QuizFlusherCommand;
use App\Hot\Ulid;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class QuizFlusherTest extends TestCase
{
    private PDO $db;
    private int $testAttemptId;
    private string $testPublicId;
    private int $testQuizId;
    private int $testEmpId;
    private int $testQId;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->testPublicId = Ulid::generate();

        // Fetch valid quiz, employee, and question
        $this->testQuizId = (int) $this->db->query('SELECT id FROM quizzes LIMIT 1')->fetchColumn();
        $this->testEmpId = (int) $this->db->query('SELECT id FROM employees LIMIT 1')->fetchColumn();
        $this->testQId = (int) $this->db->query('SELECT id FROM questions LIMIT 1')->fetchColumn();

        // Create a test attempt row
        $stmt = $this->db->prepare(
            'INSERT INTO attempts ' .
            '(public_id, quiz_id, quiz_version, employee_id, attempt_no, status, total_questions) ' .
            'VALUES (:pid, :qid, 1, :eid, 99, "IN_PROGRESS", 40)'
        );
        $stmt->execute([
            'pid' => $this->testPublicId,
            'qid' => $this->testQuizId,
            'eid' => $this->testEmpId,
        ]);
        $this->testAttemptId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM attempt_answers WHERE attempt_id = :id')
            ->execute(['id' => $this->testAttemptId]);
        $this->db->prepare('DELETE FROM attempts WHERE id = :id')
            ->execute(['id' => $this->testAttemptId]);
    }

    public function test_flusher_writes_dirty_answers_and_dirty_attempts_to_mysql(): void
    {
        $pid = $this->testPublicId;
        $qid = (string) $this->testQId;
        $optId = (int) $this->db->query('SELECT id FROM answer_options LIMIT 1')->fetchColumn();

        // Create mock Redis containing dirty answers and dirty attempts
        $redisMock = new class ($pid, $qid, $optId, $this->testAttemptId) {
            private string $pid;
            private string $qid;
            private int $optId;
            private int $attId;

            public array $dirty = [];
            public array $dirtyAtt = [];

            public function __construct(string $pid, string $qid, int $optId, int $attId)
            {
                $this->pid = $pid;
                $this->qid = $qid;
                $this->optId = $optId;
                $this->attId = $attId;
                $this->dirty = ["{$pid}:{$qid}"];
                $this->dirtyAtt = [$pid];
            }

            public function sPop(string $key, int $limit = 500): array
            {
                if ($key === 'dirty') {
                    $items = array_splice($this->dirty, 0, $limit);
                    return $items;
                }
                if ($key === 'dirty_att') {
                    $items = array_splice($this->dirtyAtt, 0, $limit);
                    return $items;
                }
                return [];
            }

            public function hGet(string $key, string $field): mixed
            {
                if ($key === "ans:{$this->pid}" && $field === $this->qid) {
                    return "{$this->optId}|5|1700000000000";
                }
                if ($key === "att:{$this->pid}" && $field === 'id') {
                    return (string) $this->attId;
                }
                return null;
            }

            public function hGetAll(string $key): array
            {
                if ($key === "att:{$this->pid}") {
                    return [
                        'status' => 'COMPLETED',
                        'started_ms' => '1700000000000',
                        'deadline_ms' => '1700001800000',
                        'submitted_ms' => '1700001200000',
                        'reason' => 'manual',
                        'layout' => '{"q":[1,2]}',
                        'max_seq' => '5',
                        'graded' => '1',
                        'correct' => '30',
                        'score' => '30.0',
                        'accuracy' => '75.0',
                        'time_s' => '1200',
                    ];
                }
                return [];
            }
        };

        $flusher = new QuizFlusherCommand($this->db, $redisMock);

        // 1. Flush answers
        $flushedAnswers = $flusher->flushAnswers($redisMock, 500);
        $this->assertSame(1, $flushedAnswers);

        // Verify in DB
        $ansStmt = $this->db->prepare('SELECT * FROM attempt_answers WHERE attempt_id = :id AND question_id = :qid');
        $ansStmt->execute(['id' => $this->testAttemptId, 'qid' => $this->testQId]);
        $savedAns = $ansStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($savedAns);
        $this->assertSame($optId, (int) $savedAns['selected_option_id']);
        $this->assertSame(5, (int) $savedAns['seq']);

        // 2. Flush attempts
        $flushedAttempts = $flusher->flushAttempts($redisMock, 500);
        $this->assertSame(1, $flushedAttempts);

        // Verify in DB
        $attStmt = $this->db->prepare('SELECT * FROM attempts WHERE id = :id');
        $attStmt->execute(['id' => $this->testAttemptId]);
        $savedAtt = $attStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($savedAtt);
        $this->assertSame('COMPLETED', $savedAtt['status']);
        $this->assertSame(5, (int) $savedAtt['max_seq']);
        $this->assertSame(30, (int) $savedAtt['correct_count']);
        $this->assertEquals(30.0, (float) $savedAtt['score']);
        $this->assertEquals(75.0, (float) $savedAtt['accuracy']);
        $this->assertSame(1200, (int) $savedAtt['completion_time_s']);
        $this->assertNotNull($savedAtt['graded_at']);
    }
}
