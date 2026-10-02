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
     *
     * @param array<string, mixed> $filters
     */
    public function exportSubmissionsCsv(array $filters, string $outputPath): int
    {
        $handle = fopen($outputPath, 'w');
        if (!$handle) {
            throw new RuntimeException("Cannot open output file: {$outputPath}");
        }

        // Header row
        fputcsv($handle, [
            'Attempt ID',
            'Employee Code',
            'Employee Name',
            'Email',
            'Quiz Title',
            'Status',
            'Score',
            'Accuracy (%)',
            'Completion Time (s)',
            'Started At (UTC)',
            'Submitted At (UTC)',
        ]);

        $quizId = isset($filters['quiz_id']) ? (int) $filters['quiz_id'] : null;
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
        $chunkSize = 500;
        $lastId = PHP_INT_MAX;
        $totalExported = 0;

        while (true) {
            $chunkWhere = $whereSql . ' AND a.id < :last_id';
            $chunkParams = array_merge($params, ['last_id' => $lastId]);

            $sql = 'SELECT a.id, a.status, a.score, a.accuracy, a.completion_time_s, '
                . 'a.started_at, a.submitted_at, '
                . 'e.employee_code, e.name AS employee_name, e.email, '
                . 'q.title AS quiz_title '
                . 'FROM attempts a '
                . 'JOIN employees e ON e.id = a.employee_id '
                . 'JOIN quizzes q ON q.id = a.quiz_id '
                . "WHERE {$chunkWhere} "
                . 'ORDER BY a.id DESC '
                . "LIMIT {$chunkSize}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($chunkParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                fputcsv($handle, [
                    self::sanitizeCell($row['id']),
                    self::sanitizeCell($row['employee_code']),
                    self::sanitizeCell($row['employee_name']),
                    self::sanitizeCell($row['email']),
                    self::sanitizeCell($row['quiz_title']),
                    self::sanitizeCell($row['status']),
                    self::sanitizeCell($row['score'] !== null ? (string) $row['score'] : ''),
                    self::sanitizeCell($row['accuracy'] !== null ? (string) $row['accuracy'] : ''),
                    self::sanitizeCell($row['completion_time_s'] !== null ? (string) $row['completion_time_s'] : ''),
                    self::sanitizeCell($row['started_at']),
                    self::sanitizeCell($row['submitted_at']),
                ]);
                $totalExported++;
                $lastId = (int) $row['id'];
            }
        }

        fclose($handle);
        return $totalExported;
    }

    /**
     * Stream results summary report with Pass / Fail determination.
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

        $quizId = isset($filters['quiz_id']) ? (int) $filters['quiz_id'] : null;

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
        $chunkSize = 500;
        $lastId = PHP_INT_MAX;
        $totalExported = 0;

        while (true) {
            $chunkWhere = $whereSql . ' AND a.id < :last_id';
            $chunkParams = array_merge($params, ['last_id' => $lastId]);

            $sql = 'SELECT a.id, a.status, a.score, a.accuracy, a.completion_time_s, '
                . 'e.employee_code, e.name AS employee_name, e.email, '
                . 'q.title AS quiz_title '
                . 'FROM attempts a '
                . 'JOIN employees e ON e.id = a.employee_id '
                . 'JOIN quizzes q ON q.id = a.quiz_id '
                . "WHERE {$chunkWhere} "
                . 'ORDER BY a.id DESC '
                . "LIMIT {$chunkSize}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($chunkParams);
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

                fputcsv($handle, [
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
                $lastId = (int) $row['id'];
            }
        }

        fclose($handle);
        return $totalExported;
    }
}
