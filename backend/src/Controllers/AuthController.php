<?php
namespace App\Controllers;

use App\Domain\DTO\Commands\UpdateUserProfileCommand;
use App\Domain\UseCases\Auth\LoginUserUseCase;
use App\Domain\UseCases\Auth\UpdateUserProfileUseCase;
use App\Infrastructure\Session\SessionManager;
use App\Infrastructure\Middleware\AuthMiddleware;
use App\Infrastructure\Auth\GoogleOAuthVerifier;
use App\Infrastructure\Auth\JWTService;
use App\Domain\UseCases\Auth\UserLimitReachedException;
use App\Domain\Model\User;
use App\Domain\Repository\User\UserRepositoryInterface;
use App\Domain\Services\AdminPolicy;

class AuthController extends BaseController implements Contracts\AuthControllerInterface
{
    private const MAX_BATCH_LOGS = 20;
    private const MAX_LOG_MESSAGE = 1000;
    private const MAX_LOG_CONTEXT_BYTES = 2048;

    private LoginUserUseCase $loginUserUseCase;
    private UpdateUserProfileUseCase $updateUserProfileUseCase;
    private SessionManager $sessionManager;
    private AuthMiddleware $authMiddleware;
    private GoogleOAuthVerifier $googleVerifier;
    private JWTService $jwtService;
    private UserRepositoryInterface $userRepository;

    public function __construct(
        LoginUserUseCase $loginUserUseCase,
        UpdateUserProfileUseCase $updateUserProfileUseCase,
        SessionManager $sessionManager,
        AuthMiddleware $authMiddleware,
        GoogleOAuthVerifier $googleVerifier,
        JWTService $jwtService,
        UserRepositoryInterface $userRepository
    ) {
        $this->userRepository = $userRepository;
        $this->loginUserUseCase = $loginUserUseCase;
        $this->updateUserProfileUseCase = $updateUserProfileUseCase;
        $this->sessionManager = $sessionManager;
        $this->authMiddleware = $authMiddleware;
        $this->googleVerifier = $googleVerifier;
        $this->jwtService = $jwtService;
    }

    public function login(array $inputData): array
    {
        if (!isset($inputData['google_token']) || !is_string($inputData['google_token'])) {
            throw new \InvalidArgumentException('Google token is required for login.');
        }
        
        // Properly verify Google ID token with cryptographic signature validation
        $payload = $this->googleVerifier->verifyToken($inputData['google_token']);

        // Create login command from verified payload
        $command = \App\Domain\DTO\Commands\LoginUserCommand::fromGoogleToken($payload);
        try {
            $user = $this->loginUserUseCase->execute($command);
        } catch (UserLimitReachedException $e) {
            return $this->errorResponse('Se ha alcanzado el límite de usuarios. No se admiten registros nuevos.', 403);
        }
        $this->sessionManager->login($user);
        
        $data = [
            'user'       => $this->publicUser($user),
            'csrf_token' => $this->authMiddleware->getCSRFToken(),
        ];

        // JWT only for the native app (client:'native'); the web relies on the session cookie alone.
        if (($inputData['client'] ?? null) === 'native') {
            $data['jwt_token'] = $this->jwtService->generate([
                'user_id' => $user->getId(),
                'email'   => $user->getEmail()->toString(),
                'name'    => $user->getName(),
                'picture' => $user->getPicture(),
                'tv'      => $user->getTokenVersion(),
            ]);
        }

        return $this->successResponse('Login successful.', $data);
    }

    private function publicUser(User $user): array
    {
        return $user->toPublicArray() + ['is_admin' => AdminPolicy::isAdmin($user)];
    }

    public function logout(?int $userId = null): array
    {
        // Bump token_version first: revokes every JWT and session of this user (all devices).
        if ($userId) {
            $this->userRepository->incrementTokenVersion($userId);
        }
        $this->sessionManager->logout();
        return $this->successResponse('Logout successful.');
    }

    public function checkAuth(): array
    {
        $authResult = $this->authMiddleware->requireAuth();
        if ($authResult['status'] === 'error') {
            return $authResult;
        } else {
            return $this->successResponse('User is authenticated.', [
                'user' => $authResult['user'],
                'csrf_token' => $this->authMiddleware->getCSRFToken()
            ]);
        }
    }

    /**
     * Cheap authenticated endpoint: confirms the request's user_id (resolved by
     * AuthenticationMiddleware from session OR JWT). Used by the frontend to validate a
     * stored JWT on startup without depending on any domain action.
     */
    public function me(?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            return $this->errorResponse('Authentication required.', 401);
        }
        return $this->successResponse('Authenticated', ['user' => $this->publicUser($user)]);
    }

    public function updateProfile(array $inputData, ?int $userId): array
    {
        // The id ALWAYS comes from the auth middleware; any userId/user_id in the body is ignored.
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }

        try {
            $command = UpdateUserProfileCommand::fromArray($inputData, (int) $userId);
            $user = $this->updateUserProfileUseCase->execute($command);

            return $this->successResponse('Profile updated successfully.', [
                'user' => $this->publicUser($user)
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    public function logFrontend(array $logData): array
    {
        if (empty($logData)) {
            return $this->errorResponse('Invalid log data format.');
        }

        // Tope por entrada: message ≤ 1000 chars, contexto ≤ 2 KB (se trunca, no se rechaza)
        $logData = $this->capLogEntry($logData);
        
        try {
            // Preserve complete frontend log data
            $context = [
                'frontend_data' => $logData, // Guardar todo el log original del frontend
                'source' => $logData['source'] ?? 'frontend',
                'url' => $logData['url'] ?? 'unknown',
                'userAgent' => $logData['userAgent'] ?? 'unknown',
                'timestamp' => $logData['timestamp'] ?? date('c'),
                'original_message' => $logData['message'] ?? 'Frontend log entry',
                'frontend_level' => $logData['level'] ?? 'info',
                'args' => $logData['data']['args'] ?? [],
                'additional_data' => $logData['data'] ?? []
            ];
            
            // Add user context if authenticated
            if ($this->sessionManager->isLoggedIn()) {
                $context['user_id'] = $this->sessionManager->getCurrentUserId();
                $context['user_email'] = $this->sessionManager->getCurrentUser()['email'] ?? 'unknown';
            }
            
            $message = $logData['message'] ?? 'Frontend log entry';
            $level = $logData['level'] ?? 'info';
            
            // Log to appropriate channel based on level and content
            switch ($level) {
                case 'error':
                    logger('frontend')->error($message, $context);
                    break;
                case 'warn':
                    logger('frontend')->warning($message, $context);
                    break;
                case 'auth':
                    logger('auth')->info($message, $context);
                    break;
                default:
                    logger('frontend')->info($message, $context);
                    break;
            }
            
            return $this->successResponse('Log entry recorded.');
            
        } catch (\Exception $e) {
            // Don't let logging failures break the app
            error_log('frontend_logging_error: ' . $e->getMessage());

            return $this->errorResponse('Failed to record log entry.', 500);
        }
    }

    /**
     * Handle batched frontend log entries.
     * Accepts an array of log entries and processes them in one request.
     *
     * @param array $logs Array of log entry objects
     * @return array Success or error response
     */
    public function logFrontendBatch(array $logs): array
    {
        if (empty($logs)) {
            return $this->errorResponse('No log entries provided.');
        }

        if (count($logs) > self::MAX_BATCH_LOGS) {
            return $this->errorResponse('Too many log entries (max ' . self::MAX_BATCH_LOGS . ').', 400);
        }

        $count = 0;
        foreach ($logs as $logData) {
            if (!is_array($logData) || empty($logData)) {
                continue;
            }
            // Re-use the single-entry logic
            $this->logFrontend($logData);
            $count++;
        }

        return $this->successResponse("Recorded {$count} log entries.");
    }

    /** Recorta message y el resto del contexto de una entrada de log del frontend. */
    private function capLogEntry(array $entry): array
    {
        if (isset($entry['message'])) {
            $entry['message'] = is_scalar($entry['message'])
                ? mb_substr((string) $entry['message'], 0, self::MAX_LOG_MESSAGE)
                : 'Frontend log entry';
        }
        $context = $entry;
        unset($context['message']);
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || strlen($json) > self::MAX_LOG_CONTEXT_BYTES) {
            $entry = [
                'message' => $entry['message'] ?? 'Frontend log entry',
                'level' => is_string($entry['level'] ?? null) ? $entry['level'] : 'info',
                'truncated_context' => mb_strcut((string) $json, 0, self::MAX_LOG_CONTEXT_BYTES),
            ];
        }
        return $entry;
    }
}
