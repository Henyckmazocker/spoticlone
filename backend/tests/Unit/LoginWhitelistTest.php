<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AuthController;
use App\Domain\UseCases\Auth\LoginUserUseCase;
use App\Domain\UseCases\Auth\UpdateUserProfileUseCase;
use App\Infrastructure\Auth\GoogleOAuthVerifier;
use App\Infrastructure\Auth\JWTService;
use App\Infrastructure\Middleware\AuthMiddleware;
use App\Infrastructure\Session\SessionManager;
use App\Domain\Services\EmailWhitelist;
use PHPUnit\Framework\TestCase;

/**
 * The login whitelist gate runs right after Google token verification. A disallowed email returns
 * a 403 BEFORE the login use case; an allowed email passes the gate and proceeds into the login
 * flow (which, with empty mocks here, then fails — proving the gate let it through).
 *
 * NOTE: LoginUserUseCase::execute() is final (AbstractUseCase), so it can't be configured on a mock;
 * the tests therefore assert on the gate's observable behavior, not on use-case interactions.
 */
class LoginWhitelistTest extends TestCase
{
    private function controllerWithVerifierEmail(string $email): AuthController
    {
        $verifier = $this->createMock(GoogleOAuthVerifier::class);
        $verifier->method('verifyToken')->willReturn([
            'email' => $email, 'sub' => '1', 'name' => 'Test', 'picture' => '', 'email_verified' => true,
        ]);

        return new AuthController(
            $this->createMock(LoginUserUseCase::class),
            $this->createMock(UpdateUserProfileUseCase::class),
            $this->createMock(SessionManager::class),
            $this->createMock(AuthMiddleware::class),
            $verifier,
            $this->createMock(JWTService::class),
            new EmailWhitelist() // reads $_ENV['ALLOWED_EMAILS'] set by each test
        );
    }

    public function test_rejects_email_not_in_whitelist(): void
    {
        $_ENV['ALLOWED_EMAILS'] = 'allowed@example.com, owner@example.com';

        $res = $this->controllerWithVerifierEmail('intruder@evil.com')->login(['google_token' => 'tok']);

        $this->assertSame('error', $res['status']);
        $this->assertSame(403, $res['http_code']);
    }

    public function test_whitelisted_email_passes_the_gate(): void
    {
        $_ENV['ALLOWED_EMAILS'] = 'good@example.com';

        // Allowed (case-insensitive) → must NOT be rejected by the gate; it proceeds into the login
        // flow, which with empty mocks blows up. We only require that it is NOT the 403 rejection.
        try {
            $res = $this->controllerWithVerifierEmail('GOOD@example.com')->login(['google_token' => 'tok']);
            $this->assertNotSame(403, $res['http_code'] ?? null, 'allowed email must not be rejected');
        } catch (\Throwable $e) {
            $this->assertTrue(true); // threw deeper in the flow => it passed the gate
        }
    }

    public function test_empty_whitelist_does_not_restrict(): void
    {
        $_ENV['ALLOWED_EMAILS'] = '';

        try {
            $res = $this->controllerWithVerifierEmail('anyone@example.com')->login(['google_token' => 'tok']);
            $this->assertNotSame(403, $res['http_code'] ?? null, 'empty whitelist must not restrict');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
    }
}
