<?php
ob_start(); // Start output buffering

// --- Basic Auth ---
$USERNAME = getenv('APP_USER');
$PASSWORD = getenv('APP_PASS');

// Ensure credentials are set
if (!$USERNAME || !$PASSWORD) {
    http_response_code(500);
    echo "Authentication not configured properly.";
    exit;
}

if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW']) ||
    $_SERVER['PHP_AUTH_USER'] !== $USERNAME ||
    $_SERVER['PHP_AUTH_PW'] !== $PASSWORD) {

    header('WWW-Authenticate: Basic realm="Protected Area"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Unauthorized';
    exit;
}

// --- Load your app ---
require_once __DIR__ . '/app/bootstrap.php';

$controller = AppFactory::makeDashboardController();
$controller->index(new Request());

ob_end_flush(); // Send output