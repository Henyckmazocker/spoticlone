<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Auth\JWTService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

class JWTServiceTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (['JWT_SECRET', 'APP_ENV'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
        }
        $_ENV['JWT_SECRET'] = 'test-secret-at-least-32-characters-long!!';
        $_ENV['APP_ENV'] = 'production';
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    public function test_generate_then_validate_round_trips(): void
    {
        $svc = new JWTService();
        $payload = $svc->validate($svc->generate(['user_id' => 5]));

        $this->assertNotNull($payload);
        $this->assertSame(5, $payload['user_id']);
    }

    public function test_short_secret_in_production_throws(): void
    {
        $_ENV['JWT_SECRET'] = 'too-short';

        $this->expectException(\RuntimeException::class);
        new JWTService();
    }

    public function test_placeholder_secret_in_production_throws(): void
    {
        $_ENV['JWT_SECRET'] = 'CHANGE_ME';

        $this->expectException(\RuntimeException::class);
        new JWTService();
    }

    public function test_short_secret_in_development_does_not_throw(): void
    {
        $_ENV['JWT_SECRET'] = 'too-short';
        $_ENV['APP_ENV'] = 'development';

        $this->assertInstanceOf(JWTService::class, new JWTService());
    }

    public function test_token_signed_with_other_secret_is_rejected(): void
    {
        $forged = JWT::encode(
            ['user_id' => 1, 'exp' => time() + 60],
            'a-completely-different-secret-32-characters',
            'HS256'
        );

        $this->assertNull((new JWTService())->validate($forged));
    }
}
