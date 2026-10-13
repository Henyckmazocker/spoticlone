<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Concerns;

use Psr\Log\LoggerInterface;
use Exception;

/**
 * Trait: LoggableTrait
 * 
 * Proporciona métodos de logging unificados para repositorios.
 * Elimina duplicación de código de logging en MySqlBookRepository,
 * MySqlMovieRepository y MySqlUserRepository.
 * 
 * Uso:
 * ```php
 * class MySqlBookRepository implements BookRepositoryInterface {
 *     use LoggableTrait;
 *     
 *     protected function getLogger(): ?LoggerInterface {
 *         return $this->logger;
 *     }
 * }
 * ```
 */
trait LoggableTrait
{
    /**
     * Método abstracto que debe implementar la clase que use el trait.
     * Retorna el logger o null si no está disponible.
     */
    abstract protected function getLogger(): ?LoggerInterface;

    /**
     * Registra un error con contexto de excepción
     * 
     * @param string $message Mensaje descriptivo del error
     * @param Exception $e Excepción capturada
     * @param array<string, mixed> $context Contexto adicional
     */
    protected function logError(string $message, Exception $e, array $context = []): void
    {
        $logger = $this->getLogger();
        
        if ($logger === null) {
            return;
        }

        $logger->error($message, [
            'exception' => [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Registra información general
     * 
     * @param string $message Mensaje informativo
     * @param array<string, mixed> $context Contexto adicional
     */
    protected function logInfo(string $message, array $context = []): void
    {
        $logger = $this->getLogger();
        
        if ($logger === null) {
            return;
        }

        $logger->info($message, [
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Registra información de depuración
     * 
     * @param string $message Mensaje de debug
     * @param array<string, mixed> $context Contexto adicional
     */
    protected function logDebug(string $message, array $context = []): void
    {
        $logger = $this->getLogger();
        
        if ($logger === null) {
            return;
        }

        $logger->debug($message, [
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Registra una advertencia
     * 
     * @param string $message Mensaje de advertencia
     * @param array<string, mixed> $context Contexto adicional
     */
    protected function logWarning(string $message, array $context = []): void
    {
        $logger = $this->getLogger();
        
        if ($logger === null) {
            return;
        }

        $logger->warning($message, [
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Registra un evento crítico
     * 
     * @param string $message Mensaje crítico
     * @param array<string, mixed> $context Contexto adicional
     */
    protected function logCritical(string $message, array $context = []): void
    {
        $logger = $this->getLogger();
        
        if ($logger === null) {
            return;
        }

        $logger->critical($message, [
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Registra operación de base de datos
     * Útil para auditoría y debugging
     * 
     * @param string $operation Tipo de operación (INSERT, UPDATE, DELETE, SELECT)
     * @param string $table Tabla afectada
     * @param array<string, mixed> $data Datos de la operación
     */
    protected function logDatabaseOperation(string $operation, string $table, array $data = []): void
    {
        $this->logDebug("Database operation: {$operation} on {$table}", [
            'operation' => $operation,
            'table' => $table,
            'data' => $data
        ]);
    }

    /**
     * Registra tiempo de ejecución de una operación
     * 
     * @param string $operation Nombre de la operación
     * @param float $startTime Tiempo de inicio (de microtime(true))
     * @param array<string, mixed> $context Contexto adicional
     */
    protected function logExecutionTime(string $operation, float $startTime, array $context = []): void
    {
        $executionTime = microtime(true) - $startTime;
        
        $this->logDebug("Operation '{$operation}' completed", array_merge([
            'execution_time_ms' => round($executionTime * 1000, 2),
            'execution_time_s' => round($executionTime, 4)
        ], $context));
        
        // Advertir si la operación es lenta (> 1 segundo)
        if ($executionTime > 1.0) {
            $this->logWarning("Slow operation detected: '{$operation}'", [
                'execution_time_s' => round($executionTime, 4)
            ]);
        }
    }
}
