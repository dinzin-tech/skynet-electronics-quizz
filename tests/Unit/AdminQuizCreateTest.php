<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminController;
use Core\Database;
use Core\Http\Request;
use Core\Session;
use PDO;
use PHPUnit\Framework\TestCase;

class AdminQuizCreateTest extends TestCase
{
    private PDO $db;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->controller = new AdminController();
        Session::set('admin_user', [
            'id' => 1,
            'username' => 'admin_test',
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        Session::delete('admin_user');
        // Clean up test quizzes
        $this->db->exec("DELETE FROM quizzes WHERE code LIKE 'TESTQZ%'");
    }

    public function test_empty_dates_does_not_throw_gmdate_type_error(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'title' => 'Test Empty Dates',
            'code' => 'TESTQZEMPTY',
            'window_start' => '',
            'window_end' => '',
            'duration_minutes' => 30,
        ];

        $request = new Request();
        $response = $this->controller->quizCreate($request);

        $this->assertSame(200, $response->getStatusCode());
        ob_start();
        $response->send();
        $body = ob_get_clean();
        // Ensure the error message is clean and not a gmdate TypeError
        $this->assertStringNotContainsString('gmdate()', (string) $body);
        $this->assertStringContainsString('Window Start is required', (string) $body);
    }

    public function test_valid_datetime_local_creates_quiz_successfully(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'title' => 'Test Valid Quiz',
            'code' => 'TESTQZVALID',
            'description' => 'Test Description',
            'window_start' => '2026-11-01T10:00',
            'window_end' => '2026-11-01T12:00',
            'duration_minutes' => 45,
            'target_audience' => 'all',
        ];

        $request = new Request();
        $response = $this->controller->quizCreate($request);

        // Success redirects to questions page (302)
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/questions', $response->getHeader('Location') ?? '');

        // Verify quiz created in database
        $stmt = $this->db->prepare("SELECT * FROM quizzes WHERE code = 'TESTQZVALID'");
        $stmt->execute();
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($quiz);
        $this->assertSame('Test Valid Quiz', $quiz['title']);
        // Verify start_at and end_at converted to UTC (IST is UTC+5:30)
        // 10:00 IST -> 04:30:00 UTC
        $this->assertSame('2026-11-01 04:30:00', $quiz['start_at']);
        // 12:00 IST -> 06:30:00 UTC
        $this->assertSame('2026-11-01 06:30:00', $quiz['end_at']);
    }

    public function test_end_before_start_shows_error(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'title' => 'Test Inverted Window',
            'code' => 'TESTQZINV',
            'window_start' => '2026-11-01T12:00',
            'window_end' => '2026-11-01T10:00',
            'duration_minutes' => 30,
        ];

        $request = new Request();
        $response = $this->controller->quizCreate($request);

        $this->assertSame(200, $response->getStatusCode());
        ob_start();
        $response->send();
        $body = ob_get_clean();
        $this->assertStringNotContainsString('gmdate()', (string) $body);
        $this->assertStringContainsString('end time must be after start time', (string) $body);
    }
}
