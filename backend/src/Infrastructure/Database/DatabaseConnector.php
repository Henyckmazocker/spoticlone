<?php
declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database Connector service for dependency injection
 */
class DatabaseConnector
{
    private array $config;

    public function __construct()
    {
        $this->config = [
            'host' => $_ENV['DB_HOST'] ?? 'mysql',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_DATABASE'] ?? 'spoticlone_db',
            'username' => $_ENV['DB_USERNAME'] ?? 'spoticlone_user',
            'password' => $_ENV['DB_PASSWORD'] ?? 'spoticlone_pass',
            'charset' => 'utf8mb4'
        ];
    }

    /**
     * Get database connection
     * NOTE: Creates a NEW connection each time to avoid transaction conflicts in concurrent requests
     */
    public function getConnection(): PDO
    {
        return $this->connect();
    }

    /**
     * Establish database connection
     * @return PDO New database connection
     */
    private function connect(): PDO
    {
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=%s",
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
            $this->config['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO(
                $dsn, 
                $this->config['username'], 
                $this->config['password'], 
                $options
            );
            
            // Log conexión exitosa
            if (function_exists('logger')) {
                try {
                    logger('database')->info('Database connection established', [
                        'host' => $this->config['host'],
                        'port' => $this->config['port'],
                        'database' => $this->config['database'],
                        'charset' => $this->config['charset']
                    ]);
                } catch (\Throwable $e) {
                    error_log("Logging error in DatabaseConnector: " . $e->getMessage());
                }
            }
            
            return $pdo;
            
        } catch (PDOException $e) {
            // Log error de conexión
            if (function_exists('logger')) {
                try {
                    logger('database')->error('Database connection failed', [
                        'host' => $this->config['host'],
                        'port' => $this->config['port'],
                        'database' => $this->config['database'],
                        'error' => $e->getMessage(),
                        'code' => $e->getCode()
                    ]);
                } catch (\Throwable $logError) {
                    error_log("Logging error in DatabaseConnector: " . $logError->getMessage());
                }
            }
            
            error_log("Database Connection Error: " . $e->getMessage());
            throw new RuntimeException(
                "Database connection failed. Check server logs. Details: " . $e->getMessage()
            );
        }
    }

    /**
     * Reset connection (útil para testing)
     */
    public function resetConnection(): void
    {
        $this->pdoInstance = null;
    }

    /**
     * Get configuration for debugging purposes
     */
    public function getConfig(): array
    {
        // Return config without sensitive data
        return [
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'database' => $this->config['database'],
            'charset' => $this->config['charset']
        ];
    }
} 