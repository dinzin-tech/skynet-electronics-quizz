<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Core\Database;
use PDO;

/**
 * Authentication provider for administrators.
 */
class AdminAuthProvider
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    public function authenticate(string $email, string $password): ?array
    {
        $email = trim(strtolower($email));
        if ($email === '' || $password === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, name, email, password_hash, status ' .
            'FROM administrators ' .
            'WHERE email = :email ' .
            'LIMIT 1'
        );

        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || ($admin['status'] ?? '') !== 'active') {
            return null;
        }

        if (!password_verify($password, $admin['password_hash'] ?? '')) {
            return null;
        }

        // Update last login timestamp
        $updateStmt = $this->db->prepare('UPDATE administrators SET last_login_at = NOW() WHERE id = :id');
        $updateStmt->execute(['id' => $admin['id']]);

        unset($admin['password_hash']);
        $admin['role'] = 'admin';

        return $admin;
    }
}
