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
        $sql = "SELECT id, public_id, code, title, description, duration_seconds, start_at, end_at, " .
               "status, current_version, published_at, created_at, updated_at " .
               "FROM quizzes {$whereClause} ORDER BY id DESC LIMIT :limit OFFSET :offset";
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
        $stmt = $this->db->prepare('SELECT * FROM quizzes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['settings'] = json_decode((string) $row['settings'], true) ?? [];

        // Question count
        $qCountStmt = $this->db->prepare('SELECT COUNT(*) FROM questions WHERE quiz_id = :id');
        $qCountStmt->execute(['id' => $id]);
        $row['question_count'] = (int) $qCountStmt->fetchColumn();

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

        $durationSeconds = (int) ($data['duration_seconds'] ?? 0);
        if ($durationSeconds <= 0) {
            throw new InvalidArgumentException('Duration must be greater than 0 seconds');
        }

        $startAt = $this->validateAndFormatDateTime((string) ($data['start_at'] ?? ''));
        $endAt = $this->validateAndFormatDateTime((string) ($data['end_at'] ?? ''));

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

        $stmt = $this->db->prepare(
            'INSERT INTO quizzes (public_id, code, title, description, instructions, ' .
            'duration_seconds, start_at, end_at, status, settings, current_version, ' .
            'created_by, created_at, updated_at) ' .
            'VALUES (:pid, :code, :title, :desc, :inst, :dur, :start, :end, :status, ' .
            ':settings, 0, :uid, :cat, :uat)'
        );

        $stmt->execute([
            'pid' => $publicId,
            'code' => $code,
            'title' => $title,
            'desc' => $data['description'] ?? null,
            'inst' => $data['instructions'] ?? null,
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

        if (isset($data['title'])) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                throw new InvalidArgumentException('Quiz title cannot be empty');
            }
            $fields[] = 'title = :title';
            $params['title'] = $title;
        }

        if (array_key_exists('description', $data)) {
            $fields[] = 'description = :desc';
            $params['desc'] = $data['description'];
        }

        if (array_key_exists('instructions', $data)) {
            $fields[] = 'instructions = :inst';
            $params['inst'] = $data['instructions'];
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
     * Delete a draft quiz with 0 attempts.
     */
    public function delete(int $id): bool
    {
        $existing = $this->getById($id);
        if (!$existing) {
            return false;
        }

        if ($existing['status'] !== 'draft') {
            throw new RuntimeException('Only draft quizzes can be deleted. Use archive instead.');
        }

        $attemptCountStmt = $this->db->prepare('SELECT COUNT(*) FROM attempts WHERE quiz_id = :id');
        $attemptCountStmt->execute(['id' => $id]);
        if ((int) $attemptCountStmt->fetchColumn() > 0) {
            throw new RuntimeException('Cannot delete quiz with existing attempts');
        }

        $this->db->beginTransaction();
        try {
            // Delete answer options
            $this->db->prepare(
                'DELETE ao FROM answer_options ao ' .
                'INNER JOIN questions q ON ao.question_id = q.id ' .
                'WHERE q.quiz_id = :qid'
            )->execute(['qid' => $id]);

            // Delete questions
            $this->db->prepare('DELETE FROM questions WHERE quiz_id = :qid')->execute(['qid' => $id]);

            // Delete quiz
            $this->db->prepare('DELETE FROM quizzes WHERE id = :id')->execute(['id' => $id]);

            $this->db->commit();
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
        $targetGroups = $settings['target_groups'] ?? [];

        return [
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
            'target_groups' => is_array($targetGroups) ? array_values($targetGroups) : [],
        ];
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
