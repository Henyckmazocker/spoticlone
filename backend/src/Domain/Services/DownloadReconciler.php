<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\DownloadRepositoryInterface;
use App\Infrastructure\Soulseek\SlskdClient;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reconciles the `downloads` table against slskd's live transfer list and imports whatever
 * finished. This is the engine behind the Descargas view: the client just polls slsk_downloads
 * and paints the result.
 *
 * Why the table is the source of truth: slskd's transfer list is volatile (it drops finished
 * transfers) and the browser's memory dies on reload, so neither can answer "did this album
 * actually finish downloading?". The row records what was enqueued (expected_files) and survives
 * both.
 */
class DownloadReconciler
{
    /** Slskd transfer states we treat as dead ends. */
    private const FAILED_PATTERN = '/errored|rejected|timedout|cancell?ed|failed/i';

    /**
     * After this long, a row still sitting in 'importing' is assumed orphaned and retried. The
     * claim is only released by the request that took it, so a container restart (or a fatal)
     * mid-import would otherwise strand the row in 'importing' forever.
     */
    private const IMPORT_STUCK_SECONDS = 900;

    public function __construct(
        private readonly SlskdClient $slskd,
        private readonly DownloadRepositoryInterface $downloads,
        private readonly DownloadImporter $importer,
        private readonly MediaDeletionService $deletion,
        private readonly AlbumRepositoryInterface $albums,
        private readonly ?LoggerInterface $logger = null
    ) {}

    /**
     * Bring every active download of $userId up to date, importing the completed ones.
     *
     * @return array<int, array<string, mixed>> rows ready for the UI (state + progress)
     */
    public function reconcile(int $userId): array
    {
        $rows = $this->downloads->findActiveForUser($userId);
        if (!$rows) {
            return [];
        }

        $transfers = $this->groupTransfers($this->slskd->downloads());

        $out = [];
        foreach ($rows as $row) {
            $row = $this->advance($row, $transfers[$this->keyOf((string) $row['slskd_username'], (string) $row['remote_dir'])] ?? null);
            $out[] = $this->present($row);
        }
        return $out;
    }

    /**
     * Import a single row on demand (manual "importar" action). Goes through the same claim as the
     * poll, so a manual trigger racing the poll can't import twice.
     *
     * With $force, a 'failed' row is imported too — that is "importar igualmente": a partially
     * downloaded album still has real tracks on disk, and whether an incomplete album belongs in
     * the library is the user's call, not ours.
     *
     * @return array{already_imported:bool, moved_to:string, scan:?array}|null null if not claimable
     */
    public function importRow(int $userId, int $id, bool $force = false): ?array
    {
        $row = $this->downloads->findById($id);
        if ($row === null || (int) $row['user_id'] !== $userId) {
            throw new RuntimeException('La descarga no existe.');
        }

        $state = (string) $row['state'];
        if ($force && $state === 'failed') {
            $this->downloads->markState($id, 'complete');
            $row['state'] = $state = 'complete';
        }
        if ($state !== 'complete') {
            // Ya importada, aún descargando, o fallida sin forzar: no hay nada que hacer aquí.
            return null;
        }
        if (!$this->downloads->claimForImport($id)) {
            return null;   // un sondeo se nos adelantó y ya la está importando
        }
        return $this->runImport($row);
    }

    /**
     * Re-enqueue in slskd only the tracks that did not make it, and put the row back in play.
     *
     * Re-downloading the whole album would fetch (and duplicate) what is already on disk, and slskd
     * refuses to re-enqueue a transfer it still remembers — hence the purge-then-enqueue per file.
     *
     * @return int how many tracks were re-enqueued
     */
    public function retryMissing(int $userId, int $id): int
    {
        $row = $this->downloads->findById($id);
        if ($row === null || (int) $row['user_id'] !== $userId) {
            throw new RuntimeException('La descarga no existe.');
        }

        $username = (string) $row['slskd_username'];
        $key = $this->keyOf($username, (string) $row['remote_dir']);
        $group = $this->groupTransfers($this->slskd->downloads())[$key] ?? null;

        $retry = [];
        foreach ($group['files'] ?? [] as $f) {
            if (!preg_match(self::FAILED_PATTERN, (string) ($f['state'] ?? ''))) {
                continue;
            }
            $this->cancelTransfer($username, (string) ($f['id'] ?? ''));
            // Ojo: la ruta va tal cual la dio slskd (con barras invertidas), que es lo que espera.
            $retry[] = ['filename' => (string) ($f['filename'] ?? ''), 'size' => (int) ($f['size'] ?? 0)];
        }

        if (!$retry) {
            throw new RuntimeException(
                'slskd ya no recuerda las pistas que fallaron; vuelve a lanzar la descarga desde Descubrir.'
            );
        }

        $this->slskd->enqueueDownloads($username, $retry);
        // Estado no terminal: limpia el error y hace que el cliente vuelva a sondear.
        $this->downloads->markState($id, 'queued');
        $this->logger?->info('Reintento de pistas que faltaban', ['id' => $id, 'files' => count($retry)]);

        return count($retry);
    }

    /**
     * Scrap a download: cancel and drop whatever slskd still holds for it, throw away what had
     * landed in DOWNLOADS_ROOT, and remove the row. The client only knows row ids — resolving those
     * to slskd transfer ids belongs here.
     *
     * This is both "cancelar" on a stalled download (a remote queue that never moves) and "quitar"
     * on a failed one: in both cases the user is discarding the attempt, so leaving the half album
     * on disk would just accumulate.
     */
    public function remove(int $userId, int $id): void
    {
        $row = $this->downloads->findById($id);
        if ($row === null || (int) $row['user_id'] !== $userId) {
            throw new RuntimeException('La descarga no existe.');
        }
        if ((string) $row['state'] === 'importing') {
            // Los ficheros se están moviendo ahora mismo: borrarlos a media copia deja restos en la
            // biblioteca. Se importa (o se libera sola a los 15 min) y entonces ya se puede quitar.
            throw new RuntimeException('La descarga se está importando; espera a que termine.');
        }

        $key = $this->keyOf((string) $row['slskd_username'], (string) $row['remote_dir']);
        $group = $this->groupTransfers($this->slskd->downloads())[$key] ?? null;
        foreach ($group['files'] ?? [] as $f) {
            $this->cancelTransfer((string) $row['slskd_username'], (string) ($f['id'] ?? ''));
        }

        // Después de cancelar, no antes: si slskd sigue escribiendo, borrar primero deja el fichero
        // a medias recreado.
        $this->importer->discard((string) $row['local_folder']);

        $this->downloads->delete($id);
    }

    /** Best-effort: slskd rejects removing an active transfer, so cancel it first and retry. */
    private function cancelTransfer(string $username, string $transferId): void
    {
        if ($transferId === '') {
            return;
        }
        try {
            $this->slskd->cancelDownload($username, $transferId, true);
        } catch (RuntimeException $e) {
            try {
                $this->slskd->cancelDownload($username, $transferId);
                $this->slskd->cancelDownload($username, $transferId, true);
            } catch (RuntimeException $e2) {
                $this->logger?->warning('No se pudo eliminar la transferencia en slskd', [
                    'username' => $username,
                    'transfer' => $transferId,
                    'error'    => $e2->getMessage(),
                ]);
            }
        }
    }

    /**
     * Decide the new state of one row from its slskd transfers (or their absence).
     *
     * @param array{files:array<int,array<string,mixed>>}|null $group
     * @return array<string, mixed> the row, with progress fields attached
     */
    private function advance(array $row, ?array $group): array
    {
        $state = (string) $row['state'];
        $expected = max(1, (int) $row['expected_files']);

        // Terminal states are just reported back; nothing left to decide.
        if ($state === 'imported') {
            $row['_percent'] = 100.0;
            $row['_done'] = $expected;
            return $row;
        }
        if ($state === 'failed') {
            // A failed album usually still has most of its tracks on disk, and the user can import
            // them anyway — so report what actually landed instead of a flat 0.
            $onDisk = min($expected, max(0, $this->importer->countAudioFiles((string) $row['local_folder'])));
            $row['_done'] = $onDisk;
            $row['_percent'] = round($onDisk / $expected * 100, 1);
            return $row;
        }

        // An 'importing' row that nobody finished (the process died mid-import) goes back into the
        // pool instead of being stranded.
        if ($state === 'importing' && $this->isStuck($row)) {
            $this->logger?->warning('Import huérfano, se reintenta', ['id' => (int) $row['id']]);
            $this->downloads->releaseImport((int) $row['id']);
            $row['state'] = $state = 'complete';
        }

        if ($group === null) {
            // slskd doesn't know about it. Either it purged the finished transfers or the download
            // never really started — the files on disk are what settles it.
            $onDisk = $this->importer->countAudioFiles((string) $row['local_folder']);
            $row['_done'] = max(0, $onDisk);
            $row['_percent'] = $onDisk >= $expected ? 100.0 : 0.0;
            if ($onDisk >= $expected && $state !== 'importing') {
                $this->downloads->markState((int) $row['id'], 'complete');
                $row['state'] = 'complete';
                return $this->maybeImport($row);
            }
            return $row;
        }

        $files = $group['files'];
        $done = 0;
        $failed = 0;
        $percentSum = 0.0;
        $reason = '';
        foreach ($files as $f) {
            $fState = (string) ($f['state'] ?? '');
            $percentSum += (float) ($f['percentComplete'] ?? 0);
            if (str_contains($fState, 'Succeeded')) {
                $done++;
            } elseif (preg_match(self::FAILED_PATTERN, $fState)) {
                $failed++;
                $reason = $reason ?: $fState;
            }
        }
        $row['_done'] = $done;
        $row['_percent'] = round($percentSum / max(1, count($files)), 1);

        // Something failed and nothing is still running: what slskd got is all we are going to get.
        // Resolving the row here is what stops a *partially* failed album (12 of 13 tracks, the 13th
        // errored) from orbiting in 'downloading' for ever — it never reaches `done >= expected` and
        // it isn't "every file failed" either, so before this it just sat in the list unusable.
        if ($failed > 0 && $done + $failed === count($files)) {
            // slskd may already have purged some finished transfers; disk is the honest count.
            $done = $row['_done'] = max($done, $this->importer->countAudioFiles((string) $row['local_folder']));
            $row['_percent'] = round(min(100.0, $done / $expected * 100), 1);

            if ($done < $expected) {
                $error = sprintf('Faltan %d de %d pistas (slskd: %s)', $expected - $done, $expected, $reason);
                $this->downloads->markState((int) $row['id'], 'failed', $error);
                $row['state'] = 'failed';
                $row['error'] = $error;
                return $row;
            }
        }

        // expected_files is what was enqueued, so this holds even if slskd already dropped some of
        // the finished transfers from the list.
        if ($done >= $expected) {
            if ($state !== 'importing' && $state !== 'complete') {
                $this->downloads->markState((int) $row['id'], 'complete');
            }
            $row['state'] = 'complete';
            return $this->maybeImport($row);
        }

        if ($state === 'queued') {
            $this->downloads->markState((int) $row['id'], 'downloading');
            $row['state'] = 'downloading';
        }
        return $row;
    }

    private function isStuck(array $row): bool
    {
        $updatedAt = strtotime((string) ($row['updated_at'] ?? '')) ?: 0;
        return $updatedAt > 0 && $updatedAt < time() - self::IMPORT_STUCK_SECONDS;
    }

    /** @return array<string, mixed> */
    private function maybeImport(array $row): array
    {
        $id = (int) $row['id'];
        // Only the request that wins the row imports; concurrent polls (two tabs, or a slow poll
        // overlapped by the next one) move on. Importing twice moves files around twice.
        if (!$this->downloads->claimForImport($id)) {
            return $row;
        }

        try {
            $result = $this->runImport($row);
            $row['state'] = 'imported';
            $row['imported_path'] = $result['moved_to'];
            $row['error'] = null;
        } catch (\Throwable $e) {
            $row['state'] = 'failed';
            $row['error'] = $e->getMessage();
        }
        return $row;
    }

    /**
     * Run the import for a claimed row and record the outcome.
     *
     * @return array{already_imported:bool, moved_to:string, scan:?array}
     */
    private function runImport(array $row): array
    {
        $id = (int) $row['id'];
        // Before the move, never after: see retireReplacedAlbum().
        $this->retireReplacedAlbum($row);
        try {
            $result = $this->importer->import(
                (string) $row['local_folder'],
                $row['target_artist'] !== null ? (string) $row['target_artist'] : null,
                $row['album_title'] !== null ? (string) $row['album_title'] : null
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('Download import failed', ['id' => $id, 'error' => $e->getMessage()]);
            $this->downloads->markState($id, 'failed', $e->getMessage());
            throw $e;
        }

        $this->downloads->markState($id, 'imported', null, $result['moved_to']);
        $this->logger?->info('Download imported', ['id' => $id, 'to' => $result['moved_to']]);
        return $result;
    }

    /**
     * "Reemplazar": turn the album this download replaces into a ghost before the new files land.
     *
     * It has to happen before the import for two reasons. If the old album lives exactly at
     * MUSIC_ROOT/<artist>/<album>, the importer would see its folder and report "already imported"
     * without moving anything. And the scanner only reattaches files to *ghost* tracks: with the old
     * album still live, the new files would enter as new tracks of the same album — the very
     * duplicate the replace is meant to avoid. Ghosting (not forgetting) keeps the ids, so history,
     * favorites and playlists follow the reattached tracks.
     *
     * Once per album: a multi-disc album arrives as one row per remote folder, all pointing at the
     * same album. clearReplace() wipes the mark from every sibling, so the second disc only imports
     * — deleting again would take away what the first disc just brought in. Should two rows race
     * past this anyway, the soft delete of an album that is already a ghost touches nothing.
     *
     * @throws RuntimeException when the old album could not be retired; the row is marked failed
     *                          and nothing is imported, since importing next to the live old album
     *                          would create the duplicate.
     */
    private function retireReplacedAlbum(array $row): void
    {
        // Re-read the mark from the DB, never from $row: reconcile() loads every row before its loop,
        // so a sibling's clearReplace() wouldn't show in it. And "already a ghost" doesn't cover that
        // case — the first disc's import reattaches the ghost and brings it back to life.
        $fresh = $this->downloads->findById((int) $row['id']);
        $albumId = (int) ($fresh['replace_album_id'] ?? 0);
        if ($albumId <= 0) {
            return;
        }
        $album = $this->albums->findById($albumId);
        if ($album === null || ($album['missing_at'] ?? null) !== null) {
            // Already a ghost (a sibling retired it, or the user deleted it meanwhile) or forgotten:
            // nothing to retire, the import reattaches to the ghost or creates the album anew.
            return;
        }

        $id = (int) $row['id'];
        $error = null;
        try {
            $result = $this->deletion->deleteAlbum($albumId);
            // deleteAlbum doesn't throw on a stubborn folder: it leaves the album live and says so.
            if (($result['deleted'] ?? false) !== true) {
                $error = 'quedaron ficheros en disco';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error !== null) {
            $message = 'No se pudo retirar el álbum anterior';
            $this->logger?->warning($message, ['id' => $id, 'album_id' => $albumId, 'error' => $error]);
            $this->downloads->markState($id, 'failed', $message);
            throw new RuntimeException($message);
        }

        $this->downloads->clearReplace($albumId);
        $this->logger?->info('Álbum reemplazado retirado antes de importar', ['id' => $id, 'album_id' => $albumId]);
    }

    /**
     * Group slskd's downloads by (username, full remote directory). The full directory is the key:
     * the basename alone collides across albums that share a subfolder name.
     *
     * @param array<int, array<string, mixed>> $users
     * @return array<string, array{files:array<int, array<string, mixed>>}>
     */
    private function groupTransfers(array $users): array
    {
        $out = [];
        foreach ($users as $u) {
            $username = (string) ($u['username'] ?? '');
            foreach (($u['directories'] ?? []) as $dir) {
                foreach (($dir['files'] ?? []) as $f) {
                    $filename = str_replace('\\', '/', (string) ($f['filename'] ?? ''));
                    $remoteDir = dirname($filename);
                    $key = $this->keyOf($username, $remoteDir);
                    $out[$key]['files'][] = $f + ['username' => $username, 'filename' => $filename];
                }
            }
        }
        return $out;
    }

    private function keyOf(string $username, string $remoteDir): string
    {
        return $username . '|' . str_replace('\\', '/', $remoteDir);
    }

    /** Shape a row for the UI: only what Descargas needs to paint. */
    private function present(array $row): array
    {
        $expected = max(1, (int) $row['expected_files']);
        return [
            'id'             => (int) $row['id'],
            'username'       => (string) $row['slskd_username'],
            'remote_dir'     => (string) $row['remote_dir'],
            'folder'         => (string) $row['local_folder'],
            'artist'         => $row['target_artist'],
            'album'          => $row['album_title'],
            'state'          => (string) $row['state'],
            'error'          => $row['error'] ?? null,
            'imported_path'  => $row['imported_path'] ?? null,
            'expected_files' => $expected,
            'done_files'     => (int) ($row['_done'] ?? 0),
            'percent'        => (float) ($row['_percent'] ?? 0),
        ];
    }
}
