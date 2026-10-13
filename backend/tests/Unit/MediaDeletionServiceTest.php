<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\ArtistRepositoryInterface;
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

        $result = (new MediaDeletionService($artists, $albums))->deleteAlbum(1);

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

        $result = (new MediaDeletionService($artists, $albums))->deleteAlbum(1);

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

        $result = (new MediaDeletionService($artists, $albums))->deleteAlbum(1);

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

        $result = (new MediaDeletionService($artists, $albums))->deleteAlbum(1);

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

        $result = (new MediaDeletionService($artists, $albums))->deleteAlbum(99);

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

        $result = (new MediaDeletionService($artists, $albums))->deleteArtist(7);

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

        $result = (new MediaDeletionService($artists, $albums))->forgetAlbum(1);

        $this->assertFalse($result['forgotten']);
        $this->assertSame('not_ghost', $result['error']);
    }

    public function test_forget_album_not_found(): void
    {
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findById')->with(99)->willReturn(null);
        $albums->expects($this->never())->method('delete');
        $artists = $this->createMock(ArtistRepositoryInterface::class);

        $result = (new MediaDeletionService($artists, $albums))->forgetAlbum(99);

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

        $result = (new MediaDeletionService($artists, $albums))->forgetAlbum(1);

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

        $result = (new MediaDeletionService($artists, $albums))->forgetAlbum(1);

        $this->assertSame(['forgotten' => true, 'artist_removed' => false], $result);
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
