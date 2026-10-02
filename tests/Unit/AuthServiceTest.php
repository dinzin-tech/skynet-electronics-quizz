<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Clock;
use App\Hot\Token;
use App\Middlewares\AuthToken;
use App\Middlewares\RequireRole;
use App\Services\Auth\AdminAuthProvider;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordAuthProvider;
use Core\Database;
use Core\Http\Request;
use Core\Http\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use Redis;

class AuthServiceTest extends TestCase
{
    private PDO $db;
    private AuthService $authService;
    private string $secret = 'test_secret_for_auth_testing_1234567890';

    protected function setUp(): void
    {
        // Use real database connection for integration checks
        $this->db = Database::getInstance()->getConnection();
        $this->authService = new AuthService(
            new PasswordAuthProvider($this->db),
            new AdminAuthProvider($this->db),
            $this->secret,
            'v1'
        );
    }

    protected function tearDown(): void
    {
        Clock::setMockNowMs(null);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['AUTH_USER']);
    }

    public function test_employee_can_login_with_code_email_or_username(): void
    {
        // 1. By employee_code
        $res1 = $this->authService->loginEmployee('EMP0001', 'QuizPass2026!');
        $this->assertNotNull($res1);
        $this->assertArrayHasKey('token', $res1);
        $this->assertSame('EMP0001', $res1['user']['employee_code']);
        $this->assertSame('employee', $res1['user']['role']);

        // 2. By email
        $res2 = $this->authService->loginEmployee('emp1@corp.local', 'QuizPass2026!');
        $this->assertNotNull($res2);
        $this->assertSame('EMP0001', $res2['user']['employee_code']);

        // 3. By username
        $res3 = $this->authService->loginEmployee('emp0001', 'QuizPass2026!');
        $this->assertNotNull($res3);
        $this->assertSame('EMP0001', $res3['user']['employee_code']);
    }

    public function test_employee_login_fails_with_wrong_password(): void
    {
        $res = $this->authService->loginEmployee('EMP0001', 'WrongPassword!');
        $this->assertNull($res);
    }

    public function test_employee_login_fails_for_inactive_user(): void
    {
        // EMP2500 is seeded as inactive
        $res = $this->authService->loginEmployee('EMP2500', 'QuizPass2026!');
        $this->assertNull($res);
    }

    public function test_role_escalation_blocked(): void
    {
        // Issue valid employee token
        $employeeToken = Token::issue(1001, 'employee', 3600, $this->secret, 'v1');

        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$employeeToken}";
        $_ENV['APP_SECRET'] = $this->secret;

        $authMiddleware = new AuthToken();
        $adminOnlyMiddleware = new RequireRole('admin');

        $request = new Request();

        // 1. Auth middleware parses token and sets AUTH_USER
        $authResponse = $authMiddleware->handle($request, function ($req) {
            return (new Response())->json(['status' => 'ok']);
        });

        $this->assertSame(200, $authResponse->getStatusCode());
        $this->assertSame('employee', $_SERVER['AUTH_USER']['role']);

        // 2. RequireRole('admin') middleware must reject employee
        $adminResponse = $adminOnlyMiddleware->handle($request, function ($req) {
            return (new Response())->json(['status' => 'secret_admin_data']);
        });

        $this->assertSame(403, $adminResponse->getStatusCode());
    }

    public function test_expired_token_rejected_by_middleware(): void
    {
        Clock::setMockNowMs(1700000000000);
        $expiredToken = Token::issue(1001, 'employee', 60, $this->secret, 'v1'); // expires at 1700000060

        // Advance clock past expiration
        Clock::setMockNowMs(1700000065000);

        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$expiredToken}";
        $_ENV['APP_SECRET'] = $this->secret;

        $authMiddleware = new AuthToken();
        $response = $authMiddleware->handle(new Request(), function ($req) {
            return (new Response())->json(['status' => 'ok']);
        });

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_token_revocation_in_redis(): void
    {
        $mockRedis = $this->createMock(Redis::class);
        $token = 'sample.token.to.revoke';
        $hash = hash('sha256', $token);

        $mockRedis->expects($this->once())
            ->method('setex')
            ->with("revoked:{$hash}", 86400, '1')
            ->willReturn(true);

        $mockRedis->expects($this->once())
            ->method('exists')
            ->with("revoked:{$hash}")
            ->willReturn(1);

        $this->authService->revokeToken($token, $mockRedis, 86400);
        $this->assertTrue($this->authService->isRevoked($token, $mockRedis));
    }
}
