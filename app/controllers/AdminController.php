<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Auth\AuthService;
use App\Services\DashboardService;
use App\Services\EmployeeImportService;
use App\Services\EmployeeService;
use App\Services\QuestionService;
use App\Services\QuizFinalizerService;
use App\Services\QuizPublisher;
use App\Services\QuizService;
use App\Services\QuizWarmer;
use App\Services\ReportExportService;
use App\Services\SubmissionsService;
use App\Services\TimeHelper;
use App\Services\WorkerManagerService;
use Core\Controller;
use Core\Database;
use Core\Http\Request;
use Core\Http\Response;
use Core\Session;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AdminController extends Controller
{
    private PDO $db;
    private AuthService $authService;
    private DashboardService $dashboardService;
    private EmployeeService $employeeService;
    private EmployeeImportService $importService;
    private QuestionService $questionService;
    private QuizService $quizService;
    private QuizPublisher $quizPublisher;
    private QuizWarmer $quizWarmer;
    private QuizFinalizerService $quizFinalizerService;
    private SubmissionsService $submissionsService;
    private ReportExportService $exportService;
    private WorkerManagerService $workerManager;

    public function __construct()
    {
        parent::__construct();
        $this->db = Database::getInstance()->getConnection();
        $this->authService = new AuthService();
        $this->dashboardService = new DashboardService($this->db);
        $this->employeeService = new EmployeeService($this->db);
        $this->importService = new EmployeeImportService($this->db);
        $this->questionService = new QuestionService($this->db);
        $this->quizService = new QuizService($this->db);
        $this->quizPublisher = new QuizPublisher($this->db);
        $this->quizWarmer = new QuizWarmer($this->db);
        $this->quizFinalizerService = new QuizFinalizerService($this->db);
        $this->submissionsService = new SubmissionsService($this->db);
        $this->exportService = new ReportExportService($this->db);
        $this->workerManager = new WorkerManagerService($this->db);

        $companyTzName = $_ENV['COMPANY_TZ'] ?? 'Asia/Kolkata';
        if ($this->twig->hasExtension(\Twig\Extension\CoreExtension::class)) {
            $core = $this->twig->getExtension(\Twig\Extension\CoreExtension::class);
            $core->setTimezone($companyTzName);
            $core->setDateFormat('d M Y, h:i A');
        }

        $this->twig->addFilter(new TwigFilter('company_date', [TimeHelper::class, 'toCompanyTz']));
        $this->twig->addFilter(new TwigFilter('company_input_date', [TimeHelper::class, 'formatInputDateTime']));
        $this->twig->addFunction(new TwigFunction('company_date', [TimeHelper::class, 'toCompanyTz']));
        $this->twig->addFunction(new TwigFunction('company_tz', fn() => $_ENV['COMPANY_TZ'] ?? 'Asia/Kolkata'));
        $this->twig->addGlobal('company_tz', $companyTzName);

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
            $username = trim((string) ($request->get('username') ?: ($_POST['username'] ?? '')));
            $password = (string) ($request->get('password') ?: ($_POST['password'] ?? ''));

            if ($username === '' || $password === '') {
                $error = 'Please provide username/email and password';
            } else {
                try {
                    $result = $this->authService->loginAdmin($username, $password);
                    if ($result) {
                        Session::set('admin_user', $result['admin'] ?? $result['user'] ?? $result);
                        return $this->redirect('/admin/dashboard');
                    }
                    $error = 'Invalid credentials or access denied';
                } catch (\Throwable $e) {
                    error_log('Admin login error: ' . $e->getMessage());
                    $error = 'Login error: ' . $e->getMessage();
                }
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
            $search,
            null,
            $page,
            $limit
        );

        $totalPages = (int) ceil(($result['total'] ?? 0) / $limit);

        $groupsStmt = $this->db->query('SELECT id, name FROM `groups` ORDER BY name ASC');
        $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $success = Session::get('flash_success');
        $error = Session::get('flash_error');
        Session::delete('flash_success');
        Session::delete('flash_error');

        return $this->render('admin/employees/index.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'employees',
            'employees' => $result['data'] ?? [],
            'total_count' => $result['total'] ?? 0,
            'current_page' => $page,
            'total_pages' => max(1, $totalPages),
            'search' => $search,
            'groups' => $groups,
            'success' => $success,
            'error' => $error,
        ]);
    }

    /**
     * @Route(path="/admin/employees/create", methods="GET,POST", name="admin.employees.create")
     */
    public function employeeCreate(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $groupsStmt = $this->db->query('SELECT id, name FROM `groups` ORDER BY name ASC');
        $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (strtoupper($request->getMethod()) === 'POST') {
            $code = trim((string) ($request->input('employee_code') ?? $_POST['employee_code'] ?? ''));
            $name = trim((string) ($request->input('name') ?? $_POST['name'] ?? ''));
            $zoneRegion = trim((string) ($request->input('zone_region') ?? $_POST['zone_region'] ?? ''));
            $department = trim((string) ($request->input('department') ?? $_POST['department'] ?? ''));
            $designation = trim((string) ($request->input('designation') ?? $_POST['designation'] ?? ''));
            $email = trim((string) ($request->input('email') ?? $_POST['email'] ?? ''));
            $status = trim((string) ($request->input('status') ?? $_POST['status'] ?? 'active'));
            $groupIds = array_map('intval', (array) ($request->input('group_ids') ?? $_POST['group_ids'] ?? []));

            try {
                $this->employeeService->create([
                    'employee_code' => $code,
                    'name' => $name,
                    'zone_region' => $zoneRegion,
                    'department' => $department,
                    'designation' => $designation,
                    'email' => $email,
                    'status' => in_array($status, ['active', 'inactive'], true) ? $status : 'active',
                ], $groupIds);

                Session::set('flash_success', "Employee '{$name}' ({$code}) created successfully.");
                return $this->redirect('/admin/employees');
            } catch (\Throwable $e) {
                return $this->render('admin/employees/form.html.twig', [
                    'admin' => $this->getAdminUser(),
                    'current_route' => 'employees',
                    'groups' => $groups,
                    'employee' => null,
                    'error' => $e->getMessage(),
                    'old' => [
                        'employee_code' => $code,
                        'name' => $name,
                        'zone_region' => $zoneRegion,
                        'department' => $department,
                        'designation' => $designation,
                        'email' => $email,
                        'status' => $status,
                        'group_ids' => $groupIds,
                    ],
                ]);
            }
        }

        return $this->render('admin/employees/form.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'employees',
            'groups' => $groups,
            'employee' => null,
            'old' => null,
        ]);
    }

    /**
     * @Route(path="/admin/employees/{id}/edit", methods="GET,POST", name="admin.employees.edit")
     */
    public function employeeEdit(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $empId = (int) $id;
        $employee = $this->employeeService->getById($empId);
        if (!$employee) {
            Session::set('flash_error', 'Employee not found');
            return $this->redirect('/admin/employees');
        }

        $groupsStmt = $this->db->query('SELECT id, name FROM `groups` ORDER BY name ASC');
        $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (strtoupper($request->getMethod()) === 'POST') {
            $code = trim((string) ($request->input('employee_code') ?? $_POST['employee_code'] ?? ''));
            $name = trim((string) ($request->input('name') ?? $_POST['name'] ?? ''));
            $zoneRegion = trim((string) ($request->input('zone_region') ?? $_POST['zone_region'] ?? ''));
            $department = trim((string) ($request->input('department') ?? $_POST['department'] ?? ''));
            $designation = trim((string) ($request->input('designation') ?? $_POST['designation'] ?? ''));
            $email = trim((string) ($request->input('email') ?? $_POST['email'] ?? ''));
            $status = trim((string) ($request->input('status') ?? $_POST['status'] ?? 'active'));
            $groupIds = array_map('intval', (array) ($request->input('group_ids') ?? $_POST['group_ids'] ?? []));

            try {
                $this->employeeService->update($empId, [
                    'employee_code' => $code,
                    'name' => $name,
                    'zone_region' => $zoneRegion,
                    'department' => $department,
                    'designation' => $designation,
                    'email' => $email,
                    'status' => in_array($status, ['active', 'inactive'], true) ? $status : 'active',
                ], $groupIds);

                Session::set('flash_success', "Employee '{$name}' ({$code}) updated successfully.");
                return $this->redirect('/admin/employees');
            } catch (\Throwable $e) {
                return $this->render('admin/employees/form.html.twig', [
                    'admin' => $this->getAdminUser(),
                    'current_route' => 'employees',
                    'groups' => $groups,
                    'employee' => $employee,
                    'error' => $e->getMessage(),
                    'old' => [
                        'employee_code' => $code,
                        'name' => $name,
                        'zone_region' => $zoneRegion,
                        'department' => $department,
                        'designation' => $designation,
                        'email' => $email,
                        'status' => $status,
                        'group_ids' => $groupIds,
                    ],
                ]);
            }
        }

        return $this->render('admin/employees/form.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'employees',
            'groups' => $groups,
            'employee' => $employee,
            'old' => null,
        ]);
    }

    /**
     * @Route(path="/admin/employees/{id}/delete", methods="POST", name="admin.employees.delete")
     */
    public function employeeDelete(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $empId = (int) $id;
        $employee = $this->employeeService->getById($empId);
        if (!$employee) {
            Session::set('flash_error', 'Employee not found');
            return $this->redirect('/admin/employees');
        }

        try {
            $this->employeeService->delete($empId);
            Session::set('flash_success', "Employee '{$employee['name']}' deleted/deactivated successfully.");
        } catch (\Throwable $e) {
            Session::set('flash_error', 'Failed to delete employee: ' . $e->getMessage());
        }

        return $this->redirect('/admin/employees');
    }

    /**
     * @Route(path="/admin/employees/bulk-delete", methods="POST", name="admin.employees.bulk_delete")
     */
    public function employeeBulkDelete(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $ids = (array) ($request->input('ids') ?? $_POST['ids'] ?? []);
        $ids = array_filter(array_map('intval', $ids));

        if (empty($ids)) {
            Session::set('flash_error', 'No employees were selected for deletion.');
            return $this->redirect('/admin/employees');
        }

        try {
            $count = $this->employeeService->bulkDelete($ids);
            Session::set('flash_success', "Successfully deleted/deactivated {$count} employee(s).");
        } catch (\Throwable $e) {
            Session::set('flash_error', 'Bulk delete failed: ' . $e->getMessage());
        }

        return $this->redirect('/admin/employees');
    }

    /**
     * @Route(path="/admin/employees/sample-csv", methods="GET", name="admin.employees.sample_csv")
     */
    public function employeeSampleCsv(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $rows = [
            ['SL NO', 'ZONE/Region', 'EMP CODE', 'EMP NAME', 'DEPARTMENT', 'DESIGNATION'],
            ['1', 'ZONE 1', '000169', 'AFZAL PASHA', 'KOLAR SALES', 'SALES LEADER'],
            ['2', 'ZONE 1', '000172', 'SRIDHARA R', 'KOLAR SALES', 'SALES LEADER'],
            ['3', 'ZONE 1', '000313', 'SUDEEP G V', 'KOLAR SALES', 'COOPERATIVE PROMOTER'],
            ['4', 'ZONE 1', '000325', 'VINOD KUMAR DV', 'KOLAR SALES', 'OPPO EXPERIENCE CONSULTANT'],
            ['5', 'ZONE 1', '000227', 'ISMAIL', 'BANGALORE SALES', 'REGIONAL MANAGER'],
            ['6', 'ZONE 1', '000028', 'SALMAN PASHA', 'BANGALORE SALES', 'OPPO EXPERIENCE CONSULTANT'],
            ['7', 'ZONE 1', '000450', 'ARIF SHARIFF', 'BANGALORE SALES', 'OPPO EXPERIENCE CONSULTANT'],
            ['8', 'ZONE 1', '000075', 'MOHAMMED UMAR A', 'KOLAR SALES', 'SALES LEADER'],
            ['9', 'ZONE 1', '000531', 'SALMAN PASHA', 'KOLAR SALES', 'CITY MANAGER'],
            ['10', 'ZONE 1', '000107', 'HARSHA R', 'TRAINING', 'TRAINING MANAGER'],
            ['11', 'ZONE 1', '000109', 'MOHAN U', 'CHANNEL', 'CHANNEL MANAGER'],
            ['12', 'ZONE 1', '000155', 'SYED JUNAID', 'BRANDING', 'BRANDING EXECUTIVE'],
        ];

        $handle = fopen('php://memory', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return new Response((string) $csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="employees_template.csv"',
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

        $filePath = trim((string) ($request->input('file_path') ?? $_POST['file_path'] ?? $request->get('file_path', '')));
        if ($filePath === '' || !file_exists($filePath) || !is_file($filePath)) {
            return $this->render('admin/employees/import.html.twig', [
                'admin' => $this->getAdminUser(),
                'current_route' => 'employees',
                'error' => 'Uploaded file no longer exists. Please upload again.',
            ]);
        }

        $admin = $this->getAdminUser();
        $adminId = (int) ($admin['id'] ?? 1);

        try {
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
        } catch (\Throwable $e) {
            return $this->render('admin/employees/import.html.twig', [
                'admin' => $this->getAdminUser(),
                'current_route' => 'employees',
                'error' => 'Import error: ' . $e->getMessage(),
            ]);
        }
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
     * @return array<int, string>
     */
    private function getDistinctDepartments(): array
    {
        try {
            $stmt = $this->db->query(
                "SELECT DISTINCT department FROM employees WHERE status = 'active' AND department IS NOT NULL AND department != '' ORDER BY department ASC"
            );
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function getDistinctZones(): array
    {
        try {
            $stmt = $this->db->query(
                "SELECT DISTINCT zone_region FROM employees WHERE status = 'active' AND zone_region IS NOT NULL AND zone_region != '' ORDER BY zone_region ASC"
            );
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @Route(path="/admin/quizzes", methods="GET", name="admin.quizzes")
     */
    public function quizzes(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $stmt = $this->db->query(
            'SELECT q.*, (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) AS question_count ' .
            'FROM quizzes q ORDER BY q.id DESC'
        );
        $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $groupsStmt = $this->db->query('SELECT id, name FROM `groups`');
        $groupNames = $groupsStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        foreach ($quizzes as &$q) {
            $settings = json_decode((string) ($q['settings'] ?? ''), true) ?: [];
            $targetAudience = $settings['target_audience'] ?? 'all';
            $targetGroups = $settings['target_groups'] ?? [];
            $targetDepts = $settings['target_departments'] ?? [];
            $targetZones = $settings['target_zones'] ?? [];

            if ($targetAudience === 'all' || (empty($targetGroups) && empty($targetDepts) && empty($targetZones))) {
                $q['audience_label'] = 'All Active Employees';
            } else {
                $labels = [];
                if (!empty($targetDepts)) {
                    $labels[] = 'Depts: ' . (count($targetDepts) <= 2 ? implode(', ', $targetDepts) : count($targetDepts) . ' depts');
                }
                if (!empty($targetZones)) {
                    $labels[] = 'Zones: ' . (count($targetZones) <= 2 ? implode(', ', $targetZones) : count($targetZones) . ' zones');
                }
                if (!empty($targetGroups)) {
                    $names = [];
                    foreach ($targetGroups as $gid) {
                        if (isset($groupNames[$gid])) {
                            $names[] = $groupNames[$gid];
                        }
                    }
                    if (!empty($names)) {
                        $labels[] = 'Groups: ' . (count($names) <= 2 ? implode(', ', $names) : count($names) . ' groups');
                    }
                }
                $q['audience_label'] = !empty($labels) ? implode(' • ', $labels) : 'Specific Criteria';
            }
        }
        unset($q);

        $success = Session::get('flash_success');
        $error = Session::get('flash_error');
        Session::delete('flash_success');
        Session::delete('flash_error');

        return $this->render('admin/quizzes/index.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'quizzes',
            'quizzes' => $quizzes,
            'success' => $success,
            'error' => $error,
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

        $admin = $this->getAdminUser();
        $adminId = (int) ($admin['id'] ?? 1);

        if ($request->getMethod() === 'POST') {
            try {
                $title = trim((string) ($request->get('title') ?: ($request->getPostData()['title'] ?? ($_POST['title'] ?? ''))));
                $code = strtoupper(trim((string) ($request->get('code') ?: ($request->getPostData()['code'] ?? ($_POST['code'] ?? '')))));
                $desc = trim((string) ($request->get('description') ?: ($request->getPostData()['description'] ?? ($_POST['description'] ?? ''))));
                $feedbackQuestion = trim((string) ($request->get('feedback_question') ?: ($request->getPostData()['feedback_question'] ?? ($_POST['feedback_question'] ?? ''))));
                $windowStart = trim((string) ($request->get('window_start') ?: ($request->getPostData()['window_start'] ?? ($_POST['window_start'] ?? ''))));
                $windowEnd = trim((string) ($request->get('window_end') ?: ($request->getPostData()['window_end'] ?? ($_POST['window_end'] ?? ''))));
                $duration = (int) ($request->get('duration_minutes') ?: ($request->getPostData()['duration_minutes'] ?? ($_POST['duration_minutes'] ?? 30)));

                $scoring = [
                    'marks_per_correct' => (float) ($request->input('marks_per_correct') ?? $request->get('marks_per_correct', 1.0)),
                    'negative_marks_per_wrong' => (float) ($request->input('negative_marks') ?? $request->get('negative_marks', 0.0)),
                    'unanswered_penalty' => (float) ($request->input('unanswered_penalty') ?? $request->get('unanswered_penalty', 0.0)),
                    'pass_mark' => (float) ($request->input('pass_mark') ?? $request->get('pass_mark', 20.0)),
                ];

                $nav = [
                    'allow_back' => (bool) ($request->input('allow_back') ?? $request->get('allow_back', false)),
                    'allow_skip' => (bool) ($request->input('allow_skip') ?? $request->get('allow_skip', false)),
                    'allow_review_screen' => (bool) ($request->input('allow_review_screen') ?? $request->get('allow_review_screen', false)),
                    'randomize_questions' => (bool) ($request->input('randomize_questions') ?? $request->get('randomize_questions', false)),
                    'randomize_options' => (bool) ($request->input('randomize_options') ?? $request->get('randomize_options', false)),
                ];

                $startUtc = $this->parseWindowDateTime($windowStart, 'Window Start');
                $endUtc = $this->parseWindowDateTime($windowEnd, 'Window End');

                if ($endUtc <= $startUtc) {
                    throw new InvalidArgumentException('Quiz window end time must be after start time. Please correct the dates and try again.');
                }

                $targetAudience = (string) ($request->input('target_audience') ?? $request->get('target_audience', 'all'));
                $selectedGroups = (array) ($request->input('target_groups') ?? $request->get('target_groups', []));
                $selectedDepts = (array) ($request->input('target_departments') ?? $request->get('target_departments', []));
                $selectedZones = (array) ($request->input('target_zones') ?? $request->get('target_zones', []));

                $targetGroups = [];
                $targetDepts = [];
                $targetZones = [];

                if ($targetAudience !== 'all') {
                    $targetGroups = array_values(array_filter(array_map('intval', $selectedGroups), fn($g) => $g > 0));
                    $targetDepts = array_values(array_filter(array_map('trim', $selectedDepts), fn($d) => $d !== ''));
                    $targetZones = array_values(array_filter(array_map('trim', $selectedZones), fn($z) => $z !== ''));
                }

                $created = $this->quizService->create([
                    'title' => $title,
                    'code' => $code,
                    'description' => $desc,
                    'feedback_question' => $feedbackQuestion,
                    'duration_minutes' => $duration,
                    'duration_seconds' => $duration * 60,
                    'start_at' => $startUtc,
                    'end_at' => $endUtc,
                    'window_start_at' => $startUtc,
                    'window_end_at' => $endUtc,
                    'settings' => [
                        'scoring' => $scoring,
                        'navigation' => $nav,
                        'target_audience' => $targetAudience,
                        'target_groups' => $targetGroups,
                        'target_departments' => $targetDepts,
                        'target_zones' => $targetZones,
                    ],
                ], $adminId);

                Session::set('flash_success', 'Quiz created successfully. Now add questions below before publishing.');
                return $this->redirect("/admin/quizzes/{$created['id']}/questions");
            } catch (\Throwable $e) {
                $groups = [];
                try {
                    $groups = $this->db->query('SELECT id, name, description FROM `groups` ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
                } catch (\Throwable $ignored) {
                    $groups = [];
                }
                return $this->render('admin/quizzes/form.html.twig', [
                    'admin' => $this->getAdminUser(),
                    'current_route' => 'quizzes',
                    'groups' => $groups,
                    'departments' => $this->getDistinctDepartments(),
                    'zones' => $this->getDistinctZones(),
                    'error' => $e->getMessage(),
                    'old' => $request->getPostData(),
                ]);
            }
        }

        $groups = $this->db->query('SELECT id, name, description FROM `groups` ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
        return $this->render('admin/quizzes/form.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'quizzes',
            'groups' => $groups,
            'departments' => $this->getDistinctDepartments(),
            'zones' => $this->getDistinctZones(),
            'old' => null,
        ]);
    }

    /**
     * @Route(path="/admin/quizzes/{id}/edit", methods="GET,POST", name="admin.quizzes.edit")
     */
    public function quizEdit(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $admin = $this->getAdminUser();
        $adminId = (int) ($admin['id'] ?? 1);
        $quizId = (int) $id;

        $quiz = $this->quizService->getById($quizId);
        if (!$quiz) {
            Session::set('flash_error', 'Quiz not found');
            return $this->redirect('/admin/quizzes');
        }

        if ($request->getMethod() === 'POST') {
            try {
                $title = trim((string) ($request->input('title') ?? $request->get('title', '')));
                $code = strtoupper(trim((string) ($request->input('code') ?? $request->get('code', ''))));
                $desc = trim((string) ($request->input('description') ?? $request->get('description', '')));
                $feedbackQuestion = trim((string) ($request->input('feedback_question') ?? $request->get('feedback_question', '')));
                $windowStart = (string) ($request->input('window_start') ?? $request->get('window_start', ''));
                $windowEnd = (string) ($request->input('window_end') ?? $request->get('window_end', ''));
                $duration = (int) ($request->input('duration_minutes') ?? $request->get('duration_minutes', 30));

                $scoring = [
                    'marks_per_correct' => (float) ($request->input('marks_per_correct') ?? $request->get('marks_per_correct', 1.0)),
                    'negative_marks_per_wrong' => (float) ($request->input('negative_marks') ?? $request->get('negative_marks', 0.0)),
                    'unanswered_penalty' => (float) ($request->input('unanswered_penalty') ?? $request->get('unanswered_penalty', 0.0)),
                    'pass_mark' => (float) ($request->input('pass_mark') ?? $request->get('pass_mark', 20.0)),
                ];

                $nav = [
                    'allow_back' => (bool) ($request->input('allow_back') ?? $request->get('allow_back', false)),
                    'allow_skip' => (bool) ($request->input('allow_skip') ?? $request->get('allow_skip', false)),
                    'allow_review_screen' => (bool) ($request->input('allow_review_screen') ?? $request->get('allow_review_screen', false)),
                    'randomize_questions' => (bool) ($request->input('randomize_questions') ?? $request->get('randomize_questions', false)),
                    'randomize_options' => (bool) ($request->input('randomize_options') ?? $request->get('randomize_options', false)),
                ];

                $startTs = strtotime(str_replace('T', ' ', $windowStart)) ?: time();
                $endTs = strtotime(str_replace('T', ' ', $windowEnd)) ?: (time() + 86400);
                $startUtc = gmdate('Y-m-d H:i:s', $startTs);
                $endUtc = gmdate('Y-m-d H:i:s', $endTs);

                $targetAudience = (string) ($request->input('target_audience') ?? $request->get('target_audience', 'all'));
                $selectedGroups = (array) ($request->input('target_groups') ?? $request->get('target_groups', []));
                $selectedDepts = (array) ($request->input('target_departments') ?? $request->get('target_departments', []));
                $selectedZones = (array) ($request->input('target_zones') ?? $request->get('target_zones', []));

                $targetGroups = [];
                $targetDepts = [];
                $targetZones = [];

                if ($targetAudience !== 'all') {
                    $targetGroups = array_values(array_filter(array_map('intval', $selectedGroups), fn($g) => $g > 0));
                    $targetDepts = array_values(array_filter(array_map('trim', $selectedDepts), fn($d) => $d !== ''));
                    $targetZones = array_values(array_filter(array_map('trim', $selectedZones), fn($z) => $z !== ''));
                }

                $this->quizService->update($quizId, [
                    'title' => $title,
                    'code' => $code,
                    'description' => $desc,
                    'feedback_question' => $feedbackQuestion,
                    'duration_minutes' => $duration,
                    'duration_seconds' => $duration * 60,
                    'start_at' => $startUtc,
                    'end_at' => $endUtc,
                    'window_start_at' => $startUtc,
                    'window_end_at' => $endUtc,
                    'settings' => [
                        'scoring' => $scoring,
                        'navigation' => $nav,
                        'target_audience' => $targetAudience,
                        'target_groups' => $targetGroups,
                        'target_departments' => $targetDepts,
                        'target_zones' => $targetZones,
                    ],
                ], $adminId);

                Session::set('flash_success', 'Quiz updated successfully.');
                return $this->redirect('/admin/quizzes');
            } catch (\Throwable $e) {
                $groups = $this->db->query('SELECT id, name, description FROM `groups` ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
                return $this->render('admin/quizzes/form.html.twig', [
                    'admin' => $this->getAdminUser(),
                    'current_route' => 'quizzes',
                    'quiz' => $quiz,
                    'groups' => $groups,
                    'departments' => $this->getDistinctDepartments(),
                    'zones' => $this->getDistinctZones(),
                    'error' => $e->getMessage(),
                    'old' => $request->getPostData(),
                ]);
            }
        }

        $groups = $this->db->query('SELECT id, name, description FROM `groups` ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
        return $this->render('admin/quizzes/form.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'quizzes',
            'quiz' => $quiz,
            'groups' => $groups,
            'departments' => $this->getDistinctDepartments(),
            'zones' => $this->getDistinctZones(),
            'old' => null,
        ]);
    }

    /**
     * @Route(path="/admin/quizzes/{id}/delete", methods="POST", name="admin.quizzes.delete")
     */
    public function quizDelete(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $quizId = (int) $id;
        try {
            $this->quizService->delete($quizId);
            Session::set('flash_success', 'Quiz deleted successfully.');
        } catch (\Throwable $e) {
            Session::set('flash_error', 'Failed to delete quiz: ' . $e->getMessage());
        }

        return $this->redirect('/admin/quizzes');
    }

    /**
     * @Route(path="/admin/quizzes/{id}/questions", methods="GET,POST", name="admin.quizzes.questions")
     */
    public function quizQuestions(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $quizId = (int) $id;
        $quiz = $this->quizService->getById($quizId);
        if (!$quiz) {
            Session::set('flash_error', 'Quiz not found');
            return $this->redirect('/admin/quizzes');
        }

        if ($request->getMethod() === 'POST') {
            try {
                $targetDir = dirname(__DIR__, 2) . '/public/uploads/questions';
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                $rawQuestions = $request->input('questions') ?? $request->get('questions');
                $addedCount = 0;

                if (is_array($rawQuestions) && !empty($rawQuestions)) {
                    foreach ($rawQuestions as $i => $qData) {
                        $qText = trim((string) ($qData['text'] ?? ''));
                        if ($qText === '') {
                            continue;
                        }

                        $correctIndex = (int) ($qData['correct_option'] ?? 0);
                        $rawOptions = (array) ($qData['options'] ?? []);

                        $options = [];
                        foreach ($rawOptions as $idx => $optText) {
                            $optText = trim((string) $optText);
                            if ($optText !== '') {
                                $options[] = [
                                    'text' => $optText,
                                    'is_correct' => ($idx === $correctIndex),
                                ];
                            }
                        }

                        // Process optional uploaded image for question $i
                        $imagePath = null;
                        $fileKey = "question_image_{$i}";
                        if (!empty($_FILES[$fileKey]['tmp_name']) && is_uploaded_file($_FILES[$fileKey]['tmp_name'])) {
                            $file = $_FILES[$fileKey];
                            $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                            $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
                            if (in_array($ext, $allowedExts, true)) {
                                $fileName = 'q_' . $quizId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                                $dest = $targetDir . '/' . $fileName;
                                if (move_uploaded_file($file['tmp_name'], $dest)) {
                                    $imagePath = '/uploads/questions/' . $fileName;
                                }
                            }
                        }

                        $this->questionService->addQuestion($quizId, $qText, $options, 0, $imagePath);
                        $addedCount++;
                    }

                    if ($addedCount === 0) {
                        throw new InvalidArgumentException('Please fill in at least one question before saving.');
                    }

                    $msg = $addedCount === 1 ? '1 question added successfully.' : "{$addedCount} questions added successfully.";
                    Session::set('flash_success', $msg);
                    return $this->redirect("/admin/quizzes/{$quizId}/questions");
                }

                // Fallback for single question submission format
                $questionText = trim((string) ($request->input('question_text') ?? $request->get('question_text', '')));
                if ($questionText !== '') {
                    $correctIndex = (int) ($request->input('correct_option') ?? $request->get('correct_option', -1));
                    $rawOptions = (array) ($request->input('options') ?? $request->get('options', []));

                    $options = [];
                    foreach ($rawOptions as $idx => $optText) {
                        $optText = trim((string) $optText);
                        if ($optText !== '') {
                            $options[] = [
                                'text' => $optText,
                                'is_correct' => ($idx === $correctIndex),
                            ];
                        }
                    }

                    $imagePath = null;
                    if (!empty($_FILES['question_image']['tmp_name']) && is_uploaded_file($_FILES['question_image']['tmp_name'])) {
                        $file = $_FILES['question_image'];
                        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
                        if (in_array($ext, $allowedExts, true)) {
                            $fileName = 'q_' . $quizId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                            $dest = $targetDir . '/' . $fileName;
                            if (move_uploaded_file($file['tmp_name'], $dest)) {
                                $imagePath = '/uploads/questions/' . $fileName;
                            }
                        }
                    }

                    $this->questionService->addQuestion($quizId, $questionText, $options, 0, $imagePath);
                    Session::set('flash_success', 'Question added successfully.');
                    return $this->redirect("/admin/quizzes/{$quizId}/questions");
                }

                throw new InvalidArgumentException('Please provide question text and at least 2 options.');
            } catch (\Throwable $e) {
                $questions = $this->questionService->getQuestionsByQuiz($quizId);
                $groups = $this->db->query('SELECT id, name, description FROM `groups` ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
                return $this->render('admin/quizzes/questions.html.twig', [
                    'admin' => $this->getAdminUser(),
                    'current_route' => 'quizzes',
                    'quiz' => $quiz,
                    'questions' => $questions,
                    'groups' => $groups,
                    'departments' => $this->getDistinctDepartments(),
                    'zones' => $this->getDistinctZones(),
                    'error' => $e->getMessage(),
                    'old' => $request->getPostData(),
                ]);
            }
        }

        $questions = $this->questionService->getQuestionsByQuiz($quizId);
        $groups = $this->db->query('SELECT id, name, description FROM `groups` ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
        $success = Session::get('flash_success');
        $error = Session::get('flash_error');
        Session::delete('flash_success');
        Session::delete('flash_error');

        return $this->render('admin/quizzes/questions.html.twig', [
            'admin' => $this->getAdminUser(),
            'current_route' => 'quizzes',
            'quiz' => $quiz,
            'questions' => $questions,
            'groups' => $groups,
            'departments' => $this->getDistinctDepartments(),
            'zones' => $this->getDistinctZones(),
            'success' => $success,
            'error' => $error,
            'old' => null,
        ]);
    }

    /**
     * @Route(path="/admin/quizzes/{id}/audience", methods="POST", name="admin.quizzes.audience")
     */
    public function quizAudience(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $admin = $this->getAdminUser();
        $adminId = (int) ($admin['id'] ?? 1);
        $quizId = (int) $id;

        $targetAudience = (string) $request->get('target_audience', 'all');
        $selectedGroups = (array) $request->get('target_groups', []);
        $selectedDepts = (array) $request->get('target_departments', []);
        $selectedZones = (array) $request->get('target_zones', []);

        $targetGroups = [];
        $targetDepts = [];
        $targetZones = [];

        if ($targetAudience !== 'all') {
            $targetGroups = array_values(array_filter(array_map('intval', $selectedGroups), fn($g) => $g > 0));
            $targetDepts = array_values(array_filter(array_map('trim', $selectedDepts), fn($d) => $d !== ''));
            $targetZones = array_values(array_filter(array_map('trim', $selectedZones), fn($z) => $z !== ''));
        }

        try {
            $this->quizService->update($quizId, [
                'settings' => [
                    'target_audience' => $targetAudience,
                    'target_groups' => $targetGroups,
                    'target_departments' => $targetDepts,
                    'target_zones' => $targetZones,
                ],
            ], $adminId);
            Session::set('flash_success', 'Target audience updated successfully.');
        } catch (\Throwable $e) {
            Session::set('flash_error', 'Failed to update audience: ' . $e->getMessage());
        }

        return $this->redirect("/admin/quizzes/{$quizId}/questions");
    }

    /**
     * @Route(path="/admin/quizzes/{id}/questions/{qid}/delete", methods="POST", name="admin.quizzes.questions.delete")
     */
    public function quizQuestionDelete(Request $request, string $id, string $qid): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $quizId = (int) $id;
        $questionId = (int) $qid;

        try {
            $this->questionService->deleteQuestion($questionId);
            Session::set('flash_success', 'Question deleted successfully.');
        } catch (\Throwable $e) {
            Session::set('flash_error', 'Failed to delete question: ' . $e->getMessage());
        }

        return $this->redirect("/admin/quizzes/{$quizId}/questions");
    }

    /**
     * @Route(path="/admin/quizzes/{id}/questions/{qid}/edit", methods="POST", name="admin.quizzes.questions.edit")
     */
    public function quizQuestionEdit(Request $request, string $id, string $qid): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $quizId = (int) $id;
        $questionId = (int) $qid;

        try {
            $questionText = trim((string) ($request->input('question_text') ?? $request->get('question_text', '')));
            if ($questionText === '') {
                throw new InvalidArgumentException('Question text cannot be empty.');
            }

            $correctIndex = (int) ($request->input('correct_option') ?? $request->get('correct_option', -1));
            $rawOptions = (array) ($request->input('options') ?? $request->get('options', []));
            $removeImage = (bool) ($request->input('remove_image') ?? $request->get('remove_image', false));

            $options = [];
            foreach ($rawOptions as $idx => $optText) {
                $optText = trim((string) $optText);
                if ($optText !== '') {
                    $options[] = [
                        'text' => $optText,
                        'is_correct' => ($idx === $correctIndex),
                    ];
                }
            }

            $imagePath = null;
            $removeImage = (bool) $request->get('remove_image', false);
            if (!empty($_FILES['question_image']['tmp_name']) && is_uploaded_file($_FILES['question_image']['tmp_name'])) {
                $targetDir = dirname(__DIR__, 2) . '/public/uploads/questions';
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                $file = $_FILES['question_image'];
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExts, true)) {
                    $fileName = 'q_' . $quizId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $dest = $targetDir . '/' . $fileName;
                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        $imagePath = '/uploads/questions/' . $fileName;
                    }
                }
            }

            $this->questionService->updateQuestion($questionId, $questionText, $options, $imagePath, $removeImage);
            Session::set('flash_success', 'Question updated successfully.');
        } catch (\Throwable $e) {
            Session::set('flash_error', 'Failed to update question: ' . $e->getMessage());
        }

        return $this->redirect("/admin/quizzes/{$quizId}/questions");
    }

    /**
     * @Route(path="/admin/quizzes/{id}/publish", methods="POST", name="admin.quizzes.publish")
     */
    public function quizPublish(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $admin = $this->getAdminUser();
        $adminId = (int) ($admin['id'] ?? 1);

        $quizId = (int) $id;
        try {
            $result = $this->quizPublisher->publish($quizId, $adminId);
            $rosterCount = (int) ($result['roster_count'] ?? 0);
            Session::set('flash_success', "Quiz published successfully! Materialized {$rosterCount} eligible employee attempts into roster.");
            return $this->redirect('/admin/quizzes');
        } catch (\Throwable $e) {
            Session::set('flash_error', $e->getMessage());
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
            Session::set('flash_success', 'Quiz warmed in Redis cache.');
        } catch (\Throwable $e) {
            Session::set('flash_error', 'Failed to warm quiz: ' . $e->getMessage());
        }
        return $this->redirect('/admin/quizzes');
    }

    /**
     * @Route(path="/admin/quizzes/{id}/finalize-status", methods="GET", name="admin.quizzes.finalize_status")
     */
    public function quizFinalizeStatus(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $quizId = (int) $id;
        try {
            $pending = $this->quizFinalizerService->getUngradedCount($quizId);
            return new Response(
                json_encode([
                    'success' => true,
                    'quiz_id' => $quizId,
                    'total_pending' => $pending,
                ], JSON_UNESCAPED_SLASHES),
                200,
                ['Content-Type' => 'application/json; charset=utf-8']
            );
        } catch (\Throwable $e) {
            return new Response(
                json_encode([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], JSON_UNESCAPED_SLASHES),
                400,
                ['Content-Type' => 'application/json; charset=utf-8']
            );
        }
    }

    /**
     * @Route(path="/admin/quizzes/{id}/finalize-batch", methods="POST", name="admin.quizzes.finalize_batch")
     */
    public function quizFinalizeBatch(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $quizId = (int) $id;
        $limit = max(1, min(200, (int) ($request->get('limit', 100))));

        try {
            $result = $this->quizFinalizerService->finalizeBatch($quizId, $limit);
            return new Response(
                json_encode($result, JSON_UNESCAPED_SLASHES),
                200,
                ['Content-Type' => 'application/json; charset=utf-8']
            );
        } catch (\Throwable $e) {
            return new Response(
                json_encode([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], JSON_UNESCAPED_SLASHES),
                400,
                ['Content-Type' => 'application/json; charset=utf-8']
            );
        }
    }

    /**
     * @Route(path="/admin/quizzes/{id}/finalize", methods="POST", name="admin.quizzes.finalize")
     */
    public function quizForceFinalize(Request $request, string $id): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $quizId = (int) $id;
        try {
            $totalGraded = 0;
            do {
                $res = $this->quizFinalizerService->finalizeBatch($quizId, 100);
                $totalGraded += $res['batch_graded'];
            } while ($res['batch_graded'] > 0 && $res['remaining'] > 0);

            if ($totalGraded > 0) {
                Session::set('flash_success', "Force finalizer completed: {$totalGraded} submission(s) graded.");
            } else {
                Session::set('flash_success', 'All submissions for this quiz are already graded.');
            }
        } catch (\Throwable $e) {
            Session::set('flash_error', 'Force finalizer failed: ' . $e->getMessage());
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
        $page = max(1, (int) $request->get('page', 1));

        $filters = [
            'quiz_id' => $quizId > 0 ? $quizId : null,
            'status' => $status !== '' ? $status : null,
            'search' => $search !== '' ? $search : null,
            'cursor' => $cursor > 0 ? $cursor : null,
            'page' => $page,
            'limit' => 25,
            'sort_by_rank' => true,
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
            'has_more' => $data['has_more'],
            'current_page' => $page,
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

    /**
     * Parse a datetime string from the form and convert it to UTC 'Y-m-d H:i:s'.
     */
    private function parseWindowDateTime(string $datetime, string $fieldName = 'Window date'): string
    {
        return TimeHelper::toUtc($datetime, $fieldName);
    }

    /**
     * @Route(path="/admin/workers", methods="GET", name="admin.workers")
     */
    public function workers(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $status = $this->workerManager->getStatus();
        $success = Session::get('flash_success');
        $error = Session::get('flash_error');
        Session::delete('flash_success');
        Session::delete('flash_error');

        return $this->render('admin/workers/index.html.twig', [
            'status' => $status,
            'current_route' => 'workers',
            'success' => $success,
            'error' => $error,
            'admin' => $this->getAdminUser(),
        ]);
    }

    /**
     * @Route(path="/admin/workers/status", methods="GET", name="admin.workers.status")
     */
    public function workersStatus(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $status = $this->workerManager->getStatus();
        return new Response(
            json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * @Route(path="/admin/workers/action", methods="POST", name="admin.workers.action")
     */
    public function workersAction(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $worker = trim((string) ($request->get('worker') ?: ($_POST['worker'] ?? '')));
        $action = trim((string) ($request->get('action') ?: ($_POST['action'] ?? '')));

        $result = match ($action) {
            'run_once'  => $this->workerManager->runOnce($worker),
            'restart'   => $this->workerManager->restartWorker($worker),
            'start'     => $this->workerManager->startWorker($worker),
            'stop'      => $this->workerManager->stopWorker($worker),
            'start_all' => $this->workerManager->startAll(),
            'stop_all'  => $this->workerManager->stopAll(),
            default     => ['success' => false, 'message' => "Invalid worker action: {$action}"],
        };

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $isAjax = str_contains($accept, 'application/json')
            || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || !empty($_SERVER['HTTP_HX_REQUEST']);

        if ($isAjax) {
            $code = $result['success'] ? 200 : 400;
            return new Response(
                json_encode($result, JSON_UNESCAPED_SLASHES),
                $code,
                ['Content-Type' => 'application/json; charset=utf-8']
            );
        }

        if ($result['success']) {
            Session::set('flash_success', $result['message']);
        } else {
            Session::set('flash_error', $result['message']);
        }

        return $this->redirect('/admin/workers');
    }

    /**
     * @Route(path="/admin/workers/logs", methods="GET", name="admin.workers.logs")
     */
    public function workersLogs(Request $request): Response
    {
        if ($authRedirect = $this->requireAdmin()) {
            return $authRedirect;
        }

        $worker = trim((string) $request->get('worker', 'flusher'));
        $lines = max(10, min(200, (int) $request->get('lines', 50)));

        $logs = $this->workerManager->getLogs($worker, $lines);
        return new Response(
            json_encode(['worker' => $worker, 'logs' => $logs], JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }
}
