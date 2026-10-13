<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FavoriteController;
use App\Domain\Repository\Music\AlbumFavoriteRepositoryInterface;
use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\FavoriteRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\AlbumPresenter;
use App\Domain\Services\TrackPresenter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FavoriteControllerTest extends TestCase
{
    private AlbumRepositoryInterface&MockObject $albums;
    private AlbumFavoriteRepositoryInterface&MockObject $albumFavorites;
    private FavoriteController $controller;

    protected function setUp(): void
    {
        $this->albums = $this->createMock(AlbumRepositoryInterface::class);
        $this->albumFavorites = $this->createMock(AlbumFavoriteRepositoryInterface::class);
        $this->controller = new FavoriteController(
            $this->createMock(FavoriteRepositoryInterface::class),
            $this->createMock(TrackRepositoryInterface::class),
            $this->createMock(TrackPresenter::class),
            $this->albums,
            $this->albumFavorites,
            $this->createMock(AlbumPresenter::class)
        );
    }

    public function test_toggle_album_requires_user(): void
    {
        $this->albums->expects($this->never())->method('findById');
        $this->albumFavorites->expects($this->never())->method('toggle');

        $res = $this->controller->toggleAlbumFavorite(['albumId' => 7], null);

        $this->assertSame('error', $res['status']);
        $this->assertSame(401, $res['http_code']);
    }

    public function test_toggle_album_unknown_album(): void
    {
        $this->albums->expects($this->once())->method('findById')->with(99)->willReturn(null);
        $this->albumFavorites->expects($this->never())->method('toggle');

        $res = $this->controller->toggleAlbumFavorite(['albumId' => 99], 2);

        $this->assertSame('error', $res['status']);
        $this->assertSame(404, $res['http_code']);
        $this->assertSame('Album not found.', $res['message']);
    }

    public function test_toggle_album_returns_new_state(): void
    {
        $this->albums->method('findById')->with(7)->willReturn(['id' => 7, 'title' => 'X']);
        $this->albumFavorites->expects($this->once())->method('toggle')->with(2, 7)->willReturn(true);

        $res = $this->controller->toggleAlbumFavorite(['albumId' => 7], 2);

        $this->assertSame('success', $res['status']);
        $this->assertSame(200, $res['http_code']);
        $this->assertSame(['is_favorite' => true], $res['data']);
    }
}
