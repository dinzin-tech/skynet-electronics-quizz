<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// 1. Hot path endpoints bypass framework bootstrap
if (preg_match('#^/api/(quiz|attempts|time)#', $uri)) {
    require __DIR__ . '/public/hot.php';
    exit;
}

// 2. Direct access to static files in public/
$publicFile = __DIR__ . '/public' . $uri;
if (file_exists($publicFile) && !is_dir($publicFile)) {
    return false;
}

// 3. Employee SPA routing
if (preg_match('#^/quiz/#', $uri) || $uri === '/app' || $uri === '/app/') {
    $spaHtml = __DIR__ . '/public/app/index.html';
    if (file_exists($spaHtml)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($spaHtml);
        exit;
    }
}

// 4. Default: framework entry point
require __DIR__ . '/public/index.php';
