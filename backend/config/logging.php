<?php

declare(strict_types=1);

use Monolog\Logger;

/**
 * Configuración del sistema de logging
 */
return [
    // Canal por defecto
    'default_channel' => $_ENV['LOG_CHANNEL'] ?? 'app',

    // Nivel mínimo de logging
    'log_level' => match($_ENV['LOG_LEVEL'] ?? 'info') {
        'debug' => Logger::DEBUG,
        'info' => Logger::INFO,
        'notice' => Logger::NOTICE,
        'warning' => Logger::WARNING,
        'error' => Logger::ERROR,
        'critical' => Logger::CRITICAL,
        'alert' => Logger::ALERT,
        'emergency' => Logger::EMERGENCY,
        default => Logger::INFO
    },

    // Directorio donde se almacenan los logs
    'log_path' => $_ENV['LOG_PATH'] ?? __DIR__ . '/../storage/logs',

    // Número máximo de archivos de log a mantener (rotación)
    'max_files' => (int)($_ENV['LOG_MAX_FILES'] ?? 7),

    // Entorno de ejecución
    'environment' => $_ENV['APP_ENV'] ?? 'production',

    // Habilitar logging en consola
    'enable_console' => ($_ENV['LOG_CONSOLE'] ?? 'false') === 'true',

    // Habilitar formato JSON
    'enable_json_format' => ($_ENV['LOG_JSON_FORMAT'] ?? 'true') === 'true',

    // Configuración de canales específicos
    'channels' => [
        'app' => [
            'level' => $_ENV['LOG_LEVEL_APP'] ?? 'info',
            'path' => $_ENV['LOG_PATH_APP'] ?? 'app.log',
        ],
        'api' => [
            'level' => $_ENV['LOG_LEVEL_API'] ?? 'info',
            'path' => $_ENV['LOG_PATH_API'] ?? 'api.log',
        ],
        'database' => [
            'level' => $_ENV['LOG_LEVEL_DATABASE'] ?? 'info',
            'path' => $_ENV['LOG_PATH_DATABASE'] ?? 'database.log',
        ],
        'auth' => [
            'level' => $_ENV['LOG_LEVEL_AUTH'] ?? 'info',
            'path' => $_ENV['LOG_PATH_AUTH'] ?? 'auth.log',
        ],
        'security' => [
            'level' => $_ENV['LOG_LEVEL_SECURITY'] ?? 'warning',
            'path' => $_ENV['LOG_PATH_SECURITY'] ?? 'security.log',
        ],
        'application' => [
            'level' => $_ENV['LOG_LEVEL_APPLICATION'] ?? 'info',
            'path' => $_ENV['LOG_PATH_APPLICATION'] ?? 'application.log',
        ],
        'frontend' => [
            'level' => $_ENV['LOG_LEVEL_FRONTEND'] ?? 'info',
            'path' => $_ENV['LOG_PATH_FRONTEND'] ?? 'frontend.log',
        ],
        'errors' => [
            'level' => $_ENV['LOG_LEVEL_ERRORS'] ?? 'error',
            'path' => $_ENV['LOG_PATH_ERRORS'] ?? 'errors.log',
        ]
    ]
];
