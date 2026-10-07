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
     * List of submissions with optional keyset or rank-based pagination.
     *
     * @param array{
     *     quiz_id?: int|null,
     *     status?: string|null,
     *     search?: string|null,
     *     cursor?: int|null,
     *     page?: int|null,
     *     limit?: int,
     *     sort_by_rank?: bool
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
        $page = isset($filters['page']) && (int) $filters['page'] > 0 ? (int) $filters['page'] : 1;
        $quizId = isset($filters['quiz_id']) && (int) $filters['quiz_id'] > 0 ? (int) $filters['quiz_id'] : null;
        $status = !empty($filters['status']) ? strtoupper(trim((string) $filters['status'])) : null;
        $search = !empty($filters['search']) ? trim((string) $filters['search']) : null;
        $sortByRank = !empty($filters['sort_by_rank']) || (($filters['sort'] ?? '') === 'rank');

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

        // Keyset pagination condition (when not sorting by rank)
        if (!$sortByRank && $cursor !== null) {
            $where[] = 'a.id < :cursor';
            $params['cursor'] = $cursor;
        }

        $whereSql = implode(' AND ', $where);

        // Fetch limit + 1 to determine if there are more items
        $fetchLimit = $limit + 1;

        $rankSubqueryWhere = 'WHERE status = "COMPLETED"';
        $rankSubqueryParams = [];
        if ($quizId !== null) {
            $rankSubqueryWhere .= ' AND quiz_id = :rank_quiz_id';
            $rankSubqueryParams['rank_quiz_id'] = $quizId;
        }

        $sql = "SELECT a.id, a.quiz_id, a.employee_id, a.status, a.score, a.accuracy, "
            . "a.completion_time_s, a.started_at, a.submitted_at, a.graded_at, "
            . "e.employee_code, e.name AS employee_name, e.email AS employee_email, "
            . "q.title AS quiz_title, q.code AS quiz_code, "
            . "r.`rank` "
            . "FROM attempts a "
            . "JOIN employees e ON e.id = a.employee_id "
            . "JOIN quizzes q ON q.id = a.quiz_id "
            . "LEFT JOIN ( "
            . "    SELECT id, DENSE_RANK() OVER ( "
            . "        PARTITION BY quiz_id "
            . "        ORDER BY COALESCE(score, 0) DESC, "
            . "                 COALESCE(completion_time_s, 999999) ASC, "
            . "                 COALESCE(accuracy, 0) DESC "
            . "    ) AS `rank` "
            . "    FROM attempts "
            . "    {$rankSubqueryWhere} "
            . ") r ON r.id = a.id "
            . "WHERE {$whereSql} ";

        if ($sortByRank) {
            $offset = ($page - 1) * $limit;
            $sql .= "ORDER BY "
                . "CASE WHEN r.`rank` IS NOT NULL THEN 0 ELSE 1 END ASC, "
                . "r.`rank` ASC, "
                . "a.id DESC "
                . "LIMIT {$fetchLimit} OFFSET {$offset}";
        } else {
            $sql .= "ORDER BY a.id DESC LIMIT {$fetchLimit}";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge($params, $rankSubqueryParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        // Format rank as integer or null
        foreach ($rows as &$row) {
            $row['rank'] = $row['rank'] !== null ? (int) $row['rank'] : null;
        }
        unset($row);

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
