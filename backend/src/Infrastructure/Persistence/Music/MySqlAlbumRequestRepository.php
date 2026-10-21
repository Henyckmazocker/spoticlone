<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Music;

use App\Domain\Repository\Music\AlbumRequestRepositoryInterface;
use App\Infrastructure\Persistence\Concerns\LoggableTrait;
use PDO;
use Psr\Log\LoggerInterface;

class MySqlAlbumRequestRepository implements AlbumRequestRepositoryInterface
{
    use LoggableTrait;

    private const OPEN = ['pending', 'downloading'];
    private const EXTRA_COLUMNS = ['reject_reason', 'download_id', 'album_id'];

    public function __construct(
        private readonly PDO $db,
        private readonly ?LoggerInterface $logger = null
    ) {}

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT_WITH_COUNT . " WHERE r.id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->withRequesters([$row])[0] : null;
    }

    public function findBySpotifyId(string $spotifyId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM album_requests WHERE spotify_album_id = :s LIMIT 1");
        $stmt->execute(['s' => $spotifyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO album_requests (spotify_album_id, artist, title, year, cover_url)
             VALUES (:spotify_album_id, :artist, :title, :year, :cover_url)"
        );
        $stmt->execute([
            'spotify_album_id' => $data['spotify_album_id'],
            'artist'           => $data['artist'],
            'title'            => $data['title'],
            'year'             => $data['year'] ?? null,
            'cover_url'        => $data['cover_url'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function attachUser(int $requestId, int $userId): void
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO album_request_users (request_id, user_id) VALUES (:r, :u)"
        );
        $stmt->execute(['r' => $requestId, 'u' => $userId]);
    }

    public function detachUser(int $requestId, int $userId): void
    {
        $stmt = $this->db->prepare("DELETE FROM album_request_users WHERE request_id = :r AND user_id = :u");
        $stmt->execute(['r' => $requestId, 'u' => $userId]);
    }

    public function hasUser(int $requestId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM album_request_users WHERE request_id = :r AND user_id = :u LIMIT 1"
        );
        $stmt->execute(['r' => $requestId, 'u' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    public function countUsers(int $requestId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM album_request_users WHERE request_id = :r");
        $stmt->execute(['r' => $requestId]);
        return (int) $stmt->fetchColumn();
    }

    public function countPendingForUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM album_request_users u
             JOIN album_requests r ON r.id = u.request_id
             WHERE u.user_id = :u AND r.status IN ('pending','downloading')"
        );
        $stmt->execute(['u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.* FROM album_requests r
             JOIN album_request_users u ON u.request_id = r.id
             WHERE u.user_id = :u
             ORDER BY r.created_at DESC, r.id DESC"
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listAll(?array $statuses = null): array
    {
        $sql = self::SELECT_WITH_COUNT;
        $params = [];
        if ($statuses !== null) {
            if (!$statuses) {
                return [];
            }
            $marks = [];
            foreach (array_values($statuses) as $i => $s) {
                $marks[] = ":s{$i}";
                $params["s{$i}"] = $s;
            }
            $sql .= " WHERE r.status IN (" . implode(',', $marks) . ")";
        }
        $stmt = $this->db->prepare($sql . " ORDER BY r.created_at DESC, r.id DESC");
        $stmt->execute($params);
        return $this->withRequesters($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function listOpen(): array
    {
        return $this->listAll(self::OPEN);
    }

    public function setStatus(int $id, string $status, array $extra = []): void
    {
        $sets = ['status = :status'];
        $params = ['id' => $id, 'status' => $status];
        foreach (self::EXTRA_COLUMNS as $col) {
            if (array_key_exists($col, $extra)) {
                $sets[] = "{$col} = :{$col}";
                $params[$col] = $extra[$col];
            }
        }
        if (array_key_exists('resolved_at', $extra)) {
            $sets[] = $extra['resolved_at'] === null ? 'resolved_at = NULL' : 'resolved_at = NOW()';
        }
        $stmt = $this->db->prepare("UPDATE album_requests SET " . implode(', ', $sets) . " WHERE id = :id");
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM album_requests WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    private const SELECT_WITH_COUNT =
        "SELECT r.*, (SELECT COUNT(*) FROM album_request_users u WHERE u.request_id = r.id) AS users_count
         FROM album_requests r";

    /**
     * Adds `requested_by` (display names) with one extra query instead of GROUP_CONCAT, which
     * would break on names containing the separator.
     */
    private function withRequesters(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['users_count'] = (int) $row['users_count'];
            $row['requested_by'] = [];
        }
        unset($row);
        if (!$rows) {
            return $rows;
        }
        $ids = array_map(fn(array $r) => (int) $r['id'], $rows);
        $stmt = $this->db->query(
            "SELECT u.request_id, us.name FROM album_request_users u
             JOIN users us ON us.id = u.user_id
             WHERE u.request_id IN (" . implode(',', $ids) . ")
             ORDER BY u.created_at, us.id"
        );
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $names[(int) $n['request_id']][] = (string) $n['name'];
        }
        foreach ($rows as &$row) {
            $row['requested_by'] = $names[(int) $row['id']] ?? [];
        }
        return $rows;
    }
}
