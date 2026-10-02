<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Ulid;
use App\Services\QuestionService;
use App\Services\QuizService;
use Core\Database;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class QuizServiceTest extends TestCase
{
    private PDO $db;
    private QuizService $quizService;
    private QuestionService $questionService;
    private int $adminId;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->quizService = new QuizService($this->db);
        $this->questionService = new QuestionService($this->db);

        // Fetch or create an admin id for testing
        $adminStmt = $this->db->query('SELECT id FROM administrators LIMIT 1');
        $id = $adminStmt->fetchColumn();
        $this->adminId = $id ? (int) $id : 1;
    }

    public function test_can_create_and_fetch_quiz(): void
    {
        $uniqueCode = 'QZ' . substr(Ulid::generate(), -6);
        $data = [
            'code' => $uniqueCode,
            'title' => 'Unit Test Assessment',
            'description' => 'Test description',
            'instructions' => 'Follow all rules',
            'duration_seconds' => 1200,
            'start_at' => gmdate('Y-m-d H:i:s'),
            'end_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'settings' => [
                'scoring' => [
                    'marks_per_correct' => 2.0,
                    'negative_marks_per_wrong' => 0.5,
                    'pass_mark' => 10.0,
                ],
                'navigation' => [
                    'allow_back' => false,
                    'allow_skip' => false,
                ],
            ],
        ];

        $quiz = $this->quizService->create($data, $this->adminId);

        $this->assertNotEmpty($quiz);
        $this->assertSame($uniqueCode, $quiz['code']);
        $this->assertSame('draft', $quiz['status']);
        $this->assertSame(1200, (int) $quiz['duration_seconds']);
        $this->assertEquals(2.0, (float) $quiz['settings']['scoring']['marks_per_correct']);
        $this->assertEquals(0.5, (float) $quiz['settings']['scoring']['negative_marks_per_wrong']);
        $this->assertFalse($quiz['settings']['navigation']['allow_back']);

        // Fetch by code
        $byCode = $this->quizService->getByCode($uniqueCode);
        $this->assertNotNull($byCode);
        $this->assertSame($quiz['id'], $byCode['id']);

        // Clean up
        $this->quizService->delete((int) $quiz['id']);
    }

    public function test_validates_end_time_must_be_after_start_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quiz end time must be after start time');

        $now = gmdate('Y-m-d H:i:s');
        $this->quizService->create([
            'title' => 'Bad Time Quiz',
            'duration_seconds' => 600,
            'start_at' => $now,
            'end_at' => gmdate('Y-m-d H:i:s', time() - 100),
        ], $this->adminId);
    }

    public function test_can_duplicate_quiz_with_all_questions_and_options(): void
    {
        // 1. Create a draft quiz
        $orig = $this->quizService->create([
            'title' => 'Original Quiz To Duplicate',
            'duration_seconds' => 900,
            'start_at' => gmdate('Y-m-d H:i:s'),
            'end_at' => gmdate('Y-m-d H:i:s', time() + 7200),
        ], $this->adminId);

        $origId = (int) $orig['id'];

        // 2. Add 2 questions with options
        $this->questionService->addQuestion($origId, 'Question 1', [
            ['text' => 'Option 1A', 'is_correct' => true],
            ['text' => 'Option 1B', 'is_correct' => false],
        ]);
        $this->questionService->addQuestion($origId, 'Question 2', [
            ['text' => 'Option 2A', 'is_correct' => false],
            ['text' => 'Option 2B', 'is_correct' => true],
            ['text' => 'Option 2C', 'is_correct' => false],
        ]);

        // 3. Duplicate
        $clone = $this->quizService->duplicate($origId, $this->adminId);

        $this->assertNotEmpty($clone);
        $this->assertNotSame($origId, (int) $clone['id']);
        $this->assertNotSame($orig['code'], $clone['code']);
        $this->assertSame('Copy of ' . $orig['title'], $clone['title']);
        $this->assertSame('draft', $clone['status']);
        $this->assertSame(2, $clone['question_count']);

        // Clean up both
        $this->quizService->delete((int) $clone['id']);
        $this->quizService->delete($origId);
    }

    public function test_can_archive_quiz(): void
    {
        $quiz = $this->quizService->create([
            'title' => 'Quiz To Archive',
            'duration_seconds' => 600,
            'start_at' => gmdate('Y-m-d H:i:s'),
            'end_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $this->adminId);

        $archived = $this->quizService->archive((int) $quiz['id'], $this->adminId);
        $this->assertSame('archived', $archived['status']);

        // Clean up
        $this->db->prepare('DELETE FROM quizzes WHERE id = :id')->execute(['id' => $quiz['id']]);
    }
}
