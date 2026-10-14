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

    public function test_export_question_summary_csv(): void
    {
        $code = 'QSUM_' . strtoupper(bin2hex(random_bytes(3)));
        $now = gmdate('Y-m-d H:i:s');

        // Create Quiz
        $quizStmt = $this->db->prepare(
            'INSERT INTO quizzes (public_id, code, title, duration_seconds, start_at, end_at, status, settings, current_version, created_by, created_at, updated_at) '
            . 'VALUES (:pid, :code, "QSum Test", 600, :start, :end, "published", "{}", 1, 1, :cat, :uat)'
        );
        $quizStmt->execute([
            'pid' => Ulid::generate(),
            'code' => $code,
            'start' => $now,
            'end' => gmdate('Y-m-d H:i:s', time() + 3600),
            'cat' => $now,
            'uat' => $now,
        ]);
        $quizId = (int) $this->db->lastInsertId();

        // Create Question
        $qStmt = $this->db->prepare('INSERT INTO questions (quiz_id, question_text, display_order) VALUES (:qid, "What is 2+2?", 1)');
        $qStmt->execute(['qid' => $quizId]);
        $qId = (int) $this->db->lastInsertId();

        // Create Employee & Completed Attempt
        $empStmt = $this->db->prepare("INSERT INTO employees (public_id, employee_code, name, status, created_at, updated_at) VALUES (:p, :c, 'Tester', 'active', NOW(), NOW())");
        $empStmt->execute([':p' => bin2hex(random_bytes(10)), ':c' => 'EMP_QS_' . bin2hex(random_bytes(3))]);
        $empId = (int) $this->db->lastInsertId();

        $attStmt = $this->db->prepare("INSERT INTO attempts (public_id, quiz_id, quiz_version, employee_id, status, started_at, submitted_at, score) VALUES (:p, :qid, 1, :eid, 'COMPLETED', NOW(), NOW(), 10)");
        $attStmt->execute([':p' => bin2hex(random_bytes(10)), ':qid' => $quizId, ':eid' => $empId]);
        $attId = (int) $this->db->lastInsertId();

        // Answer
        $ansStmt = $this->db->prepare("INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id, is_correct, answered_at) VALUES (:aid, :qid, 999, 1, NOW())");
        $ansStmt->execute([':aid' => $attId, ':qid' => $qId]);

        try {
            $count = $this->service->exportQuestionSummaryCsv(['quiz_id' => $quizId], $this->tempCsv);
            $this->assertSame(1, $count);

            $lines = file($this->tempCsv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertCount(2, $lines); // header + 1 question
            $row = str_getcsv($lines[1]);
            $this->assertSame('1', $row[0]); // Display Order
            $this->assertSame('What is 2+2?', $row[1]); // Text
            $this->assertSame('1', $row[3]); // Total Completed Attempts
            $this->assertSame('1', $row[4]); // Answered
            $this->assertSame('1', $row[5]); // Correct Count
            $this->assertSame('100%', $row[6]); // Correct %
        } finally {
            $this->db->exec("DELETE FROM attempt_answers WHERE attempt_id = {$attId}");
            $this->db->exec("DELETE FROM attempts WHERE id = {$attId}");
            $this->db->exec("DELETE FROM questions WHERE id = {$qId}");
            $this->db->exec("DELETE FROM quizzes WHERE id = {$quizId}");
            $this->db->exec("DELETE FROM employees WHERE id = {$empId}");
        }
    }

    public function test_export_results_summary_csv_with_quiz_filtering_and_pass_mark(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $codeA = 'RESA_' . substr(Ulid::generate(), 0, 6);
        $codeB = 'RESB_' . substr(Ulid::generate(), 0, 6);

        // Quiz A with pass_mark 10
        $settingsA = json_encode(['scoring' => ['pass_mark' => 10.0]]);
        $qStmt = $this->db->prepare(
            'INSERT INTO quizzes (public_id, code, title, duration_seconds, start_at, end_at, status, settings, current_version, created_by, created_at, updated_at) ' .
            'VALUES (:pid, :code, :title, 600, :start, :end, "published", :settings, 1, 1, :cat, :uat)'
        );
        $qStmt->execute([
            'pid' => Ulid::generate(),
            'code' => $codeA,
            'title' => 'Quiz A Pass 10',
            'start' => $now,
            'end' => gmdate('Y-m-d H:i:s', time() + 3600),
            'settings' => $settingsA,
            'cat' => $now,
            'uat' => $now,
        ]);
        $quizAId = (int) $this->db->lastInsertId();

        // Quiz B with pass_mark 20
        $settingsB = json_encode(['scoring' => ['pass_mark' => 20.0]]);
        $qStmt->execute([
            'pid' => Ulid::generate(),
            'code' => $codeB,
            'title' => 'Quiz B Pass 20',
            'start' => $now,
            'end' => gmdate('Y-m-d H:i:s', time() + 3600),
            'settings' => $settingsB,
            'cat' => $now,
            'uat' => $now,
        ]);
        $quizBId = (int) $this->db->lastInsertId();

        // Create Employees
        $empCodeA = 'EMPA_' . substr(Ulid::generate(), 0, 6);
        $empCodeB = 'EMPB_' . substr(Ulid::generate(), 0, 6);
        $eStmt = $this->db->prepare(
            'INSERT INTO employees (public_id, employee_code, name, email, created_at, updated_at) VALUES (:pid, :code, :name, :email, :cat, :uat)'
        );
        $eStmt->execute(['pid' => Ulid::generate(), 'code' => $empCodeA, 'name' => 'Emp A', 'email' => "{$empCodeA}@test.com", 'cat' => $now, 'uat' => $now]);
        $empAId = (int) $this->db->lastInsertId();

        $eStmt->execute(['pid' => Ulid::generate(), 'code' => $empCodeB, 'name' => 'Emp B', 'email' => "{$empCodeB}@test.com", 'cat' => $now, 'uat' => $now]);
        $empBId = (int) $this->db->lastInsertId();

        // Attempts:
        // Emp A on Quiz A: score 15 (>= 10, PASS)
        // Emp B on Quiz B: score 15 (< 20, FAIL)
        $attStmt = $this->db->prepare(
            'INSERT INTO attempts (public_id, quiz_id, quiz_version, employee_id, status, started_at, submitted_at, score, accuracy, completion_time_s) ' .
            'VALUES (:pid, :qid, 1, :eid, "COMPLETED", :start, :sub, :score, 75, 100)'
        );
        $attStmt->execute([
            'pid' => Ulid::generate(),
            'qid' => $quizAId,
            'eid' => $empAId,
            'start' => $now,
            'sub' => $now,
            'score' => 15.0,
        ]);
        $attAId = (int) $this->db->lastInsertId();

        $attStmt->execute([
            'pid' => Ulid::generate(),
            'qid' => $quizBId,
            'eid' => $empBId,
            'start' => $now,
            'sub' => $now,
            'score' => 15.0,
        ]);
        $attBId = (int) $this->db->lastInsertId();

        try {
            // Test 1: Filtered by Quiz A only
            $countA = $this->service->exportResultsSummaryCsv(['quiz_id' => $quizAId], $this->tempCsv);
            $this->assertSame(1, $countA);

            $linesA = file($this->tempCsv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertCount(2, $linesA); // header + 1 data row
            $rowA = str_getcsv($linesA[1]);
            $this->assertSame($empCodeA, $rowA[1]); // Employee Code
            $this->assertSame('Quiz A Pass 10', $rowA[4]); // Quiz Title
            $this->assertSame('PASS', $rowA[9]); // Result (15 >= 10)

            // Test 2: Filtered by Quiz B only
            $countB = $this->service->exportResultsSummaryCsv(['quiz_id' => $quizBId], $this->tempCsv);
            $this->assertSame(1, $countB);

            $linesB = file($this->tempCsv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertCount(2, $linesB);
            $rowB = str_getcsv($linesB[1]);
            $this->assertSame($empCodeB, $rowB[1]);
            $this->assertSame('Quiz B Pass 20', $rowB[4]);
            $this->assertSame('FAIL', $rowB[9]); // Result (15 < 20)
        } finally {
            $this->db->prepare('DELETE FROM attempts WHERE id IN (?, ?)')
                ->execute([$attAId, $attBId]);
            $this->db->prepare('DELETE FROM employees WHERE id IN (?, ?)')
                ->execute([$empAId, $empBId]);
            $this->db->prepare('DELETE FROM quizzes WHERE id IN (?, ?)')
                ->execute([$quizAId, $quizBId]);
        }
    }

    public function test_question_wise_export_with_question_bank_exports_candidate_question_rows(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $code = 'QWB_' . substr(Ulid::generate(), 0, 5);

        // 1. Create Question Bank
        $bankStmt = $this->db->prepare(
            'INSERT INTO question_banks (title, description, created_by, created_at, updated_at) VALUES ("Bank QW", "Desc", 1, :cat, :uat)'
        );
        $bankStmt->execute(['cat' => $now, 'uat' => $now]);
        $bankId = (int) $this->db->lastInsertId();

        // 2. Create Question in Bank (quiz_id is NULL)
        $qStmt = $this->db->prepare(
            'INSERT INTO questions (question_bank_id, quiz_id, question_text, display_order) VALUES (:bid, NULL, "Bank Question 1", 1)'
        );
        $qStmt->execute(['bid' => $bankId]);
        $qId = (int) $this->db->lastInsertId();

        // 3. Option
        $optStmt = $this->db->prepare(
            'INSERT INTO answer_options (question_id, option_text, is_correct, display_order) VALUES (:qid, "Option A", 1, 1)'
        );
        $optStmt->execute(['qid' => $qId]);
        $optId = (int) $this->db->lastInsertId();

        // 4. Create Quiz linked to Bank
        $quizStmt = $this->db->prepare(
            'INSERT INTO quizzes (public_id, code, title, duration_seconds, start_at, end_at, status, settings, question_bank_id, current_version, created_by, created_at, updated_at) ' .
            'VALUES (:pid, :code, "Bank Quiz", 600, :start, :end, "published", "{}", :bid, 1, 1, :cat, :uat)'
        );
        $quizStmt->execute([
            'pid' => Ulid::generate(),
            'code' => $code,
            'start' => $now,
            'end' => gmdate('Y-m-d H:i:s', time() + 3600),
            'bid' => $bankId,
            'cat' => $now,
            'uat' => $now,
        ]);
        $quizId = (int) $this->db->lastInsertId();

        // 5. Create Employee & Completed Attempt with recorded answer
        $empCode = 'EMPQW_' . substr(Ulid::generate(), 0, 5);
        $empStmt = $this->db->prepare(
            'INSERT INTO employees (public_id, employee_code, name, email, created_at, updated_at) VALUES (:pid, :code, "QW Candidate", :email, :cat, :uat)'
        );
        $empStmt->execute(['pid' => Ulid::generate(), 'code' => $empCode, 'email' => "{$empCode}@test.com", 'cat' => $now, 'uat' => $now]);
        $empId = (int) $this->db->lastInsertId();

        $attStmt = $this->db->prepare(
            'INSERT INTO attempts (public_id, quiz_id, quiz_version, employee_id, status, started_at, submitted_at, score, accuracy, completion_time_s) ' .
            'VALUES (:pid, :qid, 1, :eid, "COMPLETED", :start, :sub, 10, 100, 60)'
        );
        $attStmt->execute(['pid' => Ulid::generate(), 'qid' => $quizId, 'eid' => $empId, 'start' => $now, 'sub' => $now]);
        $attId = (int) $this->db->lastInsertId();

        $ansStmt = $this->db->prepare(
            'INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id, is_correct, answered_at) VALUES (:aid, :qid, :oid, 1, :at)'
        );
        $ansStmt->execute(['aid' => $attId, 'qid' => $qId, 'oid' => $optId, 'at' => $now]);

        try {
            // Process export job for question_summary
            $jobId = $this->service->createExportJob('question_summary', ['quiz_id' => $quizId]);
            $res = $this->service->processExportJob($jobId);

            $this->assertGreaterThan(0, $res['row_count']);
            $this->assertFileExists($res['file_path']);

            $lines = file($res['file_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertGreaterThanOrEqual(2, count($lines));

            // Verify header has Rank, Employee ID, Quiz Question, Employee Answer, etc.
            $header = str_getcsv($lines[0]);
            $this->assertSame('Rank', $header[0]);
            $this->assertSame('Employee ID', $header[1]);
            $this->assertSame('Employee Name', $header[2]);
            $this->assertSame('Quiz Name', $header[3]);
            $this->assertSame('Quiz Question', $header[4]);
            $this->assertSame('Employee Answer', $header[5]);
            $this->assertSame('Result', $header[6]);

            // Verify row 1 has question details
            $row1 = str_getcsv($lines[1]);
            $this->assertSame($empCode, $row1[1]);
            $this->assertSame('QW Candidate', $row1[2]);
            $this->assertSame('Bank Quiz', $row1[3]);
            $this->assertSame('Bank Question 1', $row1[4]);
            $this->assertSame('Option A', $row1[5]);
            $this->assertSame('Correct', $row1[6]);

            @unlink($res['file_path']);
        } finally {
            $this->db->exec("DELETE FROM attempt_answers WHERE attempt_id = {$attId}");
            $this->db->exec("DELETE FROM attempts WHERE id = {$attId}");
            $this->db->exec("DELETE FROM employees WHERE id = {$empId}");
            $this->db->exec("DELETE FROM quizzes WHERE id = {$quizId}");
            $this->db->exec("DELETE FROM answer_options WHERE question_id = {$qId}");
            $this->db->exec("DELETE FROM questions WHERE id = {$qId}");
            $this->db->exec("DELETE FROM question_banks WHERE id = {$bankId}");
        }
    }
}
