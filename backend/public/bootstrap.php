<?php

declare(strict_types=1);

/**
 * Application Bootstrap - Versión Simplificada
 * 
 * Este archivo inicializa la aplicación cargando variables de entorno
 * y configurando headers básicos.
 */

/**
 * Cargar variables de entorno
 */
function loadEnvironmentVariables(): void {
    // Detectar el entorno actual
    $environment = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'development';
    
    // Cargar archivo .env específico del entorno
    $envFiles = [
        __DIR__ . "/.env.{$environment}",
        __DIR__ . "/.env"
    ];
    
    foreach ($envFiles as $envFile) {
        if (file_exists($envFile)) {
            loadEnvFile($envFile);
            break;
        }
    }
}

/**
 * Parsear archivo .env y cargar variables en $_ENV
 */
function loadEnvFile(string $path): void {
    if (!is_readable($path)) {
        return;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Saltar comentarios
        if (strpos($line, '#') === 0) {
            continue;
        }
        
        // Parsear línea clave=valor
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remover comillas si existen
            if (preg_match('/^"(.*)"$/', $value, $matches)) {
                $value = $matches[1];
            } elseif (preg_match("/^'(.*)'$/", $value, $matches)) {
                $value = $matches[1];
            }
            
            // Establecer variable de entorno
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

/**
 * Configurar headers básicos para la API
 */
function configureBasicHeaders(): void {
    // Solo configurar headers si no estamos en CLI y no hay headers ya enviados
    if (php_sapi_name() === 'cli' || headers_sent()) {
        return;
    }
    
    // Headers básicos de seguridad - handled by .htaccess to avoid duplication
    // header('X-Content-Type-Options: nosniff');
    // header('X-Frame-Options: DENY');
    // header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // CORS headers are handled by .htaccess to avoid duplication
    // Commenting out PHP CORS headers to prevent duplication
    /*
    if (!headers_sent() && !isset($_SERVER['HTTP_ACCESS_CONTROL_ALLOW_ORIGIN'])) {
        $corsOrigin = $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost:8080';
        header("Access-Control-Allow-Origin: {$corsOrigin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
        header('Access-Control-Max-Age: 86400');
    }
    */
}

/**
 * Configurar manejo básico de errores
 */
function configureBasicErrorHandling(): void {
    $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if ($debug) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
    } else {
        error_reporting(E_ERROR | E_WARNING | E_PARSE);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
    }
    
    // Configurar zona horaria
    date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');
}

/**
 * Inicializar aplicación
 */
function bootstrap(): void {
    // Cargar variables de entorno
    loadEnvironmentVariables();
    
    // Cargar helpers de configuración
    require_once __DIR__ . '/../config/helpers.php';
    
    // Configurar manejo de errores
    configureBasicErrorHandling();
    
    // Configurar headers básicos
    configureBasicHeaders();
    
    // Inicializar sistema de logging solo si es seguro hacerlo
    if (shouldInitializeLogging()) {
        initializeLogging();
    }
}

/**
 * Verificar si es seguro inicializar el sistema de logging
 */
function shouldInitializeLogging(): bool {
    // Solo inicializar logging si Composer ya está cargado
    return class_exists('Monolog\\Logger') && file_exists(__DIR__ . '/../src/Infrastructure/Logging/functions.php');
}

/**
 * Inicializar sistema de logging
 */
function initializeLogging(): void {
    // Cargar funciones de logging solo si el archivo existe
    $functionsFile = __DIR__ . '/../src/Infrastructure/Logging/functions.php';
    if (file_exists($functionsFile)) {
        require_once $functionsFile;
    }
    
    // Configurar manejador de errores con logging solo si las funciones están disponibles
    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        if (function_exists('log_error')) {
            try {
                log_error("PHP Error: {$message}", [
                    'severity' => $severity,
                    'file' => $file,
                    'line' => $line
                ]);
            } catch (\Throwable $e) {
                // Fallback a error_log si el sistema de logging falla
                error_log("Logging error: " . $e->getMessage());
                error_log("Original PHP Error: {$message} in {$file}:{$line}");
            }
        }
        
        return false; // Continuar con el manejador normal
    });
    
    // Configurar manejador de excepciones
    set_exception_handler(function ($exception) {
        if (function_exists('log_exception')) {
            try {
                log_exception($exception, 'Uncaught exception');
            } catch (\Throwable $e) {
                // Fallback a error_log si el sistema de logging falla
                error_log("Logging error: " . $e->getMessage());
                error_log("Original exception: " . $exception->getMessage());
            }
        }
        
        // En producción, mostrar error genérico
        $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$debug && !headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Internal server error']);
        }
    });
}

// Auto-ejecutar bootstrap si este archivo es incluido
if (!defined('BOOTSTRAP_LOADED')) {
    define('BOOTSTRAP_LOADED', true);
    bootstrap();
}
