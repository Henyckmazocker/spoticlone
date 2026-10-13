<?php

declare(strict_types=1);

/**
 * Helper functions para el sistema de configuración
 */

if (!function_exists('env')) {
    /**
     * Obtiene una variable de entorno con valor por defecto
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        
        if ($value === false) {
            return $default;
        }

        // Convertir strings especiales a sus tipos correspondientes
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value
        };
    }
}

if (!function_exists('config')) {
    /**
     * Obtiene una configuración del archivo de config
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $configs = [];
        
        $parts = explode('.', $key);
        $file = array_shift($parts);
        
        if (!isset($configs[$file])) {
            $configPath = __DIR__ . "/{$file}.php";
            if (file_exists($configPath)) {
                $configs[$file] = require $configPath;
            } else {
                return $default;
            }
        }
        
        $config = $configs[$file];
        
        foreach ($parts as $part) {
            if (!is_array($config) || !array_key_exists($part, $config)) {
                return $default;
            }
            $config = $config[$part];
        }
        
        return $config;
    }
}
