<?php

declare(strict_types=1);

namespace App\Services;

use App\Hot\Ulid;
use Core\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class EmployeeImportService
{
    private PDO $db;
    public const MAX_FILE_SIZE = 5242880; // 5 MB
    public const MAX_ROWS = 20000;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Preview CSV file: reads first 10 rows and calculates validation summary.
     *
     * @return array{total_rows: int, valid_count: int, duplicate_count: int, preview: array, errors: array}
     */
    public function preview(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: {$filePath}");
        }

        if (filesize($filePath) > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('File size exceeds the 5 MB limit');
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new RuntimeException("Cannot open file: {$filePath}");
        }

        // Read header
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            throw new InvalidArgumentException('CSV file is empty');
        }

        $colMap = $this->parseHeaders($header);
        $preview = [];
        $totalRows = 0;
        $validCount = 0;
        $duplicateCount = 0;
        $errors = [];

        $seenCodes = [];
        $seenEmails = [];
        $seenUsernames = [];

        // Pre-load existing sets from database for duplicate checks
        $existingCodes = $this->db->query('SELECT employee_code FROM employees')->fetchAll(PDO::FETCH_COLUMN);
        $existingCodesSet = array_fill_keys(array_map('strval', $existingCodes), true);

        $existingEmails = $this->db->query(
            'SELECT email FROM employees WHERE email IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN);
        $existingEmailsSet = array_fill_keys(array_map('strtolower', array_map('strval', $existingEmails)), true);

        while (($row = fgetcsv($handle)) !== false) {
            $totalRows++;
            if ($totalRows > self::MAX_ROWS) {
                $errors[] = 'CSV exceeds maximum allowed limit of 20,000 rows';
                break;
            }

            $code = trim($row[$colMap['employee_code']] ?? '');
            $name = trim($row[$colMap['name']] ?? '');
            $email = isset($colMap['email']) && isset($row[$colMap['email']])
                ? strtolower(trim($row[$colMap['email']]))
                : null;
            $username = isset($colMap['username']) && isset($row[$colMap['username']])
                ? trim($row[$colMap['username']])
                : null;
            $status = isset($colMap['status']) && isset($row[$colMap['status']])
                ? strtolower(trim($row[$colMap['status']]))
                : 'active';

            $isDuplicate = false;
            $reason = '';

            if ($code === '' || $name === '') {
                $isDuplicate = true;
                $reason = 'Missing required employee_code or name';
            } elseif (isset($existingCodesSet[$code]) || isset($seenCodes[$code])) {
                $isDuplicate = true;
                $reason = "Duplicate employee code '{$code}'";
            } elseif ($email && (isset($existingEmailsSet[$email]) || isset($seenEmails[$email]))) {
                $isDuplicate = true;
                $reason = "Duplicate email '{$email}'";
            } elseif ($username && isset($seenUsernames[$username])) {
                $isDuplicate = true;
                $reason = "Duplicate username '{$username}'";
            }

            if ($isDuplicate) {
                $duplicateCount++;
            } else {
                $validCount++;
                $seenCodes[$code] = true;
                if ($email) {
                    $seenEmails[$email] = true;
                }
                if ($username) {
                    $seenUsernames[$username] = true;
                }
            }

            if (count($preview) < 10) {
                $preview[] = [
                    'row' => $totalRows + 1,
                    'code' => $code,
                    'name' => $name,
                    'email' => $email,
                    'username' => $username,
                    'status' => $status,
                    'is_valid' => !$isDuplicate,
                    'reason' => $reason,
                ];
            }
        }

        fclose($handle);

        return [
            'total_rows' => $totalRows,
            'valid_count' => $validCount,
            'duplicate_count' => $duplicateCount,
            'preview' => $preview,
            'errors' => $errors,
        ];
    }

    /**
     * Create an import job record.
     */
    public function createImportJob(string $filePath, int $adminId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $sql = 'INSERT INTO import_jobs '
            . '(type, status, file_path, total, imported, failed, created_by, created_at, updated_at) '
            . 'VALUES ("employee_csv", "pending", :path, 0, 0, 0, :aid, :cat, :uat)';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'path' => $filePath,
            'aid' => $adminId,
            'cat' => $now,
            'uat' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Process an import job.
     *
     * @return array{job_id: int, total: int, imported: int, failed: int, report_path: string|null}
     */
    public function processJob(int $jobId): array
    {
        return $this->processImportJob($jobId);
    }

    /**
     * Process an import job asynchronously.
     *
     * @return array{job_id: int, total: int, imported: int, failed: int, report_path: string|null}
     */
    public function processImportJob(int $jobId, ?callable $progressCallback = null): array
    {
        $stmt = $this->db->prepare('SELECT * FROM import_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            throw new InvalidArgumentException("Import job ID {$jobId} not found");
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->prepare('UPDATE import_jobs SET status = "processing", updated_at = :uat WHERE id = :id')
            ->execute(['uat' => $now, 'id' => $jobId]);

        $filePath = $job['file_path'];
        if (!file_exists($filePath)) {
            $this->db->prepare('UPDATE import_jobs SET status = "failed", updated_at = :uat WHERE id = :id')
                ->execute(['uat' => $now, 'id' => $jobId]);
            throw new RuntimeException("Import file missing: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new RuntimeException("Cannot open import file: {$filePath}");
        }

        $header = fgetcsv($handle);
        $colMap = $this->parseHeaders($header ?: []);

        $reportsDir = dirname(__DIR__, 2) . '/storage/reports';
        if (!is_dir($reportsDir) && !mkdir($reportsDir, 0755, true) && !is_dir($reportsDir)) {
            throw new RuntimeException("Cannot create reports directory: {$reportsDir}");
        }

        $failedReportPath = "{$reportsDir}/import_{$jobId}_failed.csv";
        $failedHandle = fopen($failedReportPath, 'w');
        fputcsv($failedHandle, ['Row Number', 'Employee Code', 'Name', 'Failure Reason']);

        $seenCodes = [];
        $seenEmails = [];
        $existingCodes = $this->db->query('SELECT employee_code FROM employees')->fetchAll(PDO::FETCH_COLUMN);
        $existingCodesSet = array_fill_keys(array_map('strval', $existingCodes), true);

        $existingEmails = $this->db->query(
            'SELECT email FROM employees WHERE email IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN);
        $existingEmailsSet = array_fill_keys(array_map('strtolower', array_map('strval', $existingEmails)), true);

        $total = 0;
        $imported = 0;
        $failed = 0;

        $batchRows = [];
        $batchBindings = [];

        while (($row = fgetcsv($handle)) !== false) {
            $total++;
            $code = trim($row[$colMap['employee_code']] ?? '');
            $name = trim($row[$colMap['name']] ?? '');
            $email = isset($colMap['email']) && isset($row[$colMap['email']])
                ? strtolower(trim($row[$colMap['email']]))
                : null;
            $username = isset($colMap['username']) && isset($row[$colMap['username']])
                ? trim($row[$colMap['username']])
                : null;
            $rawStatus = isset($colMap['status']) && isset($row[$colMap['status']])
                ? strtolower(trim($row[$colMap['status']]))
                : 'active';
            $status = in_array($rawStatus, ['active', 'inactive'], true) ? $rawStatus : 'active';

            $failReason = null;
            if ($code === '' || $name === '') {
                $failReason = 'Missing required employee_code or name';
            } elseif (isset($existingCodesSet[$code]) || isset($seenCodes[$code])) {
                $failReason = "Duplicate employee code '{$code}'";
            } elseif ($email && (isset($existingEmailsSet[$email]) || isset($seenEmails[$email]))) {
                $failReason = "Duplicate email '{$email}'";
            }

            if ($failReason !== null) {
                $failed++;
                fputcsv($failedHandle, [$total + 1, $code, $name, $failReason]);
                continue;
            }

            $seenCodes[$code] = true;
            if ($email) {
                $seenEmails[$email] = true;
            }

            $publicId = Ulid::generate();
            $batchRows[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
            $batchBindings[] = $publicId;
            $batchBindings[] = $code;
            $batchBindings[] = $name;
            $batchBindings[] = $email;
            $batchBindings[] = $username;
            $batchBindings[] = $status;
            $batchBindings[] = $now;
            $batchBindings[] = $now;

            if (count($batchRows) >= 500) {
                $this->executeEmployeeBatch($batchRows, $batchBindings);
                $imported += count($batchRows);
                $batchRows = [];
                $batchBindings = [];
                if ($progressCallback) {
                    $progressCallback($total, $imported, $failed);
                }
            }
        }

        if (!empty($batchRows)) {
            $this->executeEmployeeBatch($batchRows, $batchBindings);
            $imported += count($batchRows);
        }

        fclose($handle);
        fclose($failedHandle);

        $finalReportPath = $failed > 0 ? $failedReportPath : null;
        if ($failed === 0 && file_exists($failedReportPath)) {
            @unlink($failedReportPath);
        }

        // Update job completion
        $upStmt = $this->db->prepare(
            'UPDATE import_jobs SET status = "completed", total = :tot, imported = :imp, ' .
            'failed = :fail, report_path = :rep, updated_at = :uat WHERE id = :id'
        );
        $upStmt->execute([
            'tot' => $total,
            'imp' => $imported,
            'fail' => $failed,
            'rep' => $finalReportPath,
            'uat' => gmdate('Y-m-d H:i:s'),
            'id' => $jobId,
        ]);

        return [
            'job_id' => $jobId,
            'total' => $total,
            'imported' => $imported,
            'failed' => $failed,
            'report_path' => $finalReportPath,
        ];
    }

    /**
     * Insert batch of employees with ON DUPLICATE KEY UPDATE.
     *
     * @param array<int, string> $batchRows
     * @param array<int, mixed> $batchBindings
     */
    private function executeEmployeeBatch(array $batchRows, array $batchBindings): void
    {
        $sql = 'INSERT INTO employees ' .
               '(public_id, employee_code, name, email, username, status, created_at, updated_at) ' .
               'VALUES ' . implode(', ', $batchRows) . ' ' .
               'ON DUPLICATE KEY UPDATE name=VALUES(name)';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($batchBindings);
    }

    /**
     * Map CSV header names to column indexes.
     *
     * @param array<int, string> $header
     * @return array<string, int>
     */
    private function parseHeaders(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $colName) {
            $clean = strtolower(trim((string) $colName));
            if ($clean === 'employee_code' || $clean === 'code' || $clean === 'employee code') {
                $map['employee_code'] = $idx;
            } elseif ($clean === 'name' || $clean === 'employee_name' || $clean === 'full name') {
                $map['name'] = $idx;
            } elseif ($clean === 'email') {
                $map['email'] = $idx;
            } elseif ($clean === 'username') {
                $map['username'] = $idx;
            } elseif ($clean === 'status') {
                $map['status'] = $idx;
            }
        }

        if (!isset($map['employee_code']) || !isset($map['name'])) {
            throw new InvalidArgumentException(
                'Invalid CSV header format. Expected at least "employee_code" and "name" columns.'
            );
        }

        return $map;
    }
}
