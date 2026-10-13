<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\User\Mappers;

use App\Domain\Model\User;
use App\Domain\Model\ValueObjects\Email;
use App\Domain\Model\ValueObjects\GoogleId;
use App\Domain\Model\ValueObjects\Timestamp;
use App\Infrastructure\Persistence\Concerns\HydrationHelpersTrait;

/**
 * Maps between database rows and User domain entities
 */
class UserDataMapper
{
    use HydrationHelpersTrait;

    /**
     * Convert database row to User entity
     *
     * @param array $row Database row with snake_case columns
     * @return User
     */
    public function toDomain(array $row): User
    {
        $googleId = GoogleId::fromString($this->extractString($row, 'google_id'));
        $email = Email::fromString($this->extractString($row, 'email'));
        
        $createdAtDateTime = $this->extractDateTime($row, 'created_at');
        $updatedAtDateTime = $this->extractDateTime($row, 'updated_at');
        $lastLoginDateTime = isset($row['last_login']) 
            ? $this->extractDateTime($row, 'last_login') 
            : null;

        // Convert DateTime to Timestamp VOs
        $createdAt = $createdAtDateTime ? Timestamp::fromString($createdAtDateTime->format('Y-m-d H:i:s')) : null;
        $updatedAt = $updatedAtDateTime ? Timestamp::fromString($updatedAtDateTime->format('Y-m-d H:i:s')) : null;
        $lastLogin = $lastLoginDateTime ? Timestamp::fromString($lastLoginDateTime->format('Y-m-d H:i:s')) : null;

        // User entity now uses VOs directly
        return new User(
            $this->extractInt($row, 'id', null),
            $googleId,
            $email,
            $this->extractString($row, 'name'),
            $this->extractString($row, 'picture', null),
            $createdAt,
            $updatedAt,
            $lastLogin,
            $this->extractJson($row, 'preferences', []),
            $this->extractBool($row, 'is_active', false),
            $this->extractString($row, 'lastfm_username', null),
            $this->extractString($row, 'username', null)
        );
    }

    /**
     * Convert User entity to database array
     *
     * @param User $user
     * @param bool $includeId Include ID field (false for INSERT, true for UPDATE)
     * @return array Database array with snake_case keys
     */
    public function toPersistence(User $user, bool $includeId = false): array
    {
        $data = [
            'google_id' => $user->getGoogleId()->toString(),
            'email' => $user->getEmail()->toString(),
            'name' => $user->getName(),
            'picture' => $this->toDbValue($user->getPicture()),
            'created_at' => $user->getCreatedAt()->toString(),
            'updated_at' => $user->getUpdatedAt()->toString(),
            'last_login' => $user->getLastLogin()?->toString(),
            'preferences' => $this->toDbValue($user->getPreferences(), 'json'),
            'is_active' => $user->isActive() ? 1 : 0,
            'lastfm_username' => $user->getLastFmUsername(),
            'username' => $user->getUsername(),
        ];

        if ($includeId && $user->getId() !== null) {
            $data['id'] = $user->getId();
        }

        return $data;
    }

    /**
     * Convert array of database rows to array of User entities
     *
     * @param array $rows
     * @return User[]
     */
    public function toDomainCollection(array $rows): array
    {
        return array_map(
            fn(array $row) => $this->toDomain($row),
            $rows
        );
    }
}
