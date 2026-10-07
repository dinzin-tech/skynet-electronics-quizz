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
        $this->db->exec("DELETE FROM quizzes WHERE code LIKE 'TESTQZ%'");
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
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
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        // Clean up test quizzes
        $this->db->exec("DELETE FROM quizzes WHERE code LIKE 'TESTQZ%'");
    }

    private function getResponseBody(\Core\Http\Response $response): string
    {
        $ref = new \ReflectionProperty($response, 'content');
        $ref->setAccessible(true);
        return (string) $ref->getValue($response);
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
        $body = $this->getResponseBody($response);
        // Ensure the error message is clean and not a gmdate TypeError
        $this->assertStringNotContainsString('gmdate()', $body);
        $this->assertStringContainsString('Window Start is required', $body);
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
        $this->assertSame(302, $response->getStatusCode(), "Quiz create failed: " . substr($this->getResponseBody($response), 0, 500));
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
        $body = $this->getResponseBody($response);
        $this->assertStringNotContainsString('gmdate()', $body);
        $this->assertStringContainsString('end time must be after start time', $body);
    }

    public function test_create_quiz_with_custom_audience_filters(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'title' => 'Targeted Quiz Test',
            'code' => 'TESTQZTGT',
            'description' => 'Targeted Description',
            'window_start' => '2026-11-01T10:00',
            'window_end' => '2026-11-01T12:00',
            'duration_minutes' => 45,
            'target_audience' => 'custom',
            'target_departments' => ['ENGINEERING', 'SALES'],
            'target_zones' => ['NORTH', 'SOUTH'],
            'target_groups' => ['1', '2'],
        ];

        $request = new Request();
        $response = $this->controller->quizCreate($request);

        $this->assertSame(302, $response->getStatusCode());

        $stmt = $this->db->prepare("SELECT * FROM quizzes WHERE code = 'TESTQZTGT'");
        $stmt->execute();
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($quiz);
        $settings = json_decode((string) $quiz['settings'], true);
        $this->assertSame('custom', $settings['target_audience']);
        $this->assertEquals(['ENGINEERING', 'SALES'], $settings['target_departments']);
        $this->assertEquals(['NORTH', 'SOUTH'], $settings['target_zones']);
        $this->assertEquals([1, 2], $settings['target_groups']);
    }
}
