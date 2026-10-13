<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\ArtistRepositoryInterface;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Deletes albums/artists from the disk while keeping them in the database as ghosts (missing_at).
 *
 * Soft delete: the files go first (best-effort) and only then the album and its tracks are marked
 * missing, so ids — and with them play_history, favorites and playlist_tracks — survive and a
 * re-download can be reattached by the scanner. If the disk removal leaves the album folder behind,
 * the album stays live and can be deleted again (never a ghost with files on disk). Artists are
 * never removed here; forgetAlbum() is the hard delete (ON DELETE CASCADE) and only accepts ghosts.
 *
 * Every path is checked against MUSIC_ROOT (realpath, no traversal, never the root itself) before
 * anything is unlinked — same guard as public/stream.php.
 */
class MediaDeletionService
{
    private string $musicRoot;
    private string $coversPath;

    public function __construct(
        private readonly ArtistRepositoryInterface $artists,
        private readonly AlbumRepositoryInterface $albums,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->musicRoot = rtrim($_ENV['MUSIC_ROOT'] ?? '/music', '/');
        $this->coversPath = rtrim($_ENV['COVERS_PATH'] ?? (__DIR__ . '/../../../storage/covers'), '/');
    }

    /**
     * Delete an album's files and turn it into a ghost. Idempotent: on an album that is already a
     * ghost it touches neither disk nor DB. If the folder could not be fully removed the album stays
     * live and `incomplete` is true.
     *
     * @return array{deleted:bool, files_removed:int, ghost:bool, incomplete?:bool}
     */
    public function deleteAlbum(int $albumId): array
    {
        $album = $this->albums->findById($albumId);
        if (!$album) {
            return ['deleted' => false, 'files_removed' => 0, 'ghost' => false];
        }
        if ($this->isGhost($album)) {
            return ['deleted' => true, 'files_removed' => 0, 'ghost' => true];
        }

        $folder = $album['folder_path'] ?? null;
        [$removed, $ghosted] = $this->ghostAlbum($album);
        if (!$ghosted) {
            return ['deleted' => false, 'files_removed' => $removed, 'ghost' => false, 'incomplete' => true];
        }

        // The artist row stays (its ghosts still show in its page); only its folder goes if empty.
        if (is_string($folder) && $folder !== '') {
            $this->removeDirIfEmpty(dirname($folder));
        }

        return ['deleted' => true, 'files_removed' => $removed, 'ghost' => true];
    }

    /**
     * Delete the files of every live album of an artist and turn them into ghosts. The artist stays.
     *
     * @return array{deleted:bool, files_removed:int, albums_removed:int}
     */
    public function deleteArtist(int $artistId): array
    {
        $artist = $this->artists->findById($artistId);
        if (!$artist) {
            return ['deleted' => false, 'files_removed' => 0, 'albums_removed' => 0];
        }

        $removed = 0;
        $ghosted = 0;
        $parents = [];
        foreach ($this->albums->findByArtist($artistId) as $album) {
            if ($this->isGhost($album)) {
                continue;
            }
            [$n, $ok] = $this->ghostAlbum($album);
            $removed += $n;
            if ($ok) {
                $ghosted++;
            }
            $folder = $album['folder_path'] ?? null;
            if (is_string($folder) && $folder !== '') {
                $parents[dirname($folder)] = true;
            }
        }

        // The artist folder is the common parent of its album folders; drop it if now empty.
        foreach (array_keys($parents) as $parent) {
            $this->removeDirIfEmpty($parent);
        }

        return ['deleted' => true, 'files_removed' => $removed, 'albums_removed' => $ghosted];
    }

    /**
     * Hard delete of a ghost album: the row goes (ON DELETE CASCADE wipes its tracks, play_history,
     * favorites and playlist entries). The artist row goes too if it has no album rows left.
     * Live albums are refused (they still have files: the next scan would resurrect them).
     *
     * @return array{forgotten:bool, artist_removed:bool, error?:string} error: 'not_found'|'not_ghost'
     */
    public function forgetAlbum(int $albumId): array
    {
        $album = $this->albums->findById($albumId);
        if (!$album) {
            return ['forgotten' => false, 'artist_removed' => false, 'error' => 'not_found'];
        }
        if (!$this->isGhost($album)) {
            return ['forgotten' => false, 'artist_removed' => false, 'error' => 'not_ghost'];
        }

        $artistId = (int) $album['artist_id'];
        $this->albums->delete($albumId);

        $artistRemoved = false;
        if (empty($this->albums->findByArtist($artistId))) {
            $this->artists->delete($artistId);
            $artistRemoved = true;
        }

        return ['forgotten' => true, 'artist_removed' => $artistRemoved];
    }

    /** @param array<string, mixed> $album */
    private function isGhost(array $album): bool
    {
        return ($album['missing_at'] ?? null) !== null;
    }

    /**
     * Disk first, then DB: the album is marked missing only once its folder is gone.
     *
     * @param array<string, mixed> $album
     * @return array{0:int, 1:bool} [files removed, marked as ghost]
     */
    private function ghostAlbum(array $album): array
    {
        $folder = $album['folder_path'] ?? null;
        $removed = $this->removeAlbumFiles($folder, $album['cover_path'] ?? null);

        if (is_string($folder) && $folder !== '' && is_dir($folder)) {
            $this->logger?->warning('MediaDeletionService: album folder still on disk, album kept live', [
                'album_id' => $album['id'] ?? null, 'folder' => $folder,
            ]);
            return [$removed, false];
        }

        $this->albums->markMissing((int) $album['id']);
        return [$removed, true];
    }

    /**
     * Remove an album's folder (recursively) and, if it lives outside that folder, its cover file.
     */
    private function removeAlbumFiles(?string $folder, ?string $coverPath): int
    {
        $removed = 0;
        if (is_string($folder) && $folder !== '' && $this->isInsideMusicRoot($folder)) {
            $removed += $this->removeDirRecursive($folder);
        }
        // Covers extracted to COVERS_PATH live outside the album folder, so unlink them apart.
        if (is_string($coverPath) && $coverPath !== '' && $this->isInsideCovers($coverPath) && is_file($coverPath)) {
            if (@unlink($coverPath)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** The resolved path must live strictly inside MUSIC_ROOT (no traversal, never the root). */
    private function isInsideMusicRoot(string $path): bool
    {
        return $this->isInside($path, $this->musicRoot);
    }

    private function isInsideCovers(string $path): bool
    {
        return $this->isInside($path, $this->coversPath);
    }

    private function isInside(string $path, string $root): bool
    {
        $real = realpath($path);
        $realRoot = realpath($root) ?: $root;
        if ($real === false || $real === $realRoot) {
            return false;
        }
        return str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR);
    }

    /**
     * Delete a directory tree. Best-effort: a single failed unlink is logged and skipped so we
     * never throw mid-way and desync the DB from disk. Returns the number of files removed.
     */
    private function removeDirRecursive(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } elseif (@unlink($item->getPathname())) {
                $count++;
            } else {
                $this->logger?->warning('MediaDeletionService: could not unlink', ['path' => $item->getPathname()]);
            }
        }
        @rmdir($dir);
        return $count;
    }

    /** rmdir the directory only when it is inside MUSIC_ROOT and contains no entries. */
    private function removeDirIfEmpty(string $dir): void
    {
        if (!is_dir($dir) || !$this->isInsideMusicRoot($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries !== false && count($entries) <= 2) { // only '.' and '..'
            @rmdir($dir);
        }
    }
}
