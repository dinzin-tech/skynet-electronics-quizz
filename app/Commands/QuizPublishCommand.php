<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\QuizPublisher;
use Core\Database;
use PDO;

class QuizPublishCommand
{
    public function execute(array $args = []): void
    {
        $target = $args[0] ?? null;

        if (!$target) {
            echo "Usage: php bin/console quiz:publish <quiz_id|all>\n";
            return;
        }

        $db = Database::getInstance()->getConnection();
        $publisher = new QuizPublisher($db);

        // Fetch an admin ID
        $adminStmt = $db->query('SELECT id FROM administrators LIMIT 1');
        $adminId = (int) ($adminStmt->fetchColumn() ?: 1);

        if ($target === 'all') {
            $stmt = $db->query("SELECT id, code, title FROM quizzes WHERE status != 'archived' ORDER BY id ASC");
            $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($quizzes)) {
                echo "No unarchived quizzes found.\n";
                return;
            }

            echo "Publishing " . count($quizzes) . " quiz(zes)...\n";
            foreach ($quizzes as $q) {
                try {
                    $res = $publisher->publish((int) $q['id'], $adminId);
                    echo " - Quiz [{$q['id']}] {$q['code']}: Version {$res['version']}, " .
                         "Roster: {$res['roster_count']} employees, " .
                         "Bundle: {$res['bundle_sha256']}\n";
                } catch (\Throwable $e) {
                    echo " - Quiz [{$q['id']}] {$q['code']}: " . $e->getMessage() . "\n";
                }
            }
            echo "Done.\n";
            return;
        }

        $quizId = (int) $target;
        try {
            $res = $publisher->publish($quizId, $adminId);
            echo "Quiz [{$res['quiz_id']}] published successfully!\n";
            echo " - Version: {$res['version']}\n";
            echo " - Roster: {$res['roster_count']} eligible employees materialized\n";
            echo " - Bundle SHA256: {$res['bundle_sha256']}\n";
            echo " - Shareable URL: {$res['shareable_url']}\n";
        } catch (\Throwable $e) {
            echo "Error publishing quiz {$quizId}: " . $e->getMessage() . "\n";
        }
    }
}
