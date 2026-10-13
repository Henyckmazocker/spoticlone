<?php
declare(strict_types=1);

namespace App\Domain\UseCases\Auth;

use App\Domain\Repository\User\UserRepositoryInterface;
use App\Domain\Model\User;
use App\Domain\Model\ValueObjects\Timestamp;
use App\Domain\UseCases\AbstractUseCase;
use App\Domain\DTO\Commands\LoginUserCommand;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;

class LoginUserUseCase extends AbstractUseCase
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * Execute with LoginUserCommand
     * Login or register user with Google OAuth data
     */
    protected function doExecute($command): User
    {
        // Validate command
        if (!$command instanceof LoginUserCommand) {
            throw new InvalidArgumentException('Command must be an instance of LoginUserCommand');
        }

        // Try to find existing user
        $existingUser = $this->userRepository->findByGoogleId($command->googleId);
        
        if ($existingUser) {
            // Update last login and any changed data
            $existingUser->updateLastLogin();
            
            if ($existingUser->getEmail()->toString() !== $command->email->toString()) {
                $existingUser->setEmail($command->email);
            }
            
            if ($existingUser->getName() !== $command->name) {
                $existingUser->setName($command->name);
            }
            
            if ($command->picture !== null && $existingUser->getPicture() !== $command->picture) {
                $existingUser->setPicture($command->picture);
            }
            
            $this->userRepository->update($existingUser);
            
            $this->logAuthEvent('login', (string)$existingUser->getId());
            
            return $existingUser;
        }

        // Create new user
        $newUser = User::registerWithGoogle(
            $command->googleId,
            $command->email,
            $command->name,
            $command->picture
        );

        $savedUser = $this->userRepository->save($newUser);
        
        $this->logAuthEvent('register', (string)$savedUser->getId());
        
        return $savedUser;
    }

    /**
     * Hook for logging successful execution with auth context
     */
    protected function logSuccess($command, $result): void
    {
        parent::logSuccess($command, $result);
        
        // Additional auth-specific success logging handled in logAuthEvent
    }

    /**
     * Helper to log authentication events
     */
    private function logAuthEvent(string $action, string $userId): void
    {
        if (function_exists('logger')) {
            try {
                logger('auth')->auth($action, $userId, true, [
                    'login_type' => 'google_oauth'
                ]);
            } catch (\Throwable $e) {
                error_log("Auth logging error: " . $e->getMessage());
            }
        }
    }

    /**
     * Get log context for this use case
     */
    protected function getLogContext(): string
    {
        return 'LoginUserUseCase';
    }
}
