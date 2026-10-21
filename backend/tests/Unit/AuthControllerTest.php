<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AuthController;
use App\Domain\Model\User;
use App\Domain\Model\ValueObjects\Email;
use App\Domain\Model\ValueObjects\GoogleId;
use App\Domain\Repository\User\UserRepositoryInterface;
use App\Domain\UseCases\Auth\LoginUserUseCase;
use App\Domain\UseCases\Auth\UpdateUserProfileUseCase;
use App\Infrastructure\Auth\GoogleOAuthVerifier;
use App\Infrastructure\Auth\JWTService;
use App\Infrastructure\Middleware\AuthMiddleware;
use App\Infrastructure\Session\SessionManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AuthControllerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $repo;
    private LoginUserUseCase&MockObject $loginUseCase; // execute() is final: stub doExecute()
    private GoogleOAuthVerifier&MockObject $verifier;
    private JWTService&MockObject $jwt;
    private AuthMiddleware&MockObject $authMw;
    private AuthController $controller;

    protected function setUp(): void
    {
        $_ENV['ADMIN_EMAILS'] = 'admin@example.com';
        $this->repo = $this->createMock(UserRepositoryInterface::class);
        $this->loginUseCase = $this->getMockBuilder(LoginUserUseCase::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['doExecute', 'logSuccess'])
            ->getMock();
        $prop = new \ReflectionProperty(\App\Domain\UseCases\AbstractUseCase::class, 'logger');
        $prop->setAccessible(true);
        $prop->setValue($this->loginUseCase, new NullLogger());
        $this->verifier = $this->createMock(GoogleOAuthVerifier::class);
        $this->jwt = $this->createMock(JWTService::class);
        $this->authMw = $this->createMock(AuthMiddleware::class);
        $this->controller = new AuthController(
            $this->loginUseCase,
            new UpdateUserProfileUseCase($this->repo, new NullLogger()),
            $this->createMock(SessionManager::class),
            $this->authMw,
            $this->verifier,
            $this->jwt,
            $this->repo
        );
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_EMAILS']);
    }

    private function user(int $id, string $email, string $name): User
    {
        return new User($id, GoogleId::fromString('123456789012345678901'), Email::fromString($email), $name);
    }

    public function test_update_profile_ignores_foreign_user_id_in_body(): void
    {
        $own = $this->user(1, 'me@example.com', 'Me');
        $this->repo->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn($own);
        $this->repo->expects($this->once())->method('update')->willReturnArgument(0);

        $res = $this->controller->updateProfile(['userId' => 2, 'user_id' => 2, 'name' => 'x'], 1);

        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['data']['user']['id']);
        $this->assertSame('x', $res['data']['user']['name']);
        $this->assertArrayNotHasKey('google_id', $res['data']['user']);
        $this->assertFalse($res['data']['user']['is_admin']);
    }

    public function test_update_profile_requires_authenticated_user(): void
    {
        $this->repo->expects($this->never())->method('update');
        $res = $this->controller->updateProfile(['userId' => 2, 'name' => 'x'], null);
        $this->assertSame('error', $res['status']);
        $this->assertSame(401, $res['http_code']);
    }

    public function test_me_returns_public_user_without_google_id(): void
    {
        $this->repo->method('findById')->with(5)->willReturn($this->user(5, 'ADMIN@example.com', 'Boss'));

        $res = $this->controller->me(5);

        $this->assertSame('success', $res['status']);
        $this->assertSame(
            ['id', 'email', 'name', 'picture', 'lastfm_username', 'username', 'is_admin'],
            array_keys($res['data']['user'])
        );
        $this->assertTrue($res['data']['user']['is_admin']);
        $this->assertStringNotContainsString('google_id', json_encode($res));
    }

    public function test_to_public_array_has_no_sensitive_fields(): void
    {
        $arr = $this->user(1, 'me@example.com', 'Me')->toPublicArray();
        foreach (['google_id', 'preferences', 'last_login', 'created_at', 'updated_at'] as $k) {
            $this->assertArrayNotHasKey($k, $arr);
        }
    }

    public function test_logout_increments_token_version_and_destroys_session(): void
    {
        $this->repo->expects($this->once())->method('incrementTokenVersion')->with(4);
        $res = $this->controller->logout(4);
        $this->assertSame('success', $res['status']);
    }

    private function stubLogin(): void
    {
        $this->verifier->method('verifyToken')->willReturn(
            ['sub' => '123456789012345678901', 'email' => 'me@example.com', 'name' => 'Me']
        );
        $this->loginUseCase->method('doExecute')->willReturn($this->user(1, 'me@example.com', 'Me'));
        $this->authMw->method('getCSRFToken')->willReturn('csrf');
    }

    public function test_login_without_client_does_not_issue_jwt(): void
    {
        $this->stubLogin();
        $this->jwt->expects($this->never())->method('generate');

        $res = $this->controller->login(['google_token' => 'g']);

        $this->assertSame('success', $res['status']);
        $this->assertArrayNotHasKey('jwt_token', $res['data']);
        $this->assertSame('csrf', $res['data']['csrf_token']);
    }

    public function test_login_with_other_client_does_not_issue_jwt(): void
    {
        $this->stubLogin();
        $this->jwt->expects($this->never())->method('generate');

        $res = $this->controller->login(['google_token' => 'g', 'client' => 'web']);

        $this->assertArrayNotHasKey('jwt_token', $res['data']);
    }

    public function test_login_with_native_client_issues_jwt(): void
    {
        $this->stubLogin();
        $this->jwt->expects($this->once())->method('generate')
            ->with($this->callback(fn ($p) => $p['user_id'] === 1 && $p['tv'] === 0))
            ->willReturn('jwt.value');

        $res = $this->controller->login(['google_token' => 'g', 'client' => 'native']);

        $this->assertSame('jwt.value', $res['data']['jwt_token']);
    }
}
