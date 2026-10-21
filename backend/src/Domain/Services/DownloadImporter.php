<?php

declare(strict_types=1);

namespace App\Domain\Services;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Moves a completed Soulseek download from DOWNLOADS_ROOT into the music library
 * (MUSIC_ROOT/<artist>/<album>) and re-scans just that folder so it shows up immediately.
 *
 * The destination is keyed by artist+album, not by the downloaded folder's name: slskd names the
 * local folder after the remote directory, and those collide across albums ("CD1", "Disc 1",
 * "Various"). Keying by album is what stops a second album from being silently skipped as
 * "already imported". Folder layout is only organisational anyway — the scanner indexes by
 * embedded tags.
 *
 * Path-safety: both the resolved source and destination must stay inside their roots (no traversal).
 */
class DownloadImporter
{
    /** How deep to look for the download folder if it is not directly under DOWNLOADS_ROOT. */
    private const LOCAL_FOLDER_SEARCH_DEPTH = 3;

    private const AUDIO_EXTS = ['mp3', 'flac', 'ogg', 'm4a', 'wav', 'opus', 'aac', 'wma'];

    private string $downloadsRoot;
    private string $musicRoot;

    public function __construct(
        private readonly LibraryScanner $scanner,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->downloadsRoot = rtrim($_ENV['DOWNLOADS_ROOT'] ?? '/downloads', '/');
        $this->musicRoot = rtrim($_ENV['MUSIC_ROOT'] ?? '/music', '/');
    }

    /**
     * @param string $relPath path of the downloaded file/folder relative to DOWNLOADS_ROOT
     * @param string|null $artist target artist subfolder (from the Spotify selection)
     * @param string|null $album target album subfolder; falls back to the download's own name
     * @return array{already_imported:bool, moved_to:string, scan:?array}
     */
    public function import(string $relPath, ?string $artist = null, ?string $album = null): array
    {
        $src = $this->resolveSource($relPath);
        if ($src === null) {
            throw new RuntimeException('La descarga no existe o está fuera de DOWNLOADS_ROOT.');
        }

        $dest = $this->destinationFor($src, $artist, $album);

        // Already imported? If the destination already exists, skip — don't move or duplicate
        // (no "… (2)" copies, no extra scan).
        if (file_exists($dest)) {
            $this->logger?->info('Download already imported, skipping', ['dest' => $dest]);
            return ['already_imported' => true, 'moved_to' => $dest, 'scan' => null];
        }

        $parent = dirname($dest);
        // Containment BEFORE mkdir: realpath() fails on paths that don't exist yet, so check the
        // closest ancestor that does. A destination that would escape the library creates nothing.
        if ($this->resolveInside($this->musicRoot, $this->firstExistingAncestor($parent)) === null) {
            throw new RuntimeException('Destino fuera de MUSIC_ROOT.');
        }
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('No se pudo crear la carpeta destino en la biblioteca.');
        }
        // Defense in depth: destination must remain inside the library.
        if ($this->resolveInside($this->musicRoot, $parent) === null) {
            throw new RuntimeException('Destino fuera de MUSIC_ROOT.');
        }

        $this->moveItem($src, $dest);
        $this->logger?->info('Download imported', ['from' => $src, 'to' => $dest]);

        // Only rescan what we just added, not the whole library.
        // The files are already in place: if another scan holds the lock past the wait, the next
        // full scan picks them up, so log it and report no scan instead of failing the import.
        try {
            $scan = $this->scanner->scan(is_dir($dest) ? $dest : dirname($dest));
        } catch (ScanInProgressException $e) {
            $this->logger?->warning('Download imported but the follow-up scan was skipped: scan in progress', ['dest' => $dest]);
            $scan = null;
        }
        return ['already_imported' => false, 'moved_to' => $dest, 'scan' => $scan];
    }

    /**
     * How many audio files the download already has on disk, or -1 if the folder isn't there.
     *
     * The reconciler needs this because slskd purges finished transfers from its list: once that
     * happens the API says nothing about a download that did in fact complete, and counting what
     * landed on disk is the only way to tell "finished" from "vanished".
     */
    public function countAudioFiles(string $relPath): int
    {
        $src = $this->resolveSource($relPath);
        if ($src === null) {
            return -1;
        }
        if (is_file($src)) {
            return $this->isAudio($src) ? 1 : 0;
        }

        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile() && $this->isAudio($file->getPathname())) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Throw away a download's folder under DOWNLOADS_ROOT (cancelled, or given up on).
     *
     * Only ever touches DOWNLOADS_ROOT — resolveSource refuses anything outside it, so this can't
     * be pointed at the library. Without it, every cancelled or failed attempt left a half album
     * behind and /downloads slowly filled up with them.
     *
     * @return bool true if something was deleted
     */
    public function discard(string $relPath): bool
    {
        $src = $this->resolveSource($relPath);
        if ($src === null) {
            return false;   // ya no está: nada que borrar
        }
        $this->deleteRecursive($src);
        $this->logger?->info('Descarga descartada', ['path' => $src]);
        return true;
    }

    private function isAudio(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::AUDIO_EXTS, true);
    }

    /**
     * MUSIC_ROOT/<artist>/<album>, with disc folders nested underneath the album
     * (MUSIC_ROOT/<artist>/<album>/CD1) so multi-disc releases stay together.
     */
    private function destinationFor(string $src, ?string $artist, ?string $album): string
    {
        $artistDir = $this->musicRoot . '/' . $this->sanitize($artist ?: 'Soulseek');
        $name = basename($src);

        if ($album === null || trim($album) === '') {
            return $artistDir . '/' . $this->sanitize($name);
        }

        $albumDir = $artistDir . '/' . $this->sanitize($album);
        return $this->isDiscFolder($name)
            ? $albumDir . '/' . $this->sanitize($name)
            : $albumDir;
    }

    private function isDiscFolder(string $name): bool
    {
        return (bool) preg_match('/^(cd|disc|disco|disk)\s*\.?\s*\d+$/i', trim($name));
    }

    /**
     * Locate the download under DOWNLOADS_ROOT. slskd normally drops it at the top level, but it
     * can be configured to nest by username, so fall back to a bounded search by folder name
     * before giving up.
     */
    private function resolveSource(string $relPath): ?string
    {
        // The root itself is never a valid source: discard() would wipe it and import() would move it.
        $rel = trim(str_replace('\\', '/', $relPath), '/');
        if ($rel === '' || $rel === '.' || $rel === '..') {
            return null;
        }
        $direct = $this->resolveInside($this->downloadsRoot, $this->downloadsRoot . '/' . $rel);
        if ($direct !== null && $direct !== (realpath($this->downloadsRoot) ?: $this->downloadsRoot) && file_exists($direct)) {
            return $direct;
        }

        $name = basename(str_replace('\\', '/', $relPath));
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }
        return $this->findByName($this->downloadsRoot, $name, self::LOCAL_FOLDER_SEARCH_DEPTH);
    }

    /** Breadth-limited search for an entry called $name under $dir. */
    private function findByName(string $dir, string $name, int $depth): ?string
    {
        if ($depth < 0 || !is_dir($dir)) {
            return null;
        }
        $entries = @scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if ($entry === $name) {
                return $this->resolveInside($this->downloadsRoot, $path);
            }
            if (is_dir($path) && !is_link($path)) {
                $found = $this->findByName($path, $name, $depth - 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Move a file or directory into place atomically from the library's point of view: stage the
     * copy in a hidden temp folder and rename it onto $dest only once it is complete. A crash or
     * an I/O error mid-copy therefore leaves nothing half-imported — the previous version left the
     * partial copy behind, and the retry then reported it as "already imported".
     */
    private function moveItem(string $src, string $dest): void
    {
        // Same filesystem: a plain rename is already atomic and costs nothing.
        if (@rename($src, $dest)) {
            return;
        }

        // Cross-device (inside the container /music and /downloads are separate bind mounts, so
        // rename() fails with EXDEV even on the same host disk): copy, then swap into place.
        $staging = dirname($dest) . '/.import-' . uniqid('', true);
        try {
            if (!$this->copyRecursive($src, $staging)) {
                throw new RuntimeException('No se pudo copiar la descarga a la biblioteca.');
            }
            if (!@rename($staging, $dest)) {
                throw new RuntimeException('No se pudo mover la descarga a su carpeta final.');
            }
        } catch (\Throwable $e) {
            $this->deleteRecursive($staging);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage());
        }

        $this->deleteRecursive($src);
    }

    private function copyRecursive(string $src, string $dest): bool
    {
        if (is_dir($src)) {
            if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
                return false;
            }
            foreach (scandir($src) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!$this->copyRecursive($src . '/' . $entry, $dest . '/' . $entry)) {
                    return false;
                }
            }
            return true;
        }
        return @copy($src, $dest);
    }

    private function deleteRecursive(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->deleteRecursive($path . '/' . $entry);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    /**
     * Resolve $path and require it to be $root itself or below it. The trailing separator in the
     * prefix check matters: a plain strpos() would accept "/downloads-old" as inside "/downloads".
     */
    private function resolveInside(string $root, string $path): ?string
    {
        $real = realpath($path);
        $realRoot = realpath($root) ?: $root;
        if ($real === false) {
            return null;
        }
        return ($real === $realRoot || str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) ? $real : null;
    }

    /** Closest existing ancestor of $path (or $path itself), so realpath() has something to resolve. */
    private function firstExistingAncestor(string $path): string
    {
        while (!file_exists($path) && !is_link($path)) {
            $up = dirname($path);
            if ($up === $path) {
                break;
            }
            $path = $up;
        }
        return $path;
    }

    private function sanitize(string $s): string
    {
        // Strip path separators and control chars; keep it filesystem-safe.
        $s = preg_replace('#[/\\\\\x00-\x1F]+#', ' ', $s);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        $s = trim($s, '. ');   // no '.', '..', '...' or trailing dots as a folder name
        return $s === '' ? 'Soulseek' : mb_substr($s, 0, 200);
    }
}
