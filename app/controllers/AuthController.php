<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Hot\Clock;
use App\Hot\Redis as HotRedis;
use App\Services\Auth\AuthService;
use Core\Controller;
use Core\Http\Request;
use Core\Http\Response;

class AuthController extends Controller
{
    private AuthService $authService;

    public function __construct()
    {
        parent::__construct();
        $this->authService = new AuthService();
    }

    /**
     * @Route(path="/api/auth/login", methods="POST", name="api.auth.login")
     */
    public function login(Request $request): Response
    {
        $input = $this->parseInput($request);
        $identifier = (string) (
            $input['identifier'] ?? $input['login'] ?? $input['employee_code'] ?? $input['email'] ?? $input['username'] ?? ''
        );

        if ($identifier === '') {
            return (new Response())
                ->setStatusCode(400)
                ->json([
                    'error' => [
                        'code' => 'missing_credentials',
                        'message' => 'Employee identifier is required',
                    ]
                ], 400);
        }

        $result = $this->authService->loginEmployee($identifier);

        if (!$result) {
            return (new Response())
                ->setStatusCode(401)
                ->json([
                    'error' => [
                        'code' => 'invalid_credentials',
                        'message' => 'Invalid credentials or inactive account',
                    ]
                ], 401);
        }

        $result['server_now_ms'] = Clock::nowMs();

        return (new Response())->json($result, 200);
    }

    /**
     * @Route(path="/api/auth/admin/login", methods="POST", name="api.auth.admin.login")
     */
    public function adminLogin(Request $request): Response
    {
        $input = $this->parseInput($request);

        $email = (string) ($input['email'] ?? $input['username'] ?? '');
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            return (new Response())
                ->setStatusCode(400)
                ->json([
                    'error' => [
                        'code' => 'missing_credentials',
                        'message' => 'Email and password are required',
                    ]
                ], 400);
        }

        $result = $this->authService->loginAdmin($email, $password);

        if (!$result) {
            return (new Response())
                ->setStatusCode(401)
                ->json([
                    'error' => [
                        'code' => 'invalid_credentials',
                        'message' => 'Invalid administrator credentials or inactive account',
                    ]
                ], 401);
        }

        $result['server_now_ms'] = Clock::nowMs();

        return (new Response())->json($result, 200);
    }

    /**
     * @Route(path="/api/auth/logout", methods="POST", name="api.auth.logout")
     */
    public function logout(Request $request): Response
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $configFile = BASE_PATH . '/config/hot.php';
            $config = file_exists($configFile) ? require $configFile : [];

            try {
                $redis = HotRedis::getConnection($config);
                $this->authService->revokeToken($token, $redis);
            } catch (\Throwable $e) {
                // Redis might be unreachable; ignore during logout
            }
        }

        return (new Response())->json(['ok' => true, 'message' => 'Successfully logged out']);
    }

    /**
     * Parse input from either JSON request body or POST form data.
     *
     * @return array<string, mixed>
     */
    private function parseInput(Request $request): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $json;
            }
        }

        return $request->getPostData();
    }
}
