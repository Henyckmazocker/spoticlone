<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Music;

use App\Domain\Repository\Music\FavoriteRepositoryInterface;
use App\Infrastructure\Persistence\Concerns\LoggableTrait;
use PDO;
use Psr\Log\LoggerInterface;

class MySqlFavoriteRepository implements FavoriteRepositoryInterface
{
    use LoggableTrait;

    public function __construct(
        private readonly PDO $db,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function toggle(int $userId, int $trackId): bool
    {
        if ($this->isFavorite($userId, $trackId)) {
            $stmt = $this->db->prepare("DELETE FROM favorites WHERE user_id = :u AND track_id = :t");
            $stmt->execute(['u' => $userId, 't' => $trackId]);
            return false;
        }
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO favorites (user_id, track_id) VALUES (:u, :t)"
        );
        $stmt->execute(['u' => $userId, 't' => $trackId]);
        return true;
    }

    public function isFavorite(int $userId, int $trackId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM favorites WHERE user_id = :u AND track_id = :t LIMIT 1"
        );
        $stmt->execute(['u' => $userId, 't' => $trackId]);
        return (bool) $stmt->fetchColumn();
    }

    public function favoriteMap(int $userId, array $trackIds): array
    {
        $trackIds = array_values(array_filter(array_map('intval', $trackIds)));
        if (!$trackIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($trackIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT track_id FROM favorites WHERE user_id = ? AND track_id IN ($in)"
        );
        $stmt->execute(array_merge([$userId], $trackIds));
        $favs = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $map = [];
        foreach ($trackIds as $id) {
            $map[$id] = in_array($id, $favs, true);
        }
        return $map;
    }

    public function trackIds(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT track_id FROM favorites WHERE user_id = :u ORDER BY created_at DESC"
        );
        $stmt->execute(['u' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
