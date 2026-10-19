<?php

declare(strict_types=1);

namespace App\Hot;

/**
 * Server-side Fisher-Yates layout randomization for quiz attempts.
 * Uses cryptographically secure random_int() to generate unique layout permutations.
 */
class LayoutGenerator
{
    /**
     * Generate attempt question & option layout.
     *
     * @param array<int|string, array<int, int>> $structure Map of qid => [oid, oid, ...]
     * @param bool $randomizeQuestions
     * @param bool $randomizeOptions
     * @param int $randomQuestionCount If > 0, randomly samples this number of questions from the structure
     * @return array{q: array<int, int>, o: array<string, array<int, int>>}
     */
    public static function generate(
        array $structure,
        bool $randomizeQuestions = true,
        bool $randomizeOptions = true,
        int $randomQuestionCount = 0
    ): array {
        $mcqQids = [];
        $writtenQids = [];

        // 1. Detect section structure if explicitly defined or partition by options
        if (!empty($structure['sections']) && is_array($structure['sections'])) {
            $mcqQids = array_map('intval', $structure['sections']['mcq'] ?? []);
            $writtenQids = array_map('intval', $structure['sections']['written'] ?? []);
        } elseif (isset($structure['mcq']) || isset($structure['written'])) {
            $mcqQids = array_map('intval', array_keys($structure['mcq'] ?? []));
            $writtenQids = array_map('intval', array_keys($structure['written'] ?? []));
        } else {
            // Flat structure: non-empty options array = MCQ (Section A); empty options = Written (Section B)
            foreach ($structure as $qidStr => $optList) {
                if ($qidStr === 'sections') {
                    continue;
                }
                $qid = (int) $qidStr;
                if (!empty($optList) && is_array($optList)) {
                    $mcqQids[] = $qid;
                } else {
                    $writtenQids[] = $qid;
                }
            }
        }

        // 2. Section A - MCQs: Always shuffle if random question sampling is requested or question randomization is enabled
        if (($randomizeQuestions || $randomQuestionCount > 0) && count($mcqQids) > 1) {
            $mcqQids = self::fisherYatesShuffle($mcqQids);
        }

        // Sample subset of random MCQs if requested (e.g., 10 out of 40)
        if ($randomQuestionCount > 0 && $randomQuestionCount < count($mcqQids)) {
            $mcqQids = array_slice($mcqQids, 0, $randomQuestionCount);
        }

        // 3. Section B - Fixed Written Survey: Always included 100% in fixed order (never randomly dropped)
        $questionIds = array_merge($mcqQids, $writtenQids);

        // 4. Build options map
        $optionsMap = [];
        foreach ($questionIds as $qid) {
            $optionIds = $structure[(string) $qid] ?? $structure[$qid] ?? [];
            if (!is_array($optionIds)) {
                $optionIds = [];
            }
            $optList = array_map('intval', $optionIds);
            if ($randomizeOptions && count($optList) > 1) {
                $optList = self::fisherYatesShuffle($optList);
            }
            $optionsMap[(string) $qid] = $optList;
        }

        return [
            'q' => array_values($questionIds),
            'o' => $optionsMap,
            'sections' => [
                'mcq_count' => count($mcqQids),
                'written_count' => count($writtenQids),
            ],
        ];
    }

    /**
     * In-place Fisher-Yates shuffle using random_int().
     *
     * @template T
     * @param array<int, T> $items
     * @return array<int, T>
     */
    public static function fisherYatesShuffle(array $items): array
    {
        $count = count($items);
        for ($i = $count - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            if ($i !== $j) {
                $temp = $items[$i];
                $items[$i] = $items[$j];
                $items[$j] = $temp;
            }
        }

        return array_values($items);
    }
}
