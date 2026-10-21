<?php

declare(strict_types=1);

namespace App\Infrastructure\Middleware;

use App\Domain\Repository\User\UserRepositoryInterface;
use App\Infrastructure\Auth\JWTService;
use App\Infrastructure\Session\SessionManager;
use Psr\Log\LoggerInterface;

/**
 * Authentication Middleware
 * Verifies that user is authenticated before proceeding.
 * Supports two methods:
 *   1. PHP session cookie (web browser)
 *   2. Authorization: Bearer <jwt> header (mobile / Capacitor / dev token)
 */
class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly JWTService $jwtService,
        private readonly UserRepositoryInterface $users,
        private readonly SessionManager $sessionManager
    ) {}

    public function handle(array $request, callable $next): array
    {
        $userId = null;
        $authMethod = null;
        $payload = null;

        // --- 1. Session-based auth (web) ---
        if (isset($_SESSION['user_data']['id'])) {
            $userId = (int) $_SESSION['user_data']['id'];
            $authMethod = 'session';
        } else {
            // --- 2. JWT-based auth (mobile / Capacitor / dev) ---
            // Apache may pass the header as HTTP_AUTHORIZATION, REDIRECT_HTTP_AUTHORIZATION,
            // or via getallheaders() depending on the PHP SAPI / mod_rewrite config.
            $authHeader = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '';

            if (empty($authHeader) && function_exists('getallheaders')) {
                $headers = array_change_key_case(getallheaders(), CASE_LOWER);
                $authHeader = $headers['authorization'] ?? '';
            }
            if (str_starts_with($authHeader, 'Bearer ')) {
                $payload = $this->jwtService->validate(substr($authHeader, 7));
                if ($payload !== null && isset($payload['user_id'])) {
                    $userId = (int) $payload['user_id'];
                    $authMethod = 'jwt';
                }
            }
        }

        if ($userId === null) {
            $this->logger->warning('Authentication failed - No user session', [
                'action' => $request['action'] ?? 'unknown',
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
            return ['status' => 'error', 'message' => 'Authentication required. Please log in.', 'http_code' => 401];
        }

        // Carga el usuario y comprueba que sigue activo y que la versión de token coincide.
        $user = $this->users->findById($userId);
        $tv = $authMethod === 'jwt'
            ? (int) ($payload['tv'] ?? -1)
            : (int) ($_SESSION['user_data']['tv'] ?? -1);
        if (!$user || !$user->isActive() || $tv !== $user->getTokenVersion()) {
            $this->logger->warning('Authentication failed - revoked, inactive or unknown user', [
                'user_id' => $userId,
                'method'  => $authMethod,
                'action'  => $request['action'] ?? 'unknown',
            ]);
            if ($authMethod === 'session') {
                $this->sessionManager->logout();
            }
            return ['status' => 'error', 'message' => 'Authentication required. Please log in.', 'http_code' => 401];
        }

        $request['user_id'] = $userId;
        $request['user'] = $user;
        $request['auth_method'] = $authMethod;
        if ($authMethod === 'jwt') {
            $this->logger->debug('User authenticated via JWT', [
                'user_id' => $userId, 'action' => $request['action'] ?? 'unknown',
            ]);
        }
        return $next($request);
    }
}
