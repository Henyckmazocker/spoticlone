<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\ArtistRepositoryInterface;
use App\Domain\Repository\Music\ScanRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\LibraryScanner;
use App\Domain\Services\ScanInProgressException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LibraryScannerLockTest extends TestCase
{
    private mixed $previousRoot;
    private string $root;
    private ScanRepositoryInterface&MockObject $scans;
    private LibraryScanner $scanner;

    protected function setUp(): void
    {
        $this->previousRoot = $_ENV['MUSIC_ROOT'] ?? null;
        $this->root = sys_get_temp_dir() . '/scanlock_' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $_ENV['MUSIC_ROOT'] = $this->root;
        $this->scans = $this->createMock(ScanRepositoryInterface::class);
        $this->scanner = new LibraryScanner(
            $this->createMock(ArtistRepositoryInterface::class),
            $this->createMock(AlbumRepositoryInterface::class),
            $this->createMock(TrackRepositoryInterface::class),
            $this->scans
        );
    }

    protected function tearDown(): void
    {
        @rmdir($this->root);
        if ($this->previousRoot === null) {
            unset($_ENV['MUSIC_ROOT']);
        } else {
            $_ENV['MUSIC_ROOT'] = $this->previousRoot;
        }
    }

    public function test_full_scan_fails_fast_and_leaves_no_run_row(): void
    {
        $this->scans->expects($this->once())->method('acquireLock')->with(0)->willReturn(false);
        $this->scans->expects($this->never())->method('start');
        $this->scans->expects($this->never())->method('releaseLock');

        $this->expectException(ScanInProgressException::class);
        $this->scanner->scan();
    }

    public function test_partial_scan_waits_up_to_120_seconds(): void
    {
        $this->scans->expects($this->once())->method('acquireLock')->with(120)->willReturn(false);
        $this->scans->expects($this->never())->method('start');

        $this->expectException(ScanInProgressException::class);
        $this->scanner->scan($this->root);
    }

    public function test_lock_is_released_after_a_scan(): void
    {
        $this->scans->method('acquireLock')->willReturn(true);
        $this->scans->method('start')->willReturn(7);
        $this->scans->expects($this->once())->method('releaseLock');

        $res = $this->scanner->scan();

        $this->assertSame(7, $res['run_id']);
    }

    public function test_lock_is_released_when_the_scan_throws(): void
    {
        $this->scans->method('acquireLock')->willReturn(true);
        $this->scans->method('start')->willThrowException(new \RuntimeException('db down'));
        $this->scans->expects($this->once())->method('releaseLock');

        $this->expectException(\RuntimeException::class);
        $this->scanner->scan();
    }
}
