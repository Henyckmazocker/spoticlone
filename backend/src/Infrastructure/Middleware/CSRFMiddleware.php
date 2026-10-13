<?php

declare(strict_types=1);

namespace App\Infrastructure\Middleware;

use Psr\Log\LoggerInterface;

/**
 * CSRF Protection Middleware
 * Validates CSRF token for state-changing operations
 */
class CSRFMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    public function handle(array $request, callable $next): array
    {
        // JWT-authenticated requests (mobile/Capacitor) don't use session cookies,
        // so CSRF protection via session token is not applicable — skip it.
        if (($request['auth_method'] ?? '') === 'jwt') {
            $this->logger->debug('CSRF skipped for JWT auth', [
                'action' => $request['action'] ?? 'unknown',
            ]);
            return $next($request);
        }

        // Session is already started in Application::bootstrap()
        // Check if CSRF token exists in request
        $csrfToken = $request['csrf_token'] ?? null;

        // Validate CSRF token
        if (!isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
            $this->logger->warning('CSRF validation failed', [
                'action' => $request['action'] ?? 'unknown',
                'user_id' => $_SESSION['user_data']['id'] ?? 'unknown',
                'provided_token' => $csrfToken ? 'present' : 'missing',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            return [
                'status' => 'error',
                'message' => 'Invalid CSRF token. Please refresh and try again.',
                'http_code' => 403
            ];
        }

        $this->logger->debug('CSRF token validated', [
            'action' => $request['action'] ?? 'unknown',
            'user_id' => $_SESSION['user_data']['id'] ?? 'unknown'
        ]);

        // Pass to next middleware
        return $next($request);
    }
}
