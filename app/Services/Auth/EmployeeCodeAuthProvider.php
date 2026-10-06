<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Core\Database;
use PDO;

/**
 * Fast identifier-based authentication provider for employees.
 * Passwordless authentication using employee_code, email, or username.
 * Highly optimized for 2k+ concurrent requests (O(1) index lookup, no bcrypt overhead).
 */
class EmployeeCodeAuthProvider implements AuthProviderInterface
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    public function authenticate(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, public_id, employee_code, name, email, username, status ' .
            'FROM employees ' .
            'WHERE employee_code = :id1 OR email = :id2 OR username = :id3 ' .
            'LIMIT 1'
        );

        $stmt->execute([
            'id1' => $identifier,
            'id2' => $identifier,
            'id3' => $identifier,
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        // Active status check
        if (($user['status'] ?? '') !== 'active') {
            return null;
        }

        $user['role'] = 'employee';

        return $user;
    }
}
