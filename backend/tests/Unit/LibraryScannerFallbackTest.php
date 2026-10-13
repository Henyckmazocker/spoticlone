<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\ArtistRepositoryInterface;
use App\Domain\Repository\Music\ScanRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\LibraryScanner;
use PHPUnit\Framework\TestCase;

class LibraryScannerFallbackTest extends TestCase
{
    private mixed $previousRoot;
    private LibraryScanner $scanner;

    protected function setUp(): void
    {
        $this->previousRoot = $_ENV['MUSIC_ROOT'] ?? null;
        $_ENV['MUSIC_ROOT'] = '/music';
        $this->scanner = new LibraryScanner(
            $this->createMock(ArtistRepositoryInterface::class),
            $this->createMock(AlbumRepositoryInterface::class),
            $this->createMock(TrackRepositoryInterface::class),
            $this->createMock(ScanRepositoryInterface::class)
        );
    }

    protected function tearDown(): void
    {
        if ($this->previousRoot === null) {
            unset($_ENV['MUSIC_ROOT']);
        } else {
            $_ENV['MUSIC_ROOT'] = $this->previousRoot;
        }
    }

    public function test_loose_file_takes_its_folder_as_artist(): void
    {
        $this->assertSame('Bianca Steck', $this->scanner->fallbackArtist('/music/Bianca Steck/loose song.mp3'));
    }

    public function test_album_file_takes_grandparent_folder_as_artist(): void
    {
        $this->assertSame('Still Woozy', $this->scanner->fallbackArtist('/music/Still Woozy/Lately/01 Track.flac'));
    }
}
