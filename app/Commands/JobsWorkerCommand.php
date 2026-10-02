<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\EmployeeImportService;
use App\Services\ReportExportService;
use Core\Database;
use PDO;

class JobsWorkerCommand
{
    private PDO $db;
    private EmployeeImportService $importService;
    private ReportExportService $exportService;

    public function __construct(
        ?PDO $db = null,
        ?EmployeeImportService $importService = null,
        ?ReportExportService $exportService = null
    ) {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->importService = $importService ?? new EmployeeImportService($this->db);
        $this->exportService = $exportService ?? new ReportExportService($this->db);
    }

    public function execute(array $args = []): void
    {
        $once = in_array('--once', $args, true);
        echo "Starting jobs worker daemon" . ($once ? ' (single pass)' : '') . "...\n";

        do {
            $processedCount = 0;

            // 1. Process pending import jobs
            $stmt = $this->db->query(
                'SELECT id FROM import_jobs WHERE status = "pending" ORDER BY id ASC LIMIT 5'
            );
            $importJobs = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($importJobs as $jobId) {
                $jobId = (int) $jobId;
                echo "Processing import job {$jobId}...\n";
                try {
                    $res = $this->importService->processJob($jobId);
                    echo "Import job {$jobId} finished: total={$res['total']}, "
                        . "imported={$res['imported']}, failed={$res['failed']}\n";
                } catch (\Throwable $e) {
                    echo "Import job {$jobId} failed: " . $e->getMessage() . "\n";
                }
                $processedCount++;
            }

            // 2. Process pending export jobs
            $stmt = $this->db->query(
                'SELECT id FROM export_jobs WHERE status = "pending" ORDER BY id ASC LIMIT 5'
            );
            $exportJobs = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($exportJobs as $jobId) {
                $jobId = (int) $jobId;
                echo "Processing export job {$jobId}...\n";
                try {
                    $res = $this->exportService->processExportJob($jobId);
                    echo "Export job {$jobId} finished: rows={$res['row_count']}, file={$res['file_path']}\n";
                } catch (\Throwable $e) {
                    echo "Export job {$jobId} failed: " . $e->getMessage() . "\n";
                }
                $processedCount++;
            }

            if ($once) {
                break;
            }

            if ($processedCount === 0) {
                usleep(500000); // 500ms
            }
        } while (true);

        echo "Jobs worker pass complete.\n";
    }
}
