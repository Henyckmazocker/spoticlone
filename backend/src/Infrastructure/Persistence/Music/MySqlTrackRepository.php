<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Music;

use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\Normalize;
use App\Infrastructure\Persistence\Concerns\LoggableTrait;
use PDO;
use Psr\Log\LoggerInterface;

class MySqlTrackRepository implements TrackRepositoryInterface
{
    use LoggableTrait;

    private const TABLE = 'tracks';

    private const SELECT =
        "SELECT t.*, al.title AS album_title, al.cover_path, al.cover_url, ar.name AS artist_name,
                t.missing_at, al.missing_at AS album_missing_at
         FROM tracks t
         JOIN albums al ON al.id = t.album_id
         JOIN artists ar ON ar.id = t.artist_id";

    public function __construct(
        private readonly PDO $db,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function findByPath(string $filePath): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM " . self::TABLE . " WHERE file_path = :p LIMIT 1");
        $stmt->execute(['p' => $filePath]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . " WHERE t.id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function insert(array $data): int
    {
        $cols = ['album_id', 'artist_id', 'title', 'track_no', 'disc_no', 'duration_seconds',
                 'file_path', 'file_format', 'file_size', 'bitrate', 'sample_rate', 'mtime', 'genre'];
        $placeholders = array_map(fn($c) => ":$c", $cols);
        $stmt = $this->db->prepare(
            "INSERT INTO " . self::TABLE . " (" . implode(', ', $cols) . ")
             VALUES (" . implode(', ', $placeholders) . ")"
        );
        $stmt->execute($this->params($cols, $data));
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $cols = ['album_id', 'artist_id', 'title', 'track_no', 'disc_no', 'duration_seconds',
                 'file_format', 'file_size', 'bitrate', 'sample_rate', 'mtime', 'genre'];
        $set = implode(', ', array_map(fn($c) => "$c = :$c", $cols));
        $stmt = $this->db->prepare("UPDATE " . self::TABLE . " SET $set WHERE id = :id");
        $params = $this->params($cols, $data);
        $params['id'] = $id;
        $stmt->execute($params);
    }

    public function findByAlbum(int $albumId): array
    {
        $stmt = $this->db->prepare(
            self::SELECT . " WHERE t.album_id = :a ORDER BY t.disc_no, t.track_no, t.title"
        );
        $stmt->execute(['a' => $albumId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(self::SELECT . " WHERE t.id IN ($in)");
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

    public function findByArtists(array $artistIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $artistIds))));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            self::SELECT . " WHERE t.artist_id IN ($in) AND t.missing_at IS NULL
             ORDER BY t.artist_id, t.album_id, t.disc_no, t.track_no, t.id"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function search(string $term, int $limit = 50): array
    {
        $stmt = $this->db->prepare(self::SELECT . " WHERE t.title LIKE :t AND t.missing_at IS NULL ORDER BY t.title LIMIT :lim");
        $stmt->bindValue(':t', '%' . $term . '%', PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findGhostInAlbum(int $albumId, ?int $disc, ?int $trackNo, string $titleNorm): ?array
    {
        if ($trackNo !== null) {
            // Single-disc albums usually come without disc_no: COALESCE on both sides.
            $stmt = $this->db->prepare(
                "SELECT * FROM " . self::TABLE . "
                 WHERE album_id = :a AND missing_at IS NOT NULL
                   AND track_no = :n AND COALESCE(disc_no, 1) = COALESCE(:d, 1)
                 ORDER BY id LIMIT 1"
            );
            $stmt->bindValue(':a', $albumId, PDO::PARAM_INT);
            $stmt->bindValue(':n', $trackNo, PDO::PARAM_INT);
            $stmt->bindValue(':d', $disc, $disc === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($titleNorm === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM " . self::TABLE . " WHERE album_id = :a AND missing_at IS NOT NULL ORDER BY id"
        );
        $stmt->execute(['a' => $albumId]);
        $matches = array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            fn($r) => Normalize::name((string) $r['title']) === $titleNorm
        ));
        // More than one candidate (Intro, Interlude…): better a track without history than
        // history on the wrong track.
        return count($matches) === 1 ? $matches[0] : null;
    }

    public function reattach(int $trackId, array $data): void
    {
        $this->update($trackId, $data);
        // The file usually comes back under a different path: move file_path along with it.
        $stmt = $this->db->prepare(
            "UPDATE " . self::TABLE . "
             SET missing_at = NULL, file_path = COALESCE(:p, file_path)
             WHERE id = :id"
        );
        $stmt->execute(['p' => $data['file_path'] ?? null, 'id' => $trackId]);
    }

    public function liveFilePaths(): array
    {
        $stmt = $this->db->query("SELECT id, file_path FROM " . self::TABLE . " WHERE missing_at IS NULL");
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = (string) $r['file_path'];
        }
        return $out;
    }

    public function markMissingByIds(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare(
                "UPDATE " . self::TABLE . " SET missing_at = NOW() WHERE id IN ($in) AND missing_at IS NULL"
            );
            $stmt->execute($chunk);
        }
    }

    /** @param string[] $cols @return array<string, mixed> */
    private function params(array $cols, array $data): array
    {
        $out = [];
        foreach ($cols as $c) {
            $out[$c] = $data[$c] ?? null;
        }
        return $out;
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
