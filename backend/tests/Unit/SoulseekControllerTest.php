<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\SoulseekController;
use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\DownloadRepositoryInterface;
use App\Domain\Services\DownloadReconciler;
use App\Domain\Services\LibraryMatcher;
use App\Infrastructure\Music\SpotifyClient;
use App\Infrastructure\Soulseek\SlskdClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * slsk_download's duplicate check: a live album answers `duplicate` (or `skipped`) WITHOUT reaching
 * slskd; `replace` enqueues and records replace_album_id on every row; a ghost or an unknown album
 * downloads as before.
 */
class SoulseekControllerTest extends TestCase
{
    private const ROWS = [
        ['id' => 12, 'title' => 'Abbey Road', 'missing_at' => null, 'artist_name' => 'The Beatles'],
        ['id' => 30, 'title' => 'OK Computer', 'missing_at' => '2026-10-01 10:00:00', 'artist_name' => 'Radiohead'],
    ];

    private const FILES = [
        ['filename' => 'music\\Beatles\\Abbey Road CD1\\01 - Come Together.flac', 'size' => 100],
        ['filename' => 'music\\Beatles\\Abbey Road CD1\\02 - Something.flac', 'size' => 100],
        ['filename' => 'music\\Beatles\\Abbey Road CD2\\01 - Bonus.flac', 'size' => 100],
        ['filename' => 'music\\Beatles\\Abbey Road CD2\\cover.jpg', 'size' => 10],
    ];

    private DownloadRepositoryInterface&MockObject $downloads;
    /** Anonymous SlskdClient subclass that records what would have been enqueued (`$enqueued`). */
    private SlskdClient $slskd;
    private SoulseekController $controller;

    protected function setUp(): void
    {
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('allForMatching')->willReturn(self::ROWS);
        $this->downloads = $this->createMock(DownloadRepositoryInterface::class);
        $this->slskd = new class extends SlskdClient {
            /** @var array<int, array{filename:string, size:int}> */
            public array $enqueued = [];
            public function __construct() {}
            public function isConfigured(): bool { return true; }
            public function enqueueDownloads(string $username, array $files): void
            {
                $this->enqueued = array_merge($this->enqueued, $files);
            }
        };
        $this->controller = new SoulseekController(
            $this->createMock(SpotifyClient::class),
            $this->slskd,
            $this->downloads,
            $this->createMock(DownloadReconciler::class),
            new LibraryMatcher($albums)
        );
    }

    private function request(array $extra = []): array
    {
        return array_merge(
            ['username' => 'peer', 'files' => self::FILES, 'artist' => 'Beatles', 'album' => 'abbey road'],
            $extra
        );
    }

    public function test_live_album_without_decision_returns_duplicate_and_enqueues_nothing(): void
    {
        $this->downloads->expects($this->never())->method('upsertQueued');

        $res = $this->controller->slskDownload($this->request(), 2);

        $this->assertSame('success', $res['status']);
        $this->assertSame(200, $res['http_code']);
        $this->assertSame(
            ['queued' => 0, 'duplicate' => ['album_id' => 12, 'title' => 'abbey road', 'artist' => 'Beatles']],
            $res['data']
        );
        $this->assertSame([], $this->slskd->enqueued);
    }

    public function test_live_album_with_skip_enqueues_nothing(): void
    {
        $this->downloads->expects($this->never())->method('upsertQueued');

        $res = $this->controller->slskDownload($this->request(['on_duplicate' => 'skip']), 2);

        $this->assertSame(['queued' => 0, 'skipped' => true], $res['data']);
        $this->assertSame([], $this->slskd->enqueued);
    }

    public function test_live_album_with_replace_enqueues_and_records_replace_album_id_on_every_row(): void
    {
        $rows = [];
        $this->downloads->expects($this->exactly(2))->method('upsertQueued')
            ->willReturnCallback(function (int $userId, array $data) use (&$rows) {
                $rows[] = $data;
                return count($rows);
            });

        $res = $this->controller->slskDownload($this->request(['on_duplicate' => 'replace']), 2);

        $this->assertSame(['queued' => 3], $res['data']);
        $this->assertCount(3, $this->slskd->enqueued);            // the cover is filtered out
        $this->assertSame([12, 12], array_column($rows, 'replace_album_id'));   // CD1 and CD2
    }

    public function test_ghost_does_not_trigger_the_warning(): void
    {
        $this->downloads->expects($this->exactly(2))->method('upsertQueued')
            ->with(2, $this->callback(fn (array $d) => $d['replace_album_id'] === null))
            ->willReturn(1);

        $res = $this->controller->slskDownload($this->request(['artist' => 'Radiohead', 'album' => 'OK Computer']), 2);

        $this->assertSame(['queued' => 3], $res['data']);
    }

    public function test_unknown_album_downloads_as_before_even_with_skip(): void
    {
        $this->downloads->expects($this->exactly(2))->method('upsertQueued')
            ->with(2, $this->callback(fn (array $d) => $d['replace_album_id'] === null))
            ->willReturn(1);

        $res = $this->controller->slskDownload(
            $this->request(['artist' => 'Nadie', 'album' => 'Nada', 'on_duplicate' => 'skip']),
            2
        );

        $this->assertSame(['queued' => 3], $res['data']);
    }

    public function test_without_artist_or_album_there_is_no_check(): void
    {
        $this->downloads->expects($this->exactly(2))->method('upsertQueued')->willReturn(1);

        $res = $this->controller->slskDownload(['username' => 'peer', 'files' => self::FILES], 2);

        $this->assertSame(['queued' => 3], $res['data']);
    }

    public function test_invalid_on_duplicate_is_rejected(): void
    {
        $this->downloads->expects($this->never())->method('upsertQueued');

        $res = $this->controller->slskDownload($this->request(['on_duplicate' => 'overwrite']), 2);

        $this->assertSame('error', $res['status']);
        $this->assertSame(400, $res['http_code']);
        $this->assertSame([], $this->slskd->enqueued);
    }
}
