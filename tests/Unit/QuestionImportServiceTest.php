<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Ulid;
use App\Services\QuestionImportService;
use App\Services\QuizService;
use Core\Database;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class QuestionImportServiceTest extends TestCase
{
    private PDO $db;
    private QuestionImportService $service;
    private QuizService $quizService;
    private int $quizId;
    private string $tempCsv;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->service = new QuestionImportService($this->db);
        $this->quizService = new QuizService($this->db);

        // Fetch or create an admin id for testing
        $adminStmt = $this->db->query('SELECT id FROM administrators LIMIT 1');
        $id = $adminStmt->fetchColumn();
        $adminId = $id ? (int) $id : 1;

        $uniqueCode = 'QZ' . substr(Ulid::generate(), -6);
        $quiz = $this->quizService->create([
            'code' => $uniqueCode,
            'title' => 'Question Import Test Quiz ' . Ulid::generate(),
            'description' => 'Test quiz for bulk question upload',
            'instructions' => 'Follow rules',
            'duration_seconds' => 1200,
            'start_at' => gmdate('Y-m-d H:i:s'),
            'end_at' => gmdate('Y-m-d H:i:s', time() + 7200),
            'settings' => [
                'scoring' => ['pass_mark' => 10.0],
            ],
        ], $adminId);

        $this->quizId = (int) $quiz['id'];

        $this->tempCsv = sys_get_temp_dir() . '/test_questions_' . Ulid::generate() . '.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempCsv)) {
            @unlink($this->tempCsv);
        }

        if (isset($this->quizId)) {
            $this->db->exec("DELETE FROM answer_options WHERE question_id IN (SELECT id FROM questions WHERE quiz_id = {$this->quizId})");
            $this->db->exec("DELETE FROM questions WHERE quiz_id = {$this->quizId}");
            $this->db->exec("DELETE FROM quizzes WHERE id = {$this->quizId}");
        }
    }

    public function test_generate_sample_template_returns_valid_csv_with_40_questions(): void
    {
        $csv = $this->service->getSampleTemplateCsv();
        $this->assertNotEmpty($csv);

        $lines = explode("\n", trim($csv));
        $this->assertGreaterThanOrEqual(41, count($lines)); // 1 header + at least 40 questions

        $header = str_getcsv($lines[0]);
        $this->assertContains('Question Text', $header);
        $this->assertContains('Question Type', $header);
        $this->assertContains('Option A', $header);
        $this->assertContains('Correct Option', $header);
    }

    public function test_import_bulk_questions_including_mcq_and_written(): void
    {
        // Generate a CSV with 42 questions: 40 MCQs and 2 Written questions
        $rows = [];
        $rows[] = ['Question Text', 'Question Type', 'Option A', 'Option B', 'Option C', 'Option D', 'Correct Option', 'Display Order'];

        for ($i = 1; $i <= 40; $i++) {
            $rows[] = [
                "Test MCQ Question {$i}",
                'mcq',
                "Choice A for Q{$i}",
                "Choice B for Q{$i}",
                "Choice C for Q{$i}",
                "Choice D for Q{$i}",
                ($i % 4 === 1 ? 'A' : ($i % 4 === 2 ? 'B' : ($i % 4 === 3 ? 'C' : 'D'))),
                $i
            ];
        }

        // Add 2 written questions
        $rows[] = [
            'Test Written Question 41: Describe your experience.',
            'written',
            '', '', '', '',
            '',
            41
        ];
        $rows[] = [
            'Test Written Question 42: What improvements would you suggest?',
            'written',
            '', '', '', '',
            '',
            42
        ];

        $fp = fopen($this->tempCsv, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        $result = $this->service->import($this->quizId, $this->tempCsv);

        $this->assertSame(42, $result['total_rows']);
        $this->assertSame(42, $result['imported_count']);
        $this->assertEmpty($result['errors']);

        // Verify database records
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM questions WHERE quiz_id = :qid');
        $stmt->execute(['qid' => $this->quizId]);
        $this->assertSame(42, (int) $stmt->fetchColumn());

        // Verify written questions count
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM questions WHERE quiz_id = :qid AND type = 'written'");
        $stmt->execute(['qid' => $this->quizId]);
        $this->assertSame(2, (int) $stmt->fetchColumn());

        // Verify MCQ options count: 40 questions * 4 options = 160 options
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM answer_options WHERE question_id IN (SELECT id FROM questions WHERE quiz_id = :qid AND type = 'mcq')");
        $stmt->execute(['qid' => $this->quizId]);
        $this->assertSame(160, (int) $stmt->fetchColumn());

        // Verify written questions have 0 options
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM answer_options WHERE question_id IN (SELECT id FROM questions WHERE quiz_id = :qid AND type = 'written')");
        $stmt->execute(['qid' => $this->quizId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function test_import_validates_malformed_rows_and_rolls_back(): void
    {
        $rows = [
            ['Question Text', 'Question Type', 'Option A', 'Option B', 'Option C', 'Option D', 'Correct Option', 'Display Order'],
            ['Valid Question 1', 'mcq', 'A1', 'B1', 'C1', 'D1', 'A', '1'],
            ['Invalid MCQ Missing Option C and D', 'mcq', 'A2', 'B2', '', '', 'A', '2'], // Missing options
            ['Invalid Correct Option', 'mcq', 'A3', 'B3', 'C3', 'D3', 'Z', '3'], // Invalid option letter
        ];

        $fp = fopen($this->tempCsv, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        $result = $this->service->import($this->quizId, $this->tempCsv);

        $this->assertSame(3, $result['total_rows']);
        $this->assertSame(0, $result['imported_count']); // Rolled back due to errors
        $this->assertNotEmpty($result['errors']);

        // Verify 0 questions were inserted in DB
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM questions WHERE quiz_id = :qid');
        $stmt->execute(['qid' => $this->quizId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function test_import_bulk_questions_xlsx(): void
    {
        $tempXlsx = sys_get_temp_dir() . '/test_questions_' . Ulid::generate() . '.xlsx';

        // Build minimal sheet XML with 40 questions
        $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $headerValues = ['Question Text', 'Question Type', 'Option A', 'Option B', 'Option C', 'Option D', 'Correct Option', 'Display Order'];

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        // Row 1: Header
        $sheetXml .= '<row r="1">';
        foreach ($headerValues as $i => $h) {
            $colLetter = $cols[$i];
            $sheetXml .= '<c r="' . $colLetter . '1" t="inlineStr"><is><t>' . htmlspecialchars($h) . '</t></is></c>';
        }
        $sheetXml .= '</row>';

        // Rows 2..41: 40 questions
        for ($q = 1; $q <= 40; $q++) {
            $rNum = $q + 1;
            $rowVals = [
                "XLSX Question {$q}",
                'mcq',
                "Opt A {$q}",
                "Opt B {$q}",
                "Opt C {$q}",
                "Opt D {$q}",
                'B',
                (string) $q
            ];

            $sheetXml .= '<row r="' . $rNum . '">';
            foreach ($rowVals as $i => $val) {
                $colLetter = $cols[$i];
                $sheetXml .= '<c r="' . $colLetter . $rNum . '" t="inlineStr"><is><t>' . htmlspecialchars($val) . '</t></is></c>';
            }
            $sheetXml .= '</row>';
        }

        $sheetXml .= '</sheetData></worksheet>';

        $zip = new \ZipArchive();
        $zip->open($tempXlsx, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        try {
            $result = $this->service->import($this->quizId, $tempXlsx);

            $this->assertSame(40, $result['total_rows']);
            $this->assertSame(40, $result['imported_count']);
            $this->assertEmpty($result['errors']);

            $stmt = $this->db->prepare('SELECT COUNT(*) FROM questions WHERE quiz_id = :qid');
            $stmt->execute(['qid' => $this->quizId]);
            $this->assertSame(40, (int) $stmt->fetchColumn());
        } finally {
            if (file_exists($tempXlsx)) {
                @unlink($tempXlsx);
            }
        }
    }
}
