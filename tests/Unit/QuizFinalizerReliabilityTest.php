<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Commands\QuizFinalizerCommand;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class QuizFinalizerReliabilityTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function test_finalize_batch_uses_processing_queue_and_removes_on_success(): void
    {
        $redisMock = new class {
            public array $fq = ['ATT_REL_01'];
            public array $fqProcessing = [];
            public array $att = [
                'ATT_REL_01' => [
                    'id' => '1001',
                    'quiz_id' => '200',
                    'ver' => '1',
                    'status' => 'COMPLETED',
                    'started_ms' => '1700000000000',
                    'submitted_ms' => '1700000100000',
                    'graded' => '0',
                ],
            ];
            public array $dirtyAtt = [];

            public function lMove(string $src, string $dst, string $whereFrom, string $whereTo): ?string
            {
                if ($src === 'fq' && $dst === 'fq:processing') {
                    $item = array_shift($this->fq);
                    if ($item !== null) {
                        $this->fqProcessing[] = $item;
                    }
                    return $item;
                }
                return null;
            }

            public function lRem(string $key, string $val, int $count = 0): int
            {
                if ($key === 'fq:processing') {
                    $idx = array_search($val, $this->fqProcessing, true);
                    if ($idx !== false) {
                        array_splice($this->fqProcessing, $idx, 1);
                        return 1;
                    }
                }
                return 0;
            }

            public function hGetAll(string $key): array
            {
                if ($key === 'att:ATT_REL_01') {
                    return $this->att['ATT_REL_01'];
                }
                if ($key === 'key:200:1') {
                    return ['1' => '10'];
                }
                if ($key === 'ans:ATT_REL_01') {
                    return ['1' => '10|1|1700000050000'];
                }
                if ($key === 'quiz:RELQZ') {
                    return [
                        'settings' => json_encode([
                            'scoring' => [
                                'marks_per_correct' => 1.0,
                                'negative_marks_per_wrong' => 0.0,
                                'unanswered_penalty' => 0.0,
                            ],
                        ]),
                    ];
                }
                return [];
            }

            public function hGet(string $key, string $field): mixed
            {
                if ($key === 'att:ATT_REL_01' && $field === 'code') {
                    return 'RELQZ';
                }
                return null;
            }

            public function hMSet(string $key, array $data): bool
            {
                if ($key === 'att:ATT_REL_01') {
                    $this->att['ATT_REL_01'] = array_merge($this->att['ATT_REL_01'], $data);
                }
                return true;
            }

            public function sAdd(string $key, string $val): int
            {
                $this->dirtyAtt[] = $val;
                return 1;
            }

            public function exists(string $key): bool
            {
                return false;
            }
        };

        $pdoMock = $this->createMock(PDO::class);
        $finalizer = new QuizFinalizerCommand($pdoMock, $redisMock);

        $graded = $finalizer->finalizeBatch($redisMock, 1);

        $this->assertSame(1, $graded);
        $this->assertSame('1', $redisMock->att['ATT_REL_01']['graded']);
        $this->assertContains('ATT_REL_01', $redisMock->dirtyAtt);
        // Ensure fq:processing has been cleared
        $this->assertEmpty($redisMock->fqProcessing);
        $this->assertEmpty($redisMock->fq);
    }

    public function test_recover_processing_queue_re_queues_ungraded_and_purges_graded(): void
    {
        $redisMock = new class {
            public array $fq = [];
            public array $fqProcessing = ['AID_GRADED', 'AID_UNGRADED'];
            public array $att = [
                'AID_GRADED' => ['graded' => '1'],
                'AID_UNGRADED' => ['graded' => '0'],
            ];

            public function lRange(string $key, int $start, int $stop): array
            {
                if ($key === 'fq:processing') {
                    return $this->fqProcessing;
                }
                return [];
            }

            public function lRem(string $key, string $val, int $count = 0): int
            {
                if ($key === 'fq:processing') {
                    $idx = array_search($val, $this->fqProcessing, true);
                    if ($idx !== false) {
                        array_splice($this->fqProcessing, $idx, 1);
                        return 1;
                    }
                }
                return 0;
            }

            public function rPush(string $key, string $val): int
            {
                if ($key === 'fq') {
                    $this->fq[] = $val;
                    return count($this->fq);
                }
                return 0;
            }

            public function hGetAll(string $key): array
            {
                $aid = str_replace('att:', '', $key);
                return $this->att[$aid] ?? [];
            }
        };

        $pdoMock = $this->createMock(PDO::class);
        $finalizer = new QuizFinalizerCommand($pdoMock, $redisMock);

        $recovered = $finalizer->recoverProcessingQueue($redisMock);

        $this->assertSame(1, $recovered);
        // Ungraded was pushed back to fq
        $this->assertSame(['AID_UNGRADED'], $redisMock->fq);
        // Both were removed from fq:processing
        $this->assertEmpty($redisMock->fqProcessing);
    }

    public function test_sweep_ungraded_completed_attempts_enqueues_into_fq(): void
    {
        $redisMock = new class {
            public array $fq = [];

            public function lRange(string $key, int $start, int $stop): array
            {
                return $this->fq;
            }

            public function hGet(string $key, string $field): mixed
            {
                // Not graded in Redis yet
                return '0';
            }

            public function rPush(string $key, string $val): int
            {
                $this->fq[] = $val;
                return count($this->fq);
            }
        };

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $stmtMock->method('fetchAll')->willReturn([
            ['public_id' => 'COMPLETED_AID_99', 'quiz_id' => 10],
        ]);

        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->method('prepare')->willReturn($stmtMock);

        $finalizer = new QuizFinalizerCommand($pdoMock, $redisMock);
        $reEnqueued = $finalizer->sweepUngradedCompletedAttempts($redisMock, 10);

        $this->assertSame(1, $reEnqueued);
        $this->assertContains('COMPLETED_AID_99', $redisMock->fq);
    }
}
