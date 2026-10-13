<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Services\LibraryMatcher;
use PHPUnit\Framework\TestCase;

/**
 * LibraryMatcher: Spotify (artist, title) against the library rows, through Normalize::name.
 */
class LibraryMatcherTest extends TestCase
{
    private const ROWS = [
        ['id' => 1, 'title' => 'El Mal Querer', 'missing_at' => null, 'artist_name' => 'ROSALÍA'],
        ['id' => 2, 'title' => 'Abbey Road', 'missing_at' => null, 'artist_name' => 'Beatles'],
        ['id' => 3, 'title' => 'OK Computer', 'missing_at' => '2026-10-01 10:00:00', 'artist_name' => 'Radiohead'],
        ['id' => 4, 'title' => 'Discovery', 'missing_at' => '2026-10-01 10:00:00', 'artist_name' => 'Daft Punk'],
        ['id' => 5, 'title' => 'Discovery', 'missing_at' => null, 'artist_name' => 'Daft Punk'],
        ['id' => 6, 'title' => 'Watch the Throne', 'missing_at' => null, 'artist_name' => 'JAY-Z'],
        ['id' => 7, 'title' => 'Igor', 'missing_at' => null, 'artist_name' => 'Tyler, The Creator'],
    ];

    private function matcher(int $expectedQueries = 1): LibraryMatcher
    {
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->expects($this->exactly($expectedQueries))->method('allForMatching')->willReturn(self::ROWS);
        return new LibraryMatcher($albums);
    }

    public function test_accents_and_case_match(): void
    {
        $this->assertSame(['status' => 'present', 'album_id' => 1], $this->matcher()->match('Rosalía', 'el mal querer'));
    }

    public function test_leading_the_is_ignored(): void
    {
        $this->assertSame(['status' => 'present', 'album_id' => 2], $this->matcher()->match('The Beatles', 'ABBEY ROAD'));
    }

    public function test_ghost_is_missing(): void
    {
        $this->assertSame(['status' => 'missing', 'album_id' => 3], $this->matcher()->match('Radiohead', 'OK Computer'));
    }

    public function test_live_beats_ghost(): void
    {
        $this->assertSame(['status' => 'present', 'album_id' => 5], $this->matcher()->match('Daft Punk', 'Discovery'));
    }

    public function test_multiple_artists_fall_back_to_the_first(): void
    {
        $this->assertSame(['status' => 'present', 'album_id' => 6], $this->matcher()->match('JAY-Z, Kanye West', 'Watch The Throne'));
    }

    public function test_whole_artist_string_is_tried_first(): void
    {
        // A single artist whose name contains ", " must not be split.
        $this->assertSame(['status' => 'present', 'album_id' => 7], $this->matcher()->match('Tyler, The Creator', 'IGOR'));
    }

    public function test_no_match_is_null(): void
    {
        $this->assertNull($this->matcher()->match('Radiohead', 'Kid A'));
        $this->assertNull($this->matcher()->match('', ''));
    }

    public function test_match_many_uses_one_query_and_keeps_keys(): void
    {
        $out = $this->matcher(1)->matchMany([
            0 => ['The Beatles', 'Abbey Road'],
            1 => ['Nobody', 'Nothing'],
            2 => ['Radiohead', 'ok computer'],
        ]);
        $this->assertSame([
            0 => ['status' => 'present', 'album_id' => 2],
            1 => null,
            2 => ['status' => 'missing', 'album_id' => 3],
        ], $out);
    }

    public function test_match_many_empty_does_not_query(): void
    {
        $this->assertSame([], $this->matcher(0)->matchMany([]));
    }
}
