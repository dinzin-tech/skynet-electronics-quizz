<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Auth\AuthService;
use App\Services\DashboardService;
use App\Services\EmployeeImportService;
use App\Services\EmployeeService;
use App\Services\QuizPublisher;
use App\Services\QuizService;
use App\Services\QuizWarmer;
use App\Services\ReportExportService;
use App\Services\SubmissionsService;
use Core\Controller;
use Core\Database;
use Core\Http\Request;
use Core\Http\Response;
use Core\Session;
use PDO;

class AdminController extends Controller
{
    private PDO $db;
    private AuthService $authService;
    private DashboardService $dashboardService;
    private EmployeeService $employeeService;
    private EmployeeImportService $importService;
    private QuizService $quizService;
    private QuizPublisher $quizPublisher;
    private QuizWarmer $quizWarmer;
    private SubmissionsService $submissionsService;
    private ReportExportService $exportService;

    public function __construct()
    {
        parent::__construct();
        $this->db = Database::getInstance()->getConnection();
        $this->authService = new AuthService($this->db);
        $this->dashboardService = new DashboardService($this->db);
        $this->employeeService = new EmployeeService($this->db);
        $this->importService = new EmployeeImportService($this->db);
        $this->quizService = new QuizService($this->db);
        $this->quizPublisher = new QuizPublisher($this->db);
        $this->quizWarmer = new QuizWarmer($this->db);
        $this->submissionsService = new SubmissionsService($this->db);
        $this->exportService = new ReportExportService($this->db);

        if (session_status() === PHP_SESSION_NONE) {
            Session::start();
        }
    }

    private function getAdminUser(): ?array
    {
        return Session::get('admin_user');
    }

    private function requireAdmin(): ?Response
    {
        if (!$this->getAdminUser()) {
            return $this->redirect('/admin/login');
        }
        return null;
    }

    /**
     * @Route(path="/admin", methods="GET", name="admin.root")
     */
    public function root(Request $request): Response
    {
        return $this->redirect('/admin/dashboard');
    }

    /**
     * @Route(path="/admin/login", methods="GET,POST", name="admin.login")
     */
    public function login(Request $request): Response
    {
        if ($this->getAdminUser()) {
            return $this->redirect('/admin/dashboard');
        }

        $error = null;
        if ($request->getMethod() === 'POST') {
            $username = trim((string) $request->get('username', ''));
            $password = (string) $request->get('password', '');

            if ($username === '' || $password === '') {
                $error = 'Please provide username/email and password';
            } else {
                $result = $this->authService->loginAdmin($username, $password);
                if ($result) {
                    Session::set('admin_user', $result['user']);
                    return $this->redirect('/admin/dashboard');
                }
                $error = 'Invalid credentials or access denied';
            }
        }

        return $this->render('admin/login.html.twig', [
            'error' => $error,
            'username' => $request->get('username', ''),
        ]);
    }

    /**
     * @Route(path="/admin/logout", methods="GET", name="admin.logout")
     */
    public function logout(Request $request): Response
    {
        Session::delete('admin_user');
        return $this->redirect('/admin/login');
    }

    /**
     * @Route(path="/admin/dashboard", methods="GET", name="admin.dashboard")
     */
    public function dashboard(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $globalKpis = $this->dashboardService->getGlobalKpis();

        $quizzesStmt = $this->db->query(
            'SELECT id, code, title, status FROM quizzes ORDER BY id DESC'
        );
        $quizzes = $quizzesStmt->fetchAll(PDO::FETCH_ASSOC);

        $selectedQuizId = (int) $request->get('quiz_id', 0);
        if ($selectedQuizId === 0 && !empty($quizzes)) {
            $selectedQuizId = (int) $quizzes[0]['id'];
        }

        $quizKpis = $selectedQuizId > 0 ? $this->dashboardService->getQuizKpis($selectedQuizId) : null;

        return $this->render('admin/dashboard.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'dashboard',
            'global_kpis' => $globalKpis,
            'quizzes' => $quizzes,
            'selected_quiz_id' => $selectedQuizId,
            'quiz_kpis' => $quizKpis,
        ]);
    }

    /**
     * @Route(path="/admin/employees", methods="GET", name="admin.employees")
     */
    public function employees(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $search = trim((string) $request->get('search', ''));
        $page = max(1, (int) $request->get('page', 1));
        $limit = 20;

        $result = $this->employeeService->list(
            $search !== '' ? $search : null,
            null,
            $page,
            $limit
        );

        $totalPages = (int) ceil(($result['total'] ?? 0) / $limit);

        return $this->render('admin/employees/index.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'employees',
            'employees' => $result['data'] ?? [],
            'total_count' => $result['total'] ?? 0,
            'current_page' => $page,
            'total_pages' => max(1, $totalPages),
            'search' => $search,
        ]);
    }

    /**
     * @Route(path="/admin/employees/import", methods="GET", name="admin.employees.import")
     */
    public function employeeImportForm(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        return $this->render('admin/employees/import.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'employees',
        ]);
    }

    /**
     * @Route(path="/admin/employees/import/preview", methods="POST", name="admin.employees.import.preview")
     */
    public function employeeImportPreview(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            return $this->render('admin/employees/import.html.twig', [
                'admin' => $this->getAdminUser(),
                'current_route' => 'employees',
                'error' => 'Please select a valid CSV file to upload',
            ]);
        }

        $targetDir = dirname(__DIR__, 2) . '/storage/imports';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $fileName = 'import_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_\.]/', '', $_FILES['csv_file']['name']);
        $destPath = "{$targetDir}/{$fileName}";
        move_uploaded_file($_FILES['csv_file']['tmp_name'], $destPath);

        try {
            $preview = $this->importService->preview($destPath);

            return $this->render('admin/employees/import.html.twig', [
                'admin' => $this->getAdminUser(),
                'current_route' => 'employees',
                'preview' => $preview,
                'file_path' => $destPath,
            ]);
        } catch (\Throwable $e) {
            return $this->render('admin/employees/import.html.twig', [
                'admin' => $this->getAdminUser(),
                'current_route' => 'employees',
                'error' => 'Validation error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * @Route(path="/admin/employees/import/execute", methods="POST", name="admin.employees.import.execute")
     */
    public function employeeImportExecute(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $filePath = (string) $request->get('file_path', '');
        if (!file_exists($filePath)) {
            return $this->render('admin/employees/import.html.twig', [
                'admin' => $this->getAdminUser(),
                'current_route' => 'employees',
                'error' => 'Uploaded file no longer exists. Please upload again.',
            ]);
        }

        $admin = $this->getAdminUser();
        $adminId = (int) ($admin['id'] ?? 1);

        $jobId = $this->importService->createImportJob($filePath, $adminId);
        $result = $this->importService->processImportJob($jobId);

        $reportUrl = !empty($result['report_path'])
            ? '/admin/employees/import/report/' . $jobId
            : null;

        return $this->render('admin/employees/import.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'employees',
            'result_summary' => [
                'total' => $result['total'],
                'imported' => $result['imported'],
                'failed' => $result['failed'],
                'report_url' => $reportUrl,
            ],
            'success' => "Import complete: {$result['imported']} employees imported.",
        ]);
    }

    /**
     * @Route(path="/admin/employees/import/report/{id}", methods="GET", name="admin.employees.import.report")
     */
    public function employeeImportReport(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $jobId = (int) $id;
        $reportPath = dirname(__DIR__, 2) . "/storage/reports/import_{$jobId}_failed.csv";

        if (!file_exists($reportPath)) {
            return (new Response('Report not found', 404));
        }

        $content = (string) file_get_contents($reportPath);
        return new Response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="failed_employees_job_' . $jobId . '.csv"',
        ]);
    }

    /**
     * @Route(path="/admin/quizzes", methods="GET", name="admin.quizzes")
     */
    public function quizzes(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $stmt = $this->db->query('SELECT * FROM quizzes ORDER BY id DESC');
        $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->render('admin/quizzes/index.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'quizzes',
            'quizzes' => $quizzes,
            'success' => Session::get('flash_success'),
            'error' => Session::get('flash_error'),
        ]);
    }

    /**
     * @Route(path="/admin/quizzes/create", methods="GET,POST", name="admin.quizzes.create")
     */
    public function quizCreate(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        if ($request->getMethod() === 'POST') {
            try {
                $title = trim((string) $request->get('title', ''));
                $code = strtoupper(trim((string) $request->get('code', '')));
                $desc = trim((string) $request->get('description', ''));
                $windowStart = (string) $request->get('window_start', '');
                $windowEnd = (string) $request->get('window_end', '');
                $duration = (int) $request->get('duration_minutes', 30);

                $scoring = [
                    'marks_per_correct' => (float) $request->get('marks_per_correct', 1.0),
                    'negative_marks_per_wrong' => (float) $request->get('negative_marks', 0.0),
                    'unanswered_penalty' => (float) $request->get('unanswered_penalty', 0.0),
                    'pass_mark' => (float) $request->get('pass_mark', 20.0),
                ];

                $nav = [
                    'allow_back' => (bool) $request->get('allow_back', false),
                    'allow_skip' => (bool) $request->get('allow_skip', false),
                    'allow_review_screen' => (bool) $request->get('allow_review_screen', false),
                    'randomize_questions' => (bool) $request->get('randomize_questions', false),
                    'randomize_options' => (bool) $request->get('randomize_options', false),
                ];

                $startUtc = gmdate('Y-m-d H:i:s', strtotime($windowStart));
                $endUtc = gmdate('Y-m-d H:i:s', strtotime($windowEnd));

                $created = $this->quizService->create([
                    'title' => $title,
                    'code' => $code,
                    'description' => $desc,
                    'duration_minutes' => $duration,
                    'window_start_at' => $startUtc,
                    'window_end_at' => $endUtc,
                    'settings' => [
                        'scoring' => $scoring,
                        'navigation' => $nav,
                    ],
                ]);

                return $this->redirect('/admin/quizzes');
            } catch (\Throwable $e) {
                return $this->render('admin/quizzes/form.html.twig', [
                    'admin' => $this->getAdminUser(),
                    'current_route' => 'quizzes',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->render('admin/quizzes/form.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'quizzes',
        ]);
    }

    /**
     * @Route(path="/admin/quizzes/{id}/publish", methods="POST", name="admin.quizzes.publish")
     */
    public function quizPublish(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $quizId = (int) $id;
        try {
            $this->quizPublisher->publish($quizId);
            return $this->redirect('/admin/quizzes');
        } catch (\Throwable $e) {
            return $this->redirect('/admin/quizzes');
        }
    }

    /**
     * @Route(path="/admin/quizzes/{id}/warm", methods="POST", name="admin.quizzes.warm")
     */
    public function quizWarm(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $quizId = (int) $id;
        try {
            $this->quizWarmer->warm($quizId);
        } catch (\Throwable $e) {
            // Ignore
        }
        return $this->redirect('/admin/quizzes');
    }

    /**
     * @Route(path="/admin/submissions", methods="GET", name="admin.submissions")
     */
    public function submissions(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $quizId = (int) $request->get('quiz_id', 0);
        $status = trim((string) $request->get('status', ''));
        $search = trim((string) $request->get('search', ''));
        $cursor = (int) $request->get('cursor', 0);

        $filters = [
            'quiz_id' => $quizId > 0 ? $quizId : null,
            'status' => $status !== '' ? $status : null,
            'search' => $search !== '' ? $search : null,
            'cursor' => $cursor > 0 ? $cursor : null,
            'limit' => 25,
        ];

        $data = $this->submissionsService->getSubmissions($filters);

        $quizzes = $this->db->query(
            'SELECT id, code, title FROM quizzes ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        return $this->render('admin/submissions/index.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'submissions',
            'items' => $data['items'],
            'next_cursor' => $data['next_cursor'],
            'total_count' => $data['total_count'],
            'quizzes' => $quizzes,
            'filters' => $filters,
        ]);
    }

    /**
     * @Route(path="/admin/submissions/{id}", methods="GET", name="admin.submissions.detail")
     */
    public function submissionDetail(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $attemptId = (int) $id;
        $attempt = $this->submissionsService->getSubmissionDetails($attemptId);

        if (!$attempt) {
            return (new Response('Submission not found', 404));
        }

        return $this->render('admin/submissions/detail.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'submissions',
            'attempt' => $attempt,
        ]);
    }

    /**
     * @Route(path="/admin/reports", methods="GET", name="admin.reports")
     */
    public function reports(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $quizzes = $this->db->query(
            'SELECT id, code, title FROM quizzes ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $jobsStmt = $this->db->query(
            'SELECT * FROM export_jobs ORDER BY id DESC LIMIT 20'
        );
        $jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->render('admin/reports/index.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'reports',
            'quizzes' => $quizzes,
            'jobs' => $jobs,
        ]);
    }

    /**
     * @Route(path="/admin/reports/export", methods="POST", name="admin.reports.export")
     */
    public function reportExport(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $type = (string) $request->get('report_type', 'submissions');
        $quizId = (int) $request->get('quiz_id', 0);
        $admin = $this->getAdminUser();
        $adminId = (int) ($admin['id'] ?? 1);

        $filters = $quizId > 0 ? ['quiz_id' => $quizId] : [];
        $jobId = $this->exportService->createExportJob($type, $filters, 'csv', $adminId);

        // Process immediately for small datasets or CLI worker can pick it up
        try {
            $this->exportService->processExportJob($jobId);
        } catch (\Throwable $e) {
            // Ignore error here; worker can retry
        }

        return $this->redirect('/admin/reports');
    }

    /**
     * @Route(path="/admin/reports/download/{id}", methods="GET", name="admin.reports.download")
     */
    public function reportDownload(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $jobId = (int) $id;
        $stmt = $this->db->prepare('SELECT * FROM export_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job || empty($job['file_path']) || !file_exists($job['file_path'])) {
            return (new Response('Export file not found or expired', 404));
        }

        $content = (string) file_get_contents($job['file_path']);
        $fileName = basename($job['file_path']);

        return new Response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }
}
