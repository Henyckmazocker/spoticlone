<?php

declare(strict_types=1);

/**
 * Minimal bootstrap for the binary endpoints (public/stream.php, public/cover.php).
 *
 * They don't need the DI container / action router — only the autoloader and environment
 * variables (for JWT_SECRET, DB_*, MUSIC_ROOT). Kept separate from bootstrap.php so streaming
 * stays lightweight and never starts a PHP session or emits the JSON Content-Type header.
 */

require_once __DIR__ . '/vendor/autoload.php';

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if (!array_key_exists($key, $_ENV) || $_ENV[$key] === '') {
            $_ENV[$key] = $value;
        }
    }
}

$_ENV['APP_ENV'] = $_ENV['APP_ENV'] ?? 'development';
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');
