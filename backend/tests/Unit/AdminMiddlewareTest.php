<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Model\User;
use App\Domain\Model\ValueObjects\Email;
use App\Domain\Model\ValueObjects\GoogleId;
use App\Infrastructure\Middleware\AdminMiddleware;
use PHPUnit\Framework\TestCase;

class AdminMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_ENV['ADMIN_EMAILS']);
        putenv('ADMIN_EMAILS');
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_EMAILS']);
        putenv('ADMIN_EMAILS');
    }

    private function user(string $email): User
    {
        return User::registerWithGoogle(
            GoogleId::fromString('123456789012345678901'),
            Email::fromString($email),
            'Test'
        );
    }

    private function pass(?User $user): array
    {
        $request = ['action' => 'scan_library'];
        if ($user !== null) {
            $request['user'] = $user;
        }
        return (new AdminMiddleware())->handle($request, fn (array $r): array => ['status' => 'success', 'passed' => true]);
    }

    public function test_non_admin_gets_403(): void
    {
        $_ENV['ADMIN_EMAILS'] = 'boss@example.com';
        $result = $this->pass($this->user('someone@example.com'));
        $this->assertSame('error', $result['status']);
        $this->assertSame(403, $result['http_code']);
    }

    public function test_admin_passes(): void
    {
        $_ENV['ADMIN_EMAILS'] = 'boss@example.com';
        $this->assertTrue($this->pass($this->user('boss@example.com'))['passed']);
    }

    public function test_empty_admin_emails_means_nobody_is_admin(): void
    {
        $_ENV['ADMIN_EMAILS'] = '';
        $this->assertSame(403, $this->pass($this->user('boss@example.com'))['http_code']);
    }

    public function test_unset_admin_emails_means_nobody_is_admin(): void
    {
        $this->assertSame(403, $this->pass($this->user('boss@example.com'))['http_code']);
    }

    public function test_case_and_spaces_are_ignored(): void
    {
        $_ENV['ADMIN_EMAILS'] = ' Other@Example.com ,  BOSS@Example.COM  ';
        $this->assertTrue($this->pass($this->user('boss@example.com'))['passed']);
    }

    public function test_missing_user_gets_403(): void
    {
        $_ENV['ADMIN_EMAILS'] = 'boss@example.com';
        $this->assertSame(403, $this->pass(null)['http_code']);
    }
}
