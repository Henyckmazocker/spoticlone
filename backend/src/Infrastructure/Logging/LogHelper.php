<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Helper class para logging estructurado con métodos de conveniencia
 */
class LogHelper
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Log de debug con contexto estructurado
     */
    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $this->enrichContext($context));
    }

    /**
     * Log de información con contexto estructurado
     */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $this->enrichContext($context));
    }

    /**
     * Log de warning con contexto estructurado
     */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $this->enrichContext($context));
    }

    /**
     * Log de error con contexto estructurado
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $this->enrichContext($context));
    }

    /**
     * Log de error crítico con contexto estructurado
     */
    public function critical(string $message, array $context = []): void
    {
        $this->logger->critical($message, $this->enrichContext($context));
    }

    /**
     * Log específico para excepciones
     */
    public function exception(Throwable $exception, string $message = null, array $context = []): void
    {
        $message = $message ?? 'Exception occurred: ' . $exception->getMessage();
        
        $exceptionContext = [
            'exception' => [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->formatStackTrace($exception->getTrace())
            ]
        ];

        $this->error($message, array_merge($context, $exceptionContext));
    }

    /**
     * Log para requests HTTP
     */
    public function httpRequest(string $method, string $uri, array $context = []): void
    {
        $requestContext = [
            'http' => [
                'method' => $method,
                'uri' => $uri,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'ip' => $this->getClientIp(),
                'timestamp' => date('c')
            ]
        ];

        $this->info("HTTP Request: {$method} {$uri}", array_merge($context, $requestContext));
    }

    /**
     * Log para respuestas HTTP
     */
    public function httpResponse(int $statusCode, float $duration = null, array $context = []): void
    {
        $responseContext = [
            'http_response' => [
                'status_code' => $statusCode,
                'duration_ms' => $duration ? round($duration * 1000, 2) : null,
                'timestamp' => date('c')
            ]
        ];

        $level = $statusCode >= 500 ? 'error' : ($statusCode >= 400 ? 'warning' : 'info');
        $message = "HTTP Response: {$statusCode}";

        if ($duration) {
            $message .= " ({$responseContext['http_response']['duration_ms']}ms)";
        }

        $this->logger->log($level, $message, array_merge($context, $responseContext));
    }

    /**
     * Log para operaciones de base de datos
     */
    public function database(string $operation, string $table = null, float $duration = null, array $context = []): void
    {
        $dbContext = [
            'database' => [
                'operation' => $operation,
                'table' => $table,
                'duration_ms' => $duration ? round($duration * 1000, 2) : null,
                'timestamp' => date('c')
            ]
        ];

        $message = "Database {$operation}";
        if ($table) {
            $message .= " on {$table}";
        }
        if ($duration) {
            $message .= " ({$dbContext['database']['duration_ms']}ms)";
        }

        $this->info($message, array_merge($context, $dbContext));
    }

    /**
     * Log para autenticación
     */
    public function auth(string $action, string $userId = null, bool $success = true, array $context = []): void
    {
        $authContext = [
            'auth' => [
                'action' => $action,
                'user_id' => $userId,
                'success' => $success,
                'ip' => $this->getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'timestamp' => date('c')
            ]
        ];

        $level = $success ? 'info' : 'warning';
        $status = $success ? 'successful' : 'failed';
        $message = "Authentication {$action} {$status}";

        if ($userId) {
            $message .= " for user {$userId}";
        }

        $this->logger->log($level, $message, array_merge($context, $authContext));
    }

    /**
     * Log para eventos de seguridad
     */
    public function security(string $event, string $severity = 'medium', array $context = []): void
    {
        $securityContext = [
            'security' => [
                'event' => $event,
                'severity' => $severity,
                'ip' => $this->getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'timestamp' => date('c')
            ]
        ];

        $level = match($severity) {
            'low' => 'info',
            'medium' => 'warning',
            'high', 'critical' => 'error',
            default => 'warning'
        };

        $this->logger->log($level, "Security event: {$event}", array_merge($context, $securityContext));
    }

    /**
     * Log para métricas de performance
     */
    public function performance(string $operation, float $duration, array $metrics = [], array $context = []): void
    {
        $perfContext = [
            'performance' => [
                'operation' => $operation,
                'duration_ms' => round($duration * 1000, 2),
                'metrics' => $metrics,
                'timestamp' => date('c')
            ]
        ];

        $message = "Performance: {$operation} completed in {$perfContext['performance']['duration_ms']}ms";

        $this->info($message, array_merge($context, $perfContext));
    }

    /**
     * Enriquece el contexto con información adicional
     */
    private function enrichContext(array $context): array
    {
        return array_merge($context, [
            'timestamp' => date('c'),
            'request_id' => $this->getRequestId(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ]);
    }

    /**
     * Obtiene la IP del cliente considerando proxies
     */
    private function getClientIp(): ?string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_X_FORWARDED_FOR',      // Load Balancer/Proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Genera o recupera un ID único para la request actual
     */
    private function getRequestId(): string
    {
        static $requestId = null;
        
        if ($requestId === null) {
            $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true);
        }
        
        return $requestId;
    }

    /**
     * Formatea el stack trace para logging
     */
    private function formatStackTrace(array $trace): array
    {
        return array_map(function ($frame) {
            return [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? 'unknown',
                'class' => $frame['class'] ?? null
            ];
        }, array_slice($trace, 0, 10)); // Limitar a 10 frames
    }
}
