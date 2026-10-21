<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Single rule for how strong JWT_SECRET must be. Shared by JWTService and SignedUrlService
 * (stream.php / cover.php never instantiate JWTService, so each one enforces it on its own).
 */
final class SecretPolicy
{
    private const MIN_LENGTH = 32;

    /** Values shipped in the versioned templates; never acceptable in production. */
    private const PLACEHOLDERS = ['change-me-to-a-long-random-secret-32chars', 'CHANGE_ME'];

    /**
     * @throws \RuntimeException in production if the secret is missing, too short or a placeholder.
     *                           Outside production (APP_ENV != production) it never throws.
     */
    public static function assertStrong(string $secret): void
    {
        $weak = strlen($secret) < self::MIN_LENGTH || in_array($secret, self::PLACEHOLDERS, true);
        if ($weak && ($_ENV['APP_ENV'] ?? 'production') === 'production') {
            throw new \RuntimeException('JWT_SECRET is missing, too short (<32 bytes) or a placeholder');
        }
    }
}
