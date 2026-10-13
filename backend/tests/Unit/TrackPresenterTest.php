<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\Music\FavoriteRepositoryInterface;
use App\Domain\Services\TrackPresenter;
use App\Infrastructure\Auth\SignedUrlService;
use PHPUnit\Framework\TestCase;

class TrackPresenterTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['JWT_SECRET'] = 'test-secret-at-least-32-characters-long!!';
        $_ENV['STREAM_URL_TTL'] = '3600';
    }

    /** @return array<string, mixed> */
    private function row(int $id, array $extra = []): array
    {
        // Shape of MySqlTrackRepository::SELECT: t.* + album_title, cover_path, cover_url, artist_name
        // (+ missing_at, album_missing_at, passed in $extra where a test needs them).
        return array_merge([
            'id'               => (string) $id,
            'title'            => "Track $id",
            'artist_name'      => 'Artist',
            'artist_id'        => '3',
            'album_title'      => 'Album',
            'album_id'         => '9',
            'track_no'         => '1',
            'disc_no'          => '1',
            'duration_seconds' => '200',
            'file_format'      => 'flac',
            'genre'            => null,
            'cover_path'       => '/covers/album_9.jpg',
            'cover_url'        => null,
        ], $extra);
    }

    public function test_remote_only_cover_is_returned_as_is(): void
    {
        $favs = $this->createMock(FavoriteRepositoryInterface::class);
        $favs->method('favoriteMap')->willReturn([]);
        $presenter = new TrackPresenter(new SignedUrlService(), $favs);

        $out = $presenter->presentMany([
            $this->row(1, ['cover_path' => null, 'cover_url' => 'https://lastfm.example/img/abc.jpg']),
            $this->row(2),
        ], 2);

        $this->assertSame('https://lastfm.example/img/abc.jpg', $out[0]['cover_url']);
        $this->assertStringStartsWith('/cover.php?token=', $out[1]['cover_url']);
    }

    public function test_ghost_track_is_missing_without_stream_url(): void
    {
        $favs = $this->createMock(FavoriteRepositoryInterface::class);
        $favs->method('favoriteMap')->willReturn([]);
        $presenter = new TrackPresenter(new SignedUrlService(), $favs);

        $out = $presenter->presentMany([
            $this->row(1, ['missing_at' => '2026-10-13 10:00:00', 'album_missing_at' => '2026-10-13 10:00:00']),
            $this->row(2, ['missing_at' => null, 'album_missing_at' => '2026-10-13 10:00:00']), // album ghost only
            $this->row(3, ['missing_at' => '2026-10-13 10:00:00', 'album_missing_at' => null]), // pruned track
            $this->row(4, ['missing_at' => null, 'album_missing_at' => null]),
        ], 2);

        foreach ([0, 1, 2] as $i) {
            $this->assertTrue($out[$i]['missing']);
            $this->assertNull($out[$i]['stream_url']);
        }
        $this->assertFalse($out[3]['missing']);
        $this->assertStringStartsWith('/stream.php?token=', $out[3]['stream_url']);
        // The ghost keeps everything else the grey row needs.
        $this->assertSame('Track 1', $out[0]['title']);
        $this->assertStringStartsWith('/cover.php?token=', $out[0]['cover_url']);
    }

    public function test_row_without_missing_columns_is_live(): void
    {
        $favs = $this->createMock(FavoriteRepositoryInterface::class);
        $favs->method('favoriteMap')->willReturn([]);
        $presenter = new TrackPresenter(new SignedUrlService(), $favs);

        $out = $presenter->presentMany([$this->row(1)], 2);

        $this->assertFalse($out[0]['missing']);
        $this->assertNotNull($out[0]['stream_url']);
    }
}
