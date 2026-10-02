<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Ulid;
use App\Services\ReportExportService;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class ReportExportServiceTest extends TestCase
{
    private PDO $db;
    private ReportExportService $service;
    private string $tempCsv;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->service = new ReportExportService($this->db);
        $this->tempCsv = sys_get_temp_dir() . '/test_export_' . Ulid::generate() . '.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempCsv)) {
            @unlink($this->tempCsv);
        }
    }

    public function test_formula_injection_defense_neutralizes_dangerous_prefixes(): void
    {
        $this->assertSame("'=SUM(A1:A10)", ReportExportService::sanitizeCell('=SUM(A1:A10)'));
        $this->assertSame("'+cmd|' /C calc'!A0", ReportExportService::sanitizeCell("+cmd|' /C calc'!A0"));
        $this->assertSame("'-5+10", ReportExportService::sanitizeCell('-5+10'));
        $this->assertSame("'@dangerous", ReportExportService::sanitizeCell('@dangerous'));
        $this->assertSame("'\ttab_injected", ReportExportService::sanitizeCell("\ttab_injected"));
        $this->assertSame("'\rcarriage_injected", ReportExportService::sanitizeCell("\rcarriage_injected"));

        // Safe values remain untouched
        $this->assertSame('Safe John Doe', ReportExportService::sanitizeCell('Safe John Doe'));
        $this->assertSame('EMP1001', ReportExportService::sanitizeCell('EMP1001'));
        $this->assertSame('', ReportExportService::sanitizeCell(''));
        $this->assertSame('', ReportExportService::sanitizeCell(null));
    }

    public function test_can_export_submissions_to_csv(): void
    {
        $stmt = $this->db->query('SELECT id FROM quizzes LIMIT 1');
        $quizId = (int) $stmt->fetchColumn();

        if ($quizId === 0) {
            $this->markTestSkipped('No quiz found');
        }

        $exportedCount = $this->service->exportSubmissionsCsv(['quiz_id' => $quizId], $this->tempCsv);

        $this->assertGreaterThanOrEqual(0, $exportedCount);
        $this->assertFileExists($this->tempCsv);

        $handle = fopen($this->tempCsv, 'r');
        $header = fgetcsv($handle);
        fclose($handle);

        $this->assertIsArray($header);
        $this->assertSame('Attempt ID', $header[0]);
        $this->assertSame('Employee Code', $header[1]);
    }

    public function test_create_and_process_export_job(): void
    {
        $stmt = $this->db->query('SELECT id FROM quizzes LIMIT 1');
        $quizId = (int) $stmt->fetchColumn();

        if ($quizId === 0) {
            $this->markTestSkipped('No quiz found');
        }

        $jobId = $this->service->createExportJob('submissions', ['quiz_id' => $quizId]);
        $this->assertGreaterThan(0, $jobId);

        $result = $this->service->processExportJob($jobId);

        $this->assertFileExists($result['file_path']);
        $this->assertGreaterThanOrEqual(0, $result['row_count']);

        // Check job record updated to completed
        $checkStmt = $this->db->prepare('SELECT status, file_path, row_count FROM export_jobs WHERE id = :id');
        $checkStmt->execute(['id' => $jobId]);
        $job = $checkStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('completed', $job['status']);
        $this->assertSame($result['file_path'], $job['file_path']);

        // Clean up
        if (file_exists($result['file_path'])) {
            @unlink($result['file_path']);
        }
    }
}
