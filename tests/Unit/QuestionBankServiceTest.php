<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\LayoutGenerator;
use App\Services\QuestionBankService;
use App\Services\QuestionService;
use App\Services\QuizService;
use App\Services\QuizFinalizerService;
use Core\Database;
use InvalidArgumentException;
use PDO;
use Tests\TestCase;

class QuestionBankServiceTest extends TestCase
{
    private PDO $db;
    private QuestionBankService $bankService;
    private QuestionService $questionService;
    private QuizService $quizService;
    private int $adminId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::getInstance()->getConnection();
        $this->bankService = new QuestionBankService($this->db);
        $this->questionService = new QuestionService($this->db);
        $this->quizService = new QuizService($this->db);
    }

    public function test_question_bank_crud_lifecycle(): void
    {
        // 1. Create Question Bank
        $bankId = $this->bankService->create('Compliance & Safety Bank', 'Core bank for 2026', $this->adminId);
        $this->assertGreaterThan(0, $bankId);

        // 2. Fetch by ID
        $bank = $this->bankService->getById($bankId);
        $this->assertNotNull($bank);
        $this->assertSame('Compliance & Safety Bank', $bank['title']);
        $this->assertSame('Core bank for 2026', $bank['description']);

        // 3. Update Bank
        $this->bankService->update($bankId, 'Updated Safety Bank', 'Updated desc');
        $updated = $this->bankService->getById($bankId);
        $this->assertSame('Updated Safety Bank', $updated['title']);
        $this->assertSame('Updated desc', $updated['description']);

        // 4. Add questions to bank
        $qId1 = $this->questionService->addQuestionToBank($bankId, 'What is rule #1?', [
            ['text' => 'Wear PPE', 'is_correct' => true],
            ['text' => 'Ignore PPE', 'is_correct' => false],
        ]);
        $this->assertGreaterThan(0, $qId1);

        $qId2 = $this->questionService->addQuestionToBank($bankId, 'What is rule #2?', [
            ['text' => 'Report incidents', 'is_correct' => true],
            ['text' => 'Hide incidents', 'is_correct' => false],
        ]);
        $this->assertGreaterThan(0, $qId2);

        $questions = $this->questionService->getQuestionsByBank($bankId);
        $this->assertCount(2, $questions);

        // 5. Delete Bank (cascade removes bank questions)
        $this->bankService->delete($bankId);
        $this->assertNull($this->bankService->getById($bankId));

        $remainingQuestions = $this->questionService->getQuestionsByBank($bankId);
        $this->assertEmpty($remainingQuestions);
    }

    public function test_bank_locking_disallows_modifying_questions_when_quiz_is_active(): void
    {
        // Create bank and question
        $bankId = $this->bankService->create('Locked Assessment Bank', 'Lock test', $this->adminId);
        $qId = $this->questionService->addQuestionToBank($bankId, 'Is this locked?', [
            ['text' => 'Yes', 'is_correct' => true],
            ['text' => 'No', 'is_correct' => false],
        ]);

        // Unlocked initially
        $this->assertFalse($this->bankService->isBankLocked($bankId));

        // Create a published active quiz linked to this bank
        $activeQuiz = $this->quizService->create([
            'title' => 'Active Assessment',
            'code' => 'LCK_' . bin2hex(random_bytes(3)),
            'question_bank_id' => $bankId,
            'random_question_count' => 1,
            'duration_seconds' => 1800,
            'start_at' => gmdate('Y-m-d H:i:s', time() - 300), // started 5 mins ago
            'end_at' => gmdate('Y-m-d H:i:s', time() + 3600),   // ends in 1 hour
        ], $this->adminId);
        $quizId = (int) $activeQuiz['id'];
        $this->db->exec("UPDATE quizzes SET status = 'published' WHERE id = {$quizId}");

        try {
            // Verify bank is now locked
            $this->assertTrue($this->bankService->isBankLocked($bankId));

            // Attempting to add a question must throw RuntimeException
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot modify questions in this Question Bank');

            $this->questionService->addQuestionToBank($bankId, 'Another question?', [
                ['text' => 'Option A', 'is_correct' => true],
                ['text' => 'Option B', 'is_correct' => false],
            ]);
        } finally {
            // Cleanup
            $this->db->exec("DELETE FROM quizzes WHERE id = {$quizId}");
            $this->bankService->delete($bankId);
        }
    }

    public function test_random_question_sampling_in_layout_generator(): void
    {
        // 10 question structure
        $structure = [
            '101' => [1, 2],
            '102' => [3, 4],
            '103' => [5, 6],
            '104' => [7, 8],
            '105' => [9, 10],
            '106' => [11, 12],
            '107' => [13, 14],
            '108' => [15, 16],
            '109' => [17, 18],
            '110' => [19, 20],
        ];

        // Sample 4 random questions out of 10
        $layout = LayoutGenerator::generate($structure, true, true, 4);

        $this->assertArrayHasKey('q', $layout);
        $this->assertCount(4, $layout['q']);

        // Ensure all sampled questions exist in the original candidate pool and are unique
        $candidateIds = array_map('intval', array_keys($structure));
        $sampledIds = $layout['q'];

        $this->assertCount(4, array_unique($sampledIds));
        foreach ($sampledIds as $qid) {
            $this->assertContains($qid, $candidateIds);
        }

        // When count is 0 or exceeds pool, serve all questions
        $allLayout = LayoutGenerator::generate($structure, true, false, 0);
        $this->assertCount(10, $allLayout['q']);
    }

    public function test_finalizer_grades_only_sampled_questions_in_attempt_layout(): void
    {
        // Setup bank of 5 questions
        $answerKey = [
            1 => 11,
            2 => 21,
            3 => 31,
            4 => 41,
            5 => 51,
        ];

        // Create quiz
        $quiz = $this->quizService->create([
            'title' => 'Sampled Scoring Quiz',
            'code' => 'SC_' . bin2hex(random_bytes(3)),
            'duration_seconds' => 600,
            'start_at' => gmdate('Y-m-d H:i:s'),
            'end_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'status' => 'published',
            'settings' => [
                'scoring' => [
                    'marks_per_correct' => 2.0,
                    'negative_marks_per_wrong' => 0.5,
                    'unanswered_penalty' => 0.0,
                    'pass_mark' => 2.0,
                ],
            ],
        ], $this->adminId);
        $quizId = (int) $quiz['id'];
        $this->db->exec("UPDATE quizzes SET status = 'published', current_version = 1 WHERE id = {$quizId}");

        // Save snapshot with 5 questions in answerKey
        $this->db->prepare(
            'INSERT INTO quiz_snapshots (quiz_id, version, question_count, bundle_path, bundle_sha256, structure, answer_key, created_at) ' .
            'VALUES (:qid, 1, 5, "/tmp/test", "sha", "{}", :key, NOW())'
        )->execute([
            ':qid' => $quizId,
            ':key' => json_encode($answerKey),
        ]);

        // Insert employee
        $empCode = 'EMP_SAMPLE_' . bin2hex(random_bytes(3));
        $this->db->prepare(
            'INSERT INTO employees (public_id, employee_code, name, status, created_at, updated_at) ' .
            'VALUES (:p, :c, "Sample Employee", "active", NOW(), NOW())'
        )->execute([':p' => bin2hex(random_bytes(8)), ':c' => $empCode]);
        $empId = (int) $this->db->lastInsertId();

        // Create attempt sampled for ONLY questions 2 and 4 (2 questions sampled out of 5)
        $attPublicId = 'ATT_SAMP_' . bin2hex(random_bytes(4));
        $this->db->prepare(
            'INSERT INTO attempts (public_id, quiz_id, quiz_version, employee_id, attempt_no, status, total_questions, started_at, submitted_at) ' .
            'VALUES (:aid, :qid, 1, :eid, 1, "COMPLETED", 2, NOW() - INTERVAL 10 MINUTE, NOW() - INTERVAL 1 MINUTE)'
        )->execute([
            ':aid' => $attPublicId,
            ':qid' => $quizId,
            ':eid' => $empId,
        ]);
        $attId = (int) $this->db->lastInsertId();

        // Mock redis that returns attempt layout with only questions 2 and 4
        $redisMock = new class ($attPublicId) {
            private string $attId;
            public array $att = [];

            public function __construct(string $attId)
            {
                $this->attId = $attId;
            }

            public function hGet(string $key, string $field): ?string
            {
                if ($key === "att:{$this->attId}" && $field === 'layout') {
                    return json_encode(['q' => [2, 4]]);
                }
                return null;
            }

            public function hGetAll(string $key): array
            {
                // Answered Q2 correctly (oid 21), and Q4 incorrectly (oid 99)
                if ($key === "ans:{$this->attId}") {
                    return [
                        '2' => '21|1|1700000000',
                        '4' => '99|2|1700000010',
                    ];
                }
                return [];
            }

            public function hMSet(string $key, array $data): bool
            {
                $this->att[$key] = $data;
                return true;
            }

            public function sRem(string $key, string $val): int
            {
                return 1;
            }

            public function lRem(string $key, string $val, int $count = 0): int
            {
                return 1;
            }

            public function exists(string $key): bool
            {
                return false;
            }
        };

        try {
            $finalizer = new QuizFinalizerService($this->db, $redisMock);
            $result = $finalizer->finalizeBatch($quizId, 10);

            $this->assertTrue($result['success']);
            $this->assertSame(1, $result['batch_graded']);

            // Fetch attempt from database
            $gradedAtt = $this->db->query("SELECT * FROM attempts WHERE id = {$attId}")->fetch(PDO::FETCH_ASSOC);

            // Total questions must be 2 (the sampled questions), NOT 5 (the whole bank)!
            $this->assertSame(2, (int) $gradedAtt['total_questions']);
            $this->assertSame(1, (int) $gradedAtt['correct_count']);
            // Score: 1 correct (+2.0) - 1 wrong (-0.5) = 1.5
            $this->assertEquals(1.5, (float) $gradedAtt['score']);
            // Accuracy: 1 / 2 = 50.0%
            $this->assertEquals(50.0, (float) $gradedAtt['accuracy']);
        } finally {
            // Cleanup
            $this->db->exec("DELETE FROM attempt_answers WHERE attempt_id = {$attId}");
            $this->db->exec("DELETE FROM attempts WHERE id = {$attId}");
            $this->db->exec("DELETE FROM quiz_snapshots WHERE quiz_id = {$quizId}");
            $this->db->exec("DELETE FROM quizzes WHERE id = {$quizId}");
            $this->db->exec("DELETE FROM employees WHERE id = {$empId}");
        }
    }
}
