<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Model\User;
use App\Domain\Model\ValueObjects\Email;
use App\Domain\Model\ValueObjects\GoogleId;
use App\Domain\Repository\User\UserRepositoryInterface;
use App\Infrastructure\Auth\JWTService;
use App\Infrastructure\Middleware\AuthenticationMiddleware;
use App\Infrastructure\Session\SessionManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AuthenticationMiddlewareTest extends TestCase
{
    private UserRepositoryInterface&MockObject $repo;
    private JWTService&MockObject $jwt;
    private SessionManager&MockObject $session;
    private AuthenticationMiddleware $middleware;

    protected function setUp(): void
    {
        $_SESSION = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->repo = $this->createMock(UserRepositoryInterface::class);
        $this->jwt = $this->createMock(JWTService::class);
        $this->session = $this->createMock(SessionManager::class);
        $this->middleware = new AuthenticationMiddleware(new NullLogger(), $this->jwt, $this->repo, $this->session);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    private function user(bool $active = true, int $tv = 3): User
    {
        return new User(
            7,
            GoogleId::fromString('123456789012345678901'),
            Email::fromString('u@example.com'),
            'U',
            null, null, null, null, null,
            $active,
            null,
            null,
            $tv
        );
    }

    private function go(): array
    {
        return $this->middleware->handle(['action' => 'get_artists'], fn (array $r): array => ['status' => 'success', 'request' => $r]);
    }

    private function withJwt(array $payload): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $this->jwt->method('validate')->willReturn($payload);
    }

    public function test_jwt_with_current_tv_passes(): void
    {
        $this->withJwt(['user_id' => 7, 'tv' => 3]);
        $this->repo->method('findById')->with(7)->willReturn($this->user());
        $res = $this->go();
        $this->assertSame('success', $res['status']);
        $this->assertSame(7, $res['request']['user_id']);
    }

    public function test_jwt_with_old_tv_returns_401(): void
    {
        $this->withJwt(['user_id' => 7, 'tv' => 2]);
        $this->repo->method('findById')->willReturn($this->user());
        $res = $this->go();
        $this->assertSame(401, $res['http_code']);
    }

    public function test_jwt_without_tv_returns_401(): void
    {
        $this->withJwt(['user_id' => 7]);
        $this->repo->method('findById')->willReturn($this->user());
        $this->assertSame(401, $this->go()['http_code']);
    }

    public function test_inactive_user_returns_401(): void
    {
        $this->withJwt(['user_id' => 7, 'tv' => 3]);
        $this->repo->method('findById')->willReturn($this->user(false));
        $this->assertSame(401, $this->go()['http_code']);
    }

    public function test_session_with_old_tv_returns_401_and_destroys_session(): void
    {
        $_SESSION['user_data'] = ['id' => 7, 'tv' => 1];
        $this->repo->method('findById')->willReturn($this->user());
        $this->session->expects($this->once())->method('logout');
        $this->assertSame(401, $this->go()['http_code']);
    }

    public function test_session_with_current_tv_passes(): void
    {
        $_SESSION['user_data'] = ['id' => 7, 'tv' => 3];
        $this->repo->method('findById')->willReturn($this->user());
        $this->session->expects($this->never())->method('logout');
        $this->assertSame('success', $this->go()['status']);
    }

    public function test_session_without_tv_returns_401(): void
    {
        $_SESSION['user_data'] = ['id' => 7];
        $this->repo->method('findById')->willReturn($this->user());
        $this->session->expects($this->once())->method('logout');
        $this->assertSame(401, $this->go()['http_code']);
    }
}
