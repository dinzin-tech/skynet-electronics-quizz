<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Hot\RateLimiter;
use App\Hot\Redis as HotRedis;
use Core\Http\Request;
use Core\Http\Response;

/**
 * User-keyed rate limiting middleware for the framework.
 * Keyed strictly by user ID, never by IP.
 */
class RateLimit implements MiddlewareInterface
{
    private string $bucket;
    private int $maxRequests;
    private int $windowSeconds;

    public function __construct(string $bucket = 'api', int $maxRequests = 60, int $windowSeconds = 60)
    {
        $this->bucket = $bucket;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    public function handle(Request $request, callable $next): Response
    {
        $uid = $_SERVER['AUTH_USER']['uid'] ?? 'guest';

        $configFile = defined('BASE_PATH') ? BASE_PATH . '/config/hot.php' : dirname(__DIR__, 2) . '/config/hot.php';
        $config = file_exists($configFile) ? require $configFile : [
            'redis_socket' => $_ENV['REDIS_SOCKET'] ?? '',
            'redis_host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'redis_port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
            'redis_password' => $_ENV['REDIS_PASSWORD'] ?? '',
        ];

        try {
            $redis = HotRedis::getConnection($config);
            $result = RateLimiter::check(
                $redis,
                (string) $uid,
                $this->bucket,
                $this->maxRequests,
                $this->windowSeconds
            );

            if (!$result['allowed']) {
                $response = (new Response())
                    ->setStatusCode(429)
                    ->setHeader('Retry-After', (string) $result['retry_after'])
                    ->setHeader('X-RateLimit-Limit', (string) $this->maxRequests)
                    ->setHeader('X-RateLimit-Remaining', '0');

                return $response->json([
                    'error' => [
                        'code' => 'rate_limit_exceeded',
                        'message' => 'Too many requests. Please slow down.',
                        'retry_after' => $result['retry_after'],
                    ]
                ], 429);
            }
        } catch (\Throwable $e) {
            // Fail open on Redis errors in framework pipeline to avoid hard outage
            error_log('RateLimit middleware Redis error: ' . $e->getMessage());
        }

        return $next($request);
    }
}
