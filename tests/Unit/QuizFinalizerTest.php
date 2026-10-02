<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Commands\QuizFinalizerCommand;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class QuizFinalizerTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function test_grades_answers_with_scoring_rules_and_calculates_accuracy(): void
    {
        // Create an in-memory Redis mock
        $redisMock = new class {
            public array $fq = ['ATT_TEST_01'];
            public array $att = [
                'ATT_TEST_01' => [
                    'id' => '9999',
                    'quiz_id' => '100',
                    'ver' => '1',
                    'status' => 'COMPLETED',
                    'started_ms' => '1700000000000',
                    'submitted_ms' => '1700000300000', // 300s = 5 min
                    'graded' => '0',
                ],
            ];
            public array $ans = [
                'ATT_TEST_01' => [
                    '1' => '101|1|1700000050000', // Correct
                    '2' => '201|2|1700000060000', // Correct
                    '3' => '302|3|1700000070000', // Wrong (expected 301)
                    '4' => '402|4|1700000080000', // Wrong (expected 401)
                    // 5 is unanswered
                ],
            ];
            public array $keys = [
                '100:1' => [
                    '1' => '101',
                    '2' => '201',
                    '3' => '301',
                    '4' => '401',
                    '5' => '501',
                ],
            ];
            public array $dirtyAtt = [];

            public function lPop(string $key): ?string
            {
                return array_shift($this->fq);
            }

            public function hGetAll(string $key): array
            {
                if ($key === 'att:ATT_TEST_01') {
                    return $this->att['ATT_TEST_01'];
                }
                if ($key === 'ans:ATT_TEST_01') {
                    return $this->ans['ATT_TEST_01'];
                }
                if ($key === 'key:100:1') {
                    return $this->keys['100:1'];
                }
                if ($key === 'quiz:TESTQZ') {
                    return [
                        'settings' => json_encode([
                            'scoring' => [
                                'marks_per_correct' => 2.0,
                                'negative_marks_per_wrong' => 0.5,
                                'unanswered_penalty' => 0.25,
                            ],
                        ]),
                    ];
                }
                return [];
            }

            public function hGet(string $key, string $field): mixed
            {
                if ($key === 'att:ATT_TEST_01' && $field === 'code') {
                    return 'TESTQZ';
                }
                return null;
            }

            public function hMSet(string $key, array $data): bool
            {
                if ($key === 'att:ATT_TEST_01') {
                    $this->att['ATT_TEST_01'] = array_merge($this->att['ATT_TEST_01'], $data);
                }
                return true;
            }

            public function sAdd(string $key, string $val): int
            {
                $this->dirtyAtt[] = $val;
                return 1;
            }
        };

        $finalizer = new QuizFinalizerCommand($this->db, $redisMock);
        $gradedCount = $finalizer->finalizeBatch($redisMock, 10);

        $this->assertSame(1, $gradedCount);

        $gradedAtt = $redisMock->att['ATT_TEST_01'];
        $this->assertSame('1', $gradedAtt['graded']);
        $this->assertSame('2', $gradedAtt['correct']); // 2 correct out of 5
        // Score: (2 * 2.0) - (2 * 0.5) - (1 * 0.25) = 4.0 - 1.0 - 0.25 = 2.75
        $this->assertEquals(2.75, (float) $gradedAtt['score']);
        // Accuracy: 2 / 5 * 100 = 40.0%
        $this->assertEquals(40.0, (float) $gradedAtt['accuracy']);
        // Completion time: (1700000300000 - 1700000000000) / 1000 = 300s
        $this->assertSame('300', $gradedAtt['time_s']);
        $this->assertContains('ATT_TEST_01', $redisMock->dirtyAtt);
    }
}
