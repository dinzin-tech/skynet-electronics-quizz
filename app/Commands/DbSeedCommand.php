<?php

declare(strict_types=1);

namespace App\Commands;

use App\Hot\Ulid;
use Core\Database;
use PDO;

/**
 * Database seeder generating:
 * - 1 Administrator (credentials printed once)
 * - 5 Demo User Groups
 * - 2,500 Employees assigned to groups
 * - 2 Quizzes with 40 questions each (160 options total each)
 */
class DbSeedCommand
{
    public function execute(array $args = []): void
    {
        $db = Database::getInstance()->getConnection();
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        echo "=== Seeding Database (Phase 2) ===\n";

        $db->beginTransaction();

        try {
            // 1. Seed Administrator
            $adminEmail = 'admin@corp.local';
            $adminPlainPassword = 'Admin#Pass' . bin2hex(random_bytes(4)) . '!';
            $adminHash = password_hash($adminPlainPassword, PASSWORD_BCRYPT, ['cost' => 10]);

            $db->exec("DELETE FROM administrators WHERE email = 'admin@corp.local'");
            $adminStmt = $db->prepare(
                'INSERT INTO administrators (name, email, password_hash, status, created_at) ' .
                'VALUES (:name, :email, :hash, :status, NOW())'
            );
            $adminStmt->execute([
                'name' => 'System Administrator',
                'email' => $adminEmail,
                'hash' => $adminHash,
                'status' => 'active',
            ]);
            $adminId = (int) $db->lastInsertId();

            echo "--------------------------------------------------------\n";
            echo "ADMINISTRATOR CREATED (save these credentials):\n";
            echo "  Email:    {$adminEmail}\n";
            echo "  Password: {$adminPlainPassword}\n";
            echo "--------------------------------------------------------\n";

            // 2. Seed User Groups
            $groups = [
                ['name' => 'Engineering', 'description' => 'Software and QA Engineering'],
                ['name' => 'Product & Design', 'description' => 'Product Managers and Designers'],
                ['name' => 'Operations', 'description' => 'Business Operations & Support'],
                ['name' => 'Sales & Marketing', 'description' => 'Go-To-Market Teams'],
                ['name' => 'Finance & HR', 'description' => 'Corporate Services'],
            ];

            $groupSql = 'INSERT INTO `groups` (name, description, created_at, updated_at) ' .
                        'VALUES (:name, :desc, NOW(), NOW()) ' .
                        'ON DUPLICATE KEY UPDATE description = VALUES(description)';
            $groupStmt = $db->prepare($groupSql);
            $groupIds = [];
            foreach ($groups as $g) {
                $groupStmt->execute(['name' => $g['name'], 'desc' => $g['description']]);
                $fetchStmt = $db->prepare('SELECT id FROM `groups` WHERE name = :name');
                $fetchStmt->execute(['name' => $g['name']]);
                $groupIds[] = (int) $fetchStmt->fetchColumn();
            }
            echo "Seeded " . count($groupIds) . " user groups.\n";

            // 3. Seed 2,500 Employees (Chunked 500 per batch)
            $existingCount = (int) $db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
            if ($existingCount < 2500) {
                echo "Seeding 2,500 employees (chunked inserts)...\n";
                $defaultPasswordHash = password_hash('QuizPass2026!', PASSWORD_BCRYPT, ['cost' => 10]);
                $totalEmployees = 2500;
                $batchSize = 500;

                for ($i = 1; $i <= $totalEmployees; $i += $batchSize) {
                    $empRows = [];
                    $empBindings = [];

                    for ($j = $i; $j < $i + $batchSize && $j <= $totalEmployees; $j++) {
                        $code = sprintf('EMP%04d', $j);
                        $publicId = Ulid::generate();
                        $name = "Employee {$j}";
                        $email = "emp{$j}@corp.local";
                        $username = "emp" . sprintf('%04d', $j);
                        $status = ($j > 2450) ? 'inactive' : 'active'; // 50 inactive

                        $empRows[] = "(?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                        array_push(
                            $empBindings,
                            $publicId,
                            $code,
                            $name,
                            $email,
                            $username,
                            $defaultPasswordHash,
                            $status
                        );
                    }

                    $sql = "INSERT INTO employees " .
                           "(public_id, employee_code, name, email, username, " .
                           "password_hash, status, created_at, updated_at) " .
                           "VALUES " . implode(', ', $empRows) . " " .
                           "ON DUPLICATE KEY UPDATE name=VALUES(name)";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($empBindings);
                }

                // Associate Employees to Groups
                echo "Associating employees with groups...\n";
                $empIds = $db->query("SELECT id FROM employees ORDER BY id ASC LIMIT 2500")
                    ->fetchAll(PDO::FETCH_COLUMN);
                $egRows = [];
                $egBindings = [];
                $groupCount = count($groupIds);

                foreach ($empIds as $idx => $eId) {
                    $gId = $groupIds[$idx % $groupCount];
                    $egRows[] = "(?, ?)";
                    $egBindings[] = $eId;
                    $egBindings[] = $gId;

                    if (count($egRows) >= 500) {
                        $egSql = "INSERT IGNORE INTO employee_groups (employee_id, group_id) VALUES " .
                                 implode(', ', $egRows);
                        $db->prepare($egSql)->execute($egBindings);
                        $egRows = [];
                        $egBindings = [];
                    }
                }
                if (!empty($egRows)) {
                    $egSql = "INSERT IGNORE INTO employee_groups (employee_id, group_id) VALUES " .
                             implode(', ', $egRows);
                    $db->prepare($egSql)->execute($egBindings);
                }
                echo "2,500 employees seeded and grouped.\n";
            } else {
                echo "Employees table already contains {$existingCount} records. Skipping employee seeding.\n";
            }

            // 4. Seed 2 Quizzes with 40 questions each
            $quizCount = (int) $db->query("SELECT COUNT(*) FROM quizzes")->fetchColumn();
            if ($quizCount < 2) {
                echo "Seeding 2 Quizzes with 40 questions each...\n";

                $quizConfigs = [
                    [
                        'code' => 'COMP2026',
                        'title' => 'Company Policy & Compliance Assessment 2026',
                        'desc' => 'Mandatory annual compliance and ethical conduct assessment.',
                        'instructions' => 'Answer all 40 questions. Each question has exactly one correct answer.',
                        'duration' => 1800, // 30 min
                        'settings' => [
                            'scoring' => [
                                'marks_per_correct' => 1.0,
                                'negative_marks_per_wrong' => 0.0,
                                'unanswered_penalty' => 0.0,
                                'pass_mark' => 20.0,
                                'result_visibility' => 'score_only',
                            ],
                            'navigation' => [
                                'allow_back' => true,
                                'allow_skip' => true,
                                'allow_review_screen' => true,
                                'randomize_questions' => true,
                                'randomize_options' => true,
                            ],
                        ],
                    ],
                    [
                        'code' => 'SECU2026',
                        'title' => 'Security & Data Protection Standards',
                        'desc' => 'Core security practices, phishing defense, and confidentiality standards.',
                        'instructions' => 'Negative marking applies (-0.5 per wrong answer).',
                        'duration' => 2400, // 40 min
                        'settings' => [
                            'scoring' => [
                                'marks_per_correct' => 2.0,
                                'negative_marks_per_wrong' => 0.5,
                                'unanswered_penalty' => 0.0,
                                'pass_mark' => 50.0,
                                'result_visibility' => 'score_and_review',
                            ],
                            'navigation' => [
                                'allow_back' => true,
                                'allow_skip' => true,
                                'allow_review_screen' => true,
                                'randomize_questions' => true,
                                'randomize_options' => true,
                            ],
                        ],
                    ],
                ];

                foreach ($quizConfigs as $qc) {
                    $quizPublicId = Ulid::generate();
                    $startAt = gmdate('Y-m-d H:i:s', time() - 3600); // 1 hr ago
                    $endAt = gmdate('Y-m-d H:i:s', time() + 86400 * 7); // 7 days from now

                    $qSql = 'INSERT INTO quizzes ' .
                        '(public_id, code, title, description, instructions, duration_seconds, ' .
                        'start_at, end_at, status, settings, created_by, created_at, updated_at) ' .
                        'VALUES (:pub, :code, :title, :desc, :inst, :dur, :start, :end, ' .
                        '"published", :settings, :creator, NOW(), NOW())';
                    $qStmt = $db->prepare($qSql);

                    $qStmt->execute([
                        'pub' => $quizPublicId,
                        'code' => $qc['code'],
                        'title' => $qc['title'],
                        'desc' => $qc['desc'],
                        'inst' => $qc['instructions'],
                        'dur' => $qc['duration'],
                        'start' => $startAt,
                        'end' => $endAt,
                        'settings' => json_encode($qc['settings']),
                        'creator' => $adminId,
                    ]);

                    $quizId = (int) $db->lastInsertId();

                    // Seed 40 questions with 4 options each
                    for ($qNum = 1; $qNum <= 40; $qNum++) {
                        $qInsertSql = 'INSERT INTO questions (quiz_id, question_text, display_order) ' .
                                      'VALUES (:qid, :text, :ord)';
                        $qInsert = $db->prepare($qInsertSql);
                        $qInsert->execute([
                            'qid' => $quizId,
                            'text' => "Question {$qNum}: What is the primary procedure for {$qc['title']}?",
                            'ord' => $qNum,
                        ]);
                        $questionId = (int) $db->lastInsertId();

                        // 4 Answer options (Option 1 is correct)
                        $optSql = 'INSERT INTO answer_options ' .
                                  '(question_id, option_text, is_correct, display_order) VALUES (?, ?, ?, ?)';
                        $optStmt = $db->prepare($optSql);
                        for ($optNum = 1; $optNum <= 4; $optNum++) {
                            $isCorrect = ($optNum === 1) ? 1 : 0;
                            $optText = "Option {$optNum} description for Question {$qNum}";
                            $optStmt->execute([$questionId, $optText, $isCorrect, $optNum]);
                        }
                    }
                    echo "Seeded Quiz '{$qc['title']}' with 40 questions and 160 options.\n";
                }
            } else {
                echo "Quizzes table already contains records. Skipping quiz seeding.\n";
            }

            $db->commit();
            echo "=== Seeding Complete ===\n";
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
