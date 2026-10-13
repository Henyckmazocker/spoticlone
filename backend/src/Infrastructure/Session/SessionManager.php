<?php
declare(strict_types=1);

namespace App\Infrastructure\Session;

use App\Domain\Model\User;

class SessionManager
{
    private const SESSION_NAME = 'SPOTICLONE_SESSION';
    private const USER_KEY = 'user_data';
    private const CSRF_KEY = 'csrf_token';
    private const SESSION_TIMEOUT = 604800; // 7 days in seconds (development setting)
    
    public function __construct()
    {
        $this->startSession();
    }

    private function startSession(): void
    {
        // Session should already be started by Application::bootstrap()
        // Only start if not already active (for backward compatibility or testing)
        if (session_status() === PHP_SESSION_NONE) {
            // Configure session settings for security ONLY if headers haven't been sent
            if (!headers_sent()) {
                ini_set('session.cookie_httponly', '1');
                // Don't require HTTPS in development
                $isProduction = ($_ENV['APP_ENV'] ?? 'development') === 'production';
                ini_set('session.cookie_secure', $isProduction ? '1' : '0');
                // Use Lax samesite for development (allows some cross-origin)
                ini_set('session.cookie_samesite', $isProduction ? 'Strict' : 'Lax');
                // Force cookie domain to be empty for localhost
                ini_set('session.cookie_domain', '');
                // Set cookie path to root
                ini_set('session.cookie_path', '/');
                ini_set('session.use_strict_mode', '1');
                ini_set('session.gc_maxlifetime', (string)self::SESSION_TIMEOUT);
                
                session_name(self::SESSION_NAME);
                session_start();
            } else {
                // If headers were already sent, try to start session anyway
                // (this might happen in CLI or testing environments)
                @session_start();
            }
        }
        
        // Check session timeout and regenerate if needed (only if session is active)
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($this->isSessionExpired()) {
                $this->logout();
                return;
            }
            
            // Regenerate session ID periodically for security
            if (!isset($_SESSION['last_regenerated'])) {
                $this->regenerateSession();
            } elseif (time() - $_SESSION['last_regenerated'] > 300) { // 5 minutes
                $this->regenerateSession();
            }
            
            // Update last activity
            $_SESSION['last_activity'] = time();
        }
    }

    public function login(User $user): void
    {
        $_SESSION[self::USER_KEY] = [
            'id' => $user->getId(),
            'google_id' => $user->getGoogleId()->toString(),
            'email' => $user->getEmail()->toString(),
            'name' => $user->getName(),
            'picture' => $user->getPicture(),
            'is_active' => $user->isActive()
        ];
        
        $_SESSION['last_activity'] = time();
        $this->generateCSRFToken();
        $this->regenerateSession();
    }

    public function logout(): void
    {
        $_SESSION = [];
        
        // Delete the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }

    public function isLoggedIn(): bool
    {
        $hasUserKey = isset($_SESSION[self::USER_KEY]);
        $userDataNotEmpty = !empty($_SESSION[self::USER_KEY]);
        $sessionNotExpired = !$this->isSessionExpired();
        
        return $hasUserKey && $userDataNotEmpty && $sessionNotExpired;
    }

    public function getCurrentUser(): ?array
    {
        if (!$this->isLoggedIn()) {
            return null;
        }
        return $_SESSION[self::USER_KEY] ?? null;
    }

    public function getCurrentUserId(): ?int
    {
        $user = $this->getCurrentUser();
        return $user ? (int)$user['id'] : null;
    }

    public function generateCSRFToken(): string
    {
        if (!isset($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    public function validateCSRFToken(string $token): bool
    {
        return isset($_SESSION[self::CSRF_KEY]) && 
               hash_equals($_SESSION[self::CSRF_KEY], $token);
    }

    public function updateUserData(User $user): void
    {
        if ($this->isLoggedIn()) {
            $_SESSION[self::USER_KEY] = [
                'id' => $user->getId(),
                'google_id' => $user->getGoogleId()->toString(),
                'email' => $user->getEmail()->toString(),
                'name' => $user->getName(),
                'picture' => $user->getPicture(),
                'is_active' => $user->isActive()
            ];
        }
    }

    private function regenerateSession(): void
    {
        session_regenerate_id(true);
        $_SESSION['last_regenerated'] = time();
    }

    private function isSessionExpired(): bool
    {
        if (!isset($_SESSION['last_activity'])) {
            return false;
        }
        
        return (time() - $_SESSION['last_activity']) > self::SESSION_TIMEOUT;
    }

    public function extendSession(): void
    {
        $_SESSION['last_activity'] = time();
    }
}
