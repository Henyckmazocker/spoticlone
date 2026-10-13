<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Music;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Services\Normalize;
use App\Infrastructure\Persistence\Concerns\LoggableTrait;
use PDO;
use Psr\Log\LoggerInterface;

class MySqlAlbumRepository implements AlbumRepositoryInterface
{
    use LoggableTrait;

    private const TABLE = 'albums';

    public function __construct(
        private readonly PDO $db,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function upsert(int $artistId, string $title, string $folderPath, ?int $year): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO " . self::TABLE . " (artist_id, title, folder_path, year)
             VALUES (:artist, :title, :folder, :year)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                folder_path = VALUES(folder_path),
                year = COALESCE(VALUES(year), year)"
        );
        $stmt->execute([
            'artist' => $artistId,
            'title'  => $title,
            'folder' => $folderPath,
            'year'   => $year,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function setCoverPath(int $id, ?string $coverPath): void
    {
        $stmt = $this->db->prepare("UPDATE " . self::TABLE . " SET cover_path = :c WHERE id = :id");
        $stmt->execute(['c' => $coverPath, 'id' => $id]);
    }

    public function setCoverUrl(int $id, ?string $coverUrl): void
    {
        $stmt = $this->db->prepare("UPDATE " . self::TABLE . " SET cover_url = :c WHERE id = :id");
        $stmt->execute(['c' => $coverUrl, 'id' => $id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT al.*, ar.name AS artist_name
             FROM " . self::TABLE . " al
             JOIN artists ar ON ar.id = al.artist_id
             WHERE al.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByArtist(int $artistId): array
    {
        $stmt = $this->db->prepare(
            "SELECT al.*, ar.name AS artist_name, COUNT(t.id) AS track_count
             FROM " . self::TABLE . " al
             JOIN artists ar ON ar.id = al.artist_id
             LEFT JOIN tracks t ON t.album_id = al.id
             WHERE al.artist_id = :a
             GROUP BY al.id
             ORDER BY al.year, al.title"
        );
        $stmt->execute(['a' => $artistId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT al.*, ar.name AS artist_name, COUNT(t.id) AS track_count
             FROM " . self::TABLE . " al
             JOIN artists ar ON ar.id = al.artist_id
             LEFT JOIN tracks t ON t.album_id = al.id
             WHERE al.id IN ($in)
             GROUP BY al.id"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Preserve the requested order (the IN clause does not).
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }

    public function findRecent(int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT al.*, ar.name AS artist_name, COUNT(t.id) AS track_count
             FROM " . self::TABLE . " al
             JOIN artists ar ON ar.id = al.artist_id
             LEFT JOIN tracks t ON t.album_id = al.id
             WHERE al.missing_at IS NULL
             GROUP BY al.id
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function search(string $term, int $limit = 25): array
    {
        $stmt = $this->db->prepare(
            "SELECT al.*, ar.name AS artist_name
             FROM " . self::TABLE . " al
             JOIN artists ar ON ar.id = al.artist_id
             WHERE al.title LIKE :t AND al.missing_at IS NULL
             ORDER BY al.title LIMIT :lim"
        );
        $stmt->bindValue(':t', '%' . $term . '%', PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findWithoutCover(): array
    {
        $stmt = $this->db->query(
            "SELECT al.*, ar.name AS artist_name
             FROM " . self::TABLE . " al
             JOIN artists ar ON ar.id = al.artist_id
             WHERE al.cover_path IS NULL AND al.cover_url IS NULL AND al.missing_at IS NULL"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markMissing(int $albumId): void
    {
        // Ghost: the row (and its ids) stays; the local cover file is gone with the folder,
        // cover_url (remote) is kept on purpose.
        $stmt = $this->db->prepare(
            "UPDATE " . self::TABLE . "
             SET missing_at = COALESCE(missing_at, NOW()), cover_path = NULL
             WHERE id = :id"
        );
        $stmt->execute(['id' => $albumId]);
        $stmt = $this->db->prepare(
            "UPDATE tracks SET missing_at = NOW() WHERE album_id = :id AND missing_at IS NULL"
        );
        $stmt->execute(['id' => $albumId]);
    }

    public function clearMissing(int $albumId, ?string $folderPath = null): void
    {
        $stmt = $this->db->prepare(
            "UPDATE " . self::TABLE . "
             SET missing_at = NULL, folder_path = COALESCE(:folder, folder_path)
             WHERE id = :id"
        );
        $stmt->execute(['folder' => $folderPath, 'id' => $albumId]);
    }

    public function findGhostByNormalized(string $artistNorm, string $titleNorm): ?array
    {
        if ($artistNorm === '' || $titleNorm === '') {
            return null;
        }
        // Normalize::name cannot be replicated in SQL: load the (few) ghosts and compare in PHP.
        $stmt = $this->db->query(
            "SELECT al.*, ar.name AS artist_name
             FROM " . self::TABLE . " al
             JOIN artists ar ON ar.id = al.artist_id
             WHERE al.missing_at IS NOT NULL
             ORDER BY al.id"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (Normalize::name((string) $row['artist_name']) === $artistNorm
                && Normalize::name((string) $row['title']) === $titleNorm) {
                return $row;
            }
        }
        return null;
    }

    public function allForMatching(): array
    {
        // Ghosts included on purpose: LibraryMatcher reports them as "missing".
        $stmt = $this->db->query(
            "SELECT al.id, al.title, al.missing_at, ar.name AS artist_name
             FROM " . self::TABLE . " al
             JOIN artists ar ON ar.id = al.artist_id
             ORDER BY al.id"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markMissingWhereNoLiveTracks(): int
    {
        // Only albums that do have tracks, all of them ghosts (an album with no track rows at all
        // is left alone: nothing was pruned from it).
        $stmt = $this->db->prepare(
            "UPDATE " . self::TABLE . " al
             SET al.missing_at = NOW(), al.cover_path = NULL
             WHERE al.missing_at IS NULL
               AND EXISTS (SELECT 1 FROM tracks t WHERE t.album_id = al.id)
               AND NOT EXISTS (SELECT 1 FROM tracks t2 WHERE t2.album_id = al.id AND t2.missing_at IS NULL)"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM " . self::TABLE . " WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
