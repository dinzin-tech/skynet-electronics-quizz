<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Ulid;
use App\Services\ReportExportService;
use Core\Database;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

class ComprehensiveXlsxExportTest extends TestCase
{
    private PDO $db;
    private ReportExportService $service;
    private string $tempXlsx;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->service = new ReportExportService($this->db);
        $this->tempXlsx = sys_get_temp_dir() . '/test_comp_' . Ulid::generate() . '.xlsx';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempXlsx)) {
            @unlink($this->tempXlsx);
        }
    }

    public function test_export_comprehensive_xlsx_creates_multi_sheet_workbook_with_text_ids(): void
    {
        // 1. Create Quiz
        $quizStmt = $this->db->prepare(
            'INSERT INTO quizzes (public_id, code, title, week_number, duration_seconds, start_at, end_at, status, settings, created_by, created_at, updated_at) '
            . 'VALUES (:p, :c, "Week 2 Assessment", 2, 1800, NOW(), DATE_ADD(NOW(), INTERVAL 2 HOUR), "published", :s, 1, NOW(), NOW())'
        );
        $quizCode = 'W2_' . bin2hex(random_bytes(3));
        $quizStmt->execute([
            ':p' => bin2hex(random_bytes(10)),
            ':c' => $quizCode,
            ':s' => json_encode([
                'scoring' => ['marks_per_correct' => 1.0, 'pass_mark' => 20.0],
                'navigation' => ['randomize_questions' => false],
            ]),
        ]);
        $quizId = (int) $this->db->lastInsertId();

        // 2. Create Question (MCQ)
        $qStmt = $this->db->prepare('INSERT INTO questions (quiz_id, question_text, display_order, type) VALUES (:qid, "Sample MCQ", 1, "mcq")');
        $qStmt->execute([':qid' => $quizId]);
        $qId = (int) $this->db->lastInsertId();

        $optStmt = $this->db->prepare('INSERT INTO answer_options (question_id, option_text, is_correct, display_order) VALUES (:qid, "Option A", 1, 1)');
        $optStmt->execute([':qid' => $qId]);
        $optId = (int) $this->db->lastInsertId();

        // 3. Create Written Question
        $wqStmt = $this->db->prepare('INSERT INTO questions (quiz_id, question_text, display_order, type) VALUES (:qid, "How do you improve store visits?", 2, "written")');
        $wqStmt->execute([':qid' => $quizId]);
        $wqId = (int) $this->db->lastInsertId();

        // 4. Create Employee with leading zero ID: '000026'
        $empCode = '000026';
        $empStmt = $this->db->prepare(
            "INSERT INTO employees (public_id, employee_code, name, email, zone_region, designation, status, created_at, updated_at) "
            . "VALUES (:p, :c, 'Test Leading Zero', 'leadzero@corp.local', 'North Zone', 'OEC', 'active', NOW(), NOW())"
        );
        $empStmt->execute([
            ':p' => bin2hex(random_bytes(10)),
            ':c' => $empCode,
        ]);
        $empId = (int) $this->db->lastInsertId();

        // 5. Create Completed Attempt with layout
        $layoutJson = json_encode(['q' => [$qId, $wqId], 'o' => [(string) $qId => [$optId]]]);
        $attStmt = $this->db->prepare(
            "INSERT INTO attempts (public_id, quiz_id, quiz_version, employee_id, status, started_at, submitted_at, layout, score, accuracy, completion_time_s) "
            . "VALUES (:p, :qid, 1, :eid, 'COMPLETED', NOW(), NOW(), :layout, 1.0, 100.0, 120)"
        );
        $attStmt->execute([
            ':p' => bin2hex(random_bytes(10)),
            ':qid' => $quizId,
            ':eid' => $empId,
            ':layout' => $layoutJson,
        ]);
        $attId = (int) $this->db->lastInsertId();

        // Answers
        $ans1 = $this->db->prepare("INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id, is_correct, answered_at) VALUES (:aid, :qid, :oid, 1, NOW())");
        $ans1->execute([':aid' => $attId, ':qid' => $qId, ':oid' => $optId]);

        $ans2 = $this->db->prepare("INSERT INTO attempt_answers (attempt_id, question_id, text_answer, answered_at) VALUES (:aid, :qid, 'Weekly route planning', NOW())");
        $ans2->execute([':aid' => $attId, ':qid' => $wqId]);

        try {
            // Test comprehensive XLSX export
            $exported = $this->service->exportComprehensiveXlsx(['quiz_id' => $quizId], $this->tempXlsx);
            $this->assertGreaterThanOrEqual(1, $exported);
            $this->assertFileExists($this->tempXlsx);

            // Read spreadsheet with PhpSpreadsheet to verify sheets and values
            $reader = IOFactory::createReader('Xlsx');
            $spreadsheet = $reader->load($this->tempXlsx);

            $sheetNames = $spreadsheet->getSheetNames();
            $this->assertContains('Overall Summary', $sheetNames);
            $this->assertContains('Zone Summary', $sheetNames);
            $this->assertContains('Question Analysis', $sheetNames);
            $this->assertContains('Score Summary', $sheetNames);
            $this->assertContains('Participant Results', $sheetNames);
            $this->assertContains('Written Responses', $sheetNames);

            // Verify Participant Results sheet has employee code preserved with leading zeros
            $partSheet = $spreadsheet->getSheetByName('Participant Results');
            $this->assertNotNull($partSheet);

            $foundId = null;
            for ($r = 2; $r <= 10; $r++) {
                $cellVal = $partSheet->getCell("B{$r}")->getValue();
                if ($cellVal === '000026') {
                    $foundId = $cellVal;
                    // Check dataType is string
                    $this->assertSame('s', $partSheet->getCell("B{$r}")->getDataType());
                    break;
                }
            }
            $this->assertSame('000026', $foundId, 'Employee ID 000026 must be strictly preserved as string');

            // Verify Written Responses sheet contains the written response
            $writtenSheet = $spreadsheet->getSheetByName('Written Responses');
            $this->assertNotNull($writtenSheet);
            $wVal = $writtenSheet->getCell('H2')->getValue();
            $this->assertSame('Weekly route planning', $wVal);
        } finally {
            $this->db->exec("DELETE FROM attempt_answers WHERE attempt_id = {$attId}");
            $this->db->exec("DELETE FROM attempts WHERE id = {$attId}");
            $this->db->exec("DELETE FROM employees WHERE id = {$empId}");
            $this->db->exec("DELETE FROM answer_options WHERE question_id = {$qId}");
            $this->db->exec("DELETE FROM questions WHERE quiz_id = {$quizId}");
            $this->db->exec("DELETE FROM quizzes WHERE id = {$quizId}");
        }
    }
}
