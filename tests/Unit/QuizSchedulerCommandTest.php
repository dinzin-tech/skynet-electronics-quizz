<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Commands\QuizSchedulerCommand;
use App\Hot\Clock;
use PHPUnit\Framework\TestCase;

class QuizSchedulerCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::setMockNowMs(null);
    }

    public function test_sweep_expired_deadlines_with_mocked_clock(): void
    {
        $graceMs = 3000;
        $deadlineMs = 1_000_000;
        $aid = 'ATT_MOCK_123';
        $quizId = '55';

        $redisStub = new class ($aid, $deadlineMs, $quizId) {
            public array $deadlines = [];
            public array $hashes = [];
            public array $submittedAids = [];
            public array $queries = [];

            public function __construct(string $aid, int $deadlineMs, string $quizId)
            {
                $this->deadlines[$aid] = $deadlineMs;
                $this->hashes["att:{$aid}"] = ['quiz_id' => $quizId];
            }

            public function zRangeByScore(string $key, string $min, string $max, array $options = []): array
            {
                $this->queries[] = ['key' => $key, 'min' => $min, 'max' => $max, 'options' => $options];

                if ($key !== 'deadlines') {
                    return [];
                }

                $minScore = $min === '-inf' ? -INF : (float) $min;
                $maxScore = $max === '+inf' ? INF : (float) $max;

                $matched = [];
                foreach ($this->deadlines as $id => $score) {
                    if ($score >= $minScore && $score <= $maxScore) {
                        $matched[] = (string) $id;
                    }
                }

                $limit = $options['limit'] ?? null;
                if (is_array($limit) && count($limit) === 2) {
                    $matched = array_slice($matched, (int) $limit[0], (int) $limit[1]);
                }

                return $matched;
            }

            public function hGet(string $key, string $field): mixed
            {
                return $this->hashes[$key][$field] ?? null;
            }

            public function evalSha(string $sha, array $args = [], int $numKeys = 0): mixed
            {
                return $this->handleLua($args, $numKeys);
            }

            public function eval(string $script, array $args = [], int $numKeys = 0): mixed
            {
                return $this->handleLua($args, $numKeys);
            }

            public function script(string $command, mixed ...$args): mixed
            {
                return 'sha_' . md5((string) ($args[0] ?? ''));
            }

            private function handleLua(array $args, int $numKeys): string
            {
                $argv = array_slice($args, $numKeys);
                $aid = (string) ($argv[0] ?? '');
                $reason = (string) ($argv[2] ?? '');
                $this->submittedAids[] = ['aid' => $aid, 'reason' => $reason];
                unset($this->deadlines[$aid]);

                return (string) json_encode(['ok' => true, 'status' => 'COMPLETED']);
            }
        };

        $dbMock = $this->createMock(\PDO::class);
        $scheduler = new QuizSchedulerCommand($dbMock, $redisStub, $graceMs);

        // 1. Point: deadline - 1s (attempt still active, must not be submitted)
        $t1 = $deadlineMs - 1000;
        Clock::setMockNowMs($t1);

        $sweptCount1 = $scheduler->sweepExpiredDeadlines($redisStub, Clock::nowMs());
        $this->assertSame(0, $sweptCount1);
        $this->assertArrayHasKey($aid, $redisStub->deadlines);
        $this->assertEmpty($redisStub->submittedAids);
        $lastQuery1 = end($redisStub->queries);
        $this->assertSame('-inf', $lastQuery1['min']);
        $this->assertSame((string) ($t1 - $graceMs), $lastQuery1['max']);

        // 2. Point: deadline + grace - 1s (within grace window, must not be submitted)
        $t2 = $deadlineMs + $graceMs - 1000;
        Clock::setMockNowMs($t2);

        $sweptCount2 = $scheduler->sweepExpiredDeadlines($redisStub, Clock::nowMs());
        $this->assertSame(0, $sweptCount2);
        $this->assertArrayHasKey($aid, $redisStub->deadlines);
        $this->assertEmpty($redisStub->submittedAids);
        $lastQuery2 = end($redisStub->queries);
        $this->assertSame('-inf', $lastQuery2['min']);
        $this->assertSame((string) ($t2 - $graceMs), $lastQuery2['max']);

        // 3. Point: deadline + grace + 1s (expired beyond grace window, must be submitted)
        $t3 = $deadlineMs + $graceMs + 1000;
        Clock::setMockNowMs($t3);

        $sweptCount3 = $scheduler->sweepExpiredDeadlines($redisStub, Clock::nowMs());
        $this->assertSame(1, $sweptCount3);
        $this->assertArrayNotHasKey($aid, $redisStub->deadlines);
        $this->assertCount(1, $redisStub->submittedAids);
        $this->assertSame($aid, $redisStub->submittedAids[0]['aid']);
        $this->assertSame('timeout', $redisStub->submittedAids[0]['reason']);
        $lastQuery3 = end($redisStub->queries);
        $this->assertSame('-inf', $lastQuery3['min']);
        $this->assertSame((string) ($t3 - $graceMs), $lastQuery3['max']);
    }

    public function test_scheduler_loads_grace_ms_from_hot_config(): void
    {
        $dbMock = $this->createMock(\PDO::class);
        $scheduler = new QuizSchedulerCommand($dbMock);

        $configFile = dirname(__DIR__, 2) . '/config/hot.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            $this->assertSame((int) ($config['grace_ms'] ?? 3000), $scheduler->getGraceMs());
        } else {
            $this->assertSame(3000, $scheduler->getGraceMs());
        }
    }

    public function test_hot_php_uses_configured_grace_ms(): void
    {
        $hotPhpContent = (string) file_get_contents(dirname(__DIR__, 2) . '/public/hot.php');

        $this->assertStringNotContainsString("'5000', // 5s network grace", $hotPhpContent);
        $this->assertStringContainsString("(\$config['grace_ms'] ?? 3000)", $hotPhpContent);
    }
}
