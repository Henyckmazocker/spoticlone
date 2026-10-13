<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Music;

use App\Domain\Repository\Music\DownloadRepositoryInterface;
use App\Infrastructure\Persistence\Concerns\LoggableTrait;
use PDO;
use Psr\Log\LoggerInterface;

class MySqlDownloadRepository implements DownloadRepositoryInterface
{
    use LoggableTrait;

    private const TABLE = 'downloads';

    /** Estados que ya no avanzan solos; se mantienen visibles un rato para que el usuario los vea. */
    private const TERMINAL_STATES = ['imported', 'failed'];

    public function __construct(
        private readonly PDO $db,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function upsertQueued(int $userId, array $data): int
    {
        $hash = self::hash((string) $data['slskd_username'], (string) $data['remote_dir']);

        $stmt = $this->db->prepare(
            "INSERT INTO " . self::TABLE . "
                (user_id, slskd_username, remote_dir, remote_dir_hash, local_folder,
                 target_artist, album_title, replace_album_id, expected_files, state, error)
             VALUES (:uid, :user, :dir, :hash, :folder, :artist, :album, :replace, :expected, 'queued', NULL)
             ON DUPLICATE KEY UPDATE
                -- Reencolar (reintento tras un fallo) reutiliza la fila: sin duplicados y sin
                -- arrastrar el error anterior.
                local_folder   = VALUES(local_folder),
                target_artist  = COALESCE(VALUES(target_artist), target_artist),
                album_title    = COALESCE(VALUES(album_title), album_title),
                -- Un reintento sin decisión no borra la intención de reemplazar.
                replace_album_id = COALESCE(VALUES(replace_album_id), replace_album_id),
                expected_files = VALUES(expected_files),
                state          = 'queued',
                error          = NULL,
                id             = LAST_INSERT_ID(id)"
        );
        $stmt->execute([
            'uid'      => $userId,
            'user'     => $data['slskd_username'],
            'dir'      => $data['remote_dir'],
            'hash'     => $hash,
            'folder'   => $data['local_folder'],
            'artist'   => $data['target_artist'] ?? null,
            'album'    => $data['album_title'] ?? null,
            'replace'  => isset($data['replace_album_id']) ? (int) $data['replace_album_id'] : null,
            'expected' => (int) ($data['expected_files'] ?? 0),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function clearReplace(int $albumId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE " . self::TABLE . " SET replace_album_id = NULL WHERE replace_album_id = :id"
        );
        $stmt->execute(['id' => $albumId]);
    }

    public function findActiveForUser(int $userId): array
    {
        // No terminales siempre; terminales solo de las últimas 24 h, para que la vista Descargas
        // no acumule el histórico completo.
        $stmt = $this->db->prepare(
            "SELECT * FROM " . self::TABLE . "
             WHERE user_id = :uid
               AND (state NOT IN ('imported','failed') OR updated_at >= (NOW() - INTERVAL 1 DAY))
             ORDER BY id DESC"
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM " . self::TABLE . " WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function markState(int $id, string $state, ?string $error = null, ?string $importedPath = null): void
    {
        $sets = ['state = :state'];
        $params = ['state' => $state, 'id' => $id];

        // Un estado no terminal limpia el error viejo; si nos dan uno nuevo, se guarda.
        if ($error !== null) {
            $sets[] = 'error = :error';
            $params['error'] = mb_substr($error, 0, 1024);
        } elseif (!in_array($state, self::TERMINAL_STATES, true)) {
            $sets[] = 'error = NULL';
        }
        if ($importedPath !== null) {
            $sets[] = 'imported_path = :path';
            $params['path'] = $importedPath;
        }

        $stmt = $this->db->prepare(
            "UPDATE " . self::TABLE . " SET " . implode(', ', $sets) . " WHERE id = :id"
        );
        $stmt->execute($params);
    }

    public function claimForImport(int $id): bool
    {
        // Atómico: solo la petición que transiciona complete → importing se lleva la fila.
        $stmt = $this->db->prepare(
            "UPDATE " . self::TABLE . "
             SET state = 'importing'
             WHERE id = :id AND state = 'complete'"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    public function releaseImport(int $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE " . self::TABLE . " SET state = 'complete' WHERE id = :id AND state = 'importing'"
        );
        $stmt->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM " . self::TABLE . " WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    /** Clave de identidad de una descarga (el directorio remoto completo, no el basename). */
    private static function hash(string $username, string $remoteDir): string
    {
        return hash('sha256', $username . '|' . $remoteDir);
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
