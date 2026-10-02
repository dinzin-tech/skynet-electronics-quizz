<?php

declare(strict_types=1);

namespace App\Services;

use App\Hot\Ulid;
use Core\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class QuizPublisher
{
    private PDO $db;
    private QuestionService $questionService;
    private QuizWarmer $quizWarmer;

    public function __construct(
        ?PDO $db = null,
        ?QuestionService $questionService = null,
        ?QuizWarmer $quizWarmer = null
    ) {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->questionService = $questionService ?? new QuestionService($this->db);
        $this->quizWarmer = $quizWarmer ?? new QuizWarmer($this->db);
    }

    /**
     * Publish a quiz:
     * 1. Validate questions & options integrity.
     * 2. Build immutable quiz snapshot and static bundle (JSON + GZ) WITHOUT correctness data.
     * 3. Update quiz status to 'published' and increment current_version.
     * 4. Materialize eligible employee attempts in chunks of 500.
     * 5. Trigger Redis cache warming.
     *
     * @return array{
     *     quiz_id: int,
     *     version: int,
     *     bundle_sha256: string,
     *     bundle_path: string,
     *     roster_count: int,
     *     shareable_url: string
     * }
     */
    public function publish(int $quizId, int $adminId = 0, ?string $storageDir = null): array
    {
        // 1. Fetch quiz
        $qStmt = $this->db->prepare('SELECT * FROM quizzes WHERE id = :id');
        $qStmt->execute(['id' => $quizId]);
        $quiz = $qStmt->fetch(PDO::FETCH_ASSOC);

        if (!$quiz) {
            throw new InvalidArgumentException("Quiz ID {$quizId} not found");
        }

        if ($quiz['status'] === 'archived') {
            throw new RuntimeException('Cannot publish an archived quiz');
        }

        // Check if attempts have already started
        $startedStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM attempts WHERE quiz_id = :qid AND status != 'NOT_STARTED'"
        );
        $startedStmt->execute(['qid' => $quizId]);
        if ((int) $startedStmt->fetchColumn() > 0) {
            throw new RuntimeException('Cannot re-publish a quiz after employee attempts have started');
        }

        // 2. Validate Question/Option Integrity
        $validation = $this->questionService->validateQuizIntegrity($quizId);
        if (!$validation['valid']) {
            throw new InvalidArgumentException(
                'Quiz cannot be published due to validation errors: ' . implode('; ', $validation['errors'])
            );
        }

        $newVersion = ((int) $quiz['current_version']) + 1;
        $settings = json_decode((string) $quiz['settings'], true) ?? [];

        // 3. Fetch Questions and Options
        $questionsStmt = $this->db->prepare(
            'SELECT id, question_text, image_path, display_order FROM questions ' .
            'WHERE quiz_id = :qid ORDER BY display_order ASC, id ASC'
        );
        $questionsStmt->execute(['qid' => $quizId]);
        $questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

        $optionsStmt = $this->db->prepare(
            'SELECT id, option_text, is_correct, display_order FROM answer_options ' .
            'WHERE question_id = :qid ORDER BY display_order ASC, id ASC'
        );

        $bundleQuestions = [];
        $answerKey = [];
        $structure = [];

        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $optionsStmt->execute(['qid' => $qid]);
            $options = $optionsStmt->fetchAll(PDO::FETCH_ASSOC);

            $cleanOptions = [];
            $optIds = [];
            $correctOptId = null;

            foreach ($options as $opt) {
                $oid = (int) $opt['id'];
                $optIds[] = $oid;

                // CRITICAL: NEVER include is_correct in client bundle!
                $cleanOptions[] = [
                    'id' => $oid,
                    'text' => $opt['option_text'],
                    'display_order' => (int) $opt['display_order'],
                ];

                if ((int) $opt['is_correct'] === 1) {
                    $correctOptId = $oid;
                }
            }

            if ($correctOptId === null) {
                throw new RuntimeException("Question ID {$qid} is missing a correct answer option");
            }

            $bundleQuestions[] = [
                'id' => $qid,
                'text' => $q['question_text'],
                'image_url' => $q['image_path'] ?? null,
                'display_order' => (int) $q['display_order'],
                'options' => $cleanOptions,
            ];

            $answerKey[(string) $qid] = $correctOptId;
            $structure[(string) $qid] = $optIds;
        }

        // 4. Build Client Bundle
        $clientBundle = [
            'quiz_id' => (int) $quiz['id'],
            'public_id' => $quiz['public_id'],
            'code' => $quiz['code'],
            'title' => $quiz['title'],
            'description' => $quiz['description'] ?? '',
            'instructions' => $quiz['instructions'] ?? '',
            'duration_seconds' => (int) $quiz['duration_seconds'],
            'start_at' => $quiz['start_at'],
            'end_at' => $quiz['end_at'],
            'version' => $newVersion,
            'total_questions' => count($bundleQuestions),
            'settings' => [
                'navigation' => $settings['navigation'] ?? [],
            ],
            'questions' => $bundleQuestions,
        ];

        $json = json_encode($clientBundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode client bundle to JSON');
        }

        $bundleSha256 = hash('sha256', $json);
        $baseDir = $storageDir ?? (dirname(__DIR__, 2) . '/storage/bundles');
        if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
            throw new RuntimeException("Failed to create bundle directory: {$baseDir}");
        }

        $bundleFileName = "{$quizId}-v{$newVersion}-{$bundleSha256}.json";
        $bundlePath = "{$baseDir}/{$bundleFileName}";
        file_put_contents($bundlePath, $json);

        // Gzip compressed bundle
        $gzContent = gzencode($json, 9);
        if ($gzContent !== false) {
            file_put_contents("{$bundlePath}.gz", $gzContent);
        }

        // 5. Insert Snapshot & Update Quiz
        $this->db->beginTransaction();
        try {
            $snapStmt = $this->db->prepare(
                'INSERT INTO quiz_snapshots (quiz_id, version, question_count, bundle_path, ' .
                'bundle_sha256, answer_key, structure, created_at) ' .
                'VALUES (:qid, :ver, :qc, :bpath, :bsha, :akey, :struct, :cat)'
            );
            $snapStmt->execute([
                'qid' => $quizId,
                'ver' => $newVersion,
                'qc' => count($bundleQuestions),
                'bpath' => $bundlePath,
                'bsha' => $bundleSha256,
                'akey' => json_encode($answerKey, JSON_THROW_ON_ERROR),
                'struct' => json_encode($structure, JSON_THROW_ON_ERROR),
                'cat' => gmdate('Y-m-d H:i:s'),
            ]);

            $now = gmdate('Y-m-d H:i:s');
            $upQuizStmt = $this->db->prepare(
                'UPDATE quizzes SET status = :status, current_version = :ver, ' .
                'published_at = :pat, updated_at = :uat WHERE id = :id'
            );
            $upQuizStmt->execute([
                'status' => 'published',
                'ver' => $newVersion,
                'pat' => $now,
                'uat' => $now,
                'id' => $quizId,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if (file_exists($bundlePath)) {
                @unlink($bundlePath);
            }
            if (file_exists("{$bundlePath}.gz")) {
                @unlink("{$bundlePath}.gz");
            }
            throw $e;
        }

        // 6. Materialize Eligible Employee Roster
        $rosterCount = $this->syncRoster($quizId, $newVersion, count($bundleQuestions), $settings);

        // 7. Warm Redis Cache
        $this->quizWarmer->warm($quizId);

        return [
            'quiz_id' => $quizId,
            'version' => $newVersion,
            'bundle_sha256' => $bundleSha256,
            'bundle_path' => $bundlePath,
            'roster_count' => $rosterCount,
            'shareable_url' => "/quiz/{$quiz['code']}",
        ];
    }

    /**
     * Materialize eligible employees into attempts table in chunks of 500.
     *
     * @param array<string, mixed>|null $settings
     */
    public function syncRoster(int $quizId, int $version, int $totalQuestions, ?array $settings = null): int
    {
        if ($settings === null) {
            $stmt = $this->db->prepare('SELECT settings FROM quizzes WHERE id = :id');
            $stmt->execute(['id' => $quizId]);
            $rawSettings = $stmt->fetchColumn();
            $settings = $rawSettings ? json_decode((string) $rawSettings, true) : [];
        }

        $targetGroups = $settings['target_groups'] ?? [];

        // Determine eligible employees
        if (empty($targetGroups) || in_array('all', $targetGroups, true)) {
            $empStmt = $this->db->query("SELECT id FROM employees WHERE status = 'active' ORDER BY id ASC");
            $eligibleEmpIds = $empStmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $groupIds = array_filter(array_map('intval', $targetGroups), static fn($gid) => $gid > 0);
            if (empty($groupIds)) {
                $empStmt = $this->db->query("SELECT id FROM employees WHERE status = 'active' ORDER BY id ASC");
                $eligibleEmpIds = $empStmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
                $sql = "SELECT DISTINCT e.id FROM employees e " .
                       "INNER JOIN employee_groups eg ON e.id = eg.employee_id " .
                       "WHERE e.status = 'active' AND eg.group_id IN ({$placeholders}) ORDER BY e.id ASC";
                $empStmt = $this->db->prepare($sql);
                $empStmt->execute($groupIds);
                $eligibleEmpIds = $empStmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }

        $totalEligible = count($eligibleEmpIds);
        if ($totalEligible === 0) {
            return 0;
        }

        // Chunk inserts in batches of 500 to avoid locking / memory bloat
        $chunks = array_chunk($eligibleEmpIds, 500);
        foreach ($chunks as $chunk) {
            $rowPlaceholders = [];
            $bindings = [];

            foreach ($chunk as $empId) {
                $publicId = Ulid::generate();
                $rowPlaceholders[] = '(?, ?, ?, ?, 1, ?, ?)';
                $bindings[] = $publicId;
                $bindings[] = $quizId;
                $bindings[] = $version;
                $bindings[] = (int) $empId;
                $bindings[] = 'NOT_STARTED';
                $bindings[] = $totalQuestions;
            }

            $sql = 'INSERT IGNORE INTO attempts ' .
                   '(public_id, quiz_id, quiz_version, employee_id, attempt_no, status, total_questions) ' .
                   'VALUES ' . implode(', ', $rowPlaceholders);

            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);
        }

        return $totalEligible;
    }
}
