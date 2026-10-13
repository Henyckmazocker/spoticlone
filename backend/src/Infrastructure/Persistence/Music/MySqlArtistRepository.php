<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Music;

use App\Domain\Repository\Music\ArtistRepositoryInterface;
use App\Infrastructure\Persistence\Concerns\LoggableTrait;
use PDO;
use Psr\Log\LoggerInterface;

class MySqlArtistRepository implements ArtistRepositoryInterface
{
    use LoggableTrait;

    private const TABLE = 'artists';

    public function __construct(
        private readonly PDO $db,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function upsertByName(string $name): int
    {
        // INSERT ... ON DUPLICATE KEY keeps the id stable; LAST_INSERT_ID(id) returns it on update.
        $stmt = $this->db->prepare(
            "INSERT INTO " . self::TABLE . " (name, sort_name)
             VALUES (:name, :sort)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $stmt->execute(['name' => $name, 'sort' => $this->sortName($name)]);
        return (int) $this->db->lastInsertId();
    }

    public function findAll(): array
    {
        $stmt = $this->db->query(
            "SELECT a.*, COUNT(DISTINCT al.id) AS album_count
             FROM " . self::TABLE . " a
             LEFT JOIN albums al ON al.artist_id = a.id AND al.missing_at IS NULL
             GROUP BY a.id
             HAVING album_count > 0
             ORDER BY COALESCE(a.sort_name, a.name)"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM " . self::TABLE . " WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function setImageUrl(int $id, ?string $url): void
    {
        $stmt = $this->db->prepare("UPDATE " . self::TABLE . " SET image_url = :u WHERE id = :id");
        $stmt->execute(['u' => $url, 'id' => $id]);
    }

    public function search(string $term, int $limit = 25): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.* FROM " . self::TABLE . " a
             WHERE a.name LIKE :t
               AND EXISTS (SELECT 1 FROM albums al WHERE al.artist_id = a.id AND al.missing_at IS NULL)
             ORDER BY a.name LIMIT :lim"
        );
        $stmt->bindValue(':t', '%' . $term . '%', PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM " . self::TABLE . " WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /** Drop a leading "The " for nicer alphabetical sorting. */
    private function sortName(string $name): string
    {
        return preg_replace('/^(the|los|las|el|la)\s+/i', '', trim($name)) ?: $name;
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
