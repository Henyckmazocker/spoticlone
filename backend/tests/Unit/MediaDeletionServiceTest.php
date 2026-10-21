<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\ArtistRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\MediaDeletionService;
use PHPUnit\Framework\TestCase;

class MediaDeletionServiceTest extends TestCase
{
    private string $base;
    private string $music;
    private string $covers;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/spoticlone_del_' . uniqid();
        $this->music = $this->base . '/music';
        $this->covers = $this->base . '/covers';
        mkdir($this->music, 0775, true);
        mkdir($this->covers, 0775, true);
        $_ENV['MUSIC_ROOT'] = $this->music;
        $_ENV['COVERS_PATH'] = $this->covers;
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    public function test_delete_album_removes_files_then_marks_ghost_and_keeps_artist(): void
    {
        $folder = $this->music . '/The Artist/The Album';
        mkdir($folder, 0775, true);
        file_put_contents($folder . '/01 - track.flac', 'audio');
        $cover = $this->covers . '/album_1.jpg';
        file_put_contents($cover, 'jpg');

        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findById')->with(1)->willReturn([
            'id' => 1, 'artist_id' => 7, 'folder_path' => $folder, 'cover_path' => $cover, 'missing_at' => null,
        ]);
        // Disk first: by the time markMissing runs the folder must already be gone.
        $albums->expects($this->once())->method('markMissing')->with(1)
            ->willReturnCallback(fn() => $this->assertDirectoryDoesNotExist($folder));
        $albums->expects($this->never())->method('delete');

        $artists = $this->createMock(ArtistRepositoryInterface::class);
        $artists->expects($this->never())->method('delete');

        $tracks = $this->tracksOf([$folder . '/01 - track.flac']);

        $result = (new MediaDeletionService($artists, $albums, $tracks))->deleteAlbum(1);

        $this->assertSame(['deleted' => true, 'files_removed' => 2, 'ghost' => true], $result); // track + cover
        $this->assertDirectoryDoesNotExist($folder);
        $this->assertFileDoesNotExist($cover);
        $this->assertDirectoryDoesNotExist($this->music . '/The Artist'); // empty parent removed
    }

    public function test_delete_album_keeps_non_empty_artist_folder(): void
    {
        $folder = $this->music . '/A/Album1';
        mkdir($folder, 0775, true);
        mkdir($this->music . '/A/Album2', 0775, true);
        file_put_contents($folder . '/t.flac', 'x');

        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findById')->with(1)->willReturn([
            'id' => 1, 'artist_id' => 7, 'folder_path' => $folder, 'cover_path' => null, 'missing_at' => null,
        ]);
        $albums->expects($this->once())->method('markMissing')->with(1);

        $artists = $this->createMock(ArtistRepositoryInterface::class);
        $artists->expects($this->never())->method('delete');

        $tracks = $this->tracksOf([$folder . '/t.flac']);

        $result = (new MediaDeletionService($artists, $albums, $tracks))->deleteAlbum(1);

        $this->assertTrue($result['ghost']);
        $this->assertDirectoryDoesNotExist($folder);
        $this->assertDirectoryExists($this->music . '/A/Album2');
    }

    public function test_delete_album_on_ghost_is_idempotent(): void
    {
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findById')->with(1)->willReturn([
            'id' => 1, 'artist_id' => 7, 'folder_path' => $this->music . '/Gone/Album',
            'cover_path' => null, 'missing_at' => '2026-10-13 10:00:00',
        ]);
        $albums->expects($this->never())->method('markMissing');
        $albums->expects($this->never())->method('delete');
        $artists = $this->createMock(ArtistRepositoryInterface::class);
        $artists->expects($this->never())->method('delete');

        $result = (new MediaDeletionService($artists, $albums, $this->tracksOf([])))->deleteAlbum(1);

        $this->assertSame(['deleted' => true, 'files_removed' => 0, 'ghost' => true], $result);
    }

    public function test_delete_album_does_not_touch_files_outside_music_root_and_stays_live(): void
    {
        $outside = $this->base . '/outside/Album';
        mkdir($outside, 0775, true);
        file_put_contents($outside . '/keep.flac', 'x');

        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findById')->with(1)->willReturn([
            'id' => 1, 'artist_id' => 7, 'folder_path' => $outside, 'cover_path' => null, 'missing_at' => null,
        ]);
        // Folder still on disk → never a ghost with files.
        $albums->expects($this->never())->method('markMissing');

        $artists = $this->createMock(ArtistRepositoryInterface::class);

        $tracks = $this->tracksOf([$outside . '/keep.flac']);

        $result = (new MediaDeletionService($artists, $albums, $tracks))->deleteAlbum(1);

        $this->assertFalse($result['deleted']);
        $this->assertTrue($result['incomplete']);
        $this->assertSame(0, $result['files_removed']);
        $this->assertFileExists($outside . '/keep.flac'); // guard prevented deletion
    }

    public function test_delete_album_missing_returns_not_deleted(): void
    {
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findById')->with(99)->willReturn(null);
        $albums->expects($this->never())->method('markMissing');
        $albums->expects($this->never())->method('delete');
        $artists = $this->createMock(ArtistRepositoryInterface::class);

        $result = (new MediaDeletionService($artists, $albums, $this->tracksOf([])))->deleteAlbum(99);

        $this->assertFalse($result['deleted']);
    }

    public function test_delete_artist_ghosts_every_live_album_and_keeps_artist(): void
    {
        $f1 = $this->music . '/Band/One';
        $f2 = $this->music . '/Band/Two';
        mkdir($f1, 0775, true);
        mkdir($f2, 0775, true);
        file_put_contents($f1 . '/a.flac', 'x');
        file_put_contents($f2 . '/b.flac', 'y');

        $artists = $this->createMock(ArtistRepositoryInterface::class);
        $artists->method('findById')->with(7)->willReturn(['id' => 7, 'name' => 'Band']);
        $artists->expects($this->never())->method('delete');

        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findByArtist')->with(7)->willReturn([
            ['id' => 1, 'folder_path' => $f1, 'cover_path' => null, 'missing_at' => null],
            ['id' => 2, 'folder_path' => $f2, 'cover_path' => null, 'missing_at' => null],
            ['id' => 3, 'folder_path' => $this->music . '/Band/Old', 'cover_path' => null, 'missing_at' => '2026-01-01 00:00:00'],
        ]);
        $marked = [];
        $albums->expects($this->exactly(2))->method('markMissing')
            ->willReturnCallback(function (int $id) use (&$marked) { $marked[] = $id; });
        $albums->expects($this->never())->method('delete');

        $tracks = $this->createMock(TrackRepositoryInterface::class);
        $tracks->method('findByAlbum')->willReturnMap([
            [1, [['file_path' => $f1 . '/a.flac']]],
            [2, [['file_path' => $f2 . '/b.flac']]],
        ]);

        $result = (new MediaDeletionService($artists, $albums, $tracks))->deleteArtist(7);

        $this->assertTrue($result['deleted']);
        $this->assertSame(2, $result['albums_removed']);
        $this->assertSame(2, $result['files_removed']);
        $this->assertSame([1, 2], $marked); // the existing ghost is left alone
        $this->assertDirectoryDoesNotExist($f1);
        $this->assertDirectoryDoesNotExist($f2);
        $this->assertDirectoryDoesNotExist($this->music . '/Band'); // empty parent removed
    }

    public function test_forget_album_refuses_live_album(): void
    {
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findById')->with(1)->willReturn([
            'id' => 1, 'artist_id' => 7, 'folder_path' => '/x', 'cover_path' => null, 'missing_at' => null,
        ]);
        $albums->expects($this->never())->method('delete');
        $artists = $this->createMock(ArtistRepositoryInterface::class);
        $artists->expects($this->never())->method('delete');

        $result = (new MediaDeletionService($artists, $albums, $this->tracksOf([])))->forgetAlbum(1);

        $this->assertFalse($result['forgotten']);
        $this->assertSame('not_ghost', $result['error']);
    }

    public function test_forget_album_not_found(): void
    {
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findById')->with(99)->willReturn(null);
        $albums->expects($this->never())->method('delete');
        $artists = $this->createMock(ArtistRepositoryInterface::class);

        $result = (new MediaDeletionService($artists, $albums, $this->tracksOf([])))->forgetAlbum(99);

        $this->assertSame('not_found', $result['error']);
    }

    public function test_forget_ghost_album_removes_artist_left_without_albums(): void
    {
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findById')->with(1)->willReturn([
            'id' => 1, 'artist_id' => 7, 'folder_path' => '/x', 'cover_path' => null, 'missing_at' => '2026-10-13 10:00:00',
        ]);
        $albums->expects($this->once())->method('delete')->with(1)->willReturn(true);
        $albums->method('findByArtist')->with(7)->willReturn([]);
        $artists = $this->createMock(ArtistRepositoryInterface::class);
        $artists->expects($this->once())->method('delete')->with(7)->willReturn(true);

        $result = (new MediaDeletionService($artists, $albums, $this->tracksOf([])))->forgetAlbum(1);

        $this->assertSame(['forgotten' => true, 'artist_removed' => true], $result);
    }

    public function test_forget_ghost_album_keeps_artist_with_other_album_rows(): void
    {
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findById')->with(1)->willReturn([
            'id' => 1, 'artist_id' => 7, 'folder_path' => '/x', 'cover_path' => null, 'missing_at' => '2026-10-13 10:00:00',
        ]);
        $albums->expects($this->once())->method('delete')->with(1)->willReturn(true);
        // Another album row remains (even a ghost counts): the artist stays.
        $albums->method('findByArtist')->with(7)->willReturn([['id' => 2, 'missing_at' => '2026-10-13 10:00:00']]);
        $artists = $this->createMock(ArtistRepositoryInterface::class);
        $artists->expects($this->never())->method('delete');

        $result = (new MediaDeletionService($artists, $albums, $this->tracksOf([])))->forgetAlbum(1);

        $this->assertSame(['forgotten' => true, 'artist_removed' => false], $result);
    }

    /** (a) Album alone in its folder, with cover.jpg and .cue: the whole folder goes, as before. */
    public function test_scenario_a_album_alone_in_folder_removes_whole_folder(): void
    {
        $folder = $this->music . '/Solo/Album';
        mkdir($folder, 0775, true);
        file_put_contents($folder . '/01.flac', 'a');
        file_put_contents($folder . '/02.flac', 'b');
        file_put_contents($folder . '/cover.jpg', 'jpg');
        file_put_contents($folder . '/album.cue', 'cue');

        $albums = $this->albumsWith([1 => ['id' => 1, 'artist_id' => 7, 'folder_path' => $folder]]);
        $tracks = $this->tracksOf([$folder . '/01.flac', $folder . '/02.flac']);

        $result = (new MediaDeletionService($this->createMock(ArtistRepositoryInterface::class), $albums, $tracks))->deleteAlbum(1);

        $this->assertTrue($result['ghost']);
        $this->assertSame(4, $result['files_removed']);
        $this->assertDirectoryDoesNotExist($folder);
    }

    /** (b) Two albums in the same folder: deleting one leaves the other's files and the folder. */
    public function test_scenario_b_two_albums_sharing_a_folder_keeps_the_other(): void
    {
        $folder = $this->music . '/Various/Mix';
        mkdir($folder, 0775, true);
        file_put_contents($folder . '/a1.flac', 'a');
        file_put_contents($folder . '/a2.flac', 'a');
        file_put_contents($folder . '/b1.flac', 'b');
        file_put_contents($folder . '/b2.flac', 'b');

        $albums = $this->albumsWith([1 => ['id' => 1, 'artist_id' => 7, 'folder_path' => $folder]]);
        $tracks = $this->tracksOf([$folder . '/a1.flac', $folder . '/a2.flac']);

        $result = (new MediaDeletionService($this->createMock(ArtistRepositoryInterface::class), $albums, $tracks))->deleteAlbum(1);

        $this->assertTrue($result['ghost']);
        $this->assertSame(2, $result['files_removed']);
        $this->assertFileDoesNotExist($folder . '/a1.flac');
        $this->assertFileDoesNotExist($folder . '/a2.flac');
        $this->assertFileExists($folder . '/b1.flac');
        $this->assertFileExists($folder . '/b2.flac');
        $this->assertDirectoryExists($folder);
    }

    /** (c) Loose tracks in /music/Artista/ next to another album's subfolder: only its own tracks go. */
    public function test_scenario_c_loose_tracks_next_to_other_album_subfolder(): void
    {
        $artistDir = $this->music . '/Artist';
        $other = $artistDir . '/Other Album';
        mkdir($other, 0775, true);
        file_put_contents($artistDir . '/loose1.flac', 'l');
        file_put_contents($artistDir . '/loose2.flac', 'l');
        file_put_contents($other . '/o1.flac', 'o');

        $albums = $this->albumsWith([1 => ['id' => 1, 'artist_id' => 7, 'folder_path' => $artistDir]]);
        $tracks = $this->tracksOf([$artistDir . '/loose1.flac', $artistDir . '/loose2.flac']);

        $result = (new MediaDeletionService($this->createMock(ArtistRepositoryInterface::class), $albums, $tracks))->deleteAlbum(1);

        $this->assertTrue($result['ghost']);
        $this->assertSame(2, $result['files_removed']);
        $this->assertFileDoesNotExist($artistDir . '/loose1.flac');
        $this->assertFileDoesNotExist($artistDir . '/loose2.flac');
        $this->assertFileExists($other . '/o1.flac');
    }

    /** @param array<int, array<string, mixed>> $rows album rows by id (missing_at/cover_path default to null) */
    private function albumsWith(array $rows): AlbumRepositoryInterface
    {
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findById')->willReturnCallback(
            fn(int $id) => isset($rows[$id]) ? $rows[$id] + ['cover_path' => null, 'missing_at' => null] : null
        );
        return $albums;
    }

    /** @param string[] $paths file_path of the tracks returned for any album */
    private function tracksOf(array $paths): TrackRepositoryInterface
    {
        $tracks = $this->createMock(TrackRepositoryInterface::class);
        $tracks->method('findByAlbum')->willReturn(array_map(fn(string $p) => ['file_path' => $p], $paths));
        return $tracks;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = "$dir/$f";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
