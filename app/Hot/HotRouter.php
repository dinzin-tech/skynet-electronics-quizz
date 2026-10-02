<?php

declare(strict_types=1);

namespace App\Hot;

use Redis as PhpRedis;

/**
 * Ultra-fast regex dispatcher for hot endpoints (< 30 lines core dispatch).
 * Zero framework imports, uniform JSON output with X-Server-Time.
 */
class HotRouter
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
    ];

    public function get(string $pattern, callable $handler): self
    {
        $this->routes['GET'][$pattern] = $handler;
        return $this;
    }

    public function post(string $pattern, callable $handler): self
    {
        $this->routes['POST'][$pattern] = $handler;
        return $this;
    }

    public function put(string $pattern, callable $handler): self
    {
        $this->routes['PUT'][$pattern] = $handler;
        return $this;
    }

    /**
     * Dispatch the current hot request.
     */
    public function dispatch(string $method, string $path, array $config, PhpRedis $redis): void
    {
        $nowMs = Clock::nowMs();
        $path = ($path !== '/') ? rtrim($path, '/') : $path;

        $methodRoutes = $this->routes[$method] ?? [];
        foreach ($methodRoutes as $pattern => $handler) {
            $regex = '#^' . preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler($params, $config, $redis, $nowMs);
                return;
            }
        }

        self::error(404, 'not_found', 'Hot endpoint not found', $nowMs);
    }

    /**
     * Send uniform JSON success response.
     *
     * @param int $status HTTP status code
     * @param array<string, mixed> $data
     * @param int $serverTimeMs Server timestamp in milliseconds
     * @param array<string, string> $extraHeaders
     */
    public static function json(
        int $status,
        array $data,
        int $serverTimeMs,
        array $extraHeaders = []
    ): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header("X-Server-Time: {$serverTimeMs}");
        foreach ($extraHeaders as $name => $value) {
            header("{$name}: {$value}");
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Send uniform JSON error response.
     */
    public static function error(
        int $status,
        string $errorCode,
        string $message,
        int $serverTimeMs,
        array $extraHeaders = []
    ): void {
        self::json(
            $status,
            [
                'error' => [
                    'code' => $errorCode,
                    'message' => $message,
                ],
            ],
            $serverTimeMs,
            $extraHeaders
        );
    }

    /**
     * Safely read and decode JSON request body with size and depth limits.
     */
    public static function readJsonBody(int $maxBytes = 65536, int $maxDepth = 10): ?array
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBytes) {
            return null; // Exceeds size limit
        }

        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false || strlen($raw) > $maxBytes || $raw === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, $maxDepth, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null; // Malformed JSON
        }
    }
}
