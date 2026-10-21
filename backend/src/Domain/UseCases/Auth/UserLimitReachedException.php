<?php
declare(strict_types=1);

namespace App\Domain\UseCases\Auth;

/**
 * Thrown by LoginUserUseCase when a NEW Google account tries to register and the app already has
 * MAX_USERS users. Existing users are never affected.
 */
class UserLimitReachedException extends \RuntimeException
{
}
