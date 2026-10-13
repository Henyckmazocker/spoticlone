<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Music;

use App\Domain\Repository\Music\PlayHistoryRepositoryInterface;
use App\Infrastructure\Persistence\Concerns\LoggableTrait;
use PDO;
use Psr\Log\LoggerInterface;

class MySqlPlayHistoryRepository implements PlayHistoryRepositoryInterface
{
    use LoggableTrait;

    public function __construct(
        private readonly PDO $db,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function record(int $userId, int $trackId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO play_history (user_id, track_id) VALUES (:u, :t)"
        );
        $stmt->execute(['u' => $userId, 't' => $trackId]);
    }

    public function recentTrackIds(int $userId, int $limit = 30): array
    {
        // Most recent distinct tracks first.
        $stmt = $this->db->prepare(
            "SELECT track_id FROM play_history
             WHERE user_id = :u
             GROUP BY track_id
             ORDER BY MAX(played_at) DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function mostPlayed(int $userId, int $limit = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT track_id, COUNT(*) AS plays FROM play_history
             WHERE user_id = :u
             GROUP BY track_id
             ORDER BY plays DESC, MAX(played_at) DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(
            fn($r) => ['track_id' => (int) $r['track_id'], 'plays' => (int) $r['plays']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function artistAffinity(int $userId, int $days = 180): array
    {
        // Half-life of 30 days (720 h); idx_ph_user_time covers the user + time range.
        $stmt = $this->db->prepare(
            "SELECT t.artist_id,
                    SUM(POW(0.5, TIMESTAMPDIFF(HOUR, ph.played_at, NOW()) / 720)) AS affinity
             FROM play_history ph
             JOIN tracks t ON t.id = ph.track_id
             WHERE ph.user_id = :u AND ph.played_at >= NOW() - INTERVAL :d DAY
             GROUP BY t.artist_id"
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':d', $days, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['artist_id']] = (float) $r['affinity'];
        }
        return $out;
    }

    public function playedTrackIdsSince(int $userId, int $days): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT track_id FROM play_history
             WHERE user_id = :u AND played_at >= NOW() - INTERVAL :d DAY"
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':d', $days, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function albumProgress(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.album_id, p.played, COUNT(t2.id) AS total
             FROM (SELECT t.album_id, COUNT(DISTINCT ph.track_id) AS played
                   FROM play_history ph
                   JOIN tracks t ON t.id = ph.track_id
                   WHERE ph.user_id = :u
                   GROUP BY t.album_id) p
             JOIN tracks t2 ON t2.album_id = p.album_id AND t2.missing_at IS NULL
             GROUP BY p.album_id, p.played"
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['album_id']] = ['played' => (int) $r['played'], 'total' => (int) $r['total']];
        }
        return $out;
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
