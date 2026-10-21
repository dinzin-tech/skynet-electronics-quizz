<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use InvalidArgumentException;
use PDO;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class ReportExportService
{
    private PDO $db;
    private string $storageDir;

    public function __construct(?PDO $db = null, ?string $storageDir = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->storageDir = $storageDir ?? (dirname(__DIR__, 2) . '/storage/exports');

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Neutralize spreadsheet formula injection (=, +, -, @, \t, \r) by prepending a single quote.
     */
    public static function sanitizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $str = (string) $value;
        if ($str === '') {
            return '';
        }

        $firstChar = $str[0];
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }

        return $str;
    }

    /**
     * Create an export job in pending state.
     *
     * @param array<string, mixed> $filters
     */
    public function createExportJob(
        string $reportType,
        array $filters,
        string $format = 'csv',
        int $adminId = 1
    ): int {
        $now = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 86400 * 7); // 7 days retention

        $sql = 'INSERT INTO export_jobs '
            . '(report_type, filters, format, status, row_count, created_by, expires_at, created_at, updated_at) '
            . 'VALUES (:type, :filters, :format, "pending", 0, :aid, :exp, :cat, :uat)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'type' => $reportType,
            'filters' => json_encode($filters),
            'format' => strtolower($format) === 'xlsx' ? 'xlsx' : 'csv',
            'aid' => $adminId,
            'exp' => $expiresAt,
            'cat' => $now,
            'uat' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Process an export job synchronously and stream CSV output to storage.
     *
     * @return array{file_path: string, row_count: int}
     */
    public function processExportJob(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM export_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            throw new InvalidArgumentException("Export job not found: {$jobId}");
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->prepare('UPDATE export_jobs SET status = "processing", updated_at = :now WHERE id = :id')
            ->execute(['now' => $now, 'id' => $jobId]);

        $reportType = (string) $job['report_type'];
        $format = strtolower((string) ($job['format'] ?? 'csv')) === 'xlsx' ? 'xlsx' : 'csv';
        $filters = json_decode((string) ($job['filters'] ?? '{}'), true) ?? [];
        $quizSuffix = !empty($filters['quiz_id']) ? "quiz{$filters['quiz_id']}_" : '';
        $fileName = "export_{$jobId}_{$reportType}_{$quizSuffix}" . gmdate('Ymd_His') . ".{$format}";
        $outputPath = "{$this->storageDir}/{$fileName}";

        try {
            if ($format === 'xlsx') {
                $rowCount = match ($reportType) {
                    'comprehensive', 'dashboard', 'full' => $this->exportComprehensiveXlsx($filters, $outputPath),
                    'submissions' => $this->exportSubmissionsXlsx($filters, $outputPath),
                    'results' => $this->exportResultsSummaryXlsx($filters, $outputPath),
                    'question_summary', 'question_wise' => $this->exportQuestionSummaryXlsx($filters, $outputPath),
                    default => $this->exportComprehensiveXlsx($filters, $outputPath),
                };
            } else {
                $rowCount = match ($reportType) {
                    'submissions', 'question_summary', 'question_wise' => $this->exportSubmissionsCsv($filters, $outputPath),
                    'results' => $this->exportResultsSummaryCsv($filters, $outputPath),
                    'comprehensive', 'full' => $this->exportSubmissionsCsv($filters, $outputPath),
                    default => throw new InvalidArgumentException("Unsupported report type: {$reportType}"),
                };
            }

            $completedAt = gmdate('Y-m-d H:i:s');
            $upStmt = $this->db->prepare(
                'UPDATE export_jobs SET status = "completed", file_path = :path, '
                . 'row_count = :rc, updated_at = :now WHERE id = :id'
            );
            $upStmt->execute([
                'path' => $outputPath,
                'rc' => $rowCount,
                'now' => $completedAt,
                'id' => $jobId,
            ]);

            return [
                'file_path' => $outputPath,
                'row_count' => $rowCount,
            ];
        } catch (\Throwable $e) {
            $failedAt = gmdate('Y-m-d H:i:s');
            $upStmt = $this->db->prepare(
                'UPDATE export_jobs SET status = "failed", updated_at = :now WHERE id = :id'
            );
            $upStmt->execute([
                'now' => $failedAt,
                'id' => $jobId,
            ]);

            throw new RuntimeException("Export job {$jobId} failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Stream submissions report directly to CSV with formula injection neutralization.
     * Generates a question-level breakdown including rank, human-readable questions,
     * options, correct/wrong results, timing, and feedback question/answer.
     *
     * @param array<string, mixed> $filters
     */
    public function exportSubmissionsCsv(array $filters, string $outputPath): int
    {
        $handle = fopen($outputPath, 'w');
        if (!$handle) {
            throw new RuntimeException("Cannot open output file: {$outputPath}");
        }

        $tzName = TimeHelper::getCompanyTimezone()->getName();

        // Header row
        fputcsv($handle, [
            'Rank',
            'Employee ID',
            'Employee Name',
            'Quiz Name',
            'Quiz Question',
            'Employee Answer',
            'Result',
            'Score',
            'Accuracy (%)',
            'Time Taken (s)',
            "Started At ({$tzName})",
            "Ended At ({$tzName})",
        ]);

        $quizId = isset($filters['quiz_id']) && (int) $filters['quiz_id'] > 0 ? (int) $filters['quiz_id'] : null;
        $status = !empty($filters['status']) ? (string) $filters['status'] : null;

        $where = ['1=1'];
        $params = [];

        if ($quizId !== null) {
            $where[] = 'a.quiz_id = :quiz_id';
            $params['quiz_id'] = $quizId;
        }
        if ($status !== null) {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }

        $whereSql = implode(' AND ', $where);

        $rankWhere = 'WHERE status = "COMPLETED"';
        $rankParams = [];
        if ($quizId !== null) {
            $rankWhere .= ' AND quiz_id = :rank_quiz_id';
            $rankParams['rank_quiz_id'] = $quizId;
        }

        $rankSubquery = "SELECT id, DENSE_RANK() OVER ( "
            . "PARTITION BY quiz_id "
            . "ORDER BY COALESCE(score, 0) DESC, "
            . "COALESCE(completion_time_s, 999999) ASC, "
            . "COALESCE(accuracy, 0) DESC, "
            . "started_at ASC, "
            . "id ASC "
            . ") AS `rank` "
            . "FROM attempts "
            . "{$rankWhere}";

        $queryParams = array_merge($params, $rankParams);

        $chunkSize = 250;
        $offset = 0;
        $totalExported = 0;
        $quizQuestionsCache = [];

        while (true) {
            $sql = 'SELECT a.id, a.quiz_id, a.employee_id, a.status, a.score, a.accuracy, '
                . 'a.completion_time_s, a.started_at, a.submitted_at, a.feedback, '
                . 'e.employee_code, e.name AS employee_name, '
                . 'q.title AS quiz_title, q.feedback_question, '
                . 'r.`rank` '
                . 'FROM attempts a '
                . 'JOIN employees e ON e.id = a.employee_id '
                . 'JOIN quizzes q ON q.id = a.quiz_id '
                . "LEFT JOIN ({$rankSubquery}) r ON r.id = a.id "
                . "WHERE {$whereSql} "
                . 'ORDER BY q.id ASC, '
                . 'CASE WHEN r.`rank` IS NOT NULL THEN 0 ELSE 1 END ASC, '
                . 'r.`rank` ASC, '
                . 'a.started_at ASC, '
                . 'a.id ASC '
                . "LIMIT {$chunkSize} OFFSET {$offset}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($queryParams);
            $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($attempts)) {
                break;
            }

            // Cache questions for all quizzes in this chunk
            $chunkQuizIds = array_unique(array_map('intval', array_column($attempts, 'quiz_id')));
            foreach ($chunkQuizIds as $qid) {
                if (!isset($quizQuestionsCache[$qid])) {
                    $qStmt = $this->db->prepare(
                        'SELECT DISTINCT q.id, q.quiz_id, q.question_text, q.display_order, q.type AS question_type '
                        . 'FROM questions q '
                        . 'WHERE q.quiz_id = :qid '
                        . '   OR (q.question_bank_id IS NOT NULL AND q.question_bank_id = (SELECT question_bank_id FROM quizzes WHERE id = :qid2)) '
                        . '   OR q.id IN (SELECT aa.question_id FROM attempt_answers aa JOIN attempts a ON a.id = aa.attempt_id WHERE a.quiz_id = :qid3) '
                        . 'ORDER BY q.display_order ASC, q.id ASC'
                    );
                    $qStmt->execute(['qid' => $qid, 'qid2' => $qid, 'qid3' => $qid]);
                    $quizQuestionsCache[$qid] = $qStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            // Fetch recorded answers for this batch of attempts
            $attemptIds = array_map('intval', array_column($attempts, 'id'));
            $attemptAnswersMap = [];

            if (!empty($attemptIds)) {
                $inPlaceholders = implode(',', array_fill(0, count($attemptIds), '?'));
                $ansSql = 'SELECT aa.attempt_id, aa.question_id, aa.selected_option_id, aa.text_answer, aa.answered_at, aa.is_correct, '
                    . 'ao.option_text '
                    . 'FROM attempt_answers aa '
                    . 'LEFT JOIN answer_options ao ON ao.id = aa.selected_option_id '
                    . "WHERE aa.attempt_id IN ({$inPlaceholders})";

                $ansStmt = $this->db->prepare($ansSql);
                $ansStmt->execute($attemptIds);
                $ansRows = $ansStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($ansRows as $arow) {
                    $attId = (int) $arow['attempt_id'];
                    $questionId = (int) $arow['question_id'];
                    $attemptAnswersMap[$attId][$questionId] = $arow;
                }
            }

            // Write question-level rows & feedback row for each attempt
            foreach ($attempts as $attempt) {
                $attId = (int) $attempt['id'];
                $quizIdVal = (int) $attempt['quiz_id'];
                $questions = $quizQuestionsCache[$quizIdVal] ?? [];

                // Safeguard: include any questions answered in this attempt that were not in cache
                $attemptQids = array_keys($attemptAnswersMap[$attId] ?? []);
                $cachedQids = array_map('intval', array_column($questions, 'id'));
                $missingQids = array_diff($attemptQids, $cachedQids);
                if (!empty($missingQids)) {
                    $mPlaceholders = implode(',', array_fill(0, count($missingQids), '?'));
                    $mStmt = $this->db->prepare(
                        "SELECT id, quiz_id, question_text, display_order, type AS question_type FROM questions WHERE id IN ({$mPlaceholders}) ORDER BY display_order ASC, id ASC"
                    );
                    $mStmt->execute(array_values($missingQids));
                    $extraQuestions = $mStmt->fetchAll(PDO::FETCH_ASSOC);
                    $questions = array_merge($questions, $extraQuestions);
                }

                $rankVal = $attempt['rank'] !== null ? (string) $attempt['rank'] : '—';
                $empCode = (string) $attempt['employee_code'];
                $empName = (string) $attempt['employee_name'];
                $quizTitle = (string) $attempt['quiz_title'];
                $scoreVal = $attempt['score'] !== null ? (string) $attempt['score'] : '';
                $accuracyVal = $attempt['accuracy'] !== null ? (string) $attempt['accuracy'] : '';
                $timeTakenVal = $attempt['completion_time_s'] !== null ? (string) $attempt['completion_time_s'] : '';
                $startedAtVal = $attempt['started_at']
                    ? TimeHelper::toCompanyTz($attempt['started_at'], 'd M Y, h:i A')
                    : '';
                $endedAtVal = $attempt['submitted_at']
                    ? TimeHelper::toCompanyTz($attempt['submitted_at'], 'd M Y, h:i A')
                    : '';

                // 1. Output question-level response rows
                foreach ($questions as $q) {
                    $qid = (int) $q['id'];
                    $qText = (string) $q['question_text'];

                    $ansRecord = $attemptAnswersMap[$attId][$qid] ?? null;

                    $isWritten = ($q['question_type'] ?? '') === 'written'
                        || (!empty($ansRecord['text_answer']) && empty($ansRecord['selected_option_id']));

                    if ($isWritten) {
                        if ($ansRecord !== null && $ansRecord['text_answer'] !== null && trim((string) $ansRecord['text_answer']) !== '') {
                            $empAnswer = (string) $ansRecord['text_answer'];
                            $result = 'Submitted';
                        } else {
                            $empAnswer = 'Unanswered';
                            $result = ($attempt['status'] === 'ABSENT') ? 'Absent' : 'Unanswered';
                        }
                    } elseif ($ansRecord !== null && $ansRecord['selected_option_id'] !== null) {
                        $empAnswer = !empty($ansRecord['option_text'])
                            ? (string) $ansRecord['option_text']
                            : ('Option #' . $ansRecord['selected_option_id']);
                        $result = ((int) $ansRecord['is_correct'] === 1) ? 'Correct' : 'Wrong';
                    } else {
                        $empAnswer = 'Unanswered';
                        $result = ($attempt['status'] === 'ABSENT') ? 'Absent' : 'Unanswered';
                    }

                    $rowTimestamp = (!empty($ansRecord['answered_at']))
                        ? TimeHelper::toCompanyTz($ansRecord['answered_at'], 'd M Y, h:i A')
                        : $endedAtVal;

                    fputcsv($handle, [
                        self::sanitizeCell($rankVal),
                        self::sanitizeCell($empCode),
                        self::sanitizeCell($empName),
                        self::sanitizeCell($quizTitle),
                        self::sanitizeCell($qText),
                        self::sanitizeCell($empAnswer),
                        self::sanitizeCell($result),
                        self::sanitizeCell($scoreVal),
                        self::sanitizeCell($accuracyVal),
                        self::sanitizeCell($timeTakenVal),
                        self::sanitizeCell($startedAtVal),
                        self::sanitizeCell($rowTimestamp),
                    ]);
                    $totalExported++;
                }

                // 2. Output feedback question row for this attempt
                $feedbackQuestion = !empty($attempt['feedback_question'])
                    ? (string) $attempt['feedback_question']
                    : 'Participant Feedback';
                $feedbackAnswer = !empty($attempt['feedback'])
                    ? (string) $attempt['feedback']
                    : 'No Feedback';

                fputcsv($handle, [
                    self::sanitizeCell($rankVal),
                    self::sanitizeCell($empCode),
                    self::sanitizeCell($empName),
                    self::sanitizeCell($quizTitle),
                    self::sanitizeCell($feedbackQuestion),
                    self::sanitizeCell($feedbackAnswer),
                    self::sanitizeCell('N/A'),
                    self::sanitizeCell($scoreVal),
                    self::sanitizeCell($accuracyVal),
                    self::sanitizeCell($timeTakenVal),
                    self::sanitizeCell($startedAtVal),
                    self::sanitizeCell($endedAtVal),
                ]);
                $totalExported++;
            }

            $offset += count($attempts);
        }

        fclose($handle);
        return $totalExported;
    }

    /**
     * Stream results summary report with Pass / Fail determination and Rank.
     *
     * @param array<string, mixed> $filters
     */
    public function exportResultsSummaryCsv(array $filters, string $outputPath): int
    {
        $handle = fopen($outputPath, 'w');
        if (!$handle) {
            throw new RuntimeException("Cannot open output file: {$outputPath}");
        }

        fputcsv($handle, [
            'Rank',
            'Employee Code',
            'Employee Name',
            'Email',
            'Quiz Title',
            'Status',
            'Score',
            'Accuracy (%)',
            'Completion Time (s)',
            'Result',
        ]);

        $quizId = isset($filters['quiz_id']) && (int) $filters['quiz_id'] > 0 ? (int) $filters['quiz_id'] : null;

        // Fetch quiz pass marks
        $quizPassMarks = [];
        if ($quizId !== null) {
            $stmt = $this->db->prepare('SELECT id, settings FROM quizzes WHERE id = :id');
            $stmt->execute(['id' => $quizId]);
            $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->db->query('SELECT id, settings FROM quizzes');
            $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($quizzes as $qz) {
            $settings = json_decode((string) ($qz['settings'] ?? '{}'), true) ?? [];
            if (isset($settings['scoring']['pass_mark'])) {
                $quizPassMarks[(int) $qz['id']] = (float) $settings['scoring']['pass_mark'];
            }
        }

        $where = ['1=1'];
        $params = [];
        if ($quizId !== null) {
            $where[] = 'a.quiz_id = :quiz_id';
            $params['quiz_id'] = $quizId;
        }

        $whereSql = implode(' AND ', $where);

        $rankWhere = 'WHERE status = "COMPLETED"';
        $rankParams = [];
        if ($quizId !== null) {
            $rankWhere .= ' AND quiz_id = :rank_quiz_id';
            $rankParams['rank_quiz_id'] = $quizId;
        }

        $rankSubquery = "SELECT id, DENSE_RANK() OVER ( "
            . "PARTITION BY quiz_id "
            . "ORDER BY COALESCE(score, 0) DESC, "
            . "COALESCE(completion_time_s, 999999) ASC, "
            . "COALESCE(accuracy, 0) DESC, "
            . "started_at ASC, "
            . "id ASC "
            . ") AS `rank` "
            . "FROM attempts "
            . "{$rankWhere}";

        $queryParams = array_merge($params, $rankParams);

        $chunkSize = 250;
        $offset = 0;
        $totalExported = 0;

        while (true) {
            $sql = 'SELECT a.id, a.quiz_id, a.status, a.score, a.accuracy, a.completion_time_s, '
                . 'e.employee_code, e.name AS employee_name, e.email, '
                . 'q.title AS quiz_title, '
                . 'r.`rank` '
                . 'FROM attempts a '
                . 'JOIN employees e ON e.id = a.employee_id '
                . 'JOIN quizzes q ON q.id = a.quiz_id '
                . "LEFT JOIN ({$rankSubquery}) r ON r.id = a.id "
                . "WHERE {$whereSql} "
                . 'ORDER BY q.id ASC, '
                . 'CASE WHEN r.`rank` IS NOT NULL THEN 0 ELSE 1 END ASC, '
                . 'r.`rank` ASC, '
                . 'a.started_at ASC, '
                . 'a.id ASC '
                . "LIMIT {$chunkSize} OFFSET {$offset}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($queryParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $resultText = 'N/A';
                $rowQuizId = (int) ($row['quiz_id'] ?? 0);
                $passMark = $quizPassMarks[$rowQuizId] ?? null;

                if ($row['status'] === 'COMPLETED') {
                    if ($passMark !== null) {
                        $resultText = ((float) $row['score']) >= $passMark ? 'PASS' : 'FAIL';
                    } else {
                        $resultText = 'COMPLETED';
                    }
                } elseif ($row['status'] === 'ABSENT') {
                    $resultText = 'ABSENT';
                }

                $rankVal = $row['rank'] !== null ? (string) $row['rank'] : '—';

                fputcsv($handle, [
                    self::sanitizeCell($rankVal),
                    self::sanitizeCell($row['employee_code']),
                    self::sanitizeCell($row['employee_name']),
                    self::sanitizeCell($row['email']),
                    self::sanitizeCell($row['quiz_title']),
                    self::sanitizeCell($row['status']),
                    self::sanitizeCell($row['score'] !== null ? (string) $row['score'] : ''),
                    self::sanitizeCell($row['accuracy'] !== null ? (string) $row['accuracy'] : ''),
                    self::sanitizeCell($row['completion_time_s'] !== null ? (string) $row['completion_time_s'] : ''),
                    self::sanitizeCell($resultText),
                ]);
                $totalExported++;
            }

            $offset += count($rows);
        }

        fclose($handle);
        return $totalExported;
    }

    /**
     * Export Question-Wise summary report to CSV showing how many employees
     * answered correctly, incorrectly, or left unanswered.
     *
     * @param array<string, mixed> $filters
     */
    public function exportQuestionSummaryCsv(array $filters, string $outputPath): int
    {
        $handle = fopen($outputPath, 'w');
        if (!$handle) {
            throw new RuntimeException("Cannot open output file: {$outputPath}");
        }

        fputcsv($handle, [
            'Question #',
            'Question Text',
            'Question Type',
            'Total Completed Attempts',
            'Answered Count',
            'Correct Count',
            'Correct (%)',
            'Incorrect Count',
            'Incorrect (%)',
            'Unanswered Count',
            'Unanswered (%)',
        ]);

        $quizId = isset($filters['quiz_id']) && (int) $filters['quiz_id'] > 0 ? (int) $filters['quiz_id'] : null;
        if ($quizId === null) {
            $qStmt = $this->db->query('SELECT id FROM quizzes ORDER BY id DESC LIMIT 1');
            $quizId = (int) $qStmt->fetchColumn();
        }

        if ($quizId <= 0) {
            fclose($handle);
            return 0;
        }

        $dashboardService = new DashboardService($this->db);
        $summary = $dashboardService->getQuestionWiseSummary($quizId, $filters);

        $rowCount = 0;
        foreach ($summary as $row) {
            fputcsv($handle, [
                self::sanitizeCell($row['display_order']),
                self::sanitizeCell($row['question_text']),
                self::sanitizeCell(strtoupper($row['question_type'])),
                self::sanitizeCell($row['total_attempts']),
                self::sanitizeCell($row['answered_count']),
                self::sanitizeCell($row['correct_count']),
                self::sanitizeCell($row['correct_pct'] . '%'),
                self::sanitizeCell($row['incorrect_count']),
                self::sanitizeCell($row['incorrect_pct'] . '%'),
                self::sanitizeCell($row['unanswered_count']),
                self::sanitizeCell($row['unanswered_pct'] . '%'),
            ]);
            $rowCount++;
        }

        fclose($handle);
        return $rowCount;
    }

    /**
     * Apply clean header styling to a worksheet range.
     */
    private function applyHeaderStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E293B'], // Slate-800
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);
    }

    /**
     * Auto-fit column widths across a worksheet.
     */
    private function autoFitColumns(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Export Comprehensive Multi-Sheet XLSX Workbook based on Week 2 layout reference.
     * Generates 6 dedicated sheets:
     *  1. Overall Summary
     *  2. Zone Summary (with Excel SUM/AVERAGE formulas)
     *  3. Question Analysis (with Assigned vs Not Assigned, Accuracy)
     *  4. Score Summary (with 50-scale weighting)
     *  5. Participant Results (Employee IDs stored as text, editable HR sales/CRM columns with formulas)
     *  6. Written Responses (Section B written survey + Section C feedback)
     *
     * @param array<string, mixed> $filters
     */
    public function exportComprehensiveXlsx(array $filters, string $outputPath): int
    {
        $spreadsheet = new Spreadsheet();
        $dashboardService = new DashboardService($this->db);

        $quizId = isset($filters['quiz_id']) && (int) $filters['quiz_id'] > 0 ? (int) $filters['quiz_id'] : null;
        if ($quizId === null) {
            $qStmt = $this->db->query('SELECT id FROM quizzes ORDER BY id DESC LIMIT 1');
            $quizId = (int) $qStmt->fetchColumn();
        }

        if ($quizId <= 0) {
            throw new InvalidArgumentException('No valid quiz selected for comprehensive export');
        }

        $qStmt = $this->db->prepare('SELECT id, code, title, week_number FROM quizzes WHERE id = :id');
        $qStmt->execute(['id' => $quizId]);
        $quiz = $qStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'id' => $quizId,
            'code' => "Q{$quizId}",
            'title' => "Assessment #{$quizId}",
            'week_number' => 1,
        ];

        $overall = $dashboardService->getOverallSummary($quizId, $filters);
        $zoneData = $dashboardService->getZoneSummary($quizId, $filters);
        $questionData = $dashboardService->getQuestionWiseSummary($quizId, $filters);
        $scoreData = $dashboardService->getScoreSummary($quizId, $filters);
        $participants = $dashboardService->getParticipantResults($quizId, $filters, 10000);
        $writtenData = $dashboardService->getWrittenResponses($quizId, $filters);

        // ----------------------------------------------------
        // SHEET 1: Overall Summary
        // ----------------------------------------------------
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Overall Summary');

        $sheet1->setCellValue('A1', 'Metric');
        $sheet1->setCellValue('B1', 'Value');
        $sheet1->setCellValue('C1', 'Percentage / Reference');
        $this->applyHeaderStyle($sheet1, 'A1:C1');

        $weekLabel = 'Week ' . ($quiz['week_number'] ?? 1);
        $s1Rows = [
            ['Assessment Title', "[{$quiz['code']}] {$quiz['title']}", $weekLabel],
            ['Active Eligible OECs', $overall['eligible_count'], 'Enrolled active target employees (test accounts excluded)'],
            ['Employees Who Started', $overall['started_count'], $overall['started_pct'] . '% of eligible'],
            ['Completed Attempts', $overall['completed_count'], $overall['participation_pct'] . '% participation rate'],
            ['In-Progress Attempts', $overall['in_progress_count'], 'Currently in-window / pending finalization'],
            ['Absent Employees', $overall['absent_count'], 'Enrolled but never launched assessment'],
            ['Average Quiz Score', $overall['avg_score'], 'Raw score average across completed attempts'],
            ['Average Accuracy', $overall['avg_accuracy'] . '%', 'Correct answers / total answered'],
            ['Avg Completion Time', $overall['avg_time_s'] . 's', gmdate('i\m s\s', $overall['avg_time_s'])],
            ['Pass Rate', $overall['pass_rate'] . '%', 'Completed attempts scoring >= pass mark'],
        ];

        $r = 2;
        foreach ($s1Rows as $row) {
            $sheet1->setCellValue("A{$r}", $row[0]);
            $sheet1->setCellValue("B{$r}", $row[1]);
            $sheet1->setCellValue("C{$r}", $row[2]);
            $r++;
        }
        $this->autoFitColumns($sheet1);

        // ----------------------------------------------------
        // SHEET 2: Zone Summary
        // ----------------------------------------------------
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Zone Summary');

        $zHeaders = [
            'Zone',
            'Eligible OECs',
            'Not Started',
            'In Progress',
            'Completed',
            'Absent',
            'Participation (%)',
            'Average Score',
            'Average Accuracy (%)',
        ];
        foreach ($zHeaders as $idx => $hdr) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $sheet2->setCellValue("{$colLetter}1", $hdr);
        }
        $this->applyHeaderStyle($sheet2, 'A1:I1');

        $zr = 2;
        foreach ($zoneData['zones'] as $z) {
            $sheet2->setCellValue("A{$zr}", $z['zone_name']);
            $sheet2->setCellValue("B{$zr}", $z['eligible_count']);
            $sheet2->setCellValue("C{$zr}", $z['not_started_count']);
            $sheet2->setCellValue("D{$zr}", $z['in_progress_count']);
            $sheet2->setCellValue("E{$zr}", $z['completed_count']);
            $sheet2->setCellValue("F{$zr}", $z['absent_count']);
            $sheet2->setCellValue("G{$zr}", "=IF(B{$zr}>0, ROUND((E{$zr}/B{$zr})*100, 1), 0)");
            $sheet2->setCellValue("H{$zr}", $z['avg_score']);
            $sheet2->setCellValue("I{$zr}", $z['avg_accuracy']);
            $zr++;
        }

        // Consolidated Total Row with Formulas
        if ($zr > 2) {
            $lastDataRow = $zr - 1;
            $sheet2->setCellValue("A{$zr}", 'Consolidated Total');
            $sheet2->setCellValue("B{$zr}", "=SUM(B2:B{$lastDataRow})");
            $sheet2->setCellValue("C{$zr}", "=SUM(C2:C{$lastDataRow})");
            $sheet2->setCellValue("D{$zr}", "=SUM(D2:D{$lastDataRow})");
            $sheet2->setCellValue("E{$zr}", "=SUM(E2:E{$lastDataRow})");
            $sheet2->setCellValue("F{$zr}", "=SUM(F2:F{$lastDataRow})");
            $sheet2->setCellValue("G{$zr}", "=IF(B{$zr}>0, ROUND((E{$zr}/B{$zr})*100, 1), 0)");
            $sheet2->setCellValue("H{$zr}", "=IF(E{$zr}>0, ROUND(AVERAGE(H2:H{$lastDataRow}), 2), 0)");
            $sheet2->setCellValue("I{$zr}", "=IF(E{$zr}>0, ROUND(AVERAGE(I2:I{$lastDataRow}), 1), 0)");

            $sheet2->getStyle("A{$zr}:I{$zr}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2E8F0'],
                ],
            ]);
        }
        $this->autoFitColumns($sheet2);

        // ----------------------------------------------------
        // SHEET 3: Question Analysis
        // ----------------------------------------------------
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Question Analysis');

        $qHeaders = [
            'Q#',
            'Question Text',
            'Question Type',
            'Correct Answer (MCQ)',
            'Total Completed',
            'Assigned Count',
            'Answered Count',
            'Correct Count',
            'Incorrect Count',
            'Assigned but Unanswered',
            'Not Assigned',
            'MCQ Accuracy (%)',
        ];
        foreach ($qHeaders as $idx => $hdr) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $sheet3->setCellValue("{$colLetter}1", $hdr);
        }
        $this->applyHeaderStyle($sheet3, 'A1:L1');

        $qr = 2;
        foreach ($questionData as $q) {
            $sheet3->setCellValue("A{$qr}", $q['display_order']);
            $sheet3->setCellValue("B{$qr}", $q['question_text']);
            $sheet3->setCellValue("C{$qr}", strtoupper((string) $q['question_type']));
            $sheet3->setCellValue("D{$qr}", $q['correct_answer']);
            $sheet3->setCellValue("E{$qr}", $q['total_attempts']);
            $sheet3->setCellValue("F{$qr}", $q['assigned_count']);
            $sheet3->setCellValue("G{$qr}", $q['answered_count']);
            $sheet3->setCellValue("H{$qr}", $q['correct_count']);
            $sheet3->setCellValue("I{$qr}", $q['incorrect_count']);
            $sheet3->setCellValue("J{$qr}", $q['unanswered_count']);
            $sheet3->setCellValue("K{$qr}", $q['not_assigned_count']);
            $sheet3->setCellValue("L{$qr}", "=IF(G{$qr}>0, ROUND((H{$qr}/G{$qr})*100, 1), 0)");
            $qr++;
        }
        $this->autoFitColumns($sheet3);

        // ----------------------------------------------------
        // SHEET 4: Score Summary
        // ----------------------------------------------------
        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle('Score Summary');

        $sheet4->setCellValue('A1', 'Metric');
        $sheet4->setCellValue('B1', 'Value');
        $sheet4->setCellValue('C1', 'Formula / Description');
        $this->applyHeaderStyle($sheet4, 'A1:C1');

        $s4Rows = [
            ['Total Completed Attempts', $scoreData['total_completed'], 'Total completed attempts graded'],
            ['Max Quiz Marks', $scoreData['max_marks'], 'Total questions × Marks per correct answer'],
            ['Average Raw Score', $scoreData['avg_raw_score'], 'Unweighted score average across candidates'],
            ['Average Weighted Score (out of 50)', $scoreData['avg_weighted_50'], 'Normalized 50-point benchmark'],
            ['Scoring Formula', $scoreData['scoring_formula'], 'HR approved score conversion formula'],
            ['Zero-Scored Attempts', $scoreData['zero_scored_count'], 'Attempts scoring <= 0 marks'],
        ];

        $sr = 2;
        foreach ($s4Rows as $row) {
            $sheet4->setCellValue("A{$sr}", $row[0]);
            $sheet4->setCellValue("B{$sr}", $row[1]);
            $sheet4->setCellValue("C{$sr}", $row[2]);
            $sr++;
        }

        // Bracket Distribution Header
        $sr++;
        $sheet4->setCellValue("A{$sr}", 'Score Bracket');
        $sheet4->setCellValue("B{$sr}", 'Candidate Count');
        $sheet4->setCellValue("C{$sr}", 'Percentage of Completed');
        $this->applyHeaderStyle($sheet4, "A{$sr}:C{$sr}");
        $bHeadRow = $sr;
        $sr++;

        $exportBrackets = [
            '< 20 Marks' => $scoreData['brackets']['< 20 Marks'] ?? ($scoreData['brackets']['lt_20'] ?? 0),
            '20 - 29 Marks' => $scoreData['brackets']['20 - 29 Marks'] ?? ($scoreData['brackets']['b_20_29'] ?? 0),
            '30 - 39 Marks' => $scoreData['brackets']['30 - 39 Marks'] ?? ($scoreData['brackets']['b_30_39'] ?? 0),
            '40 - 50 Marks' => $scoreData['brackets']['40 - 50 Marks'] ?? ($scoreData['brackets']['b_40_50'] ?? 0),
        ];

        foreach ($exportBrackets as $bracket => $cnt) {
            $sheet4->setCellValue("A{$sr}", $bracket);
            $sheet4->setCellValue("B{$sr}", $cnt);
            $sheet4->setCellValue("C{$sr}", "=IF(B2>0, ROUND((B{$sr}/B2)*100, 1), 0)");
            $sr++;
        }
        $this->autoFitColumns($sheet4);

        // ----------------------------------------------------
        // SHEET 5: Participant Results
        // ----------------------------------------------------
        $sheet5 = $spreadsheet->createSheet();
        $sheet5->setTitle('Participant Results');

        $pHeaders = [
            'Rank',
            'Employee ID',
            'Employee Name',
            'Email',
            'Zone / Region',
            'Designation',
            'Department',
            'Status',
            'Raw Score',
            'Score (out of 50)',
            'Accuracy (%)',
            'Duration (s)',
            'Started At',
            'Submitted At',
            'Sales Target',
            'Achieved Sales',
            'Sales Achievement (%)',
            'CRM Score',
        ];
        foreach ($pHeaders as $idx => $hdr) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $sheet5->setCellValue("{$colLetter}1", $hdr);
        }
        $this->applyHeaderStyle($sheet5, 'A1:R1');

        $pr = 2;
        foreach ($participants as $p) {
            $sheet5->setCellValue("A{$pr}", $p['rank'] !== null ? $p['rank'] : '—');
            // CRITICAL: Employee ID stored explicitly as STRING to preserve leading zeros (e.g. 000026)
            $sheet5->setCellValueExplicit("B{$pr}", (string) $p['employee_code'], DataType::TYPE_STRING);
            $sheet5->setCellValue("C{$pr}", $p['employee_name']);
            $sheet5->setCellValue("D{$pr}", $p['email']);
            $sheet5->setCellValue("E{$pr}", $p['zone']);
            $sheet5->setCellValue("F{$pr}", $p['designation']);
            $sheet5->setCellValue("G{$pr}", $p['department']);
            $sheet5->setCellValue("H{$pr}", $p['status']);
            $sheet5->setCellValue("I{$pr}", $p['raw_score'] !== null ? $p['raw_score'] : '');
            $sheet5->setCellValue("J{$pr}", $p['weighted_score_50'] !== null ? $p['weighted_score_50'] : '');
            $sheet5->setCellValue("K{$pr}", $p['accuracy'] !== null ? $p['accuracy'] : '');
            $sheet5->setCellValue("L{$pr}", $p['completion_time_s'] !== null ? $p['completion_time_s'] : '');
            $sheet5->setCellValue("M{$pr}", $p['started_at'] ? TimeHelper::toCompanyTz($p['started_at'], 'd M Y, h:i A') : '');
            $sheet5->setCellValue("N{$pr}", $p['submitted_at'] ? TimeHelper::toCompanyTz($p['submitted_at'], 'd M Y, h:i A') : '');

            // Pre-configured editable columns for HR Sales & CRM data with formula
            $sheet5->setCellValue("O{$pr}", ''); // Sales Target
            $sheet5->setCellValue("P{$pr}", ''); // Achieved Sales
            $sheet5->setCellValue("Q{$pr}", "=IF(AND(ISNUMBER(O{$pr}), O{$pr}>0), ROUND((P{$pr}/O{$pr})*100, 1), \"\")");
            $sheet5->setCellValue("R{$pr}", ''); // CRM Score
            $pr++;
        }
        $this->autoFitColumns($sheet5);

        // ----------------------------------------------------
        // SHEET 6: Written Responses
        // ----------------------------------------------------
        $sheet6 = $spreadsheet->createSheet();
        $sheet6->setTitle('Written Responses');

        $wHeaders = [
            'Employee ID',
            'Employee Name',
            'Zone / Region',
            'Designation',
            'Section',
            'Question #',
            'Question Text',
            'Employee Written Response',
            'Submitted At',
            'HR Evaluation / Marks',
        ];
        foreach ($wHeaders as $idx => $hdr) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $sheet6->setCellValue("{$colLetter}1", $hdr);
        }
        $this->applyHeaderStyle($sheet6, 'A1:J1');

        $wr = 2;
        foreach ($writtenData as $w) {
            // Employee ID stored explicitly as STRING
            $sheet6->setCellValueExplicit("A{$wr}", (string) $w['employee_code'], DataType::TYPE_STRING);
            $sheet6->setCellValue("B{$wr}", $w['employee_name']);
            $sheet6->setCellValue("C{$wr}", $w['zone_region']);
            $sheet6->setCellValue("D{$wr}", $w['designation']);
            $sheet6->setCellValue("E{$wr}", $w['section_label']);
            $sheet6->setCellValue("F{$wr}", $w['display_order']);
            $sheet6->setCellValue("G{$wr}", $w['question_text']);
            $sheet6->setCellValue("H{$wr}", $w['response_text']);
            $sheet6->setCellValue("I{$wr}", $w['submitted_at'] ? TimeHelper::toCompanyTz($w['submitted_at'], 'd M Y, h:i A') : '');
            $sheet6->setCellValue("J{$wr}", ''); // HR input
            $sheet6->getStyle("H{$wr}")->getAlignment()->setWrapText(true);
            $wr++;
        }
        $this->autoFitColumns($sheet6);
        $sheet6->getColumnDimension('H')->setWidth(50);

        // Set active sheet to Overall Summary and save
        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);

        return count($participants);
    }

    /**
     * Export Submissions Report to native XLSX with Employee IDs preserved as text.
     *
     * @param array<string, mixed> $filters
     */
    public function exportSubmissionsXlsx(array $filters, string $outputPath): int
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Submissions');

        $tzName = TimeHelper::getCompanyTimezone()->getName();
        $headers = [
            'Rank',
            'Employee ID',
            'Employee Name',
            'Quiz Name',
            'Quiz Question',
            'Employee Answer',
            'Result',
            'Score',
            'Accuracy (%)',
            'Time Taken (s)',
            "Started At ({$tzName})",
            "Ended At ({$tzName})",
        ];
        foreach ($headers as $idx => $hdr) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $sheet->setCellValue("{$colLetter}1", $hdr);
        }
        $this->applyHeaderStyle($sheet, 'A1:L1');

        $tempCsv = sys_get_temp_dir() . '/temp_sub_' . uniqid() . '.csv';
        $this->exportSubmissionsCsv($filters, $tempCsv);

        $rowCount = 0;
        if (file_exists($tempCsv)) {
            $handle = fopen($tempCsv, 'r');
            if ($handle) {
                fgetcsv($handle); // Skip CSV header
                $r = 2;
                while (($data = fgetcsv($handle)) !== false) {
                    if (empty($data)) {
                        continue;
                    }
                    $sheet->setCellValue("A{$r}", $data[0] ?? '');
                    // Preserve Employee ID as text
                    $empId = isset($data[1]) ? ltrim((string) $data[1], "'") : '';
                    $sheet->setCellValueExplicit("B{$r}", $empId, DataType::TYPE_STRING);
                    $sheet->setCellValue("C{$r}", $data[2] ?? '');
                    $sheet->setCellValue("D{$r}", $data[3] ?? '');
                    $sheet->setCellValue("E{$r}", $data[4] ?? '');
                    $sheet->setCellValue("F{$r}", $data[5] ?? '');
                    $sheet->setCellValue("G{$r}", $data[6] ?? '');
                    $sheet->setCellValue("H{$r}", $data[7] ?? '');
                    $sheet->setCellValue("I{$r}", $data[8] ?? '');
                    $sheet->setCellValue("J{$r}", $data[9] ?? '');
                    $sheet->setCellValue("K{$r}", $data[10] ?? '');
                    $sheet->setCellValue("L{$r}", $data[11] ?? '');
                    $r++;
                    $rowCount++;
                }
                fclose($handle);
            }
            @unlink($tempCsv);
        }

        $this->autoFitColumns($sheet);
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);

        return $rowCount;
    }

    /**
     * Export Results Summary Report to native XLSX with Employee IDs preserved as text.
     *
     * @param array<string, mixed> $filters
     */
    public function exportResultsSummaryXlsx(array $filters, string $outputPath): int
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Results Summary');

        $headers = [
            'Rank',
            'Employee Code',
            'Employee Name',
            'Email',
            'Quiz Title',
            'Status',
            'Score',
            'Accuracy (%)',
            'Completion Time (s)',
            'Result',
        ];
        foreach ($headers as $idx => $hdr) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $sheet->setCellValue("{$colLetter}1", $hdr);
        }
        $this->applyHeaderStyle($sheet, 'A1:J1');

        $tempCsv = sys_get_temp_dir() . '/temp_res_' . uniqid() . '.csv';
        $this->exportResultsSummaryCsv($filters, $tempCsv);

        $rowCount = 0;
        if (file_exists($tempCsv)) {
            $handle = fopen($tempCsv, 'r');
            if ($handle) {
                fgetcsv($handle); // Skip header
                $r = 2;
                while (($data = fgetcsv($handle)) !== false) {
                    if (empty($data)) {
                        continue;
                    }
                    $sheet->setCellValue("A{$r}", $data[0] ?? '');
                    $empCode = isset($data[1]) ? ltrim((string) $data[1], "'") : '';
                    $sheet->setCellValueExplicit("B{$r}", $empCode, DataType::TYPE_STRING);
                    $sheet->setCellValue("C{$r}", $data[2] ?? '');
                    $sheet->setCellValue("D{$r}", $data[3] ?? '');
                    $sheet->setCellValue("E{$r}", $data[4] ?? '');
                    $sheet->setCellValue("F{$r}", $data[5] ?? '');
                    $sheet->setCellValue("G{$r}", $data[6] ?? '');
                    $sheet->setCellValue("H{$r}", $data[7] ?? '');
                    $sheet->setCellValue("I{$r}", $data[8] ?? '');
                    $sheet->setCellValue("J{$r}", $data[9] ?? '');
                    $r++;
                    $rowCount++;
                }
                fclose($handle);
            }
            @unlink($tempCsv);
        }

        $this->autoFitColumns($sheet);
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);

        return $rowCount;
    }

    /**
     * Export Question-Wise Summary to native XLSX.
     *
     * @param array<string, mixed> $filters
     */
    public function exportQuestionSummaryXlsx(array $filters, string $outputPath): int
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Question Summary');

        $headers = [
            'Question #',
            'Question Text',
            'Question Type',
            'Total Completed Attempts',
            'Answered Count',
            'Correct Count',
            'Correct (%)',
            'Incorrect Count',
            'Incorrect (%)',
            'Unanswered Count',
            'Unanswered (%)',
        ];
        foreach ($headers as $idx => $hdr) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $sheet->setCellValue("{$colLetter}1", $hdr);
        }
        $this->applyHeaderStyle($sheet, 'A1:K1');

        $quizId = isset($filters['quiz_id']) && (int) $filters['quiz_id'] > 0 ? (int) $filters['quiz_id'] : null;
        if ($quizId === null) {
            $qStmt = $this->db->query('SELECT id FROM quizzes ORDER BY id DESC LIMIT 1');
            $quizId = (int) $qStmt->fetchColumn();
        }

        if ($quizId <= 0) {
            $writer = new Xlsx($spreadsheet);
            $writer->save($outputPath);
            return 0;
        }

        $dashboardService = new DashboardService($this->db);
        $summary = $dashboardService->getQuestionWiseSummary($quizId, $filters);

        $r = 2;
        foreach ($summary as $row) {
            $sheet->setCellValue("A{$r}", $row['display_order']);
            $sheet->setCellValue("B{$r}", $row['question_text']);
            $sheet->setCellValue("C{$r}", strtoupper((string) $row['question_type']));
            $sheet->setCellValue("D{$r}", $row['total_attempts']);
            $sheet->setCellValue("E{$r}", $row['answered_count']);
            $sheet->setCellValue("F{$r}", $row['correct_count']);
            $sheet->setCellValue("G{$r}", $row['correct_pct'] . '%');
            $sheet->setCellValue("H{$r}", $row['incorrect_count']);
            $sheet->setCellValue("I{$r}", $row['incorrect_pct'] . '%');
            $sheet->setCellValue("J{$r}", $row['unanswered_count']);
            $sheet->setCellValue("K{$r}", $row['unanswered_pct'] . '%');
            $r++;
        }

        $this->autoFitColumns($sheet);
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);

        return count($summary);
    }
}
