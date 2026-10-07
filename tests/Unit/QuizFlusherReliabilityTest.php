<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Commands\QuizFlusherCommand;
use Core\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class QuizFlusherReliabilityTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function test_flush_answers_moves_to_processing_and_clears_after_commit(): void
    {
        $redisMock = new class {
            public array $dirty = ['TEST_AID_1:101'];
            public array $processing = [];
            public array $removed = [];

            public function sPop(string $key, int $limit = 500): array
            {
                if ($key === 'dirty') {
                    $items = array_splice($this->dirty, 0, $limit);
                    return $items;
                }
                return [];
            }

            public function sAdd(string $key, string $val): int
            {
                if ($key === 'dirty:processing') {
                    $this->processing[] = $val;
                } elseif ($key === 'dirty') {
                    $this->dirty[] = $val;
                }
                return 1;
            }

            public function sRem(string $key, string $val): int
            {
                if ($key === 'dirty:processing') {
                    $idx = array_search($val, $this->processing, true);
                    if ($idx !== false) {
                        array_splice($this->processing, $idx, 1);
                    }
                    $this->removed[] = $val;
                }
                return 1;
            }

            public function hGet(string $key, string $field): mixed
            {
                if ($key === 'ans:TEST_AID_1' && $field === '101') {
                    return '501|1|1700000000000';
                }
                if ($key === 'att:TEST_AID_1' && $field === 'id') {
                    return '99999';
                }
                return null;
            }
        };

        // Mock PDO to observe transaction and commit
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->expects($this->once())->method('execute')->willReturn(true);

        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->method('prepare')->willReturn($stmtMock);
        $pdoMock->method('inTransaction')->willReturn(false);
        $pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdoMock->expects($this->once())->method('commit')->willReturn(true);

        $flusher = new QuizFlusherCommand($pdoMock, $redisMock);
        $count = $flusher->flushAnswers($redisMock);

        $this->assertSame(1, $count);
        $this->assertContains('TEST_AID_1:101', $redisMock->removed);
        $this->assertEmpty($redisMock->processing);
    }

    public function test_flush_answers_rolls_back_and_re_queues_on_db_exception(): void
    {
        $redisMock = new class {
            public array $dirty = ['TEST_AID_2:102'];
            public array $processing = [];

            public function sPop(string $key, int $limit = 500): array
            {
                if ($key === 'dirty') {
                    return array_splice($this->dirty, 0, $limit);
                }
                return [];
            }

            public function sAdd(string $key, string $val): int
            {
                if ($key === 'dirty:processing') {
                    $this->processing[] = $val;
                } elseif ($key === 'dirty') {
                    $this->dirty[] = $val;
                }
                return 1;
            }

            public function sRem(string $key, string $val): int
            {
                if ($key === 'dirty:processing') {
                    $idx = array_search($val, $this->processing, true);
                    if ($idx !== false) {
                        array_splice($this->processing, $idx, 1);
                    }
                }
                return 1;
            }

            public function hGet(string $key, string $field): mixed
            {
                if ($key === 'ans:TEST_AID_2' && $field === '102') {
                    return '502|1|1700000000000';
                }
                if ($key === 'att:TEST_AID_2' && $field === 'id') {
                    return '88888';
                }
                return null;
            }
        };

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willThrowException(new PDOException('Deadlock found'));

        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->method('prepare')->willReturn($stmtMock);
        $pdoMock->method('inTransaction')->willReturnOnConsecutiveCalls(false, true, true);
        $pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdoMock->expects($this->once())->method('rollBack')->willReturn(true);

        $flusher = new QuizFlusherCommand($pdoMock, $redisMock);

        try {
            $flusher->flushAnswers($redisMock);
            $this->fail('Expected PDOException was not thrown');
        } catch (PDOException $e) {
            $this->assertSame('Deadlock found', $e->getMessage());
        }

        // Verify items were restored back to dirty and not lost!
        $this->assertContains('TEST_AID_2:102', $redisMock->dirty);
        $this->assertEmpty($redisMock->processing);
    }

    public function test_flush_attempts_rolls_back_and_re_queues_on_db_exception(): void
    {
        $redisMock = new class {
            public array $dirtyAtt = ['TEST_AID_3'];
            public array $processing = [];

            public function sPop(string $key, int $limit = 500): array
            {
                if ($key === 'dirty_att') {
                    return array_splice($this->dirtyAtt, 0, $limit);
                }
                return [];
            }

            public function sAdd(string $key, string $val): int
            {
                if ($key === 'dirty_att:processing') {
                    $this->processing[] = $val;
                } elseif ($key === 'dirty_att') {
                    $this->dirtyAtt[] = $val;
                }
                return 1;
            }

            public function sRem(string $key, string $val): int
            {
                if ($key === 'dirty_att:processing') {
                    $idx = array_search($val, $this->processing, true);
                    if ($idx !== false) {
                        array_splice($this->processing, $idx, 1);
                    }
                }
                return 1;
            }

            public function hGetAll(string $key): array
            {
                if ($key === 'att:TEST_AID_3') {
                    return [
                        'status' => 'COMPLETED',
                        'started_ms' => '1700000000000',
                        'deadline_ms' => '1700001000000',
                        'submitted_ms' => '1700000500000',
                        'max_seq' => '2',
                    ];
                }
                return [];
            }
        };

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willThrowException(new PDOException('MySQL server has gone away'));

        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->method('prepare')->willReturn($stmtMock);
        $pdoMock->method('inTransaction')->willReturnOnConsecutiveCalls(false, true, true);
        $pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdoMock->expects($this->once())->method('rollBack')->willReturn(true);

        $flusher = new QuizFlusherCommand($pdoMock, $redisMock);

        try {
            $flusher->flushAttempts($redisMock);
            $this->fail('Expected PDOException was not thrown');
        } catch (PDOException $e) {
            $this->assertSame('MySQL server has gone away', $e->getMessage());
        }

        // Verify attempts restored to dirty_att
        $this->assertContains('TEST_AID_3', $redisMock->dirtyAtt);
        $this->assertEmpty($redisMock->processing);
    }

    public function test_recover_processing_sets_re_queues_orphaned_items(): void
    {
        $redisMock = new class {
            public array $dirty = [];
            public array $dirtyProcessing = ['AID_ORPHAN_1:Q1', 'AID_ORPHAN_2:Q2'];
            public array $dirtyAtt = [];
            public array $dirtyAttProcessing = ['AID_ORPHAN_ATT_1'];

            public function sMembers(string $key): array
            {
                if ($key === 'dirty:processing') {
                    return $this->dirtyProcessing;
                }
                if ($key === 'dirty_att:processing') {
                    return $this->dirtyAttProcessing;
                }
                return [];
            }

            public function sAdd(string $key, string $val): int
            {
                if ($key === 'dirty') {
                    $this->dirty[] = $val;
                } elseif ($key === 'dirty_att') {
                    $this->dirtyAtt[] = $val;
                }
                return 1;
            }

            public function sRem(string $key, string $val): int
            {
                if ($key === 'dirty:processing') {
                    $idx = array_search($val, $this->dirtyProcessing, true);
                    if ($idx !== false) {
                        array_splice($this->dirtyProcessing, $idx, 1);
                    }
                } elseif ($key === 'dirty_att:processing') {
                    $idx = array_search($val, $this->dirtyAttProcessing, true);
                    if ($idx !== false) {
                        array_splice($this->dirtyAttProcessing, $idx, 1);
                    }
                }
                return 1;
            }
        };

        $pdoMock = $this->createMock(PDO::class);
        $flusher = new QuizFlusherCommand($pdoMock, $redisMock);

        $res = $flusher->recoverProcessingSets($redisMock);

        $this->assertSame(2, $res['answers_recovered']);
        $this->assertSame(1, $res['attempts_recovered']);

        $this->assertEquals(['AID_ORPHAN_1:Q1', 'AID_ORPHAN_2:Q2'], $redisMock->dirty);
        $this->assertEquals(['AID_ORPHAN_ATT_1'], $redisMock->dirtyAtt);
        $this->assertEmpty($redisMock->dirtyProcessing);
        $this->assertEmpty($redisMock->dirtyAttProcessing);
    }
}
