<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SubmissionsService;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class SubmissionsServiceTest extends TestCase
{
    private PDO $db;
    private SubmissionsService $service;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->service = new SubmissionsService($this->db);
    }

    public function test_get_submissions_keyset_pagination(): void
    {
        $page1 = $this->service->getSubmissions(['limit' => 2]);

        $this->assertArrayHasKey('items', $page1);
        $this->assertArrayHasKey('has_more', $page1);
        $this->assertArrayHasKey('total_count', $page1);

        if (count($page1['items']) === 2 && $page1['has_more']) {
            $this->assertNotNull($page1['next_cursor']);

            // Fetch page 2 using keyset cursor
            $page2 = $this->service->getSubmissions([
                'limit' => 2,
                'cursor' => $page1['next_cursor'],
            ]);

            $this->assertNotEmpty($page2['items']);
            // Keyset guarantee: every id in page2 must be strictly less than next_cursor
            foreach ($page2['items'] as $item) {
                $this->assertLessThan($page1['next_cursor'], $item['id']);
            }
        }
    }

    public function test_filter_submissions_by_quiz_and_status(): void
    {
        $stmt = $this->db->query('SELECT id FROM quizzes LIMIT 1');
        $quizId = (int) $stmt->fetchColumn();

        if ($quizId === 0) {
            $this->markTestSkipped('No quiz found');
        }

        $result = $this->service->getSubmissions([
            'quiz_id' => $quizId,
            'status' => 'NOT_STARTED',
            'limit' => 10,
        ]);

        $this->assertIsArray($result['items']);
        $this->assertGreaterThanOrEqual(0, $result['total_count']);

        foreach ($result['items'] as $item) {
            $this->assertSame($quizId, (int) $item['quiz_id']);
            $this->assertSame('NOT_STARTED', $item['status']);
        }
    }

    public function test_get_submission_details_returns_data(): void
    {
        $stmt = $this->db->query('SELECT id FROM attempts LIMIT 1');
        $attemptId = (int) $stmt->fetchColumn();

        if ($attemptId === 0) {
            $this->markTestSkipped('No attempts found');
        }

        $details = $this->service->getSubmissionDetails($attemptId);
        $this->assertNotNull($details);
        $this->assertSame($attemptId, (int) $details['id']);
        $this->assertArrayHasKey('employee_code', $details);
        $this->assertArrayHasKey('quiz_title', $details);
        $this->assertArrayHasKey('answers', $details);
        $this->assertArrayHasKey('feedback', $details);
    }

    public function test_submission_feedback_persists_and_returns(): void
    {
        $stmt = $this->db->query(
            'SELECT a.id FROM attempts a ' .
            'JOIN employees e ON e.id = a.employee_id ' .
            'JOIN quizzes q ON q.id = a.quiz_id ' .
            'ORDER BY a.id DESC LIMIT 1'
        );
        $attemptId = (int) $stmt->fetchColumn();

        if ($attemptId === 0) {
            $this->markTestSkipped('No joined attempts found');
        }

        $testFeedback = 'Great corporate quiz with insightful questions! #' . uniqid();
        $update = $this->db->prepare('UPDATE attempts SET feedback = :fb WHERE id = :id');
        $update->execute(['fb' => $testFeedback, 'id' => $attemptId]);

        // Verify in getSubmissionDetails
        $details = $this->service->getSubmissionDetails($attemptId);
        $this->assertSame($testFeedback, $details['feedback']);

        // Verify in getSubmissions list
        $list = $this->service->getSubmissions(['limit' => 100]);
        $found = null;
        foreach ($list['items'] as $item) {
            if ((int) $item['id'] === $attemptId) {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame($testFeedback, $found['feedback']);
    }
}
