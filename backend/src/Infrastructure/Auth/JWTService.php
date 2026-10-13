<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT Service - Generates and validates JWT tokens for stateless auth (mobile/Capacitor)
 */
class JWTService
{
    private const ALGORITHM = 'HS256';
    private const TTL = 604800; // 7 days (matches session timeout)

    private string $secret;

    public function __construct()
    {
        $this->secret = $_ENV['JWT_SECRET']
            ?? throw new \RuntimeException('JWT_SECRET environment variable is required and must not be empty');
    }

    /**
     * Generate a signed JWT with user payload
     */
    public function generate(array $payload): string
    {
        $now = time();
        $data = array_merge($payload, [
            'iat' => $now,
            'exp' => $now + self::TTL,
        ]);

        return JWT::encode($data, $this->secret, self::ALGORITHM);
    }

    /**
     * Validate a JWT and return its payload, or null if invalid/expired
     */
    public function validate(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
            return (array) $decoded;
        } catch (\Throwable) {
            return null;
        }
    }
}
