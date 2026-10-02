<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Hot\Token;
use Redis as PhpRedis;
use RuntimeException;

/**
 * Authentication service handling employee and administrator authentication,
 * token generation, and token revocation.
 */
class AuthService
{
    private AuthProviderInterface $employeeProvider;
    private AdminAuthProvider $adminProvider;
    private string $tokenSecret;
    private string $tokenKid;

    public function __construct(
        ?AuthProviderInterface $employeeProvider = null,
        ?AdminAuthProvider $adminProvider = null,
        ?string $tokenSecret = null,
        string $tokenKid = 'v1'
    ) {
        $this->employeeProvider = $employeeProvider ?? new PasswordAuthProvider();
        $this->adminProvider = $adminProvider ?? new AdminAuthProvider();
        $this->tokenSecret = $tokenSecret ?? ($_ENV['APP_SECRET'] ?? '');
        $this->tokenKid = $tokenKid;

        if ($this->tokenSecret === '') {
            throw new RuntimeException('APP_SECRET must be configured.');
        }
    }

    /**
     * Authenticate an employee and issue a signed session token.
     * Pre-window login is allowed with long TTL (default 24h = 86400s).
     */
    public function loginEmployee(string $identifier, string $password, int $ttlSeconds = 86400): ?array
    {
        $user = $this->employeeProvider->authenticate($identifier, $password);
        if (!$user) {
            return null;
        }

        $token = Token::issue($user['id'], 'employee', $ttlSeconds, $this->tokenSecret, $this->tokenKid);

        return [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'public_id' => $user['public_id'],
                'employee_code' => $user['employee_code'],
                'name' => $user['name'],
                'email' => $user['email'],
                'username' => $user['username'],
                'role' => 'employee',
            ],
            'expires_in' => $ttlSeconds,
        ];
    }

    /**
     * Authenticate an administrator and issue a signed session token.
     */
    public function loginAdmin(string $email, string $password, int $ttlSeconds = 28800): ?array
    {
        $admin = $this->adminProvider->authenticate($email, $password);
        if (!$admin) {
            return null;
        }

        $token = Token::issue($admin['id'], 'admin', $ttlSeconds, $this->tokenSecret, $this->tokenKid);

        return [
            'token' => $token,
            'admin' => [
                'id' => $admin['id'],
                'name' => $admin['name'],
                'email' => $admin['email'],
                'role' => 'admin',
            ],
            'expires_in' => $ttlSeconds,
        ];
    }

    /**
     * Revoke a token by adding its hash to Redis revocation set.
     */
    public function revokeToken(string $token, PhpRedis $redis, int $ttlSeconds = 86400): void
    {
        $hash = hash('sha256', $token);
        $key = "revoked:{$hash}";
        $redis->setex($key, $ttlSeconds, '1');
    }

    /**
     * Check if a token has been revoked.
     */
    public function isRevoked(string $token, PhpRedis $redis): bool
    {
        $hash = hash('sha256', $token);
        $key = "revoked:{$hash}";
        return (bool) $redis->exists($key);
    }
}
