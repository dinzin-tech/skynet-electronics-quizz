<?php

declare(strict_types=1);

namespace App\Services;

use App\Hot\Ulid;
use Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class QuizService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * List quizzes with search, status filtering, and pagination.
     *
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $params = [];

        if (!empty($filters['status']) && in_array($filters['status'], ['draft', 'published', 'archived'], true)) {
            $conditions[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $conditions[] = '(title LIKE :search OR code LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $whereClause = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Count total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM quizzes {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch page
        $sql = "SELECT q.id, q.public_id, q.code, q.title, q.description, q.week_number, q.duration_seconds, q.start_at, q.end_at, " .
               "q.status, q.current_version, q.published_at, q.created_at, q.updated_at, q.question_bank_id, q.random_question_count, " .
               "qb.title AS bank_title, " .
               "CASE WHEN q.question_bank_id IS NOT NULL THEN (SELECT COUNT(*) FROM questions qn WHERE qn.question_bank_id = q.question_bank_id) ELSE (SELECT COUNT(*) FROM questions qn2 WHERE qn2.quiz_id = q.id) END AS question_count " .
               "FROM quizzes q " .
               "LEFT JOIN question_banks qb ON qb.id = q.question_bank_id " .
               "{$whereClause} ORDER BY q.id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lastPage = (int) ceil($total / $perPage);
        if ($lastPage < 1) {
            $lastPage = 1;
        }

        return [
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Get quiz by ID with parsed settings and question statistics.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT q.*, qb.title AS bank_title ' .
            'FROM quizzes q ' .
            'LEFT JOIN question_banks qb ON qb.id = q.question_bank_id ' .
            'WHERE q.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['settings'] = json_decode((string) $row['settings'], true) ?? [];

        // Question count (from bank or direct questions)
        if (!empty($row['question_bank_id'])) {
            $qCountStmt = $this->db->prepare('SELECT COUNT(*) FROM questions WHERE question_bank_id = :bid');
            $qCountStmt->execute(['bid' => $row['question_bank_id']]);
            $row['bank_question_count'] = (int) $qCountStmt->fetchColumn();
            $row['question_count'] = !empty($row['random_question_count']) ? (int) $row['random_question_count'] : $row['bank_question_count'];
        } else {
            $qCountStmt = $this->db->prepare('SELECT COUNT(*) FROM questions WHERE quiz_id = :id');
            $qCountStmt->execute(['id' => $id]);
            $row['question_count'] = (int) $qCountStmt->fetchColumn();
            $row['bank_question_count'] = $row['question_count'];
        }

        return $row;
    }

    /**
     * Get quiz by unique code.
     *
     * @return array<string, mixed>|null
     */
    public function getByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM quizzes WHERE code = :code');
        $stmt->execute(['code' => trim($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['settings'] = json_decode((string) $row['settings'], true) ?? [];
        return $row;
    }

    /**
     * Create a new quiz in draft status.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data, int $adminId): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Quiz title is required');
        }

        // Support either duration_seconds or duration_minutes
        $durationSeconds = (int) ($data['duration_seconds'] ?? 0);
        if ($durationSeconds <= 0 && isset($data['duration_minutes'])) {
            $durationSeconds = ((int) $data['duration_minutes']) * 60;
        }

        if ($durationSeconds <= 0) {
            throw new InvalidArgumentException('Duration must be greater than 0 seconds');
        }

        $startAt = $this->validateAndFormatDateTime((string) ($data['start_at'] ?? $data['window_start_at'] ?? ''));
        $endAt = $this->validateAndFormatDateTime((string) ($data['end_at'] ?? $data['window_end_at'] ?? ''));

        if ($endAt <= $startAt) {
            throw new InvalidArgumentException('Quiz end time must be after start time');
        }

        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = $this->generateUniqueCode();
        } else {
            $code = strtoupper($code);
            $this->assertCodeAvailable($code);
        }

        $settings = $this->normalizeSettings($data['settings'] ?? []);
        $publicId = Ulid::generate();
        $now = gmdate('Y-m-d H:i:s');

        $weekNumber = isset($data['week_number']) ? max(1, (int) $data['week_number']) : 1;
        $questionBankId = !empty($data['question_bank_id']) ? (int) $data['question_bank_id'] : null;
        $randomQuestionCount = !empty($data['random_question_count']) ? max(1, (int) $data['random_question_count']) : null;

        $stmt = $this->db->prepare(
            'INSERT INTO quizzes (public_id, code, title, description, instructions, feedback_question, ' .
            'question_bank_id, random_question_count, ' .
            'week_number, duration_seconds, start_at, end_at, status, settings, current_version, ' .
            'created_by, created_at, updated_at) ' .
            'VALUES (:pid, :code, :title, :desc, :inst, :fbq, :qbid, :rand_cnt, :week_num, :dur, :start, :end, :status, ' .
            ':settings, 0, :uid, :cat, :uat)'
        );

        $fbQuestion = isset($data['feedback_question']) ? trim((string) $data['feedback_question']) : null;
        if ($fbQuestion === '') {
            $fbQuestion = null;
        }

        $stmt->execute([
            'pid' => $publicId,
            'code' => $code,
            'title' => $title,
            'desc' => $data['description'] ?? null,
            'inst' => $data['instructions'] ?? null,
            'fbq' => $fbQuestion,
            'qbid' => $questionBankId,
            'rand_cnt' => $randomQuestionCount,
            'week_num' => $weekNumber,
            'dur' => $durationSeconds,
            'start' => $startAt,
            'end' => $endAt,
            'status' => 'draft',
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            'uid' => $adminId,
            'cat' => $now,
            'uat' => $now,
        ]);

        $newId = (int) $this->db->lastInsertId();
        return $this->getById($newId) ?? [];
    }

    /**
     * Update an existing quiz.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data, int $adminId): array
    {
        $existing = $this->getById($id);
        if (!$existing) {
            throw new InvalidArgumentException("Quiz ID {$id} not found");
        }

        if ($existing['status'] === 'archived') {
            throw new RuntimeException('Cannot update an archived quiz');
        }

        // Check if any attempts have started
        $hasStartedAttempts = $this->hasStartedAttempts($id);

        $fields = [];
        $params = ['id' => $id];

        if (array_key_exists('question_bank_id', $data)) {
            if ($hasStartedAttempts) {
                throw new RuntimeException('Cannot change question bank after attempts have started');
            }
            $bankId = !empty($data['question_bank_id']) ? (int) $data['question_bank_id'] : null;
            $fields[] = 'question_bank_id = :qbid';
            $params['qbid'] = $bankId;
        }

        if (array_key_exists('random_question_count', $data)) {
            if ($hasStartedAttempts) {
                throw new RuntimeException('Cannot change question count after attempts have started');
            }
            $rndCount = !empty($data['random_question_count']) ? max(1, (int) $data['random_question_count']) : null;
            $fields[] = 'random_question_count = :rand_cnt';
            $params['rand_cnt'] = $rndCount;
        }

        if (isset($data['title'])) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                throw new InvalidArgumentException('Quiz title cannot be empty');
            }
            $fields[] = 'title = :title';
            $params['title'] = $title;
        }

        if (isset($data['code'])) {
            $code = strtoupper(trim((string) $data['code']));
            if ($code === '') {
                throw new InvalidArgumentException('Quiz code cannot be empty');
            }
            if ($code !== $existing['code']) {
                $codeCheck = $this->db->prepare('SELECT COUNT(*) FROM quizzes WHERE code = :code AND id != :id');
                $codeCheck->execute(['code' => $code, 'id' => $id]);
                if (((int) $codeCheck->fetchColumn()) > 0) {
                    throw new InvalidArgumentException("Quiz code '{$code}' is already taken");
                }
            }
            $fields[] = 'code = :code';
            $params['code'] = $code;
        }

        if (array_key_exists('description', $data)) {
            $fields[] = 'description = :desc';
            $params['desc'] = $data['description'];
        }

        if (array_key_exists('instructions', $data)) {
            $fields[] = 'instructions = :inst';
            $params['inst'] = $data['instructions'];
        }

        if (array_key_exists('feedback_question', $data)) {
            $fbq = trim((string) ($data['feedback_question'] ?? ''));
            $fields[] = 'feedback_question = :fbq';
            $params['fbq'] = $fbq !== '' ? $fbq : null;
        }

        if (isset($data['week_number'])) {
            $fields[] = 'week_number = :week_number';
            $params['week_number'] = max(1, (int) $data['week_number']);
        }

        if (isset($data['duration_seconds'])) {
            if ($hasStartedAttempts) {
                throw new RuntimeException('Cannot change duration after attempts have started');
            }
            $dur = (int) $data['duration_seconds'];
            if ($dur <= 0) {
                throw new InvalidArgumentException('Duration must be greater than 0');
            }
            $fields[] = 'duration_seconds = :dur';
            $params['dur'] = $dur;
        }

        if (isset($data['start_at']) || isset($data['end_at'])) {
            $startAt = isset($data['start_at'])
                ? $this->validateAndFormatDateTime((string) $data['start_at'])
                : $existing['start_at'];
            $endAt = isset($data['end_at'])
                ? $this->validateAndFormatDateTime((string) $data['end_at'])
                : $existing['end_at'];

            if ($endAt <= $startAt) {
                throw new InvalidArgumentException('Quiz end time must be after start time');
            }

            if (isset($data['start_at'])) {
                if ($hasStartedAttempts) {
                    throw new RuntimeException('Cannot change start time after attempts have started');
                }
                $fields[] = 'start_at = :start_at';
                $params['start_at'] = $startAt;
            }

            if (isset($data['end_at'])) {
                $fields[] = 'end_at = :end_at';
                $params['end_at'] = $endAt;
            }
        }

        if (isset($data['settings'])) {
            if ($hasStartedAttempts) {
                throw new RuntimeException('Cannot change quiz settings after attempts have started');
            }
            $mergedSettings = array_replace_recursive($existing['settings'], $data['settings']);
            if (array_key_exists('target_audience', $data['settings'])) {
                $mergedSettings['target_audience'] = $data['settings']['target_audience'];
            }
            if (array_key_exists('target_groups', $data['settings'])) {
                $mergedSettings['target_groups'] = $data['settings']['target_groups'];
            }
            if (array_key_exists('target_departments', $data['settings'])) {
                $mergedSettings['target_departments'] = $data['settings']['target_departments'];
            }
            if (array_key_exists('target_zones', $data['settings'])) {
                $mergedSettings['target_zones'] = $data['settings']['target_zones'];
            }
            if (array_key_exists('target_designations', $data['settings'])) {
                $mergedSettings['target_designations'] = $data['settings']['target_designations'];
            }
            if (array_key_exists('helpline', $data['settings'])) {
                $mergedSettings['helpline'] = $data['settings']['helpline'];
            }
            $normalized = $this->normalizeSettings($mergedSettings);
            $fields[] = 'settings = :settings';
            $params['settings'] = json_encode($normalized, JSON_THROW_ON_ERROR);
        }

        if ($fields === []) {
            return $existing;
        }

        $fields[] = 'updated_at = :uat';
        $params['uat'] = gmdate('Y-m-d H:i:s');

        $setSql = implode(', ', $fields);
        $stmt = $this->db->prepare("UPDATE quizzes SET {$setSql} WHERE id = :id");
        $stmt->execute($params);

        return $this->getById($id) ?? [];
    }

    /**
     * Duplicate a quiz and its questions/options into a new draft.
     *
     * @return array<string, mixed>
     */
    public function duplicate(int $id, int $adminId): array
    {
        $existing = $this->getById($id);
        if (!$existing) {
            throw new InvalidArgumentException("Quiz ID {$id} not found");
        }

        $this->db->beginTransaction();
        try {
            $newCode = $this->generateUniqueCode();
            $newPublicId = Ulid::generate();
            $now = gmdate('Y-m-d H:i:s');
            $newTitle = 'Copy of ' . $existing['title'];

            $insertQuizStmt = $this->db->prepare(
                'INSERT INTO quizzes (public_id, code, title, description, instructions, ' .
                'duration_seconds, start_at, end_at, status, settings, current_version, ' .
                'created_by, created_at, updated_at) ' .
                'VALUES (:pid, :code, :title, :desc, :inst, :dur, :start, :end, :status, ' .
                ':settings, 0, :uid, :cat, :uat)'
            );

            $insertQuizStmt->execute([
                'pid' => $newPublicId,
                'code' => $newCode,
                'title' => $newTitle,
                'desc' => $existing['description'],
                'inst' => $existing['instructions'],
                'dur' => $existing['duration_seconds'],
                'start' => $existing['start_at'],
                'end' => $existing['end_at'],
                'status' => 'draft',
                'settings' => json_encode($existing['settings'], JSON_THROW_ON_ERROR),
                'uid' => $adminId,
                'cat' => $now,
                'uat' => $now,
            ]);

            $newQuizId = (int) $this->db->lastInsertId();

            // Fetch original questions
            $qStmt = $this->db->prepare(
                'SELECT * FROM questions WHERE quiz_id = :qid ORDER BY display_order ASC, id ASC'
            );
            $qStmt->execute(['qid' => $id]);
            $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

            $insQStmt = $this->db->prepare(
                'INSERT INTO questions (quiz_id, question_text, display_order) VALUES (:qid, :text, :ord)'
            );
            $insOptStmt = $this->db->prepare(
                'INSERT INTO answer_options (question_id, option_text, is_correct, display_order) ' .
                'VALUES (:qid, :text, :cor, :ord)'
            );
            $getOptsStmt = $this->db->prepare(
                'SELECT * FROM answer_options WHERE question_id = :qid ORDER BY display_order ASC, id ASC'
            );

            foreach ($questions as $q) {
                $insQStmt->execute([
                    'qid' => $newQuizId,
                    'text' => $q['question_text'],
                    'ord' => $q['display_order'],
                ]);
                $newQuestionId = (int) $this->db->lastInsertId();

                $getOptsStmt->execute(['qid' => $q['id']]);
                $options = $getOptsStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($options as $opt) {
                    $insOptStmt->execute([
                        'qid' => $newQuestionId,
                        'text' => $opt['option_text'],
                        'cor' => $opt['is_correct'],
                        'ord' => $opt['display_order'],
                    ]);
                }
            }

            $this->db->commit();
            return $this->getById($newQuizId) ?? [];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Archive a quiz.
     *
     * @return array<string, mixed>
     */
    public function archive(int $id, int $adminId): array
    {
        $existing = $this->getById($id);
        if (!$existing) {
            throw new InvalidArgumentException("Quiz ID {$id} not found");
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE quizzes SET status = :status, updated_at = :uat WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'archived',
            'uat' => $now,
            'id' => $id,
        ]);

        return $this->getById($id) ?? [];
    }

    /**
     * Delete a quiz. If force=true, allows deleting published/completed quizzes and cascades attempt data.
     */
    public function delete(int $id, bool $force = false): bool
    {
        $existing = $this->getById($id);
        if (!$existing) {
            return false;
        }

        if (!$force && $existing['status'] !== 'draft') {
            throw new RuntimeException('Only draft quizzes can be deleted directly. Use force delete for cleanup.');
        }

        if (!$force) {
            $attemptCountStmt = $this->db->prepare('SELECT COUNT(*) FROM attempts WHERE quiz_id = :id');
            $attemptCountStmt->execute(['id' => $id]);
            if ((int) $attemptCountStmt->fetchColumn() > 0) {
                throw new RuntimeException('Cannot delete quiz with existing attempts without force cleanup');
            }
        }

        $this->db->beginTransaction();
        try {
            // Delete attempt answers
            $this->db->prepare(
                'DELETE aa FROM attempt_answers aa ' .
                'INNER JOIN attempts a ON aa.attempt_id = a.id ' .
                'WHERE a.quiz_id = :id'
            )->execute(['id' => $id]);

            // Delete attempts
            $this->db->prepare('DELETE FROM attempts WHERE quiz_id = :id')->execute(['id' => $id]);

            // Delete quiz snapshots
            $this->db->prepare('DELETE FROM quiz_snapshots WHERE quiz_id = :id')->execute(['id' => $id]);

            // Delete answer options for direct questions
            $this->db->prepare(
                'DELETE ao FROM answer_options ao ' .
                'INNER JOIN questions q ON ao.question_id = q.id ' .
                'WHERE q.quiz_id = :qid'
            )->execute(['qid' => $id]);

            // Delete direct questions
            $this->db->prepare('DELETE FROM questions WHERE quiz_id = :qid')->execute(['qid' => $id]);

            // Delete quiz
            $this->db->prepare('DELETE FROM quizzes WHERE id = :id')->execute(['id' => $id]);

            $this->db->commit();

            // Clean up Redis if available
            try {
                $redis = Database::getInstance()->getRedis();
                if ($redis) {
                    $code = $existing['code'] ?? '';
                    if ($code !== '') {
                        $redis->del("quiz:{$code}");
                    }
                    $redis->del("qstat:{$id}");
                    $redis->del("qa:{$id}");
                }
            } catch (\Throwable $re) {
                // Ignore Redis errors on cleanup
            }

            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Check if any attempt has been started for this quiz.
     */
    public function hasStartedAttempts(int $quizId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM attempts WHERE quiz_id = :qid AND status != 'NOT_STARTED'"
        );
        $stmt->execute(['qid' => $quizId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get attempt status summary for dashboard.
     *
     * @return array{not_started: int, in_progress: int, completed: int, absent: int, total: int}
     */
    public function getAttemptStats(int $quizId): array
    {
        $stmt = $this->db->prepare(
            'SELECT status, COUNT(*) AS cnt FROM attempts WHERE quiz_id = :qid GROUP BY status'
        );
        $stmt->execute(['qid' => $quizId]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $notStarted = (int) ($rows['NOT_STARTED'] ?? 0);
        $inProgress = (int) ($rows['IN_PROGRESS'] ?? 0);
        $completed = (int) ($rows['COMPLETED'] ?? 0);
        $absent = (int) ($rows['ABSENT'] ?? 0);

        return [
            'not_started' => $notStarted,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'absent' => $absent,
            'total' => $notStarted + $inProgress + $completed + $absent,
        ];
    }

    /**
     * Normalize settings with defaults.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function normalizeSettings(array $settings): array
    {
        $scoring = $settings['scoring'] ?? [];
        $navigation = $settings['navigation'] ?? [];
        $targetAudience = (string) ($settings['target_audience'] ?? 'all');
        $targetGroups = $settings['target_groups'] ?? [];
        $targetDepartments = $settings['target_departments'] ?? [];
        $targetZones = $settings['target_zones'] ?? [];
        $targetDesignations = $settings['target_designations'] ?? [];
        $helpline = $settings['helpline'] ?? null;

        $normalized = [
            'scoring' => [
                'marks_per_correct' => (float) ($scoring['marks_per_correct'] ?? 1.0),
                'negative_marks_per_wrong' => (float) ($scoring['negative_marks_per_wrong'] ?? 0.0),
                'unanswered_penalty' => (float) ($scoring['unanswered_penalty'] ?? 0.0),
                'pass_mark' => (float) ($scoring['pass_mark'] ?? 0.0),
                'result_visibility' => (string) ($scoring['result_visibility'] ?? 'score_only'),
            ],
            'navigation' => [
                'allow_back' => (bool) ($navigation['allow_back'] ?? true),
                'allow_skip' => (bool) ($navigation['allow_skip'] ?? true),
                'allow_review_screen' => (bool) ($navigation['allow_review_screen'] ?? true),
                'randomize_questions' => (bool) ($navigation['randomize_questions'] ?? true),
                'randomize_options' => (bool) ($navigation['randomize_options'] ?? true),
            ],
            'target_audience' => in_array($targetAudience, ['all', 'custom', 'groups', 'departments', 'zones', 'designations'], true) ? $targetAudience : 'all',
            'target_groups' => is_array($targetGroups) ? array_values(array_filter(array_map('intval', $targetGroups), fn($g) => $g > 0)) : [],
            'target_departments' => is_array($targetDepartments) ? array_values(array_filter(array_map('trim', $targetDepartments), fn($d) => $d !== '')) : [],
            'target_zones' => is_array($targetZones) ? array_values(array_filter(array_map('trim', $targetZones), fn($z) => $z !== '')) : [],
            'target_designations' => is_array($targetDesignations) ? array_values(array_filter(array_map('trim', $targetDesignations), fn($des) => $des !== '')) : [],
        ];

        if (is_array($helpline)) {
            $normalized['helpline'] = [
                'enabled' => (bool) ($helpline['enabled'] ?? true),
                'phone' => trim((string) ($helpline['phone'] ?? '')),
                'email' => trim((string) ($helpline['email'] ?? '')),
                'text' => trim((string) ($helpline['text'] ?? '')),
                'image_path' => trim((string) ($helpline['image_path'] ?? '')),
            ];
        }

        return $normalized;
    }

    private function generateUniqueCode(): string
    {
        $chars = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'; // base30 without confusing chars 0,1,I,L,O
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }

            $stmt = $this->db->prepare('SELECT COUNT(*) FROM quizzes WHERE code = :code');
            $stmt->execute(['code' => $code]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $code;
            }
        }

        throw new RuntimeException('Failed to generate a unique quiz code');
    }

    private function assertCodeAvailable(string $code): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM quizzes WHERE code = :code');
        $stmt->execute(['code' => $code]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException("Quiz code '{$code}' is already taken");
        }
    }

    private function validateAndFormatDateTime(string $datetime): string
    {
        $datetime = trim($datetime);
        if ($datetime === '') {
            throw new InvalidArgumentException('Date time cannot be empty');
        }

        try {
            $dt = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Invalid date format '{$datetime}'. Expected ISO-8601 or Y-m-d H:i:s");
        }
    }
}
