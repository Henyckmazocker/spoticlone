<?php
declare(strict_types=1);

namespace App\Domain\DTO\Commands;

/**
 * Command DTO for updating a user's profile information.
 *
 * Only fields that are present (non-null) should be updated.
 * A null value means "do not update this field".
 */
final readonly class UpdateUserProfileCommand
{
    public function __construct(
        public int $userId,
        public ?string $lastfmUsername = null,
        public ?string $name = null
    ) {}

    public static function fromArray(array $data, int $userId): self
    {
        return new self(
            userId: $userId,
            lastfmUsername: array_key_exists('lastfm_username', $data)
                ? ($data['lastfm_username'] !== '' ? (string) $data['lastfm_username'] : null)
                : null,
            name: isset($data['name']) && $data['name'] !== ''
                ? (string) $data['name']
                : null
        );
    }
}
