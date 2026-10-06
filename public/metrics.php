<?php

declare(strict_types=1);

// Metrics endpoint for Prometheus and health scrapers
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\MetricsCollector;
use Dotenv\Dotenv;

// Load environment variables if not cached
if (!isset($_ENV['APP_ENV'])) {
    if (file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->safeLoad();
    }
}

// Security: Optional Bearer token validation for external scrapers
$metricsToken = $_ENV['METRICS_TOKEN'] ?? getenv('METRICS_TOKEN') ?: '';
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$isLocal = in_array($remoteAddr, ['127.0.0.1', '::1', 'localhost'], true);

if ($metricsToken !== '' && !$isLocal) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $queryToken = $_GET['token'] ?? '';

    $authorized = false;
    if ($authHeader !== '' && str_starts_with($authHeader, 'Bearer ')) {
        $token = substr($authHeader, 7);
        $authorized = hash_equals($metricsToken, $token);
    } elseif ($queryToken !== '') {
        $authorized = hash_equals($metricsToken, (string) $queryToken);
    }

    if (!$authorized) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "401 Unauthorized: Invalid or missing metrics token.\n";
        exit;
    }
}

$collector = new MetricsCollector();

// Support JSON debugging
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($collector->collect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Standard Prometheus exposition format
header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $collector->toPrometheus();
