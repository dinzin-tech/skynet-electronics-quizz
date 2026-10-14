<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminController;
use App\Hot\Ulid;
use Core\Database;
use Core\Http\Request;
use Core\Session;
use PDO;
use PHPUnit\Framework\TestCase;

class AdminReportExportTest extends TestCase
{
    private PDO $db;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
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
    }

    public function test_report_export_handles_results_type_and_quiz_id(): void
    {
        // Create a test quiz
        $now = gmdate('Y-m-d H:i:s');
        $code = 'EXP_' . substr(Ulid::generate(), 0, 6);
        $this->db->prepare(
            'INSERT INTO quizzes (public_id, code, title, duration_seconds, start_at, end_at, status, settings, current_version, created_by, created_at, updated_at) ' .
            'VALUES (:pid, :code, "Test Quiz", 600, :start, :end, "published", "{}", 1, 1, :cat, :uat)'
        )->execute([
            'pid' => Ulid::generate(),
            'code' => $code,
            'start' => $now,
            'end' => gmdate('Y-m-d H:i:s', time() + 3600),
            'cat' => $now,
            'uat' => $now,
        ]);
        $quizId = (int) $this->db->lastInsertId();

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'report_type' => 'results',
                'quiz_id' => (string) $quizId,
            ];

            $request = new Request();
            $response = $this->controller->reportExport($request);

            $this->assertSame(302, $response->getStatusCode());

            // Check latest export job
            $stmt = $this->db->query('SELECT * FROM export_jobs ORDER BY id DESC LIMIT 1');
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assertSame('results', $job['report_type']);
            $filters = json_decode((string) $job['filters'], true);
            $this->assertSame($quizId, $filters['quiz_id'] ?? null);
        } finally {
            $this->db->exec("DELETE FROM quizzes WHERE id = {$quizId}");
        }
    }

    public function test_report_export_handles_question_summary_type(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $code = 'QS' . substr(Ulid::generate(), 0, 6);
        $this->db->prepare(
            'INSERT INTO quizzes (public_id, code, title, duration_seconds, start_at, end_at, status, settings, current_version, created_by, created_at, updated_at) ' .
            'VALUES (:pid, :code, "Test Quiz QS", 600, :start, :end, "published", "{}", 1, 1, :cat, :uat)'
        )->execute([
            'pid' => Ulid::generate(),
            'code' => $code,
            'start' => $now,
            'end' => gmdate('Y-m-d H:i:s', time() + 3600),
            'cat' => $now,
            'uat' => $now,
        ]);
        $quizId = (int) $this->db->lastInsertId();

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'report_type' => 'question_summary',
                'quiz_id' => (string) $quizId,
            ];

            $request = new Request();
            $response = $this->controller->reportExport($request);

            $this->assertSame(302, $response->getStatusCode());

            $stmt = $this->db->query('SELECT * FROM export_jobs ORDER BY id DESC LIMIT 1');
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assertSame('question_summary', $job['report_type']);
            $filters = json_decode((string) $job['filters'], true);
            $this->assertSame($quizId, $filters['quiz_id'] ?? null);
        } finally {
            $this->db->exec("DELETE FROM quizzes WHERE id = {$quizId}");
        }
    }

    public function test_report_export_handles_submissions_all_quizzes(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'report_type' => 'submissions',
            'quiz_id' => '',
        ];

        $request = new Request();
        $response = $this->controller->reportExport($request);

        $this->assertSame(302, $response->getStatusCode());

        $stmt = $this->db->query('SELECT * FROM export_jobs ORDER BY id DESC LIMIT 1');
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('submissions', $job['report_type']);
        $filters = json_decode((string) $job['filters'], true);
        $this->assertEmpty($filters);
    }
}
