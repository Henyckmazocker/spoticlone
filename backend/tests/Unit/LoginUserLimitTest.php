<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\DTO\Commands\LoginUserCommand;
use App\Domain\Model\User;
use App\Domain\Repository\User\UserRepositoryInterface;
use App\Domain\UseCases\Auth\LoginUserUseCase;
use App\Domain\UseCases\Auth\UserLimitReachedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * MAX_USERS caps registration only: a new Google account is rejected once the cap is reached,
 * while existing users keep logging in.
 */
class LoginUserLimitTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['MAX_USERS']);
    }

    private function command(): LoginUserCommand
    {
        return LoginUserCommand::fromGoogleToken([
            'sub' => '123456789012345678901', 'email' => 'new@example.com', 'name' => 'New', 'picture' => null,
        ]);
    }

    public function test_rejects_new_user_when_limit_reached(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByGoogleId')->willReturn(null);
        $repo->method('count')->willReturn(10);
        $repo->method('withRegistrationLock')->willReturnCallback(fn(callable $fn) => $fn());
        $repo->expects($this->never())->method('save');

        $this->expectException(UserLimitReachedException::class);
        (new LoginUserUseCase($repo, new NullLogger()))->execute($this->command());
    }

    public function test_registers_new_user_below_limit(): void
    {
        $_ENV['MAX_USERS'] = '3';
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByGoogleId')->willReturn(null);
        $repo->method('count')->willReturn(2);
        $repo->method('withRegistrationLock')->willReturnCallback(fn(callable $fn) => $fn());
        $repo->expects($this->once())->method('save')->willReturnArgument(0);

        $user = (new LoginUserUseCase($repo, new NullLogger()))->execute($this->command());
        $this->assertSame('new@example.com', $user->getEmail()->toString());
    }

    public function test_existing_user_logs_in_even_over_limit(): void
    {
        $existing = User::registerWithGoogle(
            $this->command()->googleId, $this->command()->email, 'Old', null
        );
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByGoogleId')->willReturn($existing);
        $repo->method('count')->willReturn(50);
        $repo->expects($this->never())->method('save');
        $repo->expects($this->once())->method('update')->willReturnArgument(0);

        $user = (new LoginUserUseCase($repo, new NullLogger()))->execute($this->command());
        $this->assertSame($existing, $user);
    }

    public function test_zero_disables_the_limit(): void
    {
        $_ENV['MAX_USERS'] = '0';
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByGoogleId')->willReturn(null);
        $repo->method('count')->willReturn(1000);
        $repo->method('withRegistrationLock')->willReturnCallback(fn(callable $fn) => $fn());
        $repo->expects($this->once())->method('save')->willReturnArgument(0);

        (new LoginUserUseCase($repo, new NullLogger()))->execute($this->command());
    }

    public function test_count_and_save_run_inside_the_registration_lock(): void
    {
        $_ENV['MAX_USERS'] = '3';
        $events = [];
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByGoogleId')->willReturn(null);
        $repo->method('withRegistrationLock')->willReturnCallback(function (callable $fn) use (&$events) {
            $events[] = 'lock';
            try {
                return $fn();
            } finally {
                $events[] = 'unlock';
            }
        });
        $repo->method('count')->willReturnCallback(function () use (&$events) {
            $events[] = 'count';
            return 0;
        });
        $repo->method('save')->willReturnCallback(function (User $u) use (&$events) {
            $events[] = 'save';
            return $u;
        });

        (new LoginUserUseCase($repo, new NullLogger()))->execute($this->command());
        $this->assertSame(['lock', 'count', 'save', 'unlock'], $events);
    }

    public function test_registration_aborts_when_the_lock_is_not_obtained(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByGoogleId')->willReturn(null);
        $repo->method('withRegistrationLock')->willThrowException(new \RuntimeException('no lock'));
        $repo->expects($this->never())->method('save');

        $this->expectException(\RuntimeException::class);
        (new LoginUserUseCase($repo, new NullLogger()))->execute($this->command());
    }
}
