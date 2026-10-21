<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\PlaylistController;
use App\Domain\Repository\Music\PlaylistRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\TrackPresenter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PlaylistControllerTest extends TestCase
{
    private PlaylistRepositoryInterface&MockObject $playlists;
    private PlaylistController $controller;

    protected function setUp(): void
    {
        $this->playlists = $this->createMock(PlaylistRepositoryInterface::class);
        $this->controller = new PlaylistController(
            $this->playlists,
            $this->createMock(TrackRepositoryInterface::class),
            $this->createMock(TrackPresenter::class)
        );
    }

    public function test_add_501_track_ids_returns_400(): void
    {
        $this->playlists->method('findForUser')->willReturn(['id' => 1]);
        $this->playlists->expects($this->never())->method('addTrack');

        $r = $this->controller->addToPlaylist(['playlistId' => 1, 'trackIds' => range(1, 501)], 7);
        $this->assertSame(400, $r['http_code']);
    }

    public function test_add_500_track_ids_is_accepted(): void
    {
        $this->playlists->method('findForUser')->willReturn(['id' => 1]);
        $this->playlists->expects($this->exactly(500))->method('addTrack');

        $r = $this->controller->addToPlaylist(['playlistId' => 1, 'trackIds' => range(1, 500)], 7);
        $this->assertSame(200, $r['http_code']);
    }

    public function test_reorder_501_track_ids_returns_400(): void
    {
        $this->playlists->method('findForUser')->willReturn(['id' => 1]);
        $this->playlists->expects($this->never())->method('reorder');

        $r = $this->controller->reorderPlaylist(['playlistId' => 1, 'trackIds' => range(1, 501)], 7);
        $this->assertSame(400, $r['http_code']);
    }

    public function test_create_with_array_name_returns_400(): void
    {
        $this->playlists->expects($this->never())->method('create');

        $r = $this->controller->createPlaylist(['name' => ['x']], 7);
        $this->assertSame(400, $r['http_code']);
    }

    public function test_update_with_array_name_returns_400(): void
    {
        $this->playlists->method('findForUser')->willReturn(['id' => 1]);
        $this->playlists->expects($this->never())->method('update');

        $r = $this->controller->updatePlaylist(['playlistId' => 1, 'name' => ['x']], 7);
        $this->assertSame(400, $r['http_code']);
    }

    public function test_name_over_100_chars_returns_400(): void
    {
        $r = $this->controller->createPlaylist(['name' => str_repeat('a', 101)], 7);
        $this->assertSame(400, $r['http_code']);
    }

    public function test_valid_create_succeeds(): void
    {
        $this->playlists->method('create')->willReturn(5);
        $r = $this->controller->createPlaylist(['name' => 'Mix', 'description' => 'ok'], 7);
        $this->assertSame(200, $r['http_code']);
    }
}
