<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Ulid;
use App\Services\EmployeeImportService;
use Core\Database;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class EmployeeImportServiceTest extends TestCase
{
    private PDO $db;
    private EmployeeImportService $service;
    private string $tempCsv;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->service = new EmployeeImportService($this->db);
        $this->tempCsv = sys_get_temp_dir() . '/test_import_' . Ulid::generate() . '.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempCsv)) {
            @unlink($this->tempCsv);
        }
    }

    public function test_preview_validates_headers_and_row_count(): void
    {
        $uniqueSuffix = substr(Ulid::generate(), -6);
        $content = "employee_code,name,email,username,status\n"
            . "IMP_{$uniqueSuffix}_1,Import User One,imp1_{$uniqueSuffix}@corp.local,imp_u1_{$uniqueSuffix},active\n"
            . "IMP_{$uniqueSuffix}_2,Import User Two,imp2_{$uniqueSuffix}@corp.local,imp_u2_{$uniqueSuffix},active\n";

        file_put_contents($this->tempCsv, $content);

        $preview = $this->service->preview($this->tempCsv);

        $this->assertSame(2, $preview['total_rows']);
        $this->assertSame(2, $preview['valid_count']);
        $this->assertSame(0, $preview['duplicate_count']);
        $this->assertCount(2, $preview['preview']);
        $this->assertTrue($preview['preview'][0]['is_valid']);
        $this->assertTrue($preview['preview'][1]['is_valid']);
    }

    public function test_preview_detects_duplicates_and_empty_fields(): void
    {
        $uniqueSuffix = substr(Ulid::generate(), -6);
        $content = "employee_code,name,email\n"
            . "DUP_{$uniqueSuffix},User First,dup_{$uniqueSuffix}@corp.local\n"
            . "DUP_{$uniqueSuffix},User Duplicate,other_{$uniqueSuffix}@corp.local\n"
            . ",Missing Code,missing_{$uniqueSuffix}@corp.local\n";

        file_put_contents($this->tempCsv, $content);

        $preview = $this->service->preview($this->tempCsv);

        $this->assertSame(3, $preview['total_rows']);
        $this->assertSame(1, $preview['valid_count']);
        $this->assertSame(2, $preview['duplicate_count']);
        $this->assertFalse($preview['preview'][1]['is_valid']);
        $this->assertStringContainsString('Duplicate employee code', $preview['preview'][1]['reason']);
        $this->assertFalse($preview['preview'][2]['is_valid']);
    }

    public function test_can_create_import_job_and_process_job(): void
    {
        $uniqueSuffix = substr(Ulid::generate(), -6);
        $code1 = "JOB_{$uniqueSuffix}_1";
        $code2 = "JOB_{$uniqueSuffix}_2";

        $content = "employee_code,name,email,status\n"
            . "{$code1},Import User 1,job1_{$uniqueSuffix}@corp.local,active\n"
            . "{$code2},Import User 2,job2_{$uniqueSuffix}@corp.local,active\n"
            . ",Bad Row Missing Code,bad_{$uniqueSuffix}@corp.local,active\n";

        file_put_contents($this->tempCsv, $content);

        $adminId = 1;
        $jobId = $this->service->createImportJob($this->tempCsv, $adminId);
        $this->assertGreaterThan(0, $jobId);

        $result = $this->service->processJob($jobId);

        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertNotEmpty($result['report_path']);
        $this->assertFileExists($result['report_path']);

        // Verify employees were inserted in database
        $stmt = $this->db->prepare('SELECT id, name FROM employees WHERE employee_code = :c');
        $stmt->execute(['c' => $code1]);
        $row1 = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row1);
        $this->assertSame('Import User 1', $row1['name']);

        // Clean up created employees & report file
        $this->db->prepare('DELETE FROM employees WHERE employee_code IN (:c1, :c2)')
            ->execute(['c1' => $code1, 'c2' => $code2]);

        if (file_exists($result['report_path'])) {
            @unlink($result['report_path']);
        }
    }

    public function test_file_size_exceeded_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found');
        $this->service->preview('/non/existent/path.csv');
    }

    public function test_import_with_user_spreadsheet_format_and_leading_zeros(): void
    {
        $uniqueSuffix = substr(Ulid::generate(), -4);
        $code1 = '000' . rand(100, 999) . '_' . $uniqueSuffix;
        $code2 = '000' . rand(100, 999) . '_' . $uniqueSuffix;

        $content = "SL NO,ZONE/Region,EMP CODE,EMP NAME,DEPARTMENT,DESIGNATION\n"
            . "1,ZONE 1,{$code1},AFZAL PASHA,KOLAR SALES,SALES LEADER\n"
            . "2,ZONE 1,{$code2},VINOD KUMAR,BANGALORE SALES,OPPO EXPERIENCE CONSULTANT\n";

        file_put_contents($this->tempCsv, $content);

        $preview = $this->service->preview($this->tempCsv);
        $this->assertSame(2, $preview['total_rows']);
        $this->assertSame(2, $preview['valid_count']);
        $this->assertSame('ZONE 1', $preview['preview'][0]['zone_region']);
        $this->assertSame($code1, $preview['preview'][0]['code']);
        $this->assertSame('AFZAL PASHA', $preview['preview'][0]['name']);
        $this->assertSame('KOLAR SALES', $preview['preview'][0]['department']);
        $this->assertSame('SALES LEADER', $preview['preview'][0]['designation']);

        $jobId = $this->service->createImportJob($this->tempCsv, 1);
        $result = $this->service->processJob($jobId);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['failed']);

        // Verify in database
        $stmt = $this->db->prepare('SELECT employee_code, name, zone_region, department, designation FROM employees WHERE employee_code = :c');
        $stmt->execute(['c' => $code1]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($emp);
        $this->assertSame($code1, $emp['employee_code']);
        $this->assertSame('AFZAL PASHA', $emp['name']);
        $this->assertSame('ZONE 1', $emp['zone_region']);
        $this->assertSame('KOLAR SALES', $emp['department']);
        $this->assertSame('SALES LEADER', $emp['designation']);

        // Clean up
        $this->db->prepare('DELETE FROM employees WHERE employee_code IN (:c1, :c2)')
            ->execute(['c1' => $code1, 'c2' => $code2]);
    }
}
