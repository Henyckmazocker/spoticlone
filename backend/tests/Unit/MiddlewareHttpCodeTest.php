<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Middleware\CSRFMiddleware;
use App\Infrastructure\Middleware\ValidationMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MiddlewareHttpCodeTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_validation_missing_field_returns_http_code_400(): void
    {
        $middleware = new ValidationMiddleware(new NullLogger(), ['trackId']);
        $next = function (array $request): array {
            $this->fail('next() must not be called when a required field is missing');
        };

        $result = $middleware->handle(['action' => 'toggle_favorite', 'data' => []], $next);

        $this->assertSame('error', $result['status']);
        $this->assertSame(400, $result['http_code']);
        $this->assertArrayNotHasKey('code', $result);
    }

    public function test_invalid_csrf_returns_http_code_403(): void
    {
        $_SESSION['csrf_token'] = 'real-token';
        $middleware = new CSRFMiddleware(new NullLogger());
        $next = function (array $request): array {
            $this->fail('next() must not be called with an invalid CSRF token');
        };

        $result = $middleware->handle([
            'action'      => 'toggle_favorite',
            'auth_method' => 'session',
            'csrf_token'  => 'fake-token',
        ], $next);

        $this->assertSame('error', $result['status']);
        $this->assertSame(403, $result['http_code']);
        $this->assertArrayNotHasKey('code', $result);
    }
}
