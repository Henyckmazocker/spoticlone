<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\ArtistRepositoryInterface;
use App\Domain\Repository\Music\ScanRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\LibraryScanner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Reattach of ghost rows and prune of vanished files in LibraryScanner, with repository stubs and
 * real (silent, tiny) WAV files in a temporary MUSIC_ROOT.
 */
class LibraryScannerReattachTest extends TestCase
{
    private const GHOST_ALBUM = ['id' => 3, 'artist_id' => 2, 'title' => 'Album Fantasma', 'artist_name' => 'Band'];

    private array $previousEnv = [];
    private string $root;

    private ArtistRepositoryInterface&MockObject $artists;
    private AlbumRepositoryInterface&MockObject $albums;
    private TrackRepositoryInterface&MockObject $tracks;
    private ScanRepositoryInterface&MockObject $scans;

    protected function setUp(): void
    {
        foreach (['MUSIC_ROOT', 'COVERS_PATH'] as $k) {
            $this->previousEnv[$k] = $_ENV[$k] ?? null;
        }
        $this->root = sys_get_temp_dir() . '/scanner_reattach_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/music', 0777, true);
        mkdir($this->root . '/covers', 0777, true);
        $_ENV['MUSIC_ROOT'] = $this->root . '/music';
        $_ENV['COVERS_PATH'] = $this->root . '/covers';

        $this->artists = $this->createMock(ArtistRepositoryInterface::class);
        $this->albums = $this->createMock(AlbumRepositoryInterface::class);
        $this->tracks = $this->createMock(TrackRepositoryInterface::class);
        $this->scans = $this->createMock(ScanRepositoryInterface::class);
        $this->scans->method('start')->willReturn(1);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        $this->rmTree($this->root);
    }

    // --- Reattach -------------------------------------------------------------------------------

    public function test_ghost_on_its_own_path_is_revived_even_with_same_mtime(): void
    {
        $path = $this->wav('Band/Album Fantasma/01 - Uno.wav');
        $this->tracks->method('findByPath')->willReturn([
            'id' => 7, 'mtime' => filemtime($path), 'missing_at' => '2026-10-13 10:00:00',
        ]);
        $this->albums->method('findGhostByNormalized')->willReturn(self::GHOST_ALBUM);
        $this->tracks->expects($this->never())->method('findGhostInAlbum');
        $this->tracks->expects($this->once())->method('reattach')
            ->with(7, $this->callback(fn($d) => $d['file_path'] === $path && $d['album_id'] === 3));
        $this->tracks->expects($this->never())->method('insert');
        $this->tracks->expects($this->never())->method('update');
        $this->albums->expects($this->atLeastOnce())->method('clearMissing')->with(3, dirname($path));
        // A subtree scan never prunes.
        $this->tracks->expects($this->never())->method('liveFilePaths');

        $stats = $this->scanner()->scan($this->root . '/music/Band');

        $this->assertSame(1, $stats['tracks_updated']);
        $this->assertSame(0, $stats['tracks_added']);
        $this->assertSame(0, $stats['tracks_missing']);
    }

    public function test_new_path_reattaches_by_disc_and_track_number(): void
    {
        $path = $this->wav('Otra Carpeta/Disco/cualquier nombre.wav', ['title' => 'Mi Titulo', 'artist' => 'Band', 'album' => 'Disco', 'track' => 3]);
        $this->tracks->method('findByPath')->willReturn(null);
        $this->albums->method('findGhostByNormalized')->with('band', 'disco')
            ->willReturn(['id' => 3, 'artist_id' => 2, 'title' => 'Disco', 'artist_name' => 'Band']);
        $this->tracks->expects($this->once())->method('findGhostInAlbum')
            ->with(3, null, 3, 'mititulo')->willReturn(['id' => 40]);
        $this->tracks->expects($this->once())->method('reattach')
            ->with(40, $this->callback(fn($d) => $d['file_path'] === $path && $d['track_no'] === 3));
        $this->tracks->expects($this->never())->method('insert');

        $stats = $this->scanner()->scan($this->root . '/music/Otra Carpeta');

        $this->assertSame(1, $stats['tracks_updated']);
        $this->assertSame(0, $stats['tracks_added']);
    }

    public function test_untagged_file_reattaches_by_normalized_title(): void
    {
        $this->wav('Band/Album Fantasma/01 - Canción.wav');
        $this->tracks->method('findByPath')->willReturn(null);
        $this->albums->method('findGhostByNormalized')->willReturn(self::GHOST_ALBUM);
        $this->tracks->expects($this->once())->method('findGhostInAlbum')
            ->with(3, null, null, 'cancion')->willReturn(['id' => 41]);
        $this->tracks->expects($this->once())->method('reattach')->with(41, $this->anything());
        $this->tracks->expects($this->never())->method('insert');

        $stats = $this->scanner()->scan($this->root . '/music/Band');

        $this->assertSame(1, $stats['tracks_updated']);
    }

    public function test_ambiguous_title_inserts_a_new_track_in_the_revived_album(): void
    {
        $this->wav('Band/Album Fantasma/Intro.wav');
        $this->tracks->method('findByPath')->willReturn(null);
        $this->albums->method('findGhostByNormalized')->willReturn(self::GHOST_ALBUM);
        // Two ghost "Intro": the repository declines to pick one.
        $this->tracks->method('findGhostInAlbum')->willReturn(null);
        $this->tracks->expects($this->never())->method('reattach');
        $this->tracks->expects($this->once())->method('insert')
            ->with($this->callback(fn($d) => $d['album_id'] === 3 && $d['artist_id'] === 2));
        // The album still has a live track now: it must not stay grey.
        $this->albums->expects($this->once())->method('clearMissing')->with(3, $this->stringEndsWith('/Album Fantasma'));

        $stats = $this->scanner()->scan($this->root . '/music/Band');

        $this->assertSame(0, $stats['tracks_updated']);
        $this->assertSame(1, $stats['tracks_added']);
    }

    public function test_album_title_differing_by_accents_and_the_reuses_the_ghost(): void
    {
        $dir = 'The Band/Álbum Fantasma';
        $this->wav($dir . '/01 - Uno.wav');
        $this->wav($dir . '/02 - Dos.wav');
        $this->tracks->method('findByPath')->willReturn(null);
        // Only the first file finds the ghost; after clearMissing it is no longer one.
        $this->albums->expects($this->once())->method('findGhostByNormalized')
            ->with('band', 'albumfantasma')->willReturn(self::GHOST_ALBUM);
        $this->artists->expects($this->never())->method('upsertByName');
        $this->albums->expects($this->never())->method('upsert');
        $this->albums->expects($this->atLeastOnce())->method('clearMissing')
            ->with(3, $this->root . '/music/' . $dir);
        $this->tracks->method('findGhostInAlbum')->willReturnOnConsecutiveCalls(['id' => 50], ['id' => 51]);
        $reattached = [];
        $this->tracks->expects($this->exactly(2))->method('reattach')
            ->willReturnCallback(function (int $id, array $d) use (&$reattached) {
                $reattached[$id] = [$d['album_id'], $d['artist_id']];
            });

        $stats = $this->scanner()->scan($this->root . '/music/The Band');

        $this->assertSame([50 => [3, 2], 51 => [3, 2]], $reattached);
        $this->assertSame(2, $stats['tracks_updated']);
    }

    // --- Prune ----------------------------------------------------------------------------------

    public function test_full_scan_prunes_vanished_files(): void
    {
        $live = $this->liveLibrary(10, 1);
        $this->tracks->method('liveFilePaths')->willReturn($live);
        $this->tracks->expects($this->once())->method('markMissingByIds')->with([10]);
        $this->albums->expects($this->once())->method('markMissingWhereNoLiveTracks')->willReturn(0);
        $this->scans->expects($this->once())->method('finish')
            ->with(1, $this->anything(), 'completed', '1 pistas marcadas como borradas');

        $stats = $this->scanner()->scan();

        $this->assertSame(9, $stats['files_seen']);
        $this->assertSame(1, $stats['tracks_missing']);
    }

    public function test_prune_is_aborted_above_the_max_ratio(): void
    {
        $live = $this->liveLibrary(10, 3); // 30 % gone
        $this->tracks->method('liveFilePaths')->willReturn($live);
        $this->tracks->expects($this->never())->method('markMissingByIds');
        $this->albums->expects($this->never())->method('markMissingWhereNoLiveTracks');
        $this->scans->expects($this->once())->method('finish')
            ->with(1, $this->anything(), 'completed', $this->stringStartsWith('Poda abortada'));

        $stats = $this->scanner()->scan();

        $this->assertSame(0, $stats['tracks_missing']);
    }

    public function test_prune_is_aborted_when_the_scan_saw_no_file(): void
    {
        // Empty MUSIC_ROOT, as with an unmounted disk.
        $this->tracks->method('liveFilePaths')->willReturn([1 => '/music/A/B/1.flac', 2 => '/music/A/B/2.flac']);
        $this->tracks->expects($this->never())->method('markMissingByIds');
        $this->albums->expects($this->never())->method('markMissingWhereNoLiveTracks');
        $this->scans->expects($this->once())->method('finish')
            ->with(1, $this->anything(), 'completed', $this->stringStartsWith('Poda abortada'));

        $stats = $this->scanner()->scan();

        $this->assertSame(0, $stats['files_seen']);
        $this->assertSame(0, $stats['tracks_missing']);
    }

    // --- Helpers --------------------------------------------------------------------------------

    private function scanner(): LibraryScanner
    {
        return new LibraryScanner($this->artists, $this->albums, $this->tracks, $this->scans);
    }

    /**
     * $total live tracks, the last $gone of them without a file. Present files are seen as
     * unchanged live rows. @return array<int, string> id => path
     */
    private function liveLibrary(int $total, int $gone): array
    {
        $live = [];
        $known = [];
        for ($i = 1; $i <= $total; $i++) {
            $rel = sprintf('Band/Album/%02d - Track.wav', $i);
            if ($i <= $total - $gone) {
                $path = $this->wav($rel);
                $known[$path] = ['id' => $i, 'mtime' => filemtime($path), 'missing_at' => null];
            } else {
                $path = $this->root . '/music/' . $rel;
            }
            $live[$i] = $path;
        }
        $this->tracks->method('findByPath')->willReturnCallback(fn(string $p) => $known[$p] ?? null);
        $this->tracks->expects($this->never())->method('insert');
        return $live;
    }

    /**
     * A silent 8 kHz mono WAV; with $tag, an ID3v1 tag (title/artist/album/track) is appended,
     * which getID3 reads on any format.
     */
    private function wav(string $relative, ?array $tag = null): string
    {
        $path = $this->root . '/music/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $n = 800;
        $bytes = 'RIFF' . pack('V', 36 + $n) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            . 'data' . pack('V', $n) . str_repeat("\0", $n);
        if ($tag !== null) {
            $f = fn(string $s, int $l) => str_pad(substr($s, 0, $l), $l, "\0");
            $bytes .= 'TAG' . $f($tag['title'], 30) . $f($tag['artist'], 30) . $f($tag['album'], 30)
                . '2001' . $f('', 28) . "\0" . chr($tag['track']) . chr(255);
        }
        file_put_contents($path, $bytes);
        return $path;
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
