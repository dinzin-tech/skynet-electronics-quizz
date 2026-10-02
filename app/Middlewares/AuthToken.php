<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Hot\Token;
use Core\Http\Request;
use Core\Http\Response;

/**
 * Validates HMAC-SHA256 bearer tokens on framework routes.
 */
class AuthToken implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return (new Response())
                ->setStatusCode(401)
                ->json([
                    'error' => [
                        'code' => 'missing_token',
                        'message' => 'Authorization bearer token is required',
                    ]
                ], 401);
        }

        $tokenStr = substr($authHeader, 7);
        $secret = $_ENV['APP_SECRET'] ?? '';

        $payload = Token::verify($tokenStr, $secret);
        if ($payload === null) {
            return (new Response())
                ->setStatusCode(401)
                ->json([
                    'error' => [
                        'code' => 'invalid_token',
                        'message' => 'Token is invalid, expired, or tampered',
                    ]
                ], 401);
        }

        // Attach verified user claims to server state
        $_SERVER['AUTH_USER'] = $payload;

        return $next($request);
    }
}
