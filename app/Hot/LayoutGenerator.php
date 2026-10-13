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
        $questionIds = array_map('intval', array_keys($structure));

        // Always shuffle if random question sampling is requested or question randomization is enabled
        if (($randomizeQuestions || $randomQuestionCount > 0) && count($questionIds) > 1) {
            $questionIds = self::fisherYatesShuffle($questionIds);
        }

        // Sample subset of random questions if requested
        if ($randomQuestionCount > 0 && $randomQuestionCount < count($questionIds)) {
            $questionIds = array_slice($questionIds, 0, $randomQuestionCount);
        }

        $optionsMap = [];
        foreach ($questionIds as $qid) {
            $optionIds = $structure[(string) $qid] ?? $structure[$qid] ?? [];
            $optList = array_map('intval', $optionIds);
            if ($randomizeOptions && count($optList) > 1) {
                $optList = self::fisherYatesShuffle($optList);
            }
            $optionsMap[(string) $qid] = $optList;
        }

        return [
            'q' => array_values($questionIds),
            'o' => $optionsMap,
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
