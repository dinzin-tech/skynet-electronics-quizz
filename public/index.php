<?php

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