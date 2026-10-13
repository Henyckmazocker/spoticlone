<?php

declare(strict_types=1);

namespace App\Domain\Services;

/**
 * Login whitelist: only the emails listed in the ALLOWED_EMAILS env var (comma-separated) may
 * access the app. An empty/unset list means no restriction. Used both at Google login
 * (AuthController) and on every authenticated request (AuthenticationMiddleware), so session and
 * JWT — including the dev token — are all gated.
 */
class EmailWhitelist
{
    /** @var string[] lowercased allowed emails */
    private array $allowed;

    public function __construct()
    {
        $this->allowed = array_values(array_filter(array_map(
            fn($e) => strtolower(trim($e)),
            explode(',', $_ENV['ALLOWED_EMAILS'] ?? '')
        )));
    }

    public function isRestricted(): bool
    {
        return $this->allowed !== [];
    }

    public function isAllowed(?string $email): bool
    {
        if (!$this->isRestricted()) {
            return true;
        }
        return in_array(strtolower(trim((string) $email)), $this->allowed, true);
    }
}
