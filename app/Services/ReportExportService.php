<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use InvalidArgumentException;
use PDO;
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
        $filters = json_decode((string) ($job['filters'] ?? '{}'), true) ?? [];
        $fileName = "export_{$jobId}_{$reportType}_" . gmdate('Ymd_His') . '.csv';
        $outputPath = "{$this->storageDir}/{$fileName}";

        try {
            $rowCount = match ($reportType) {
                'submissions' => $this->exportSubmissionsCsv($filters, $outputPath),
                'results' => $this->exportResultsSummaryCsv($filters, $outputPath),
                default => throw new InvalidArgumentException("Unsupported report type: {$reportType}"),
            };

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
                        'SELECT id, quiz_id, question_text, display_order FROM questions ' .
                        'WHERE quiz_id = :qid ORDER BY display_order ASC, id ASC'
                    );
                    $qStmt->execute(['qid' => $qid]);
                    $quizQuestionsCache[$qid] = $qStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            // Fetch recorded answers for this batch of attempts
            $attemptIds = array_map('intval', array_column($attempts, 'id'));
            $attemptAnswersMap = [];

            if (!empty($attemptIds)) {
                $inPlaceholders = implode(',', array_fill(0, count($attemptIds), '?'));
                $ansSql = 'SELECT aa.attempt_id, aa.question_id, aa.selected_option_id, aa.is_correct, '
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

                    if ($ansRecord !== null && $ansRecord['selected_option_id'] !== null) {
                        $empAnswer = !empty($ansRecord['option_text'])
                            ? (string) $ansRecord['option_text']
                            : ('Option #' . $ansRecord['selected_option_id']);
                        $result = ((int) $ansRecord['is_correct'] === 1) ? 'Correct' : 'Wrong';
                    } else {
                        $empAnswer = 'Unanswered';
                        $result = ($attempt['status'] === 'ABSENT') ? 'Absent' : 'Unanswered';
                    }

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
                        self::sanitizeCell($endedAtVal),
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

        // Fetch quiz pass mark
        $passMark = null;
        if ($quizId !== null) {
            $stmt = $this->db->prepare('SELECT settings FROM quizzes WHERE id = :id');
            $stmt->execute(['id' => $quizId]);
            $settingsJson = (string) $stmt->fetchColumn();
            $settings = json_decode($settingsJson, true) ?? [];
            if (isset($settings['scoring']['pass_mark'])) {
                $passMark = (float) $settings['scoring']['pass_mark'];
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
            $sql = 'SELECT a.id, a.status, a.score, a.accuracy, a.completion_time_s, '
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
}
