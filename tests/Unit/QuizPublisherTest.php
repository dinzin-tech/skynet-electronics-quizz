<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Ulid;
use App\Services\QuestionService;
use App\Services\QuizPublisher;
use App\Services\QuizService;
use Core\Database;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class QuizPublisherTest extends TestCase
{
    private PDO $db;
    private QuizService $quizService;
    private QuestionService $questionService;
    private QuizPublisher $quizPublisher;
    private int $adminId;
    private string $tempBundleDir;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->quizService = new QuizService($this->db);
        $this->questionService = new QuestionService($this->db);
        $this->quizPublisher = new QuizPublisher($this->db, $this->questionService);

        $adminStmt = $this->db->query('SELECT id FROM administrators LIMIT 1');
        $id = $adminStmt->fetchColumn();
        $this->adminId = $id ? (int) $id : 1;

        $this->tempBundleDir = dirname(__DIR__, 2) . '/storage/test_bundles';
        if (!is_dir($this->tempBundleDir)) {
            mkdir($this->tempBundleDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean test bundle directory
        if (is_dir($this->tempBundleDir)) {
            $files = glob($this->tempBundleDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($this->tempBundleDir);
        }
    }

    public function test_cannot_publish_quiz_without_questions(): void
    {
        $quiz = $this->quizService->create([
            'title' => 'Empty Quiz Test',
            'duration_seconds' => 600,
            'start_at' => gmdate('Y-m-d H:i:s'),
            'end_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $this->adminId);

        $quizId = (int) $quiz['id'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quiz must contain at least one question');

        try {
            $this->quizPublisher->publish($quizId, $this->adminId, $this->tempBundleDir);
        } finally {
            $this->quizService->delete($quizId);
        }
    }

    public function test_publish_creates_bundle_without_correctness_data_and_materializes_roster(): void
    {
        // 1. Create Quiz
        $quiz = $this->quizService->create([
            'title' => 'Publish Integrity Assessment',
            'duration_seconds' => 1800,
            'start_at' => gmdate('Y-m-d H:i:s'),
            'end_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            'settings' => [
                'target_groups' => [], // all employees
            ],
        ], $this->adminId);
        $quizId = (int) $quiz['id'];

        // 2. Add Questions with correct options
        $q1 = $this->questionService->addQuestion($quizId, 'What is the corporate primary color?', [
            ['text' => 'Blue', 'is_correct' => true],
            ['text' => 'Green', 'is_correct' => false],
            ['text' => 'Red', 'is_correct' => false],
        ]);

        $q2 = $this->questionService->addQuestion($quizId, 'Where is headquarters located?', [
            ['text' => 'London', 'is_correct' => false],
            ['text' => 'New York', 'is_correct' => true],
        ]);

        // 3. Publish Quiz
        $pubResult = $this->quizPublisher->publish($quizId, $this->adminId, $this->tempBundleDir);

        $this->assertSame($quizId, $pubResult['quiz_id']);
        $this->assertSame(1, $pubResult['version']);
        $this->assertNotEmpty($pubResult['bundle_sha256']);
        $this->assertFileExists($pubResult['bundle_path']);
        $this->assertFileExists($pubResult['bundle_path'] . '.gz');

        // 4. CRITICAL: Test bundle JSON contains ZERO correctness data!
        $bundleJson = (string) file_get_contents($pubResult['bundle_path']);
        $this->assertStringNotContainsString('is_correct', $bundleJson);
        $this->assertStringNotContainsString('answer_key', $bundleJson);

        $bundleData = json_decode($bundleJson, true);
        $this->assertNotNull($bundleData);
        $this->assertCount(2, $bundleData['questions']);
        foreach ($bundleData['questions'] as $bundleQ) {
            foreach ($bundleQ['options'] as $bundleOpt) {
                $this->assertArrayNotHasKey('is_correct', $bundleOpt);
            }
        }

        // 5. Verify Quiz Snapshot in DB
        $snapStmt = $this->db->prepare('SELECT * FROM quiz_snapshots WHERE quiz_id = :qid AND version = 1');
        $snapStmt->execute(['qid' => $quizId]);
        $snapshot = $snapStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($snapshot);
        $this->assertSame(2, (int) $snapshot['question_count']);
        $this->assertSame($pubResult['bundle_sha256'], $snapshot['bundle_sha256']);

        $answerKey = json_decode((string) $snapshot['answer_key'], true);
        $this->assertCount(2, $answerKey);
        // Correct answer for Q1 must be option 'Blue'
        $this->assertSame((int) $q1['options'][0]['id'], (int) $answerKey[(string) $q1['id']]);
        // Correct answer for Q2 must be option 'New York'
        $this->assertSame((int) $q2['options'][1]['id'], (int) $answerKey[(string) $q2['id']]);

        // 6. Verify Materialized Attempts
        $attStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM attempts WHERE quiz_id = :qid AND status = "NOT_STARTED"'
        );
        $attStmt->execute(['qid' => $quizId]);
        $attemptsCount = (int) $attStmt->fetchColumn();

        // Should match total active employees
        $activeEmpCount = (int) $this->db->query(
            'SELECT COUNT(*) FROM employees WHERE status = "active"'
        )->fetchColumn();
        $this->assertSame($activeEmpCount, $attemptsCount);
        $this->assertGreaterThan(0, $attemptsCount);

        // 7. Cleanup
        $this->db->prepare('DELETE FROM attempts WHERE quiz_id = :qid')->execute(['qid' => $quizId]);
        $this->db->prepare('DELETE FROM quiz_snapshots WHERE quiz_id = :qid')->execute(['qid' => $quizId]);
        $this->db->prepare('DELETE FROM answer_options WHERE question_id IN (:q1, :q2)')->execute([
            'q1' => $q1['id'],
            'q2' => $q2['id'],
        ]);
        $this->db->prepare('DELETE FROM questions WHERE id IN (:q1, :q2)')->execute([
            'q1' => $q1['id'],
            'q2' => $q2['id'],
        ]);
        $this->db->prepare('DELETE FROM quizzes WHERE id = :id')->execute(['id' => $quizId]);
    }
}
