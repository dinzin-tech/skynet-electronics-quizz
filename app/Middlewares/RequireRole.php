<?php

declare(strict_types=1);

namespace App\Middlewares;

use Core\Http\Request;
use Core\Http\Response;

/**
 * Ensures authenticated user has the required role (e.g. 'admin').
 */
class RequireRole implements MiddlewareInterface
{
    private string $requiredRole;

    public function __construct(string $requiredRole = 'admin')
    {
        $this->requiredRole = $requiredRole;
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $_SERVER['AUTH_USER'] ?? null;

        if (!$user || !isset($user['role'])) {
            return (new Response())
                ->setStatusCode(401)
                ->json([
                    'error' => [
                        'code' => 'unauthenticated',
                        'message' => 'Authentication required',
                    ]
                ], 401);
        }

        if ($user['role'] !== $this->requiredRole) {
            return (new Response())
                ->setStatusCode(403)
                ->json([
                    'error' => [
                        'code' => 'forbidden',
                        'message' => "Insufficient permissions. Required role: {$this->requiredRole}",
                    ]
                ], 403);
        }

        return $next($request);
    }
}
