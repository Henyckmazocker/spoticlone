<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Issues and verifies short-lived signed tokens for binary endpoints (stream.php, cover.php).
 *
 * The native <audio> element / Capacitor webview cannot attach an Authorization header, so the
 * access grant travels in the URL query string instead. The token is a compact HMAC-signed blob
 * (no DB state): "<base64url(payload)>.<base64url(hmac)>". Payload = {k:kind, id, uid, exp}.
 *
 * Signed with a key derived from JWT_SECRET (HMAC of a fixed context label), so it never signs
 * with the raw secret that JWTService uses. NOT a JWT (kept tiny on purpose).
 */
class SignedUrlService
{
    private string $secret;
    private string $key;
    private int $defaultTtl;
    private string $publicBaseUrl;
    private int $externalMaxTtl;

    public function __construct()
    {
        $this->secret = $_ENV['JWT_SECRET'] ?? '';
        SecretPolicy::assertStrong($this->secret);
        // Domain-separated key: a JWT_SECRET-signed JWT/blob can never double as a URL signature.
        $this->key = hash_hmac('sha256', 'spoticlone:signed-url:v1', $this->secret, true);
        $this->defaultTtl = (int) ($_ENV['STREAM_URL_TTL'] ?? 3600);
        $this->publicBaseUrl = rtrim((string) ($_ENV['PUBLIC_BASE_URL'] ?? ''), '/');
        $maxTtl = (int) ($_ENV['EXTERNAL_URL_MAX_TTL'] ?? 86400);
        $this->externalMaxTtl = $maxTtl > 0 ? $maxTtl : 86400;
    }

    /**
     * @param string $kind 'stream' | 'cover' | 'ext'
     * @param int    $id   track id (stream) or album id (cover)
     */
    public function sign(string $kind, int $id, int $userId, ?int $ttl = null): string
    {
        $payload = [
            'k'   => $kind,
            'id'  => $id,
            'uid' => $userId,
            'exp' => time() + ($ttl ?? $this->defaultTtl),
        ];
        $body = $this->b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig  = $this->b64($this->hmac($body));
        return $body . '.' . $sig;
    }

    /**
     * Verify a token. Returns the payload (k, id, uid, exp) or null if invalid/expired/tampered.
     *
     * @return array{k:string,id:int,uid:int,exp:int}|null
     */
    public function verify(string $token): ?array
    {
        if ($this->secret === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;

        $expected = $this->b64($this->hmac($body));
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $payload = json_decode($this->unb64($body), true);
        if (!is_array($payload) || !isset($payload['exp'], $payload['id'], $payload['k'])) {
            return null;
        }
        if (time() > (int) $payload['exp']) {
            return null;
        }
        return [
            'k'   => (string) $payload['k'],
            'id'  => (int) $payload['id'],
            'uid' => (int) ($payload['uid'] ?? 0),
            'exp' => (int) $payload['exp'],
        ];
    }

    /** Relative URL the frontend assigns to <audio> / <img>. */
    public function streamUrl(int $trackId, int $userId): string
    {
        return '/stream.php?token=' . rawurlencode($this->sign('stream', $trackId, $userId));
    }

    public function coverUrl(int $albumId, int $userId): string
    {
        return '/cover.php?token=' . rawurlencode($this->sign('cover', $albumId, $userId));
    }

    /**
     * Absolute stream URL for a third party (kind 'ext'), served by the same stream.php.
     * The caller is expected to pass a TTL already clamped with clampExternalTtl().
     *
     * @throws \RuntimeException if PUBLIC_BASE_URL is not configured
     */
    public function externalUrl(int $trackId, int $userId, int $ttl): string
    {
        if (!$this->hasPublicBaseUrl()) {
            throw new \RuntimeException('PUBLIC_BASE_URL is not configured.');
        }
        return $this->publicBaseUrl . '/stream.php?token='
            . rawurlencode($this->sign('ext', $trackId, $userId, $ttl));
    }

    /** null or <= 0 -> max; above max -> max (silently clamped). */
    public function clampExternalTtl(?int $requested): int
    {
        if ($requested === null || $requested <= 0 || $requested > $this->externalMaxTtl) {
            return $this->externalMaxTtl;
        }
        return $requested;
    }

    public function hasPublicBaseUrl(): bool
    {
        return $this->publicBaseUrl !== '';
    }

    private function hmac(string $body): string
    {
        return hash_hmac('sha256', $body, $this->key, true);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function unb64(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/')) ?: '';
    }
}
