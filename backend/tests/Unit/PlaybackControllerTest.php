<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\PlaybackController;
use App\Domain\Repository\Music\PlayHistoryRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\TrackPresenter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PlaybackControllerTest extends TestCase
{
    private PlayHistoryRepositoryInterface&MockObject $history;
    private PlaybackController $controller;

    protected function setUp(): void
    {
        $this->history = $this->createMock(PlayHistoryRepositoryInterface::class);
        $tracks = $this->createMock(TrackRepositoryInterface::class);
        $presenter = $this->createMock(TrackPresenter::class);
        $this->controller = new PlaybackController($this->history, $tracks, $presenter);
    }

    public function test_negative_limit_is_clamped_to_one(): void
    {
        $this->history->expects($this->once())->method('recentTrackIds')->with(7, 1)->willReturn([]);
        $r = $this->controller->getRecentlyPlayed(['limit' => -5], 7);
        $this->assertSame(200, $r['http_code']);
    }

    public function test_huge_limit_is_clamped_to_100(): void
    {
        $this->history->expects($this->once())->method('mostPlayed')->with(7, 100)->willReturn([]);
        $this->controller->getMostPlayed(['limit' => 100000], 7);
    }

    public function test_array_limit_falls_back_to_default(): void
    {
        $this->history->expects($this->once())->method('recentTrackIds')->with(7, 30)->willReturn([]);
        $this->controller->getRecentlyPlayed(['limit' => [1]], 7);
    }
}
