<?php

declare(strict_types=1);

// Hot Path Entry Point: zero framework bootstrap, zero sessions, zero MySQL
require_once __DIR__ . '/../vendor/autoload.php';

use App\Hot\Clock;
use App\Hot\HotRouter;
use App\Hot\Redis as HotRedis;
use App\Hot\Token;

$serverTimeMs = Clock::nowMs();

// Load pre-compiled configuration (never parse .env on the hot path)
$configFile = __DIR__ . '/../config/hot.php';
if (!file_exists($configFile)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'code' => 'config_not_cached',
            'message' => 'Hot configuration not cached. Run php bin/console config:cache.',
        ]
    ]);
    exit;
}

$config = require $configFile;

// Connect to Redis via unix socket (or local TCP fallback)
try {
    $redis = HotRedis::getConnection($config);
} catch (\Throwable $e) {
    HotRouter::error(503, 'redis_unavailable', 'Storage service temporarily unavailable', $serverTimeMs);
    exit;
}

$router = new HotRouter();

// 1. Server time endpoint for free client-clock synchronization
$router->get('/api/time', function (array $params, array $config, \Redis $redis, int $nowMs): void {
    HotRouter::json(200, [
        'server_now_ms' => $nowMs,
        'iso' => Clock::formatIsoMs($nowMs),
    ], $nowMs);
});

// Stubs for quiz engine endpoints (fully wired in Phase 4)
$router->get('/api/quiz/{code}', function (array $params, array $config, \Redis $redis, int $nowMs): void {
    $code = $params['code'] ?? '';
    HotRouter::json(200, [
        'status' => 'ok',
        'code' => $code,
        'server_now_ms' => $nowMs,
    ], $nowMs);
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

$router->dispatch($method, $uri, $config, $redis);
