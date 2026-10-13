<?php

declare(strict_types=1);

use App\Infrastructure\Logging\LoggingService;

/**
 * Funciones globales de conveniencia para logging
 */

if (!function_exists('logger')) {
    /**
     * Obtiene una instancia del servicio de logging
     */
    function logger(string $channel = null): \App\Infrastructure\Logging\LogHelper
    {
        return LoggingService::getInstance()->getLogger($channel);
    }
}

if (!function_exists('log_info')) {
    /**
     * Log de información rápido
     */
    function log_info(string $message, array $context = []): void
    {
        LoggingService::getInstance()->info($message, $context);
    }
}

if (!function_exists('log_error')) {
    /**
     * Log de error rápido
     */
    function log_error(string $message, array $context = []): void
    {
        LoggingService::getInstance()->error($message, $context);
    }
}

if (!function_exists('log_debug')) {
    /**
     * Log de debug rápido
     */
    function log_debug(string $message, array $context = []): void
    {
        LoggingService::getInstance()->debug($message, $context);
    }
}

if (!function_exists('log_warning')) {
    /**
     * Log de warning rápido
     */
    function log_warning(string $message, array $context = []): void
    {
        LoggingService::getInstance()->warning($message, $context);
    }
}

if (!function_exists('log_exception')) {
    /**
     * Log de excepción rápido
     */
    function log_exception(\Throwable $exception, string $message = null, array $context = []): void
    {
        LoggingService::getInstance()->exception($exception, $message, $context);
    }
}
