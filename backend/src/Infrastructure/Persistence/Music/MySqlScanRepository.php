<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Music;

use App\Domain\Repository\Music\ScanRepositoryInterface;
use App\Infrastructure\Persistence\Concerns\LoggableTrait;
use PDO;
use Psr\Log\LoggerInterface;

class MySqlScanRepository implements ScanRepositoryInterface
{
    use LoggableTrait;

    private const TABLE = 'scan_runs';

    public function __construct(
        private readonly PDO $db,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function start(): int
    {
        $this->db->exec("INSERT INTO " . self::TABLE . " (status) VALUES ('running')");
        return (int) $this->db->lastInsertId();
    }

    public function finish(int $runId, array $stats, string $status = 'completed', ?string $message = null): void
    {
        $stmt = $this->db->prepare(
            "UPDATE " . self::TABLE . "
             SET finished_at = CURRENT_TIMESTAMP,
                 files_seen = :fs, tracks_added = :ta, tracks_updated = :tu, errors = :er,
                 status = :st, message = :msg
             WHERE id = :id"
        );
        $stmt->execute([
            'fs'  => $stats['files_seen'] ?? 0,
            'ta'  => $stats['tracks_added'] ?? 0,
            'tu'  => $stats['tracks_updated'] ?? 0,
            'er'  => $stats['errors'] ?? 0,
            'st'  => $status,
            'msg' => $message,
            'id'  => $runId,
        ]);
    }

    public function latest(): ?array
    {
        $stmt = $this->db->query(
            "SELECT * FROM " . self::TABLE . " ORDER BY id DESC LIMIT 1"
        );
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
