<?php

declare(strict_types=1);

namespace App\Domain\DTO\Commands;

use App\Domain\Model\ValueObjects\GoogleId;
use App\Domain\Model\ValueObjects\Email;

/**
 * Command DTO for user login/registration via Google OAuth
 */
final readonly class LoginUserCommand
{
    public function __construct(
        public GoogleId $googleId,
        public Email $email,
        public string $name,
        public ?string $picture = null
    ) {}

    /**
     * Create from Google token data array
     */
    public static function fromGoogleToken(array $tokenData): self
    {
        // Validate required fields
        if (!isset($tokenData['sub'], $tokenData['email'], $tokenData['name'])) {
            throw new \InvalidArgumentException(
                'Invalid Google token data. Missing required fields: sub, email, name'
            );
        }

        return new self(
            googleId: GoogleId::fromString($tokenData['sub']),
            email: Email::fromString($tokenData['email']),
            name: $tokenData['name'],
            picture: $tokenData['picture'] ?? null
        );
    }

    public function toArray(): array
    {
        return [
            'google_id' => $this->googleId->toString(),
            'email' => $this->email->toString(),
            'name' => $this->name,
            'picture' => $this->picture
        ];
    }
}
