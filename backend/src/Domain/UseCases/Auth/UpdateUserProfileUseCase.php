<?php
declare(strict_types=1);

namespace App\Domain\UseCases\Auth;

use App\Domain\DTO\Commands\UpdateUserProfileCommand;
use App\Domain\Model\User;
use App\Domain\Repository\User\UserRepositoryInterface;
use App\Domain\UseCases\AbstractUseCase;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;

class UpdateUserProfileUseCase extends AbstractUseCase
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    protected function getLogContext(): string
    {
        return 'UpdateUserProfile';
    }

    protected function doExecute(mixed ...$args): User
    {
        $command = $args[0] ?? null;

        if (!$command instanceof UpdateUserProfileCommand) {
            throw new InvalidArgumentException('Command must be an instance of UpdateUserProfileCommand');
        }

        $user = $this->userRepository->findById($command->userId);
        if (!$user) {
            throw new InvalidArgumentException("User with ID {$command->userId} not found");
        }

        $updated = false;

        if ($command->lastfmUsername !== null) {
            $user->setLastFmUsername($command->lastfmUsername ?: null);
            $updated = true;
        }

        if ($command->name !== null) {
            $user->setName($command->name);
            $updated = true;
        }

        if (!$updated) {
            return $user;
        }

        return $this->userRepository->update($user);
    }
}
