<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/../vendor/autoload.php';

// Ensure environment variables are loaded if available
if (!isset($_ENV['DEFAULT_DB_HOST']) && file_exists(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// 1. Hot path endpoints bypass framework bootstrap
if (preg_match('#^/api/(quiz|attempts|time|programme)#', $uri)) {
    require __DIR__ . '/hot.php';
    exit;
}

// 2. Employee SPA routing
if (preg_match('#^/(quiz|quizz)/#', $uri) || $uri === '/app' || $uri === '/app/') {
    $spaHtml = __DIR__ . '/app/index.html';
    if (file_exists($spaHtml)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($spaHtml);
        exit;
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Kernel;
use Core\Http\Request;
use Dotenv\Dotenv;

$request = new Request();

// Load the .env file
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$kernel = new Kernel();
$kernel->boot();

// Handle request and get response
$response = $kernel->handle($request);

// Send the response
$kernel->terminate($request, $response);
