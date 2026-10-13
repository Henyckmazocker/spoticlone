<?php
declare(strict_types=1);

namespace App\Infrastructure\Middleware;

use App\Infrastructure\Session\SessionManager;
use App\Domain\Repository\User\UserRepositoryInterface;

/**
 * Authentication helper service (injected into controllers via DI).
 *
 * Despite its name this is NOT a pipeline middleware (it does not implement
 * MiddlewareInterface).  It provides session-based auth helpers such as
 * getCurrentUserId(), requireAuth(), validateCSRF() and getCSRFToken().
 *
 * Pipeline-based authentication is handled by {@see AuthenticationMiddleware},
 * which adds `user_id` to the request array for routes defined in routes.php.
 *
 * TODO: Consider renaming to AuthService and moving to Infrastructure/Auth/
 *       to avoid confusion with the pipeline middleware.
 */
class AuthMiddleware
{
    private SessionManager $sessionManager;
    private UserRepositoryInterface $userRepository;

    public function __construct(SessionManager $sessionManager, UserRepositoryInterface $userRepository)
    {
        $this->sessionManager = $sessionManager;
        $this->userRepository = $userRepository;
    }

    /**
     * Check if user is authenticated for protected routes
     */
    public function requireAuth(): array
    {
        if (!$this->sessionManager->isLoggedIn()) {
            return [
                'status' => 'error',
                'message' => 'Authentication required. Please log in.',
                'code' => 'AUTH_REQUIRED',
                'http_code' => 401
            ];
        }

        $userId = $this->sessionManager->getCurrentUserId();
        
        // Verify user still exists and is active
        $user = $this->userRepository->findById($userId);
        if (!$user || !$user->isActive()) {
            $this->sessionManager->logout();
            return [
                'status' => 'error',
                'message' => 'User account not found or inactive. Please log in again.',
                'code' => 'USER_INACTIVE',
                'http_code' => 401
            ];
        }

        // Extend session on successful auth check
        $this->sessionManager->extendSession();

        return [
            'status' => 'success',
            'user' => $user->toArray()
        ];
    }

    /**
     * Validate CSRF token for state-changing operations
     */
    public function validateCSRF(?string $token): array
    {
        if (!$token) {
            return [
                'status' => 'error',
                'message' => 'CSRF token is required.',
                'code' => 'CSRF_TOKEN_MISSING',
                'http_code' => 400
            ];
        }
        
        if (!$this->sessionManager->validateCSRFToken($token)) {
            return [
                'status' => 'error',
                'message' => 'Invalid CSRF token.',
                'code' => 'CSRF_TOKEN_INVALID',
                'http_code' => 403
            ];
        }

        return [
            'status' => 'success',
            'message' => 'CSRF token valid.'
        ];
    }

    /**
     * Get current authenticated user or null
     */
    public function getCurrentUser(): ?array
    {
        if (!$this->sessionManager->isLoggedIn()) {
            return null;
        }

        return $this->sessionManager->getCurrentUser();
    }

    /**
     * Get current authenticated user ID or null
     */
    public function getCurrentUserId(): ?int
    {
        return $this->sessionManager->getCurrentUserId();
    }

    /**
     * Check if current user owns a resource
     */
    public function checkResourceOwnership(int $resourceUserId): array
    {
        $currentUserId = $this->sessionManager->getCurrentUserId();
        
        if (!$currentUserId) {
            return [
                'status' => 'error',
                'message' => 'Authentication required.',
                'code' => 'AUTH_REQUIRED',
                'http_code' => 401
            ];
        }

        if ($currentUserId !== $resourceUserId) {
            return [
                'status' => 'error',
                'message' => 'Access denied. You can only access your own resources.',
                'code' => 'ACCESS_DENIED',
                'http_code' => 403
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Access granted.'
        ];
    }

    /**
     * Require authentication and CSRF validation for write operations
     */
    public function requireAuthAndCSRF(?string $csrfToken): array
    {
        // First check authentication
        $authResult = $this->requireAuth();
        if ($authResult['status'] === 'error') {
            return $authResult;
        }

        // Then check CSRF
        $csrfResult = $this->validateCSRF($csrfToken);
        if ($csrfResult['status'] === 'error') {
            return $csrfResult;
        }

        return [
            'status' => 'success',
            'user' => $authResult['user']
        ];
    }

    /**
     * Get CSRF token for forms
     */
    public function getCSRFToken(): string
    {
        return $this->sessionManager->generateCSRFToken();
    }
}
