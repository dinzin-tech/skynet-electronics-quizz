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

class AdminEmployeeImportTest extends TestCase
{
    private PDO $db;
    private AdminController $controller;
    private string $tempCsv;

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
        $this->tempCsv = sys_get_temp_dir() . '/test_admin_import_' . Ulid::generate() . '.csv';
    }

    protected function tearDown(): void
    {
        Session::delete('admin_user');
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        if (file_exists($this->tempCsv)) {
            @unlink($this->tempCsv);
        }
    }

    private function getResponseBody(\Core\Http\Response $response): string
    {
        $ref = new \ReflectionProperty($response, 'content');
        $ref->setAccessible(true);
        return (string) $ref->getValue($response);
    }

    public function test_import_execute_fails_when_file_does_not_exist(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'file_path' => 'non_existent_file_' . Ulid::generate() . '.csv',
        ];

        $request = new Request();
        $response = $this->controller->employeeImportExecute($request);
        $body = $this->getResponseBody($response);

        $this->assertStringContainsString('Uploaded file no longer exists', $body);
    }

    public function test_import_execute_succeeds_with_post_file_path(): void
    {
        $uniqueSuffix = substr(Ulid::generate(), -6);
        $code = "ADMIMP_{$uniqueSuffix}";
        $content = "employee_code,name,email,status\n"
            . "{$code},Admin Import User,admimp_{$uniqueSuffix}@corp.local,active\n";

        file_put_contents($this->tempCsv, $content);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'file_path' => $this->tempCsv,
        ];

        $request = new Request();
        $response = $this->controller->employeeImportExecute($request);
        $body = $this->getResponseBody($response);

        $this->assertStringContainsString('Import complete', $body);
        $this->assertStringNotContainsString('Uploaded file no longer exists', $body);

        // Verify inserted employee in database
        $stmt = $this->db->prepare('SELECT id, name FROM employees WHERE employee_code = :c');
        $stmt->execute(['c' => $code]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($employee);
        $this->assertSame('Admin Import User', $employee['name']);

        // Clean up created employee
        $this->db->prepare('DELETE FROM employees WHERE employee_code = :c')->execute(['c' => $code]);
    }
}
