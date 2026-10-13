<?php
declare(strict_types=1);

/**
 * Bootstrap file for the Library Vue Backend Application
 * This file initializes the dependency injection container and application services
 */

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        
        if (!array_key_exists($key, $_ENV) || $_ENV[$key] === '') {
            $_ENV[$key] = $value;
        }
    }
}

// Set default environment variables
$_ENV['APP_ENV'] = $_ENV['APP_ENV'] ?? 'development';
// Database configuration - MUST be set in environment
if (!isset($_ENV['DB_HOST'])) $_ENV['DB_HOST'] = 'mysql';
if (!isset($_ENV['DB_PORT'])) $_ENV['DB_PORT'] = '3306';
if (!isset($_ENV['DB_DATABASE'])) {
    throw new RuntimeException('DB_DATABASE must be set in environment variables');
}
if (!isset($_ENV['DB_USERNAME'])) {
    throw new RuntimeException('DB_USERNAME must be set in environment variables');
}
if (!isset($_ENV['DB_PASSWORD'])) {
    throw new RuntimeException('DB_PASSWORD must be set in environment variables');
}

// Error reporting configuration
if ($_ENV['APP_ENV'] === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', '0');
}

// Set timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

// Import helper functions
if (file_exists(__DIR__ . '/config/helpers.php')) {
    require_once __DIR__ . '/config/helpers.php';
}

// Import logging functions
if (file_exists(__DIR__ . '/src/Infrastructure/Logging/functions.php')) {
    require_once __DIR__ . '/src/Infrastructure/Logging/functions.php';
}

// Initialize logging if available
if (file_exists(__DIR__ . '/config/logging.php')) {
    require_once __DIR__ . '/config/logging.php';
}

// Initialize Application with ActionRouter (new architecture)
use App\Application;

try {
    $app = new Application();
    
    return $app;
    
} catch (\Throwable $e) {
    // Handle bootstrap errors
    http_response_code(500);
    header('Content-Type: application/json');
    
    $response = [
        'error' => true,
        'message' => 'Application initialization failed'
    ];
    
    if ($_ENV['APP_ENV'] === 'development') {
        $response['debug'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }
    
    error_log("Bootstrap Error: " . $e->getMessage());
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit(1);
}
