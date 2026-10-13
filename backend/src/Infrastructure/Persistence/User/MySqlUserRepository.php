<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\User;

use App\Domain\Model\User;
use App\Domain\Model\ValueObjects\Email;
use App\Domain\Model\ValueObjects\GoogleId;
use App\Domain\Repository\User\UserRepositoryInterface;
use App\Infrastructure\Persistence\Concerns\LoggableTrait;
use App\Infrastructure\Persistence\User\Mappers\UserDataMapper;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * MySQL implementation of User repository
 * Handles only User CRUD operations
 */
class MySqlUserRepository implements UserRepositoryInterface
{
    use LoggableTrait;

    private const TABLE = 'users';

    private readonly UserDataMapper $mapper;

    public function __construct(
        private readonly PDO $db,
        private readonly ?LoggerInterface $logger = null,
        ?UserDataMapper $mapper = null
    ) {
        $this->mapper = $mapper ?? new UserDataMapper();
    }

    /**
     * @inheritDoc
     */
    public function findByGoogleId(GoogleId $googleId): ?User
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM " . self::TABLE . " WHERE google_id = :google_id LIMIT 1"
            );
            
            $stmt->execute(['google_id' => $googleId->toString()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->logDebug('User not found by Google ID', [
                    'google_id' => $googleId->toMasked()
                ]);
                return null;
            }

            $this->logInfo('User found by Google ID', [
                'user_id' => $row['id'],
                'google_id' => $googleId->toMasked()
            ]);

            return $this->mapper->toDomain($row);
        } catch (PDOException $e) {
            $this->logError('Failed to find user by Google ID', $e, [
                'google_id' => $googleId->toMasked()
            ]);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function findById(int $id): ?User
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM " . self::TABLE . " WHERE id = :id LIMIT 1"
            );
            
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->logDebug('User not found by ID', ['user_id' => $id]);
                return null;
            }

            $this->logInfo('User found by ID', ['user_id' => $id]);
            return $this->mapper->toDomain($row);
        } catch (PDOException $e) {
            $this->logError('Failed to find user by ID', $e, [
                'user_id' => $id
            ]);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function findByEmail(Email $email): ?User
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM " . self::TABLE . " WHERE email = :email LIMIT 1"
            );
            
            $stmt->execute(['email' => $email->toString()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->logDebug('User not found by email', [
                    'email' => $email->toMasked()
                ]);
                return null;
            }

            $this->logInfo('User found by email', [
                'user_id' => $row['id'],
                'email' => $email->toMasked()
            ]);

            return $this->mapper->toDomain($row);
        } catch (PDOException $e) {
            $this->logError('Failed to find user by email', $e, [
                'email' => $email->toMasked()
            ]);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function save(User $user): User
    {
        try {
            $data = $this->mapper->toPersistence($user, includeId: false);
            
            $columns = array_keys($data);
            $placeholders = array_map(fn($col) => ":$col", $columns);
            
            $sql = sprintf(
                "INSERT INTO %s (%s) VALUES (%s)",
                self::TABLE,
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $stmt = $this->db->prepare($sql);
            $stmt->execute($data);

            $userId = (int) $this->db->lastInsertId();

            $this->logInfo('User created successfully', [
                'user_id' => $userId,
                'google_id' => $user->getGoogleId()
            ]);

            // Return fresh instance with ID
            return $this->findById($userId) ?? $user;
        } catch (PDOException $e) {
            $this->logError('Failed to create user', $e, [
                'google_id' => $user->getGoogleId()
            ]);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function update(User $user): User
    {
        if ($user->getId() === null) {
            $this->logWarning('Attempted to update user without ID');
            throw new \InvalidArgumentException('Cannot update user without ID');
        }

        try {
            $data = $this->mapper->toPersistence($user, includeId: false);
            
            $setClause = implode(
                ', ',
                array_map(fn($col) => "$col = :$col", array_keys($data))
            );
            
            $sql = sprintf(
                "UPDATE %s SET %s WHERE id = :id",
                self::TABLE,
                $setClause
            );

            $data['id'] = $user->getId();
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($data);

            $this->logInfo('User updated successfully', [
                'user_id' => $user->getId()
            ]);

            // Return fresh instance from database
            return $this->findById($user->getId()) ?? $user;
        } catch (PDOException $e) {
            $this->logError('Failed to update user', $e, [
                'user_id' => $user->getId()
            ]);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(int $id): void
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM " . self::TABLE . " WHERE id = :id"
            );
            
            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() > 0) {
                $this->logInfo('User deleted successfully', ['user_id' => $id]);
            } else {
                $this->logDebug('User not found for deletion', ['user_id' => $id]);
            }
        } catch (PDOException $e) {
            $this->logError('Failed to delete user', $e, [
                'user_id' => $id
            ]);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function findByUsername(string $username): ?User
    {
        try {
            // Try exact username match first, then fall back to name match
            $stmt = $this->db->prepare(
                "SELECT * FROM " . self::TABLE
                . " WHERE username = :username OR (username IS NULL AND name = :name)"
                . " LIMIT 1"
            );
            $stmt->execute(['username' => $username, 'name' => $username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            return $this->mapper->toDomain($row);
        } catch (PDOException $e) {
            $this->logError('Failed to find user by username', $e, ['username' => $username]);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function searchByUsername(string $term, int $excludeUserId, int $limit = 10): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM " . self::TABLE
                . " WHERE (username LIKE :term OR name LIKE :term2)"
                . "   AND id != :exclude AND is_active = 1"
                . " LIMIT :lim"
            );
            $like = '%' . $term . '%';
            $stmt->bindValue(':term',    $like,          PDO::PARAM_STR);
            $stmt->bindValue(':term2',   $like,          PDO::PARAM_STR);
            $stmt->bindValue(':exclude', $excludeUserId, PDO::PARAM_INT);
            $stmt->bindValue(':lim',     $limit,         PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $this->mapper->toDomainCollection($rows);
        } catch (PDOException $e) {
            $this->logError('Failed to search users by username', $e, ['term' => $term]);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
