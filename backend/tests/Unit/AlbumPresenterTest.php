<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\Music\AlbumFavoriteRepositoryInterface;
use App\Domain\Services\AlbumPresenter;
use App\Infrastructure\Auth\SignedUrlService;
use PHPUnit\Framework\TestCase;

class AlbumPresenterTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['JWT_SECRET'] = 'test-secret-at-least-32-characters-long!!';
        $_ENV['STREAM_URL_TTL'] = '3600';
    }

    /** @return array<string, mixed> */
    private function row(int $id, array $extra = []): array
    {
        return array_merge([
            'id'          => (string) $id,
            'title'       => "Album $id",
            'year'        => '2019',
            'artist_name' => 'Artist',
            'artist_id'   => '3',
            'track_count' => '11',
            'cover_path'  => "/covers/album_$id.jpg",
            'cover_url'   => null,
        ], $extra);
    }

    public function test_marks_favorites_from_map(): void
    {
        $favs = $this->createMock(AlbumFavoriteRepositoryInterface::class);
        $favs->method('favoriteMap')->willReturn([7 => true, 8 => false]);
        $presenter = new AlbumPresenter(new SignedUrlService(), $favs);

        $out = $presenter->presentMany([$this->row(7), $this->row(8), $this->row(9)], 2);

        $this->assertTrue($out[0]['is_favorite']);
        $this->assertFalse($out[1]['is_favorite']);
        $this->assertFalse($out[2]['is_favorite']); // missing from the map -> false
        $this->assertSame(
            ['id', 'title', 'year', 'artist', 'artist_id', 'track_count', 'cover_url', 'is_favorite', 'missing'],
            array_keys($out[0])
        );
        $this->assertSame(7, $out[0]['id']);
        $this->assertSame(2019, $out[0]['year']);
        $this->assertSame(3, $out[0]['artist_id']);
        $this->assertSame(11, $out[0]['track_count']);
        $this->assertStringStartsWith('/cover.php?token=', $out[0]['cover_url']);
        $this->assertFalse($out[0]['missing']); // no missing_at in the row -> live
    }

    public function test_ghost_album_is_missing_and_keeps_remote_cover(): void
    {
        $favs = $this->createMock(AlbumFavoriteRepositoryInterface::class);
        $favs->method('favoriteMap')->willReturn([]);
        $presenter = new AlbumPresenter(new SignedUrlService(), $favs);

        // markMissing nulls cover_path and keeps cover_url.
        $out = $presenter->presentMany([
            $this->row(4, ['missing_at' => '2026-10-13 10:00:00', 'cover_path' => null, 'cover_url' => 'https://x/y.jpg']),
            $this->row(5, ['missing_at' => null]),
        ], 2);

        $this->assertTrue($out[0]['missing']);
        $this->assertSame('https://x/y.jpg', $out[0]['cover_url']);
        $this->assertFalse($out[1]['missing']);
    }

    public function test_calls_favorite_map_once_per_list(): void
    {
        $favs = $this->createMock(AlbumFavoriteRepositoryInterface::class);
        $favs->expects($this->once())
            ->method('favoriteMap')
            ->with(2, [1, 2, 3])
            ->willReturn([]);
        $presenter = new AlbumPresenter(new SignedUrlService(), $favs);

        $out = $presenter->presentMany([$this->row(1), $this->row(2), $this->row(3)], 2);

        $this->assertCount(3, $out);
    }

    public function test_cover_url_null_without_cover(): void
    {
        $favs = $this->createMock(AlbumFavoriteRepositoryInterface::class);
        $favs->method('favoriteMap')->willReturn([]);
        $presenter = new AlbumPresenter(new SignedUrlService(), $favs);

        $none = $presenter->present($this->row(5, ['cover_path' => null, 'cover_url' => null]), 2);
        $empty = $presenter->present($this->row(6, ['cover_path' => '', 'cover_url' => '']), 2);

        $this->assertNull($none['cover_url']);
        $this->assertNull($empty['cover_url']);
    }

    public function test_remote_only_cover_is_returned_as_is(): void
    {
        $favs = $this->createMock(AlbumFavoriteRepositoryInterface::class);
        $favs->method('favoriteMap')->willReturn([]);
        $presenter = new AlbumPresenter(new SignedUrlService(), $favs);

        $out = $presenter->present(
            $this->row(6, ['cover_path' => null, 'cover_url' => 'https://lastfm.example/img/abc.jpg']),
            2
        );

        $this->assertSame('https://lastfm.example/img/abc.jpg', $out['cover_url']);
    }

    public function test_local_cover_wins_over_remote(): void
    {
        $favs = $this->createMock(AlbumFavoriteRepositoryInterface::class);
        $favs->method('favoriteMap')->willReturn([]);
        $presenter = new AlbumPresenter(new SignedUrlService(), $favs);

        $out = $presenter->present(
            $this->row(7, ['cover_path' => '/covers/album_7.jpg', 'cover_url' => 'https://x/y.jpg']),
            2
        );

        $this->assertStringStartsWith('/cover.php?token=', $out['cover_url']);
    }

    public function test_empty_list_returns_empty_without_querying(): void
    {
        $favs = $this->createMock(AlbumFavoriteRepositoryInterface::class);
        $favs->expects($this->never())->method('favoriteMap');
        $presenter = new AlbumPresenter(new SignedUrlService(), $favs);

        $this->assertSame([], $presenter->presentMany([], 2));
    }
}
