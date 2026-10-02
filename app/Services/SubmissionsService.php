<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use PDO;

class SubmissionsService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Keyset-paginated list of submissions.
     * Uses `WHERE id < :cursor ORDER BY id DESC LIMIT :limit` to prevent heavy table scans.
     *
     * @param array{
     *     quiz_id?: int|null,
     *     status?: string|null,
     *     search?: string|null,
     *     cursor?: int|null,
     *     limit?: int
     * } $filters
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     next_cursor: int|null,
     *     has_more: bool,
     *     total_count: int
     * }
     */
    public function getSubmissions(array $filters = []): array
    {
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 25)));
        $cursor = isset($filters['cursor']) && (int) $filters['cursor'] > 0 ? (int) $filters['cursor'] : null;
        $quizId = isset($filters['quiz_id']) && (int) $filters['quiz_id'] > 0 ? (int) $filters['quiz_id'] : null;
        $status = !empty($filters['status']) ? strtoupper(trim((string) $filters['status'])) : null;
        $search = !empty($filters['search']) ? trim((string) $filters['search']) : null;

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

        if ($search !== null) {
            $where[] = '(e.name LIKE :search OR e.employee_code LIKE :search_code)';
            $params['search'] = "%{$search}%";
            $params['search_code'] = "%{$search}%";
        }

        // Keyset pagination condition
        if ($cursor !== null) {
            $where[] = 'a.id < :cursor';
            $params['cursor'] = $cursor;
        }

        $whereSql = implode(' AND ', $where);

        // Fetch limit + 1 to determine if there are more items
        $fetchLimit = $limit + 1;
        $sql = "SELECT a.id, a.quiz_id, a.employee_id, a.status, a.score, a.accuracy, "
            . "a.completion_time_s, a.started_at, a.submitted_at, a.graded_at, "
            . "e.employee_code, e.name AS employee_name, e.email AS employee_email, "
            . "q.title AS quiz_title, q.code AS quiz_code "
            . "FROM attempts a "
            . "JOIN employees e ON e.id = a.employee_id "
            . "JOIN quizzes q ON q.id = a.quiz_id "
            . "WHERE {$whereSql} "
            . "ORDER BY a.id DESC "
            . "LIMIT {$fetchLimit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $nextCursor = null;
        if (!empty($rows)) {
            $lastRow = end($rows);
            $nextCursor = (int) $lastRow['id'];
        }

        // For summary counter (without cursor condition)
        $countWhere = array_filter($where, fn($c) => !str_starts_with($c, 'a.id <'));
        $countWhereSql = implode(' AND ', $countWhere);
        $countParams = array_filter($params, fn($k) => $k !== 'cursor', ARRAY_FILTER_USE_KEY);

        $countSql = "SELECT COUNT(*) FROM attempts a "
            . "JOIN employees e ON e.id = a.employee_id "
            . "WHERE {$countWhereSql}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $totalCount = (int) $countStmt->fetchColumn();

        return [
            'items' => $rows,
            'next_cursor' => $hasMore ? $nextCursor : null,
            'has_more' => $hasMore,
            'total_count' => $totalCount,
        ];
    }

    /**
     * Get a single submission with answers breakdown.
     *
     * @return array<string, mixed>|null
     */
    public function getSubmissionDetails(int $attemptId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, e.employee_code, e.name AS employee_name, e.email AS employee_email, '
            . 'q.title AS quiz_title, q.code AS quiz_code, q.settings AS quiz_settings '
            . 'FROM attempts a '
            . 'JOIN employees e ON e.id = a.employee_id '
            . 'JOIN quizzes q ON q.id = a.quiz_id '
            . 'WHERE a.id = :id'
        );
        $stmt->execute(['id' => $attemptId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$attempt) {
            return null;
        }

        // Fetch recorded answers
        $ansStmt = $this->db->prepare(
            'SELECT aa.question_id, aa.selected_option_id, aa.is_correct, aa.seq, aa.answered_at '
            . 'FROM attempt_answers aa '
            . 'WHERE aa.attempt_id = :id '
            . 'ORDER BY aa.question_id ASC'
        );
        $ansStmt->execute(['id' => $attemptId]);
        $attempt['answers'] = $ansStmt->fetchAll(PDO::FETCH_ASSOC);

        return $attempt;
    }
}
