<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Core\Database;
use PDO;

/**
 * Default password-based authentication provider for employees.
 * Supports employee_code, email, or username.
 * Bcrypt cost 10 (tunable via environment or parameter).
 */
class PasswordAuthProvider implements AuthProviderInterface
{
    private PDO $db;
    private int $bcryptCost;

    public function __construct(?PDO $db = null, int $bcryptCost = 10)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->bcryptCost = $bcryptCost;
    }

    public function authenticate(string $identifier, string $password = ''): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, public_id, employee_code, name, email, username, password_hash, status ' .
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

        // Password hash check
        $hash = $user['password_hash'] ?? '';
        if ($hash === '' || !password_verify($password, $hash)) {
            return null;
        }

        // Check if password needs rehash (e.g. cost changed)
        if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost])) {
            $sql = 'UPDATE employees SET password_hash = :hash, updated_at = NOW() WHERE id = :id';
            $updateStmt = $this->db->prepare($sql);
            $updateStmt->execute(['hash' => $newHash, 'id' => $user['id']]);
        }

        unset($user['password_hash']);
        $user['role'] = 'employee';

        return $user;
    }

    /**
     * Helper to hash passwords using the configured bcrypt cost.
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost]);
    }
}
