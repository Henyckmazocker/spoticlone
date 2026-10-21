<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Auth\SignedUrlService;
use PHPUnit\Framework\TestCase;

class SignedUrlServiceTest extends TestCase
{
    private ?string $appEnvBackup = null;

    protected function setUp(): void
    {
        $_ENV['JWT_SECRET'] = 'test-secret-at-least-32-characters-long!!';
        $_ENV['STREAM_URL_TTL'] = '3600';
        $this->appEnvBackup = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'production';
    }

    protected function tearDown(): void
    {
        if ($this->appEnvBackup === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->appEnvBackup;
        }
        // Don't leak the external-URL settings into other tests.
        unset($_ENV['PUBLIC_BASE_URL'], $_ENV['EXTERNAL_URL_MAX_TTL']);
    }

    public function test_sign_then_verify_round_trips(): void
    {
        $svc = new SignedUrlService();
        $token = $svc->sign('stream', 42, 7);
        $payload = $svc->verify($token);

        $this->assertNotNull($payload);
        $this->assertSame('stream', $payload['k']);
        $this->assertSame(42, $payload['id']);
        $this->assertSame(7, $payload['uid']);
    }

    public function test_tampered_token_is_rejected(): void
    {
        $svc = new SignedUrlService();
        $token = $svc->sign('stream', 42, 7);
        $tampered = $token . 'x';

        $this->assertNull($svc->verify($tampered));
    }

    public function test_expired_token_is_rejected(): void
    {
        $svc = new SignedUrlService();
        $token = $svc->sign('stream', 1, 1, -10); // already expired

        $this->assertNull($svc->verify($token));
    }

    public function test_wrong_secret_does_not_verify(): void
    {
        $svc = new SignedUrlService();
        $token = $svc->sign('cover', 5, 3);

        $_ENV['JWT_SECRET'] = 'a-completely-different-secret-32-characters';
        $other = new SignedUrlService();

        $this->assertNull($other->verify($token));
    }

    public function test_stream_url_is_relative_and_carries_token(): void
    {
        $svc = new SignedUrlService();
        $url = $svc->streamUrl(99, 1);

        $this->assertStringStartsWith('/stream.php?token=', $url);
    }

    public function test_external_url_is_absolute_and_kind_ext(): void
    {
        $_ENV['PUBLIC_BASE_URL'] = 'https://x.test/';
        $svc = new SignedUrlService();
        $url = $svc->externalUrl(12, 3, 60);

        $this->assertStringStartsWith('https://x.test/stream.php?token=', $url);

        $query = parse_url($url, PHP_URL_QUERY);
        parse_str((string) $query, $params);
        $payload = $svc->verify((string) $params['token']);

        $this->assertNotNull($payload);
        $this->assertSame('ext', $payload['k']);
        $this->assertSame(12, $payload['id']);
        $this->assertSame(3, $payload['uid']);
    }

    public function test_external_ttl_is_clamped(): void
    {
        $_ENV['EXTERNAL_URL_MAX_TTL'] = '100';
        $svc = new SignedUrlService();

        $this->assertSame(100, $svc->clampExternalTtl(null));
        $this->assertSame(100, $svc->clampExternalTtl(0));
        $this->assertSame(100, $svc->clampExternalTtl(999));
        $this->assertSame(50, $svc->clampExternalTtl(50));
    }

    public function test_external_url_without_base_url_throws(): void
    {
        $_ENV['PUBLIC_BASE_URL'] = '';
        $svc = new SignedUrlService();

        $this->assertFalse($svc->hasPublicBaseUrl());
        $this->expectException(\RuntimeException::class);
        $svc->externalUrl(1, 1, 60);
    }

    public function test_short_secret_in_production_throws(): void
    {
        $_ENV['JWT_SECRET'] = 'too-short';

        $this->expectException(\RuntimeException::class);
        new SignedUrlService();
    }

    public function test_empty_secret_outside_production_fails_closed(): void
    {
        $_ENV['JWT_SECRET'] = '';
        $_ENV['APP_ENV'] = 'development';
        $svc = new SignedUrlService();

        $this->assertNull($svc->verify($svc->sign('stream', 1, 1)));
    }

    public function test_token_signed_with_raw_secret_is_rejected(): void
    {
        // Old scheme: HMAC keyed directly with JWT_SECRET (no derived key).
        $body = rtrim(strtr(base64_encode(json_encode([
            'k' => 'stream', 'id' => 1, 'uid' => 1, 'exp' => time() + 60,
        ])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $body, $_ENV['JWT_SECRET'], true)
        ), '+/', '-_'), '=');

        $this->assertNull((new SignedUrlService())->verify($body . '.' . $sig));
    }
}
