<?php
// Get requested URI
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Allow direct access to existing files and directories
if (file_exists(__DIR__ . $request_uri) && !is_dir(__DIR__ . $request_uri)) {
    return false;
}

// Redirect everything else to public/index.php
require __DIR__ . '/public/index.php';
