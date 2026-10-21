<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\ArtistRepositoryInterface;
use App\Domain\Repository\Music\ScanRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Walks MUSIC_ROOT (Artist/Album/track structure), reads embedded tags with getID3, and
 * upserts artists/albums/tracks. Idempotent: a track is keyed by its file_path, and re-scanning
 * an unchanged file (same mtime) is skipped. Cover art is resolved per album folder (a cover
 * file in the folder, or the first embedded picture dumped to COVERS_PATH).
 *
 * Ghosts (rows with missing_at, see MediaDeletionService): a file that comes back is reattached to
 * the ghost's rows (same path, or same normalised artist+album and then disc/track no or title), so
 * play history, favourites and playlists survive a delete + re-download. A full scan also prunes:
 * live tracks whose file is gone become ghosts, unless the scan looks like a missing disk.
 */
class LibraryScanner
{
    public const AUDIO_EXTS = ['mp3', 'flac', 'ogg', 'm4a', 'wav', 'opus', 'aac', 'wma'];
    private const COVER_NAMES = ['cover.jpg', 'cover.jpeg', 'cover.png', 'folder.jpg', 'folder.png', 'front.jpg', 'front.png'];
    /**
     * The prune is aborted entirely when it would turn more than this share of the live tracks
     * into ghosts: that looks like an unmounted disk or an empty bind-mount, not real deletions.
     */
    private const PRUNE_MAX_RATIO = 0.2;
    /** Seconds a partial scan waits for the scan lock (the full scan does not wait). */
    private const PARTIAL_LOCK_TIMEOUT = 120;

    private string $musicRoot;
    private string $coversPath;
    /**
     * Ghost albums revived during the current scan, keyed by normalised artist + title => [albumId,
     * artistId]. Once the first track clears missing_at, findGhostByNormalized() no longer sees the
     * album, and the remaining tracks of the folder must still land on it (not on a new album).
     *
     * @var array<string, array{0:int, 1:int}>
     */
    private array $revivedAlbums = [];

    public function __construct(
        private readonly ArtistRepositoryInterface $artists,
        private readonly AlbumRepositoryInterface $albums,
        private readonly TrackRepositoryInterface $tracks,
        private readonly ScanRepositoryInterface $scans,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->musicRoot = rtrim($_ENV['MUSIC_ROOT'] ?? '/music', '/');
        $this->coversPath = rtrim($_ENV['COVERS_PATH'] ?? (__DIR__ . '/../../../storage/covers'), '/');
    }

    /**
     * Run a scan. Returns the run stats.
     *
     * @param string|null $onlyPath When given, scan just that subtree instead of the whole library.
     *        Used after importing a Soulseek download: walking the entire collection with getID3
     *        to pick up one new album is needlessly expensive. Must live inside MUSIC_ROOT.
     *        A subtree scan never prunes (it cannot tell a deleted file from one it did not walk).
     * @return array{run_id:int, files_seen:int, tracks_added:int, tracks_updated:int, errors:int, tracks_missing:int}
     */
    public function scan(?string $onlyPath = null): array
    {
        // Full scan fails fast; a partial one (after an import) waits for the running scan to end.
        // The lock is taken BEFORE start() so a refused scan leaves no `running` row behind.
        if (!$this->scans->acquireLock($onlyPath === null ? 0 : self::PARTIAL_LOCK_TIMEOUT)) {
            throw new ScanInProgressException('Ya hay un escaneo en curso.');
        }
        try {
            return $this->runScan($onlyPath);
        } finally {
            $this->scans->releaseLock();
        }
    }

    /** @return array{run_id:int, files_seen:int, tracks_added:int, tracks_updated:int, errors:int, tracks_missing:int} */
    private function runScan(?string $onlyPath): array
    {
        $runId = $this->scans->start();
        $stats = ['files_seen' => 0, 'tracks_added' => 0, 'tracks_updated' => 0, 'errors' => 0, 'tracks_missing' => 0];
        $this->revivedAlbums = [];

        if (!is_dir($this->musicRoot)) {
            $this->scans->finish($runId, $stats, 'failed', "MUSIC_ROOT no existe: {$this->musicRoot}");
            return ['run_id' => $runId] + $stats;
        }

        $root = $this->musicRoot;
        if ($onlyPath !== null) {
            $resolved = $this->resolveInsideRoot($onlyPath);
            if ($resolved === null) {
                $this->scans->finish($runId, $stats, 'failed', "Ruta fuera de MUSIC_ROOT: {$onlyPath}");
                return ['run_id' => $runId] + $stats;
            }
            $root = $resolved;
        }

        if (!is_dir($this->coversPath)) {
            @mkdir($this->coversPath, 0775, true);
        }

        $getID3 = class_exists('getID3') ? new \getID3() : null;
        // Per-album-folder cache so cover resolution runs once per folder.
        $coverDoneForAlbum = [];

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($it as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $ext = strtolower($fileInfo->getExtension());
                if (!in_array($ext, self::AUDIO_EXTS, true)) {
                    continue; // ignores .afpk, *_spectrogram.png, .sqlite, .xml, covers, etc.
                }

                $stats['files_seen']++;
                try {
                    $this->ingest($fileInfo->getPathname(), $ext, $getID3, $stats, $coverDoneForAlbum);
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->logger?->warning('Scan: failed to ingest file', [
                        'file' => $fileInfo->getPathname(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $message = $onlyPath === null ? $this->prune($stats) : null;
            $this->scans->finish($runId, $stats, 'completed', $message);
        } catch (\Throwable $e) {
            $this->scans->finish($runId, $stats, 'failed', $e->getMessage());
            $this->logger?->error('Scan failed', ['error' => $e->getMessage()]);
        }

        return ['run_id' => $runId] + $stats;
    }

    /**
     * Turn into ghosts the live tracks whose file no longer exists, then the albums left with no
     * live track. Guarded against a missing disk: nothing is marked when the walk saw no audio file
     * or when more than PRUNE_MAX_RATIO of the live tracks would go. Returns the scan_runs message.
     */
    private function prune(array &$stats): ?string
    {
        $live = $this->tracks->liveFilePaths();
        if ($live === []) {
            return null;
        }
        if ($stats['files_seen'] === 0) {
            return 'Poda abortada: el escaneo no encontró ningún fichero de audio (¿disco sin montar?)';
        }

        $missing = [];
        foreach ($live as $id => $filePath) {
            if (!is_file($filePath)) {
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return null;
        }

        $ratio = count($missing) / count($live);
        if ($ratio > self::PRUNE_MAX_RATIO) {
            $this->logger?->warning('Scan: prune aborted', ['missing' => count($missing), 'live' => count($live)]);
            return sprintf(
                'Poda abortada: %d de %d pistas (%d %%) no están en disco, más del %d %% permitido',
                count($missing), count($live), (int) round($ratio * 100), (int) round(self::PRUNE_MAX_RATIO * 100)
            );
        }

        $this->tracks->markMissingByIds($missing);
        $this->albums->markMissingWhereNoLiveTracks();
        $stats['tracks_missing'] = count($missing);
        return count($missing) . ' pistas marcadas como borradas';
    }

    /**
     * Resolve a directory and require it to be MUSIC_ROOT or below it. The separator in the prefix
     * check matters: without it a sibling like "/music-old" would pass as inside "/music".
     */
    private function resolveInsideRoot(string $path): ?string
    {
        $real = realpath($path);
        $realRoot = realpath($this->musicRoot) ?: $this->musicRoot;
        if ($real === false || !is_dir($real)) {
            return null;
        }
        return ($real === $realRoot || str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) ? $real : null;
    }

    /**
     * Artist when tags have none: the artist folder, never MUSIC_ROOT itself. A loose file
     * (MUSIC_ROOT/<artist>/file) takes its own folder; an album file
     * (MUSIC_ROOT/<artist>/<album>/file) takes the grandparent folder.
     */
    public function fallbackArtist(string $path): string
    {
        $folder = dirname($path);
        if (dirname($folder) === $this->musicRoot) {
            return basename($folder);
        }
        return basename(dirname($folder));
    }

    private function ingest(string $path, string $ext, ?\getID3 $getID3, array &$stats, array &$coverDoneForAlbum): void
    {
        $mtime = (int) (@filemtime($path) ?: 0);
        $existing = $this->tracks->findByPath($path);
        // A ghost coming back on its own path (disk remounted, restored by hand) must be revived
        // even with the same mtime, so missing_at is checked before the "unchanged" shortcut.
        $isGhost = $existing !== null && ($existing['missing_at'] ?? null) !== null;
        if ($existing && !$isGhost && (int) ($existing['mtime'] ?? 0) === $mtime) {
            return; // unchanged
        }

        $tags = $this->readTags($path, $getID3);
        $folder = dirname($path);

        // Artist: prefer album_artist, then artist, then the artist folder (see fallbackArtist()).
        $artistName = $this->clean($tags['album_artist']
            ?? $tags['artist']
            ?? $this->fallbackArtist($path));
        $albumTitle = $this->clean($tags['album'] ?? basename($folder));
        $title = $this->clean($tags['title'] ?? $this->titleFromFilename($path));

        // A live track keeps its album as today; a new or ghost file first looks for a ghost album
        // with the same normalised artist+title (accents, case, "The" may differ from the tags).
        [$artistId, $albumId] = ($existing && !$isGhost)
            ? $this->upsertAlbum($artistName, $albumTitle, $folder, $tags['year'])
            : $this->resolveAlbum($artistName, $albumTitle, $folder, $tags['year']);

        $data = [
            'album_id'         => $albumId,
            'artist_id'        => $artistId,
            'title'            => $title,
            'track_no'         => $tags['track_no'],
            'disc_no'          => $tags['disc_no'],
            'duration_seconds' => $tags['duration'],
            'file_path'        => $path,
            'file_format'      => $ext,
            'file_size'        => (int) (@filesize($path) ?: 0),
            'bitrate'          => $tags['bitrate'],
            'sample_rate'      => $tags['sample_rate'],
            'mtime'            => $mtime,
            'genre'            => $tags['genre'],
        ];

        $ghostTrack = $existing === null
            ? $this->tracks->findGhostInAlbum($albumId, $tags['disc_no'], $tags['track_no'], Normalize::name($title))
            : null;

        if ($isGhost || $ghostTrack !== null) {
            $this->tracks->reattach((int) ($isGhost ? $existing['id'] : $ghostTrack['id']), $data);
            $this->albums->clearMissing($albumId, $folder);
            $stats['tracks_updated']++;
        } elseif ($existing) {
            $this->tracks->update((int) $existing['id'], $data);
            $stats['tracks_updated']++;
        } else {
            $this->tracks->insert($data);
            $stats['tracks_added']++;
        }

        if (!isset($coverDoneForAlbum[$albumId])) {
            $coverDoneForAlbum[$albumId] = true;
            $this->resolveCover($albumId, $folder, $tags['picture'] ?? null);
        }
    }

    /**
     * Album (and artist) for a new or ghost file: a ghost album with the same normalised
     * artist+title is reused and revived (its folder_path moves to the new folder), so even a new
     * track inserted into it does not sit under a grey header. Otherwise, the usual upsert.
     *
     * @return array{0:int, 1:int} [artistId, albumId]
     */
    private function resolveAlbum(string $artistName, string $albumTitle, string $folder, ?int $year): array
    {
        $key = Normalize::name($artistName) . "\0" . Normalize::name($albumTitle);
        if (isset($this->revivedAlbums[$key])) {
            [$albumId, $artistId] = $this->revivedAlbums[$key];
            return [$artistId, $albumId];
        }

        $ghost = $this->albums->findGhostByNormalized(Normalize::name($artistName), Normalize::name($albumTitle));
        if ($ghost === null) {
            return $this->upsertAlbum($artistName, $albumTitle, $folder, $year);
        }

        $albumId = (int) $ghost['id'];
        $artistId = (int) $ghost['artist_id'];
        $this->albums->clearMissing($albumId, $folder);
        $this->revivedAlbums[$key] = [$albumId, $artistId];
        return [$artistId, $albumId];
    }

    /** @return array{0:int, 1:int} [artistId, albumId] */
    private function upsertAlbum(string $artistName, string $albumTitle, string $folder, ?int $year): array
    {
        $artistId = $this->artists->upsertByName($artistName);
        return [$artistId, $this->albums->upsert($artistId, $albumTitle, $folder, $year)];
    }

    /**
     * @return array{title:?string,artist:?string,album_artist:?string,album:?string,track_no:?int,
     *               disc_no:?int,year:?int,genre:?string,duration:?int,bitrate:?int,sample_rate:?int,
     *               picture:?array{data:string,ext:string}}
     */
    private function readTags(string $path, ?\getID3 $getID3): array
    {
        $out = [
            'title' => null, 'artist' => null, 'album_artist' => null, 'album' => null,
            'track_no' => null, 'disc_no' => null, 'year' => null, 'genre' => null,
            'duration' => null, 'bitrate' => null, 'sample_rate' => null, 'picture' => null,
        ];
        if ($getID3 === null) {
            return $out;
        }

        $info = $getID3->analyze($path);
        if (class_exists('getid3_lib')) {
            \getid3_lib::CopyTagsToComments($info);
        }
        $c = $info['comments'] ?? [];

        $first = fn(string $k) => isset($c[$k][0]) && $c[$k][0] !== '' ? (string) $c[$k][0] : null;

        $out['title']        = $first('title');
        $out['artist']       = $first('artist');
        $out['album_artist'] = $first('album_artist') ?? $first('albumartist') ?? $first('band');
        $out['album']        = $first('album');
        $out['genre']        = $first('genre');

        $track = $first('track_number') ?? $first('track') ?? $first('tracknumber');
        $out['track_no'] = $track !== null ? (int) $track : null;
        $disc = $first('part_of_a_set') ?? $first('discnumber') ?? $first('disc');
        $out['disc_no'] = $disc !== null ? (int) $disc : null;

        $year = $first('year') ?? $first('date') ?? $first('creation_date');
        if ($year !== null && preg_match('/(\d{4})/', $year, $m)) {
            $out['year'] = (int) $m[1];
        }

        if (isset($info['playtime_seconds'])) {
            $out['duration'] = (int) round((float) $info['playtime_seconds']);
        }
        if (isset($info['audio']['bitrate'])) {
            $out['bitrate'] = (int) round((float) $info['audio']['bitrate']);
        }
        if (isset($info['audio']['sample_rate'])) {
            $out['sample_rate'] = (int) $info['audio']['sample_rate'];
        }

        if (isset($c['picture'][0]['data'])) {
            $mime = $c['picture'][0]['image_mime'] ?? 'image/jpeg';
            $out['picture'] = ['data' => $c['picture'][0]['data'], 'ext' => $this->mimeExt($mime)];
        }

        return $out;
    }

    /** Prefer a cover file in the album folder; else dump the embedded picture once. */
    private function resolveCover(int $albumId, string $folder, ?array $picture): void
    {
        $album = $this->albums->findById($albumId);
        if ($album && !empty($album['cover_path']) && is_file($album['cover_path'])) {
            return;
        }

        foreach (self::COVER_NAMES as $name) {
            $candidate = $folder . '/' . $name;
            if (is_file($candidate)) {
                $this->albums->setCoverPath($albumId, $candidate);
                return;
            }
        }

        if ($picture !== null && $picture['data'] !== '') {
            $dest = $this->coversPath . '/album_' . $albumId . '.' . $picture['ext'];
            if (@file_put_contents($dest, $picture['data']) !== false) {
                $this->albums->setCoverPath($albumId, $dest);
            }
        }
    }

    private function titleFromFilename(string $path): string
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        // Strip a leading track number like "01. ", "01 - ", "1 ".
        return trim(preg_replace('/^\s*\d{1,3}\s*[.\-_)]?\s*/', '', $name)) ?: $name;
    }

    private function clean(string $s): string
    {
        $s = trim($s);
        return $s === '' ? 'Unknown' : mb_substr($s, 0, 500);
    }

    private function mimeExt(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }
}
