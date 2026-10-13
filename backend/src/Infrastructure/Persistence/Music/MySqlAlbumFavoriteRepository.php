<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Music;

use App\Domain\Repository\Music\AlbumFavoriteRepositoryInterface;
use App\Infrastructure\Persistence\Concerns\LoggableTrait;
use PDO;
use Psr\Log\LoggerInterface;

class MySqlAlbumFavoriteRepository implements AlbumFavoriteRepositoryInterface
{
    use LoggableTrait;

    public function __construct(
        private readonly PDO $db,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function toggle(int $userId, int $albumId): bool
    {
        if ($this->isFavorite($userId, $albumId)) {
            $stmt = $this->db->prepare("DELETE FROM favorite_albums WHERE user_id = :u AND album_id = :a");
            $stmt->execute(['u' => $userId, 'a' => $albumId]);
            return false;
        }
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO favorite_albums (user_id, album_id) VALUES (:u, :a)"
        );
        $stmt->execute(['u' => $userId, 'a' => $albumId]);
        return true;
    }

    public function isFavorite(int $userId, int $albumId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM favorite_albums WHERE user_id = :u AND album_id = :a LIMIT 1"
        );
        $stmt->execute(['u' => $userId, 'a' => $albumId]);
        return (bool) $stmt->fetchColumn();
    }

    public function favoriteMap(int $userId, array $albumIds): array
    {
        $albumIds = array_values(array_filter(array_map('intval', $albumIds)));
        if (!$albumIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($albumIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT album_id FROM favorite_albums WHERE user_id = ? AND album_id IN ($in)"
        );
        $stmt->execute(array_merge([$userId], $albumIds));
        $favs = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $map = [];
        foreach ($albumIds as $id) {
            $map[$id] = in_array($id, $favs, true);
        }
        return $map;
    }

    public function albumIds(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT album_id FROM favorite_albums WHERE user_id = :u ORDER BY created_at DESC"
        );
        $stmt->execute(['u' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
