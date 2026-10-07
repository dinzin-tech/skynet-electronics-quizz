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
        $this->assertSame('Rank', $header[0]);
        $this->assertSame('Employee ID', $header[1]);
        $this->assertSame('Employee Name', $header[2]);
        $this->assertSame('Quiz Name', $header[3]);
        $this->assertSame('Quiz Question', $header[4]);
        $this->assertSame('Employee Answer', $header[5]);
        $this->assertSame('Result', $header[6]);
        $this->assertSame('Score', $header[7]);
        $this->assertSame('Accuracy (%)', $header[8]);
        $this->assertSame('Time Taken (s)', $header[9]);
        $this->assertStringStartsWith('Started At', $header[10]);
        $this->assertStringStartsWith('Ended At', $header[11]);
    }

    public function test_export_submissions_includes_question_level_details_feedback_and_rank_order(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $code = 'EXP' . substr(Ulid::generate(), 0, 8);

        // 1. Create a quiz with feedback question
        $qStmt = $this->db->prepare(
            'INSERT INTO quizzes (public_id, code, title, duration_seconds, start_at, end_at, status, settings, feedback_question, created_by, created_at, updated_at) ' .
            'VALUES (:pid, :code, :title, 600, :start, :end, "published", "{}", :fbq, 1, :cat, :uat)'
        );
        $quizPid = Ulid::generate();
        $qStmt->execute([
            'pid' => $quizPid,
            'code' => $code,
            'title' => 'Report Export Test Quiz',
            'start' => $now,
            'end' => gmdate('Y-m-d H:i:s', time() + 3600),
            'fbq' => 'How was your assessment experience?',
            'cat' => $now,
            'uat' => $now,
        ]);
        $quizId = (int) $this->db->lastInsertId();

        // 2. Add question and options
        $quesStmt = $this->db->prepare(
            'INSERT INTO questions (quiz_id, question_text, display_order) VALUES (:qid, :text, 1)'
        );
        $quesStmt->execute([
            'qid' => $quizId,
            'text' => 'What color is the sky on a clear day?',
        ]);
        $questionId = (int) $this->db->lastInsertId();

        $opt1Stmt = $this->db->prepare(
            'INSERT INTO answer_options (question_id, option_text, is_correct, display_order) VALUES (:qid, :text, 1, 1)'
        );
        $opt1Stmt->execute(['qid' => $questionId, 'text' => 'Blue']);
        $optCorrectId = (int) $this->db->lastInsertId();

        $opt2Stmt = $this->db->prepare(
            'INSERT INTO answer_options (question_id, option_text, is_correct, display_order) VALUES (:qid, :text, 0, 2)'
        );
        $opt2Stmt->execute(['qid' => $questionId, 'text' => 'Green']);
        $optWrongId = (int) $this->db->lastInsertId();

        // 3. Create two employees
        $emp1Code = 'EMP_T1_' . substr(Ulid::generate(), 0, 6);
        $emp2Code = 'EMP_T2_' . substr(Ulid::generate(), 0, 6);

        $eStmt = $this->db->prepare(
            'INSERT INTO employees (public_id, employee_code, name, email, created_at, updated_at) VALUES (:pid, :code, :name, :email, :cat, :uat)'
        );
        $eStmt->execute(['pid' => Ulid::generate(), 'code' => $emp1Code, 'name' => 'Alice HighScore', 'email' => "{$emp1Code}@test.com", 'cat' => $now, 'uat' => $now]);
        $emp1Id = (int) $this->db->lastInsertId();

        $eStmt->execute(['pid' => Ulid::generate(), 'code' => $emp2Code, 'name' => 'Bob LowScore', 'email' => "{$emp2Code}@test.com", 'cat' => $now, 'uat' => $now]);
        $emp2Id = (int) $this->db->lastInsertId();

        // 4. Create attempts: Alice score 10 (Rank 1), Bob score 5 (Rank 2)
        $attStmt = $this->db->prepare(
            'INSERT INTO attempts (public_id, quiz_id, quiz_version, employee_id, status, started_at, submitted_at, score, accuracy, completion_time_s, feedback) ' .
            'VALUES (:pid, :qid, 1, :eid, "COMPLETED", :start, :sub, :score, :acc, :time, :fb)'
        );

        $aliceSubAt = gmdate('Y-m-d H:i:s', time() - 100);
        $attStmt->execute([
            'pid' => Ulid::generate(),
            'qid' => $quizId,
            'eid' => $emp1Id,
            'start' => $now,
            'sub' => $aliceSubAt,
            'score' => 10.0,
            'acc' => 100.0,
            'time' => 120,
            'fb' => 'Great test!',
        ]);
        $aliceAttId = (int) $this->db->lastInsertId();

        $bobSubAt = gmdate('Y-m-d H:i:s', time() - 50);
        $attStmt->execute([
            'pid' => Ulid::generate(),
            'qid' => $quizId,
            'eid' => $emp2Id,
            'start' => $now,
            'sub' => $bobSubAt,
            'score' => 5.0,
            'acc' => 50.0,
            'time' => 200,
            'fb' => 'Needs more time.',
        ]);
        $bobAttId = (int) $this->db->lastInsertId();

        // 5. Insert answers
        $ansStmt = $this->db->prepare(
            'INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id, is_correct, answered_at) VALUES (:aid, :qid, :oid, :cor, :at)'
        );
        $ansStmt->execute(['aid' => $aliceAttId, 'qid' => $questionId, 'oid' => $optCorrectId, 'cor' => 1, 'at' => $now]);
        $ansStmt->execute(['aid' => $bobAttId, 'qid' => $questionId, 'oid' => $optWrongId, 'cor' => 0, 'at' => $now]);

        try {
            // Export CSV
            $count = $this->service->exportSubmissionsCsv(['quiz_id' => $quizId], $this->tempCsv);
            $this->assertSame(4, $count); // 2 candidates * (1 question + 1 feedback) = 4 rows

            $lines = file($this->tempCsv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertCount(5, $lines); // 1 header + 4 data rows

            $csvRows = array_map('str_getcsv', $lines);

            // Row 1: Alice (Rank 1) Question
            $this->assertSame('1', $csvRows[1][0]); // Rank
            $this->assertSame($emp1Code, $csvRows[1][1]); // Employee ID
            $this->assertSame('Alice HighScore', $csvRows[1][2]); // Employee Name
            $this->assertSame('What color is the sky on a clear day?', $csvRows[1][4]); // Quiz Question
            $this->assertSame('Blue', $csvRows[1][5]); // Employee Answer
            $this->assertSame('Correct', $csvRows[1][6]); // Result

            // Row 2: Alice Feedback
            $this->assertSame('1', $csvRows[2][0]); // Rank
            $this->assertSame($emp1Code, $csvRows[2][1]);
            $this->assertSame('How was your assessment experience?', $csvRows[2][4]); // Feedback Question
            $this->assertSame('Great test!', $csvRows[2][5]); // Feedback Answer
            $this->assertSame('N/A', $csvRows[2][6]); // Result

            // Row 3: Bob (Rank 2) Question
            $this->assertSame('2', $csvRows[3][0]); // Rank
            $this->assertSame($emp2Code, $csvRows[3][1]);
            $this->assertSame('Bob LowScore', $csvRows[3][2]);
            $this->assertSame('What color is the sky on a clear day?', $csvRows[3][4]);
            $this->assertSame('Green', $csvRows[3][5]); // Wrong chosen option text
            $this->assertSame('Wrong', $csvRows[3][6]);

            // Row 4: Bob Feedback
            $this->assertSame('2', $csvRows[4][0]); // Rank
            $this->assertSame($emp2Code, $csvRows[4][1]);
            $this->assertSame('How was your assessment experience?', $csvRows[4][4]);
            $this->assertSame('Needs more time.', $csvRows[4][5]);
            $this->assertSame('N/A', $csvRows[4][6]);
        } finally {
            // Clean up test data
            $this->db->prepare('DELETE FROM attempt_answers WHERE attempt_id IN (?, ?)')
                ->execute([$aliceAttId, $bobAttId]);
            $this->db->prepare('DELETE FROM attempts WHERE id IN (?, ?)')
                ->execute([$aliceAttId, $bobAttId]);
            $this->db->prepare('DELETE FROM answer_options WHERE question_id = ?')
                ->execute([$questionId]);
            $this->db->prepare('DELETE FROM questions WHERE id = ?')
                ->execute([$questionId]);
            $this->db->prepare('DELETE FROM employees WHERE id IN (?, ?)')
                ->execute([$emp1Id, $emp2Id]);
            $this->db->prepare('DELETE FROM quizzes WHERE id = ?')
                ->execute([$quizId]);
        }
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
