<?php

declare(strict_types=1);

namespace App\Domain\UseCases;

use Psr\Log\LoggerInterface;
use Exception;

/**
 * Abstract base class for Use Cases
 * Implements Template Method pattern to eliminate duplication
 * 
 * Handles:
 * - Logging (info, debug, error)
 * - Error handling
 * - Execution flow
 */
abstract class AbstractUseCase
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Template method - defines the execution flow
     * Final to prevent overriding
     */
    final public function execute($command)
    {
        $this->logExecutionStart($command);
        
        try {
            $result = $this->doExecute($command);
            
            $this->logExecutionSuccess($result);
            
            return $result;
            
        } catch (Exception $e) {
            $this->logExecutionError($e, $command);
            throw $e;
        }
    }

    /**
     * Hook method - implement in subclasses
     * Contains the actual use case logic
     */
    abstract protected function doExecute($command);

    /**
     * Hook method - get use case context for logging
     * Override in subclasses for custom context
     */
    abstract protected function getLogContext(): string;

    /**
     * Hook method - get success message
     * Override for custom success messages
     */
    protected function getSuccessMessage(): string
    {
        return 'Use case executed successfully';
    }

    /**
     * Hook method - get error message
     * Override for custom error messages
     */
    protected function getErrorMessage(): string
    {
        return 'Use case execution failed';
    }

    /**
     * Log execution start
     */
    private function logExecutionStart($command): void
    {
        $this->logger->debug('[{context}] Starting execution', [
            'context' => $this->getLogContext(),
            'command' => $this->extractCommandData($command)
        ]);
    }

    /**
     * Log execution success
     */
    private function logExecutionSuccess($result): void
    {
        $this->logger->info('[{context}] {message}', [
            'context' => $this->getLogContext(),
            'message' => $this->getSuccessMessage(),
            'result' => $this->extractResultData($result)
        ]);
    }

    /**
     * Log execution error
     */
    private function logExecutionError(Exception $e, $command): void
    {
        $this->logger->error('[{context}] {message}', [
            'context' => $this->getLogContext(),
            'message' => $this->getErrorMessage(),
            'exception' => [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'command' => $this->extractCommandData($command)
        ]);
    }

    /**
     * Extract command data for logging
     * Safely handles different command types
     */
    private function extractCommandData($command): array
    {
        if (is_object($command)) {
            return [
                'class' => get_class($command),
                'properties' => get_object_vars($command)
            ];
        }
        
        if (is_array($command)) {
            return $command;
        }
        
        return ['raw' => (string)$command];
    }

    /**
     * Extract result data for logging
     * Safely handles different result types
     */
    private function extractResultData($result): mixed
    {
        if ($result === null) {
            return null;
        }
        
        if (is_scalar($result)) {
            return $result;
        }
        
        if (is_object($result) && method_exists($result, 'toArray')) {
            return $result->toArray();
        }
        
        if (is_object($result)) {
            return ['class' => get_class($result)];
        }
        
        if (is_array($result)) {
            return ['count' => count($result)];
        }
        
        return ['type' => gettype($result)];
    }
}
