<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\QuizWarmer;
use Core\Database;
use PDO;

class QuizWarmCommand
{
    public function execute(array $args = []): void
    {
        $target = $args[0] ?? null;

        if (!$target) {
            echo "Usage: php bin/console quiz:warm <quiz_id|code|all>\n";
            return;
        }

        $db = Database::getInstance()->getConnection();
        $warmer = new QuizWarmer($db);

        if ($target === 'all') {
            $stmt = $db->query("SELECT id, code, title FROM quizzes WHERE status = 'published' ORDER BY id ASC");
            $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($quizzes)) {
                echo "No published quizzes found to warm.\n";
                return;
            }

            echo "Warming " . count($quizzes) . " published quiz(zes)...\n";
            foreach ($quizzes as $q) {
                $res = $warmer->warm((int) $q['id']);
                echo " - Quiz [{$q['id']}] {$q['code']}: {$res['message']}\n";
            }
            echo "Done.\n";
            return;
        }

        // Target by ID or code
        if (ctype_digit((string) $target)) {
            $quizId = (int) $target;
        } else {
            $stmt = $db->prepare('SELECT id FROM quizzes WHERE code = :code');
            $stmt->execute(['code' => trim((string) $target)]);
            $quizId = (int) $stmt->fetchColumn();
            if ($quizId === 0) {
                echo "Error: Quiz with code '{$target}' not found.\n";
                return;
            }
        }

        try {
            $res = $warmer->warm($quizId);
            echo "Quiz [{$res['quiz_id']}] {$res['code']}: {$res['message']}\n";
        } catch (\Throwable $e) {
            echo "Error warming quiz {$quizId}: " . $e->getMessage() . "\n";
        }
    }
}
