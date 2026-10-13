<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Logging\LogHelper;
use Psr\Log\LoggerInterface;

/**
 * Servicio centralizado para logging en toda la aplicación
 */
class LoggingService
{
    private static ?LoggingService $instance = null;
    private array $logHelpers = [];

    private function __construct()
    {
        // Inicializar LoggerFactory con la configuración
        $config = config('logging', []);
        
        // Aplicar configuración específica del entorno si existe
        $environment = $config['environment'] ?? 'production';
        if (isset($config['environments'][$environment])) {
            $config = array_merge($config, $config['environments'][$environment]);
        }
        
        LoggerFactory::init($config);
    }

    /**
     * Obtiene la instancia singleton del servicio
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    /**
     * Obtiene un helper de logging para el canal especificado
     */
    public function getLogger(string $channel = null): LogHelper
    {
        $channel = $channel ?? 'app';
        
        if (!isset($this->logHelpers[$channel])) {
            $logger = LoggerFactory::create($channel);
            $this->logHelpers[$channel] = new LogHelper($logger);
        }
        
        return $this->logHelpers[$channel];
    }

    /**
     * Obtiene el logger de aplicación
     */
    public function app(): LogHelper
    {
        return $this->getLogger('application');
    }

    /**
     * Obtiene el logger de API
     */
    public function api(): LogHelper
    {
        return $this->getLogger('api');
    }

    /**
     * Obtiene el logger de base de datos
     */
    public function database(): LogHelper
    {
        return $this->getLogger('database');
    }

    /**
     * Obtiene el logger de autenticación
     */
    public function auth(): LogHelper
    {
        return $this->getLogger('auth');
    }

    /**
     * Obtiene el logger de seguridad
     */
    public function security(): LogHelper
    {
        return $this->getLogger('security');
    }

    /**
     * Métodos de conveniencia para logging rápido
     */
    public function info(string $message, array $context = []): void
    {
        $this->app()->info($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->app()->error($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->app()->debug($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->app()->warning($message, $context);
    }

    public function exception(\Throwable $exception, string $message = null, array $context = []): void
    {
        $this->app()->exception($exception, $message, $context);
    }

    /**
     * Reset para testing
     */
    public static function reset(): void
    {
        self::$instance = null;
        LoggerFactory::reset();
    }
}
