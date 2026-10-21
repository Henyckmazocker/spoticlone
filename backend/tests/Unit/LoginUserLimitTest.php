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
        $repo->expects($this->once())->method('save')->willReturnArgument(0);

        (new LoginUserUseCase($repo, new NullLogger()))->execute($this->command());
    }
}
