<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Music;

use App\Domain\Repository\Music\PlaylistRepositoryInterface;
use App\Infrastructure\Persistence\Concerns\LoggableTrait;
use PDO;
use Psr\Log\LoggerInterface;

class MySqlPlaylistRepository implements PlaylistRepositoryInterface
{
    use LoggableTrait;

    public function __construct(
        private readonly PDO $db,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function findAllForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*, COUNT(pt.track_id) AS track_count
             FROM playlists p
             LEFT JOIN playlist_tracks pt ON pt.playlist_id = p.id
             WHERE p.user_id = :u
             GROUP BY p.id
             ORDER BY p.updated_at DESC"
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findForUser(int $userId, int $playlistId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM playlists WHERE id = :id AND user_id = :u LIMIT 1"
        );
        $stmt->execute(['id' => $playlistId, 'u' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(int $userId, string $name, ?string $description, bool $isPublic): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO playlists (user_id, name, description, is_public)
             VALUES (:u, :n, :d, :p)"
        );
        $stmt->execute(['u' => $userId, 'n' => $name, 'd' => $description, 'p' => $isPublic ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $userId, int $playlistId, array $data): bool
    {
        $fields = [];
        $params = ['id' => $playlistId, 'u' => $userId];
        foreach (['name' => 'name', 'description' => 'description', 'is_public' => 'is_public'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $fields[] = "$col = :$col";
                $params[$col] = $col === 'is_public' ? (int) (bool) $data[$in] : $data[$in];
            }
        }
        if (!$fields) {
            return false;
        }
        $stmt = $this->db->prepare(
            "UPDATE playlists SET " . implode(', ', $fields) . " WHERE id = :id AND user_id = :u"
        );
        $stmt->execute($params);
        return true;
    }

    public function delete(int $userId, int $playlistId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM playlists WHERE id = :id AND user_id = :u");
        $stmt->execute(['id' => $playlistId, 'u' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function trackIds(int $playlistId): array
    {
        $stmt = $this->db->prepare(
            "SELECT track_id FROM playlist_tracks WHERE playlist_id = :p ORDER BY position, added_at"
        );
        $stmt->execute(['p' => $playlistId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function addTrack(int $playlistId, int $trackId): void
    {
        // Append at the end (max position + 1); ignore duplicates (PK = playlist_id, track_id).
        $stmt = $this->db->prepare(
            "INSERT INTO playlist_tracks (playlist_id, track_id, position)
             VALUES (:p, :t, (SELECT COALESCE(MAX(position), -1) + 1 FROM playlist_tracks pt WHERE pt.playlist_id = :p2))
             ON DUPLICATE KEY UPDATE position = position"
        );
        $stmt->execute(['p' => $playlistId, 't' => $trackId, 'p2' => $playlistId]);
        $this->touch($playlistId);
    }

    public function removeTrack(int $playlistId, int $trackId): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM playlist_tracks WHERE playlist_id = :p AND track_id = :t"
        );
        $stmt->execute(['p' => $playlistId, 't' => $trackId]);
        $this->touch($playlistId);
    }

    public function reorder(int $playlistId, array $orderedTrackIds): void
    {
        $stmt = $this->db->prepare(
            "UPDATE playlist_tracks SET position = :pos WHERE playlist_id = :p AND track_id = :t"
        );
        $pos = 0;
        foreach ($orderedTrackIds as $trackId) {
            $stmt->execute(['pos' => $pos++, 'p' => $playlistId, 't' => (int) $trackId]);
        }
        $this->touch($playlistId);
    }

    private function touch(int $playlistId): void
    {
        $stmt = $this->db->prepare("UPDATE playlists SET updated_at = CURRENT_TIMESTAMP WHERE id = :p");
        $stmt->execute(['p' => $playlistId]);
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
