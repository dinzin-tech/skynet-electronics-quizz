<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\LayoutGenerator;
use PHPUnit\Framework\TestCase;

class LayoutGeneratorTest extends TestCase
{
    public function test_generates_complete_layout_without_dropping_or_duplicating_ids(): void
    {
        // 5 questions, 4 options each
        $structure = [
            '101' => [1001, 1002, 1003, 1004],
            '102' => [1005, 1006, 1007, 1008],
            '103' => [1009, 1010, 1011, 1012],
            '104' => [1013, 1014, 1015, 1016],
            '105' => [1017, 1018, 1019, 1020],
        ];

        $layout = LayoutGenerator::generate($structure, true, true);

        // All questions present
        $this->assertCount(5, $layout['q']);
        $expectedQids = [101, 102, 103, 104, 105];
        $this->assertEqualsCanonicalizing($expectedQids, $layout['q']);

        // All options present per question
        foreach ($structure as $qid => $expectedOids) {
            $this->assertArrayHasKey((string) $qid, $layout['o']);
            $this->assertCount(4, $layout['o'][(string) $qid]);
            $this->assertEqualsCanonicalizing($expectedOids, $layout['o'][(string) $qid]);
        }
    }

    public function test_preserves_order_when_randomization_flags_are_false(): void
    {
        $structure = [
            '1' => [10, 20, 30, 40],
            '2' => [50, 60, 70, 80],
            '3' => [90, 100, 110, 120],
        ];

        $layout = LayoutGenerator::generate($structure, false, false);

        $this->assertSame([1, 2, 3], $layout['q']);
        $this->assertSame([10, 20, 30, 40], $layout['o']['1']);
        $this->assertSame([50, 60, 70, 80], $layout['o']['2']);
        $this->assertSame([90, 100, 110, 120], $layout['o']['3']);
    }

    public function test_fisher_yates_shuffles_elements_non_deterministically(): void
    {
        $original = range(1, 25);
        $differentCount = 0;

        // Run 5 shuffles; at least one should differ from the original order
        for ($i = 0; $i < 5; $i++) {
            $shuffled = LayoutGenerator::fisherYatesShuffle($original);
            $this->assertEqualsCanonicalizing($original, $shuffled);
            if ($shuffled !== $original) {
                $differentCount++;
            }
        }

        $this->assertGreaterThan(0, $differentCount, 'Fisher-Yates should shuffle order of 25 elements');
    }
}
