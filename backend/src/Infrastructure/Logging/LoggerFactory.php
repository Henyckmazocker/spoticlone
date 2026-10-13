<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\BrowserConsoleHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\WebProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\ProcessIdProcessor;

/**
 * Factory para crear y configurar loggers con diferentes handlers
 */
class LoggerFactory
{
    private static array $loggers = [];
    private static array $config = [];

    /**
     * Inicializa la configuración del factory
     */
    public static function init(array $config): void
    {
        self::$config = array_merge([
            'default_channel' => 'app',
            'log_level' => Logger::INFO,
            'log_path' => __DIR__ . '/../../../storage/logs',
            'max_files' => 7,
            'environment' => 'production',
            'enable_console' => false,
            'enable_json_format' => true,
        ], $config);

        // Crear directorio de logs si no existe
        if (!is_dir(self::$config['log_path'])) {
            mkdir(self::$config['log_path'], 0755, true);
        }
    }

    /**
     * Crea o retorna un logger existente para el canal especificado
     */
    public static function create(string $channel = null): Logger
    {
        $channel = $channel ?? self::$config['default_channel'];

        if (isset(self::$loggers[$channel])) {
            return self::$loggers[$channel];
        }

        $logger = new Logger($channel);

        // Agregar procesadores comunes
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(new WebProcessor());
        $logger->pushProcessor(new MemoryUsageProcessor());
        $logger->pushProcessor(new ProcessIdProcessor());

        // Configurar handlers según el entorno
        self::configureHandlers($logger, $channel);

        self::$loggers[$channel] = $logger;

        return $logger;
    }

    /**
     * Configura los handlers del logger según el entorno y configuración
     */
    private static function configureHandlers(Logger $logger, string $channel): void
    {
        $logLevel = self::$config['log_level'];
        $logPath = self::$config['log_path'];
        $environment = self::$config['environment'];

        // Handler principal: archivo rotativo
        $fileHandler = new RotatingFileHandler(
            $logPath . '/' . $channel . '.log',
            self::$config['max_files'],
            $logLevel
        );

        if (self::$config['enable_json_format']) {
            $fileHandler->setFormatter(new JsonFormatter());
        } else {
            $fileHandler->setFormatter(new LineFormatter(
                "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                'Y-m-d H:i:s'
            ));
        }

        $logger->pushHandler($fileHandler);

        // Handler para errores críticos en archivo separado
        $errorHandler = new RotatingFileHandler(
            $logPath . '/errors.log',
            self::$config['max_files'],
            Logger::ERROR
        );
        $errorHandler->setFormatter(new JsonFormatter());
        $logger->pushHandler($errorHandler);

        // Handler de consola para desarrollo
        if ($environment === 'development' || self::$config['enable_console']) {
            $consoleHandler = new StreamHandler('php://stdout', $logLevel);
            $consoleHandler->setFormatter(new LineFormatter(
                "%datetime% [%level_name%] %channel%: %message% %context%\n",
                'H:i:s'
            ));
            $logger->pushHandler($consoleHandler);
        }

        // Handler de consola del navegador para desarrollo web
        if ($environment === 'development' && isset($_SERVER['HTTP_HOST'])) {
            $browserHandler = new BrowserConsoleHandler($logLevel);
            $logger->pushHandler($browserHandler);
        }
    }

    /**
     * Crea un logger específico para errores de aplicación
     */
    public static function createApplicationLogger(): Logger
    {
        return self::create('application');
    }

    /**
     * Crea un logger específico para errores de base de datos
     */
    public static function createDatabaseLogger(): Logger
    {
        return self::create('database');
    }

    /**
     * Crea un logger específico para autenticación
     */
    public static function createAuthLogger(): Logger
    {
        return self::create('auth');
    }

    /**
     * Crea un logger específico para API requests
     */
    public static function createApiLogger(): Logger
    {
        return self::create('api');
    }

    /**
     * Crea un logger específico para seguridad
     */
    public static function createSecurityLogger(): Logger
    {
        return self::create('security');
    }

    /**
     * Obtiene la configuración actual
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * Limpia todos los loggers (útil para testing)
     */
    public static function reset(): void
    {
        self::$loggers = [];
        self::$config = [];
    }
}
