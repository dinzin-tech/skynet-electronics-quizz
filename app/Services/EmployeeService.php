<?php

declare(strict_types=1);

namespace App\Services;

use App\Hot\Ulid;
use Core\Database;
use InvalidArgumentException;
use PDO;

class EmployeeService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Search and paginate employees with group info.
     *
     * @return array{data: array, total: int, page: int, per_page: int, total_pages: int}
     */
    public function list(?string $search = '', ?string $status = null, int $page = 1, int $perPage = 20): array
    {
        $search = trim((string) ($search ?? ''));
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(name LIKE :s1 OR employee_code LIKE :s2 OR email LIKE :s3 OR username LIKE :s4)';
            $searchWild = "%{$search}%";
            $params['s1'] = $searchWild;
            $params['s2'] = $searchWild;
            $params['s3'] = $searchWild;
            $params['s4'] = $searchWild;
        }

        if ($status !== null && in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM employees WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $query = "SELECT id, public_id, employee_code, name, email, username, status, created_at, updated_at " .
                 "FROM employees " .
                 "WHERE {$whereClause} " .
                 "ORDER BY id DESC " .
                 "LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($query);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch groups for each employee
        if (!empty($employees)) {
            $empIds = array_column($employees, 'id');
            $placeholders = implode(',', array_fill(0, count($empIds), '?'));
            $groupQuery = "SELECT eg.employee_id, g.id as group_id, g.name as group_name " .
                          "FROM employee_groups eg " .
                          "JOIN `groups` g ON eg.group_id = g.id " .
                          "WHERE eg.employee_id IN ({$placeholders})";
            $gStmt = $this->db->prepare($groupQuery);
            $gStmt->execute($empIds);
            $groupsByEmp = [];
            while ($row = $gStmt->fetch(PDO::FETCH_ASSOC)) {
                $groupsByEmp[$row['employee_id']][] = [
                    'id' => (int) $row['group_id'],
                    'name' => $row['group_name'],
                ];
            }

            foreach ($employees as &$emp) {
                $emp['groups'] = $groupsByEmp[$emp['id']] ?? [];
            }
        }

        return [
            'data' => $employees,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Create a new employee with validation.
     */
    public function create(array $data, array $groupIds = []): array
    {
        $this->validateData($data);

        $code = trim($data['employee_code']);
        $name = trim($data['name']);
        $email = !empty($data['email']) ? trim(strtolower($data['email'])) : null;
        $username = !empty($data['username']) ? trim($data['username']) : null;
        $status = $data['status'] ?? 'active';

        $this->assertUnique($code, $email, $username);

        $password = $data['password'] ?? 'QuizPass2026!';
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $publicId = Ulid::generate();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO employees ' .
                '(public_id, employee_code, name, email, username, password_hash, status, created_at, updated_at) ' .
                'VALUES (:pub, :code, :name, :email, :username, :hash, :status, NOW(), NOW())'
            );
            $stmt->execute([
                'pub' => $publicId,
                'code' => $code,
                'name' => $name,
                'email' => $email,
                'username' => $username,
                'hash' => $passwordHash,
                'status' => $status,
            ]);

            $id = (int) $this->db->lastInsertId();

            if (!empty($groupIds)) {
                $this->assignGroups($id, $groupIds);
            }

            $this->db->commit();
            return $this->getById($id) ?? [];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update an employee.
     */
    public function update(int $id, array $data, ?array $groupIds = null): array
    {
        $existing = $this->getById($id);
        if (!$existing) {
            throw new InvalidArgumentException("Employee with ID {$id} not found");
        }

        $code = trim($data['employee_code'] ?? $existing['employee_code']);
        $name = trim($data['name'] ?? $existing['name']);
        $email = array_key_exists('email', $data)
            ? (!empty($data['email']) ? trim(strtolower($data['email'])) : null)
            : $existing['email'];
        $username = array_key_exists('username', $data)
            ? (!empty($data['username']) ? trim($data['username']) : null)
            : $existing['username'];
        $status = $data['status'] ?? $existing['status'];

        $this->assertUnique($code, $email, $username, $id);

        $this->db->beginTransaction();
        try {
            $query = 'UPDATE employees SET employee_code = :code, name = :name, email = :email, ' .
                     'username = :username, status = :status, updated_at = NOW() ';

            $params = [
                'code' => $code,
                'name' => $name,
                'email' => $email,
                'username' => $username,
                'status' => $status,
                'id' => $id,
            ];

            if (!empty($data['password'])) {
                $query .= ', password_hash = :hash ';
                $params['hash'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 10]);
            }

            $query .= 'WHERE id = :id';
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);

            if ($groupIds !== null) {
                $this->db->prepare('DELETE FROM employee_groups WHERE employee_id = :id')->execute(['id' => $id]);
                $this->assignGroups($id, $groupIds);
            }

            $this->db->commit();
            return $this->getById($id) ?? [];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete employee:
     * - If attempts exist: soft-delete to 'inactive' to protect quiz audit trail.
     * - If no attempts exist: hard-delete.
     *
     * @return bool True if deleted or soft-deleted
     */
    public function delete(int $id): bool
    {
        $existing = $this->getById($id);
        if (!$existing) {
            return false;
        }

        // Check for existing attempts
        $checkStmt = $this->db->prepare('SELECT COUNT(*) FROM attempts WHERE employee_id = :id');
        $checkStmt->execute(['id' => $id]);
        $hasAttempts = ((int) $checkStmt->fetchColumn()) > 0;

        if ($hasAttempts) {
            // Soft-delete to preserve audit integrity
            $stmt = $this->db->prepare('UPDATE employees SET status = "inactive", updated_at = NOW() WHERE id = :id');
            return $stmt->execute(['id' => $id]);
        }

        // Hard-delete when no attempts exist
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM employee_groups WHERE employee_id = :id')->execute(['id' => $id]);
            $this->db->prepare('DELETE FROM employees WHERE id = :id')->execute(['id' => $id]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, public_id, employee_code, name, email, username, status, created_at, updated_at ' .
            'FROM employees WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) {
            return null;
        }

        $gStmt = $this->db->prepare(
            'SELECT g.id, g.name FROM employee_groups eg ' .
            'JOIN `groups` g ON eg.group_id = g.id WHERE eg.employee_id = :id'
        );
        $gStmt->execute(['id' => $id]);
        $emp['groups'] = $gStmt->fetchAll(PDO::FETCH_ASSOC);

        return $emp;
    }

    public function getByPublicId(string $publicId): ?array
    {
        $stmt = $this->db->prepare('SELECT id FROM employees WHERE public_id = :pub');
        $stmt->execute(['pub' => $publicId]);
        $id = $stmt->fetchColumn();
        return $id ? $this->getById((int) $id) : null;
    }

    private function assignGroups(int $employeeId, array $groupIds): void
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO employee_groups (employee_id, group_id) VALUES (?, ?)');
        foreach ($groupIds as $gid) {
            $stmt->execute([$employeeId, (int) $gid]);
        }
    }

    private function validateData(array $data): void
    {
        if (empty($data['employee_code'])) {
            throw new InvalidArgumentException('Employee code is required');
        }
        if (empty($data['name'])) {
            throw new InvalidArgumentException('Employee name is required');
        }
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format');
        }
    }

    private function assertUnique(string $code, ?string $email, ?string $username, ?int $excludeId = null): void
    {
        $params = ['code' => $code];
        $idClause = $excludeId !== null ? 'AND id != :exId' : '';
        if ($excludeId !== null) {
            $params['exId'] = $excludeId;
        }

        // Check employee_code
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE employee_code = :code {$idClause}");
        $stmt->execute($params);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException("Employee code '{$code}' already exists");
        }

        // Check email
        if ($email !== null) {
            $stmt = $this->db->prepare("SELECT id FROM employees WHERE email = :email {$idClause}");
            $stmt->execute(array_merge(['email' => $email], $excludeId ? ['exId' => $excludeId] : []));
            if ($stmt->fetch()) {
                throw new InvalidArgumentException("Email '{$email}' already exists");
            }
        }

        // Check username
        if ($username !== null) {
            $stmt = $this->db->prepare("SELECT id FROM employees WHERE username = :username {$idClause}");
            $stmt->execute(array_merge(['username' => $username], $excludeId ? ['exId' => $excludeId] : []));
            if ($stmt->fetch()) {
                throw new InvalidArgumentException("Username '{$username}' already exists");
            }
        }
    }
}
