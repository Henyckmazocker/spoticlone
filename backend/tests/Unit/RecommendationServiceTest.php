<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\Music\AlbumFavoriteRepositoryInterface;
use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\ArtistRepositoryInterface;
use App\Domain\Repository\Music\FavoriteRepositoryInterface;
use App\Domain\Repository\Music\PlayHistoryRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\RecommendationService;
use App\Infrastructure\Music\LastfmClient;
use PHPUnit\Framework\TestCase;

/**
 * Fixture library: artist N has albums N*10+1 and N*10+2, each with tracks album*10+1..4,
 * so a track's artist is intdiv(trackId, 100) and its album intdiv(trackId, 10).
 */
class RecommendationServiceTest extends TestCase
{
    private const DATE = '2026-10-13';

    /** @var array<int, array{name:string, genre:string}> */
    private const ARTISTS = [
        1  => ['name' => 'Alpha', 'genre' => 'Indie Pop'],
        2  => ['name' => 'Beta', 'genre' => 'Indie Pop; Catalan'],
        3  => ['name' => 'Gamma', 'genre' => 'Metal'],
        4  => ['name' => 'Delta', 'genre' => 'Jazz'],
        5  => ['name' => 'Epsilon', 'genre' => 'Pop'],
        6  => ['name' => 'Zeta', 'genre' => 'Contemporary Hip Hop'],
        99 => ['name' => 'Various Artists', 'genre' => 'Indie Pop'],
    ];

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        $rows = [];
        foreach (self::ARTISTS as $aid => $a) {
            foreach ([1, 2] as $k) {
                $album = $aid * 10 + $k;
                for ($n = 1; $n <= 4; $n++) {
                    $rows[] = ['id' => (string) ($album * 10 + $n), 'artist_id' => (string) $aid,
                               'album_id' => (string) $album, 'genre' => $a['genre']];
                }
            }
        }
        return $rows;
    }

    /**
     * @param array<int, float> $affinity
     * @param array<int, int> $favTracks
     * @param array<int, int> $favAlbums
     * @param array<int, int> $recent played in the last 3 days
     * @param array<int, int> $ever played at any time
     * @param array<int, array{played:int, total:int}> $progress
     * @param array<string, array<int, array{name:string, match:float}>|null> $similar
     * @param array<int, array{name:string, genre:string}>|null $library artist_id => name/genre (radio fixture)
     * @param array<int, int> $tracksPerArtist artist_id => track count (radio fixture, ids aid*1000+n)
     * @param array<int, int> $ghostAlbums album ids deleted from disk: their tracks carry
     *        missing_at/album_missing_at in findByIds and drop out of findByArtists (as the repos do)
     */
    private function service(
        array $affinity = [],
        array $favTracks = [],
        array $favAlbums = [],
        array $recent = [],
        array $ever = [],
        array $progress = [],
        array $similar = [],
        ?LastfmClient $lastfm = null,
        ?array $library = null,
        array $tracksPerArtist = [],
        array $ghostAlbums = []
    ): RecommendationService {
        $rows = $library === null ? $this->rows() : $this->radioRows($library, $tracksPerArtist);
        $ghost = fn(int $albumId) => in_array($albumId, $ghostAlbums, true) ? '2026-10-13 10:00:00' : null;
        $rows = array_map(fn($r) => $r + ['missing_at' => $ghost((int) $r['album_id']),
                                          'album_missing_at' => $ghost((int) $r['album_id'])], $rows);
        $liveRows = array_values(array_filter($rows, fn($r) => $r['missing_at'] === null));
        $library ??= self::ARTISTS;

        $artists = $this->createMock(ArtistRepositoryInterface::class);
        $artists->method('findAll')->willReturn(array_map(
            fn($id, $a) => ['id' => (string) $id, 'name' => $a['name']],
            array_keys($library),
            $library
        ));

        $history = $this->createMock(PlayHistoryRepositoryInterface::class);
        $history->method('artistAffinity')->willReturn($affinity);
        $history->method('playedTrackIdsSince')->willReturnCallback(
            fn(int $u, int $days) => $days <= 3 ? $recent : array_values(array_unique(array_merge($ever, $recent)))
        );
        $history->method('albumProgress')->willReturn($progress);

        $tracks = $this->createMock(TrackRepositoryInterface::class);
        $tracks->method('findByArtists')->willReturnCallback(
            fn(array $ids) => array_values(array_filter($liveRows, fn($r) => in_array((int) $r['artist_id'], $ids, true)))
        );
        $tracks->method('findByIds')->willReturnCallback(
            fn(array $ids) => array_values(array_filter($rows, fn($r) => in_array((int) $r['id'], $ids, true)))
        );

        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('findByIds')->willReturnCallback(
            fn(array $ids) => array_map(fn($id) => ['id' => (string) $id, 'artist_id' => (string) intdiv($id, 10),
                                                     'missing_at' => $ghost($id)], $ids)
        );
        $albums->method('findRecent')->willReturn([['id' => '62'], ['id' => '61'], ['id' => '52']]);

        $favs = $this->createMock(FavoriteRepositoryInterface::class);
        $favs->method('trackIds')->willReturn($favTracks);
        $albumFavs = $this->createMock(AlbumFavoriteRepositoryInterface::class);
        $albumFavs->method('albumIds')->willReturn($favAlbums);

        if ($lastfm === null) {
            $lastfm = $this->createMock(LastfmClient::class);
            $lastfm->method('cachedSimilar')->willReturnCallback(fn(string $name) => $similar[$name] ?? null);
            $lastfm->expects($this->never())->method('similarArtists');
        }

        return new RecommendationService($artists, $lastfm, $history, $tracks, $albums, $favs, $albumFavs);
    }

    private static function artistOf(int $trackId): int
    {
        return intdiv($trackId, 100);
    }

    public function test_no_signals_returns_new_mode(): void
    {
        $ever = [111, 112];
        $rec = $this->service(ever: $ever)->forUser(7, self::DATE);

        $this->assertSame('new', $rec['mode']);
        $this->assertCount(12, $rec['track_ids']);
        $this->assertEmpty(array_intersect($ever, $rec['track_ids']));
        $this->assertSame([62, 61, 52], $rec['album_ids']);
    }

    public function test_excludes_tracks_played_last_3_days_and_favorites(): void
    {
        $recent = [111, 112, 211];
        $rec = $this->service(affinity: [1 => 2.0], favTracks: [113], recent: $recent)->forUser(7, self::DATE);

        $this->assertSame('personal', $rec['mode']);
        $this->assertNotEmpty($rec['track_ids']);
        $this->assertEmpty(array_intersect([111, 112, 211, 113], $rec['track_ids']));
        // Seed + genre-related artist still lead the row.
        $this->assertContains(self::artistOf($rec['track_ids'][0]), [1, 2]);
    }

    public function test_max_two_tracks_per_artist(): void
    {
        $rec = $this->service(affinity: [1 => 5.0, 2 => 3.0, 3 => 1.0])->forUser(7, self::DATE);

        $perArtist = array_count_values(array_map([self::class, 'artistOf'], $rec['track_ids']));
        $this->assertNotEmpty($perArtist);
        $this->assertLessThanOrEqual(2, max($perArtist));
        $this->assertCount(count(array_unique($rec['track_ids'])), $rec['track_ids']);
    }

    public function test_same_day_same_order_next_day_differs(): void
    {
        $affinity = [1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 1.0, 6 => 1.0];
        $service = $this->service(affinity: $affinity);

        $today = $service->forUser(7, '2026-10-13');
        $this->assertSame($today, $service->forUser(7, '2026-10-13'));
        $this->assertNotSame($today['track_ids'], $service->forUser(7, '2026-10-14')['track_ids']);
    }

    public function test_works_without_lastfm_cache(): void
    {
        $lastfm = $this->createMock(LastfmClient::class);
        $lastfm->expects($this->atLeastOnce())->method('cachedSimilar')->willReturn(null);
        $lastfm->expects($this->never())->method('similarArtists');

        $rec = $this->service(affinity: [1 => 2.0], lastfm: $lastfm)->forUser(7, self::DATE);

        $this->assertSame('personal', $rec['mode']);
        $artists = array_map([self::class, 'artistOf'], $rec['track_ids']);
        $this->assertContains(2, $artists, 'Beta shares "Indie Pop" with the seed');
        $albumArtists = array_map(fn($id) => intdiv($id, 10), $rec['album_ids']);
        $this->assertContains(2, $albumArtists, 'one Beta album to discover');
    }

    public function test_lastfm_cache_boosts_related_artists(): void
    {
        $similar = ['Alpha' => [['name' => 'GAMMA', 'match' => 1.0], ['name' => 'Not In Library', 'match' => 0.9]]];
        $rec = $this->service(affinity: [1 => 2.0], similar: $similar)->forUser(7, self::DATE);

        // Gamma (Metal) relates to Alpha only through Last.fm: R = 2·W_LASTFM·1.0 = 1.0 (as Beta by genre).
        $this->assertContains(3, array_map([self::class, 'artistOf'], $rec['track_ids']));
    }

    public function test_resume_albums_first_then_discover(): void
    {
        $progress = [
            21 => ['played' => 1, 'total' => 4], // Beta, < half -> resume
            11 => ['played' => 3, 'total' => 4], // Alpha, mostly played -> neither
            12 => ['played' => 2, 'total' => 4], // exactly half -> not resume
        ];
        $rec = $this->service(affinity: [1 => 3.0], ever: [211, 111, 112, 113, 121, 122], progress: $progress)
            ->forUser(7, self::DATE);

        $this->assertSame(21, $rec['album_ids'][0]);
        // Discover: Beta's untouched album; Alpha's albums were played; one per artist.
        $this->assertSame([21, 22], $rec['album_ids']);
    }

    public function test_various_artists_never_seed(): void
    {
        $rec = $this->service(affinity: [99 => 50.0, 1 => 1.0, 2 => 1.0, 5 => 1.0], favAlbums: [991])
            ->forUser(7, self::DATE);

        $this->assertSame('personal', $rec['mode']);
        $this->assertGreaterThanOrEqual(6, count($rec['track_ids']));
        $this->assertNotContains(99, array_map([self::class, 'artistOf'], $rec['track_ids']));
        $this->assertNotContains(992, $rec['album_ids']);
        $this->assertNotContains(991, $rec['album_ids']);
    }

    public function test_ghost_favorites_seed_nothing(): void
    {
        // Control: alive, a favorite Gamma track and a favorite Delta album make them seeds,
        // so one album of each (Metal and Jazz share no genre with Alpha) shows up to discover.
        $alive = $this->service(affinity: [1 => 2.0], favTracks: [311], favAlbums: [41])->forUser(7, self::DATE);
        $albumArtists = array_map(fn($id) => intdiv($id, 10), $alive['album_ids']);
        $this->assertContains(3, $albumArtists);
        $this->assertContains(4, $albumArtists);

        // Same favorites once albums 31 and 41 are ghosts: no seed, no track of them, no album of theirs.
        $rec = $this->service(affinity: [1 => 2.0], favTracks: [311], favAlbums: [41], ghostAlbums: [31, 41])
            ->forUser(7, self::DATE);

        $this->assertSame('personal', $rec['mode']);
        $this->assertNotContains(311, $rec['track_ids']);
        $this->assertEmpty(array_filter($rec['track_ids'], fn($id) => in_array(intdiv($id, 10), [31, 41], true)));
        $albumArtists = array_map(fn($id) => intdiv($id, 10), $rec['album_ids']);
        $this->assertNotContains(3, $albumArtists);
        $this->assertNotContains(4, $albumArtists);
    }

    public function test_ghost_flag_on_album_only_is_enough(): void
    {
        // A track whose own missing_at is NULL but whose album is a ghost must not seed either.
        $artists = $this->createMock(ArtistRepositoryInterface::class);
        $artists->method('findAll')->willReturn([['id' => '1', 'name' => 'Alpha'], ['id' => '3', 'name' => 'Gamma']]);
        $history = $this->createMock(PlayHistoryRepositoryInterface::class);
        $history->method('artistAffinity')->willReturn([]);
        $history->method('playedTrackIdsSince')->willReturn([]);
        $history->method('albumProgress')->willReturn([]);
        $tracks = $this->createMock(TrackRepositoryInterface::class);
        $tracks->method('findByArtists')->willReturn([
            ['id' => '111', 'artist_id' => '1', 'album_id' => '11', 'genre' => 'Indie Pop'],
            ['id' => '321', 'artist_id' => '3', 'album_id' => '32', 'genre' => 'Metal'],
        ]);
        $tracks->method('findByIds')->willReturn([
            ['id' => '311', 'artist_id' => '3', 'album_id' => '31', 'missing_at' => null, 'album_missing_at' => '2026-10-13 10:00:00'],
        ]);
        $favs = $this->createMock(FavoriteRepositoryInterface::class);
        $favs->method('trackIds')->willReturn([311]);
        $lastfm = $this->createMock(LastfmClient::class);
        $lastfm->method('cachedSimilar')->willReturn(null);

        $rec = (new RecommendationService(
            $artists, $lastfm, $history, $tracks,
            $this->createMock(AlbumRepositoryInterface::class), $favs,
            $this->createMock(AlbumFavoriteRepositoryInterface::class)
        ))->forUser(7, self::DATE);

        // No live seed -> R is empty -> no album to discover (Gamma's live album 32 would be one).
        $this->assertSame([], $rec['album_ids']);
        $this->assertNotContains(311, $rec['track_ids']);
    }

    // ---------------------------------------------------------------- radio

    /**
     * @param array<int, array{name:string, genre:string}> $library
     * @param array<int, int> $tracksPerArtist
     * @return array<int, array<string, mixed>>
     */
    private function radioRows(array $library, array $tracksPerArtist): array
    {
        $rows = [];
        foreach ($library as $aid => $a) {
            for ($n = 1; $n <= ($tracksPerArtist[$aid] ?? 10); $n++) {
                $rows[] = ['id' => (string) ($aid * 1000 + $n), 'artist_id' => (string) $aid,
                           'album_id' => (string) ($aid * 10 + 1), 'genre' => $a['genre']];
            }
        }
        return $rows;
    }

    /** Seed 1 (60 tracks) with 12 genre-related artists (10 tracks each), one unrelated, one VA. */
    private function radioService(): RecommendationService
    {
        $library = [1 => ['name' => 'Seed', 'genre' => 'Indie Pop'], 50 => ['name' => 'Metal Band', 'genre' => 'Metal'],
                    99 => ['name' => 'Various Artists', 'genre' => 'Indie Pop']];
        for ($aid = 2; $aid <= 13; $aid++) {
            $library[$aid] = ['name' => "Related $aid", 'genre' => 'Indie Pop, Dream Pop'];
        }
        return $this->service(library: $library, tracksPerArtist: [1 => 60]);
    }

    public function test_radio_never_repeats_artist_consecutively(): void
    {
        $service = $this->radioService();
        $orders = [];
        for ($run = 0; $run < 20; $run++) {
            $ids = $service->radio(1);
            $this->assertCount(50, $ids);
            $this->assertCount(50, array_unique($ids));
            $artists = array_map(fn($id) => intdiv($id, 1000), $ids);
            for ($i = 1; $i < count($artists); $i++) {
                $this->assertNotSame($artists[$i - 1], $artists[$i], "run $run, position $i");
            }
            $this->assertNotContains(50, $artists, 'unrelated genre, no Last.fm match');
            $this->assertNotContains(99, $artists, 'Various Artists is never related');
            $perArtist = array_count_values(array_filter($artists, fn($a) => $a !== 1));
            $this->assertLessThanOrEqual(4, max($perArtist));
            $orders[] = $ids;
        }
        $this->assertGreaterThan(1, count(array_unique(array_map('serialize', $orders))), 'varies on every call');
    }

    public function test_radio_fills_with_seed_when_no_related(): void
    {
        $library = [1 => ['name' => 'Seed', 'genre' => 'Indie Pop'], 2 => ['name' => 'Other', 'genre' => 'Indie Pop'],
                    50 => ['name' => 'Metal Band', 'genre' => 'Metal']];
        $ids = $this->service(library: $library, tracksPerArtist: [1 => 60, 2 => 2])->radio(1);

        $this->assertCount(50, $ids);
        $this->assertCount(50, array_unique($ids));
        $artists = array_count_values(array_map(fn($id) => intdiv($id, 1000), $ids));
        ksort($artists);
        $this->assertSame([1 => 48, 2 => 2], $artists, 'two related tracks, the rest from the seed');
    }

    public function test_radio_about_forty_percent_seed(): void
    {
        $ids = $this->radioService()->radio(1);

        $this->assertCount(50, $ids);
        $seed = count(array_filter($ids, fn($id) => intdiv($id, 1000) === 1));
        $this->assertGreaterThanOrEqual(15, $seed);
        $this->assertLessThanOrEqual(25, $seed);
    }

    public function test_radio_fills_with_related_when_seed_is_short(): void
    {
        $library = [1 => ['name' => 'Seed', 'genre' => 'Indie Pop']];
        for ($aid = 2; $aid <= 13; $aid++) {
            $library[$aid] = ['name' => "Related $aid", 'genre' => 'Indie Pop, Dream Pop'];
        }
        $service = $this->service(library: $library, tracksPerArtist: [1 => 5]);

        for ($run = 0; $run < 10; $run++) {
            $ids = $service->radio(1);
            $this->assertCount(50, $ids, "run $run");
            $this->assertCount(50, array_unique($ids));
            $artists = array_map(fn($id) => intdiv($id, 1000), $ids);
            $this->assertCount(5, array_filter($artists, fn($a) => $a === 1), 'every seed track, no more');
            for ($i = 1; $i < count($artists); $i++) {
                $this->assertNotSame($artists[$i - 1], $artists[$i], "run $run, position $i");
            }
            $perArtist = array_count_values(array_filter($artists, fn($a) => $a !== 1));
            $this->assertLessThanOrEqual(4, max($perArtist));
        }
    }
}
