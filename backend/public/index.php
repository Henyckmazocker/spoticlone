<?php
declare(strict_types=1);

/**
 * Entry point for the Library Vue Backend API
 * This file initializes the application with ActionRouter and Clean Architecture
 */

// Bootstrap the application
$app = require_once __DIR__ . '/../bootstrap.php';

// Run the application with ActionRouter
$app->run();