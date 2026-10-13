<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Repository\Music\AlbumFavoriteRepositoryInterface;
use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\ArtistRepositoryInterface;
use App\Domain\Repository\Music\FavoriteRepositoryInterface;
use App\Domain\Repository\Music\PlayHistoryRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Infrastructure\Music\LastfmClient;
use Psr\Log\LoggerInterface;

/**
 * "For you" recommendations: a hybrid engine over internal signals (plays, favorites, genres)
 * boosted by the Last.fm similar-artists cache. Request paths only read that cache
 * (cachedSimilar), never the network; warmSimilarArtists() fills it after a scan.
 */
class RecommendationService
{
    private const VARIOUS_ARTISTS = 'variousartists';
    private const MAX_CONSECUTIVE_FAILURES = 3;

    /** Last.fm weight: median of 1 library artist among Last.fm's similars (M1 measurement) -> 0.5. */
    public const W_LASTFM = 0.5;
    public const W_GENRE = 0.5;
    private const AFFINITY_DAYS = 180;
    private const FAV_TRACK_BONUS = 3.0;
    private const FAV_ALBUM_BONUS = 5.0;
    private const SEEDS = 10;
    private const RECENT_DAYS = 3;
    private const EVER_DAYS = 36500;
    private const MAX_TRACKS = 12;
    private const MIN_TRACKS = 6;
    private const MAX_TRACKS_PER_ARTIST = 2;
    private const REPLAY_FACTOR = 0.4;
    private const MAX_ALBUMS = 8;
    private const MAX_RESUME_ALBUMS = 4;
    private const RADIO_SIZE = 50;
    private const RADIO_SEED = 20;
    private const RADIO_PER_ARTIST = 4;

    public function __construct(
        private readonly ArtistRepositoryInterface $artists,
        private readonly LastfmClient $lastfm,
        private readonly PlayHistoryRepositoryInterface $history,
        private readonly TrackRepositoryInterface $tracks,
        private readonly AlbumRepositoryInterface $albums,
        private readonly FavoriteRepositoryInterface $favorites,
        private readonly AlbumFavoriteRepositoryInterface $albumFavorites,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Daily "for you" mix for a user. Deterministic for a given ($userId, $date).
     *
     * @return array{mode:string, track_ids:array<int,int>, album_ids:array<int,int>}
     */
    public function forUser(int $userId, ?string $date = null): array
    {
        $date ??= date('Y-m-d');

        $affinity = $this->history->artistAffinity($userId, self::AFFINITY_DAYS);
        $favTrackIds = $this->favorites->trackIds($userId);
        $favAlbumIds = $this->albumFavorites->albumIds($userId);

        [$names, $variousIds, $rows] = $this->library();
        $everPlayed = array_flip($this->history->playedTrackIdsSince($userId, self::EVER_DAYS));

        if (!$affinity && !$favTrackIds && !$favAlbumIds) {
            return $this->newMode($userId, $date, $rows, $everPlayed);
        }

        // ---- Affinity A(artist) ----
        // findByIds keeps ghosts on purpose (Favorites draws them grey): a deleted favorite seeds nothing.
        $A = $affinity;
        foreach ($this->tracks->findByIds($favTrackIds) as $t) {
            if (($t['missing_at'] ?? null) !== null || ($t['album_missing_at'] ?? null) !== null) {
                continue;
            }
            $aid = (int) $t['artist_id'];
            $A[$aid] = ($A[$aid] ?? 0.0) + self::FAV_TRACK_BONUS;
        }
        foreach ($this->albums->findByIds($favAlbumIds) as $al) {
            if (($al['missing_at'] ?? null) !== null) {
                continue;
            }
            $aid = (int) $al['artist_id'];
            $A[$aid] = ($A[$aid] ?? 0.0) + self::FAV_ALBUM_BONUS;
        }
        $A = array_filter($A, fn($v, $id) => $v > 0 && !isset($variousIds[$id]), ARRAY_FILTER_USE_BOTH);
        arsort($A);
        $seeds = array_slice($A, 0, self::SEEDS, true);

        // ---- Related artists R(b) ----
        $R = $this->relatedScores($seeds, $names, $variousIds, $this->genresByArtist($rows));

        // ---- Tracks ----
        $recent = array_flip($this->history->playedTrackIdsSince($userId, self::RECENT_DAYS));
        $favSet = array_flip($favTrackIds);
        $scored = [];
        foreach ($rows as $t) {
            $id = (int) $t['id'];
            $aid = (int) $t['artist_id'];
            if (($R[$aid] ?? 0.0) <= 0 || isset($recent[$id]) || isset($favSet[$id])) {
                continue;
            }
            $factor = isset($everPlayed[$id]) ? self::REPLAY_FACTOR : 1.0;
            $scored[$id] = ['score' => $R[$aid] * $factor * $this->jitter($userId, $date, $id), 'artist' => $aid];
        }
        $trackIds = $this->pickCapped($scored, self::MAX_TRACKS, self::MAX_TRACKS_PER_ARTIST);

        // Plan B: never show a thin row -> top up with the day's mix of never-played tracks.
        // The 2-per-artist cap still holds for the whole row.
        if (count($trackIds) < self::MIN_TRACKS) {
            $artistOf = [];
            foreach ($rows as $t) {
                $artistOf[(int) $t['id']] = (int) $t['artist_id'];
            }
            $perArtist = array_count_values(array_map(fn($id) => $artistOf[$id], $trackIds));
            $exclude = array_flip($trackIds) + $favSet;
            foreach ($this->neverPlayedMix($userId, $date, $rows, $everPlayed, $exclude, PHP_INT_MAX) as $id) {
                if (count($trackIds) >= self::MAX_TRACKS) {
                    break;
                }
                $aid = $artistOf[$id];
                if (($perArtist[$aid] ?? 0) < self::MAX_TRACKS_PER_ARTIST) {
                    $perArtist[$aid] = ($perArtist[$aid] ?? 0) + 1;
                    $trackIds[] = $id;
                }
            }
        }

        return [
            'mode'      => 'personal',
            'track_ids' => $trackIds,
            'album_ids' => $this->pickAlbums($userId, $date, $rows, $R, array_flip($favAlbumIds)),
        ];
    }

    /**
     * Artist radio (<= 50 track ids): up to 20 random tracks of the seed artist + the rest (30, or
     * more when the seed artist has fewer than 20 tracks) from its related artists (R from that seed
     * only, <= 4 per artist, drawn by R-weighted sampling), interleaved so the same artist never
     * plays twice in a row. Missing related tracks are topped up with more seed tracks (then
     * back-to-back seed tracks may be unavoidable). Varies on every call.
     *
     * @return array<int, int>
     */
    public function radio(int $artistId): array
    {
        [$names, $variousIds, $rows] = $this->library();

        $byArtist = [];
        foreach ($rows as $t) {
            $byArtist[(int) $t['artist_id']][] = (int) $t['id'];
        }

        $R = $this->relatedScores([$artistId => 1.0], $names, $variousIds, $this->genresByArtist($rows));
        unset($R[$artistId]);

        $seedTracks = $this->shuffled($byArtist[$artistId] ?? []);
        $relatedTarget = self::RADIO_SIZE - min(self::RADIO_SEED, count($seedTracks));
        $related = $this->weightedRelatedPick($R, $byArtist, $relatedTarget, self::RADIO_PER_ARTIST);
        $seedCount = max(self::RADIO_SEED, self::RADIO_SIZE - count($related));
        $seedTracks = array_slice($seedTracks, 0, $seedCount);

        $queues = [];
        foreach ($related as $id => $aid) {
            $queues[$aid][] = $id;
        }
        if ($seedTracks) {
            $queues[$artistId] = $seedTracks;
        }
        return array_slice($this->interleave($queues), 0, self::RADIO_SIZE);
    }

    /**
     * Library snapshot: artist names, Various Artists ids and every track (the library is small).
     *
     * @return array{0:array<int,string>, 1:array<int,true>, 2:array<int,array<string,mixed>>}
     */
    private function library(): array
    {
        $names = [];
        $variousIds = [];
        foreach ($this->artists->findAll() as $a) {
            $id = (int) $a['id'];
            $names[$id] = (string) ($a['name'] ?? '');
            if (Normalize::name($names[$id]) === self::VARIOUS_ARTISTS) {
                $variousIds[$id] = true;
            }
        }
        $rows = $names ? $this->tracks->findByArtists(array_keys($names)) : [];
        return [$names, $variousIds, $rows];
    }

    /**
     * Up to $limit tracks of artists with R > 0, sampled without replacement with probability
     * proportional to R(artist) (roulette wheel over integer weights with random_int), at most
     * $perArtist per artist. Higher R comes more often, but not always the same tracks.
     *
     * @param array<int, float> $R
     * @param array<int, array<int, int>> $byArtist artist_id => track ids
     * @return array<int, int> track_id => artist_id, in draw order
     */
    private function weightedRelatedPick(array $R, array $byArtist, int $limit, int $perArtist): array
    {
        $pool = [];
        foreach ($R as $aid => $r) {
            if ($r <= 0) {
                continue;
            }
            $w = max(1, (int) round($r * 1000));
            foreach ($byArtist[$aid] ?? [] as $id) {
                $pool[$id] = ['artist' => $aid, 'w' => $w];
            }
        }

        $out = [];
        $count = [];
        while (count($out) < $limit && $pool) {
            $total = array_sum(array_column($pool, 'w'));
            $ticket = random_int(1, $total);
            foreach ($pool as $id => $p) {
                $ticket -= $p['w'];
                if ($ticket <= 0) {
                    break;
                }
            }
            $aid = $pool[$id]['artist'];
            $out[$id] = $aid;
            unset($pool[$id]);
            if (($count[$aid] = ($count[$aid] ?? 0) + 1) >= $perArtist) {
                $pool = array_filter($pool, fn($p) => $p['artist'] !== $aid);
            }
        }
        return $out;
    }

    /**
     * Fisher-Yates with random_int (no mt_rand global state).
     *
     * @param array<int, int> $items
     * @return array<int, int>
     */
    private function shuffled(array $items): array
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }

    /**
     * Merge per-artist queues so no artist appears twice in a row whenever that is possible:
     * at each step pick at random (weighted by remaining tracks) among the artists other than the
     * previous one whose choice keeps the rest arrangeable. If no choice is (one artist dominates),
     * take the artist with the most tracks left that differs from the previous one, else repeat.
     *
     * @param array<int, array<int, int>> $queues artist_id => track ids in play order
     * @return array<int, int>
     */
    private function interleave(array $queues): array
    {
        $left = array_map('count', $queues);
        $n = array_sum($left);
        $out = [];
        $last = null;
        while ($n > 0) {
            $feasible = [];
            $fallback = null;
            foreach ($left as $aid => $c) {
                if ($c === 0 || $aid === $last) {
                    continue;
                }
                if ($fallback === null || $c > $left[$fallback]) {
                    $fallback = $aid;
                }
                // After placing $aid: any artist X needs cnt_X <= others + (X !== $aid ? 1 : 0).
                $ok = true;
                foreach ($left as $x => $cx) {
                    $cx -= $x === $aid ? 1 : 0;
                    if ($cx > ($n - 1 - $cx) + ($x === $aid ? 0 : 1)) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $feasible[$aid] = $c;
                }
            }

            if ($feasible) {
                $ticket = random_int(1, array_sum($feasible));
                foreach ($feasible as $aid => $c) {
                    $ticket -= $c;
                    if ($ticket <= 0) {
                        break;
                    }
                }
            } else {
                $aid = $fallback ?? $last;
            }

            $out[] = array_shift($queues[$aid]);
            $left[$aid]--;
            $n--;
            $last = $aid;
        }
        return $out;
    }

    /**
     * R(b) = max over seeds s of A(s)·(W_LASTFM·match(s,b) + W_GENRE·g(s,b)); seeds get R = A.
     *
     * @param array<int, float> $seeds artist_id => A
     * @param array<int, string> $names
     * @param array<int, true> $variousIds
     * @param array<int, array<int, string>> $genres artist_id => normalized genres
     * @return array<int, float>
     */
    private function relatedScores(array $seeds, array $names, array $variousIds, array $genres): array
    {
        $byNorm = [];
        foreach ($names as $id => $name) {
            if (!isset($variousIds[$id]) && $name !== '') {
                $byNorm[Normalize::name($name)][] = $id;
            }
        }

        $R = [];
        foreach ($seeds as $s => $a) {
            $match = [];
            foreach ($this->lastfm->cachedSimilar($names[$s] ?? '') ?? [] as $sim) {
                foreach ($byNorm[Normalize::name((string) $sim['name'])] ?? [] as $b) {
                    $match[$b] = max($match[$b] ?? 0.0, (float) $sim['match']);
                }
            }
            foreach ($names as $b => $_) {
                if ($b === $s || isset($variousIds[$b]) || isset($seeds[$b])) {
                    continue;
                }
                $g = $this->sharesGenre($genres[$s] ?? [], $genres[$b] ?? []) ? 1.0 : 0.0;
                $score = $a * (self::W_LASTFM * ($match[$b] ?? 0.0) + self::W_GENRE * $g);
                if ($score > ($R[$b] ?? 0.0)) {
                    $R[$b] = $score;
                }
            }
        }
        foreach ($seeds as $s => $a) {
            $R[$s] = $a;
        }
        return $R;
    }

    /**
     * Track genres split on common separators ("Pop, Rock", "Indie Pop;Catalan", "Rock - Punk").
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, string>>
     */
    private function genresByArtist(array $rows): array
    {
        $out = [];
        foreach ($rows as $t) {
            $raw = (string) ($t['genre'] ?? '');
            if ($raw === '') {
                continue;
            }
            foreach (preg_split('/[,;\/|]|\s-\s/', $raw) ?: [] as $part) {
                $g = Normalize::genre($part);
                if ($g !== '') {
                    $out[(int) $t['artist_id']][$g] = $g;
                }
            }
        }
        return array_map('array_values', $out);
    }

    /** @param array<int, string> $a @param array<int, string> $b */
    private function sharesGenre(array $a, array $b): bool
    {
        foreach ($a as $x) {
            foreach ($b as $y) {
                // Identical genres always match (e.g. "pop", under the 4-char containment floor).
                if ($x === $y || Normalize::genresMatch($x, $y)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * "New in your library": never-played tracks by the day's jitter + newest albums.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, int> $everPlayed track_id => *
     * @return array{mode:string, track_ids:array<int,int>, album_ids:array<int,int>}
     */
    private function newMode(int $userId, string $date, array $rows, array $everPlayed): array
    {
        return [
            'mode'      => 'new',
            'track_ids' => $this->neverPlayedMix($userId, $date, $rows, $everPlayed, [], self::MAX_TRACKS),
            'album_ids' => array_map(fn($a) => (int) $a['id'], $this->albums->findRecent(self::MAX_ALBUMS)),
        ];
    }

    /**
     * Never-played tracks ordered only by the day's jitter (at most $limit).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, int> $everPlayed track_id => *
     * @param array<int, int> $exclude track_id => *
     * @return array<int, int>
     */
    private function neverPlayedMix(
        int $userId,
        string $date,
        array $rows,
        array $everPlayed,
        array $exclude,
        int $limit
    ): array {
        $scored = [];
        foreach ($rows as $t) {
            $id = (int) $t['id'];
            if (!isset($everPlayed[$id]) && !isset($exclude[$id])) {
                $scored[$id] = ['score' => $this->jitter($userId, $date, $id), 'artist' => (int) $t['artist_id']];
            }
        }
        return $this->pickCapped($scored, $limit, PHP_INT_MAX);
    }

    /**
     * Up to 4 albums to resume (some but < half played) then albums to discover (artists with
     * R > 0, nothing played, 1 per artist). Favorites never appear.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, float> $R
     * @param array<int, int> $favAlbums album_id => *
     * @return array<int, int>
     */
    private function pickAlbums(int $userId, string $date, array $rows, array $R, array $favAlbums): array
    {
        $albumArtist = [];
        foreach ($rows as $t) {
            $albumArtist[(int) $t['album_id']] = (int) $t['artist_id'];
        }
        $progress = $this->history->albumProgress($userId);

        $resume = [];
        foreach ($progress as $albumId => $p) {
            if (isset($favAlbums[$albumId]) || !isset($albumArtist[$albumId])) {
                continue;
            }
            if ($p['played'] >= 1 && $p['played'] * 2 < $p['total']) {
                $aid = $albumArtist[$albumId];
                $resume[$albumId] = ['score' => ($R[$aid] ?? 0.0) * $this->jitter($userId, $date, $albumId), 'artist' => $aid];
            }
        }
        $picked = $this->pickCapped($resume, self::MAX_RESUME_ALBUMS, PHP_INT_MAX);

        $discover = [];
        foreach ($albumArtist as $albumId => $aid) {
            if (($R[$aid] ?? 0.0) <= 0 || isset($progress[$albumId]) || isset($favAlbums[$albumId])) {
                continue;
            }
            $discover[$albumId] = ['score' => $R[$aid] * $this->jitter($userId, $date, $albumId), 'artist' => $aid];
        }
        $more = $this->pickCapped($discover, self::MAX_ALBUMS - count($picked), 1);

        return array_merge($picked, $more);
    }

    /**
     * Highest score first (ties by id), at most $perArtist per artist.
     *
     * @param array<int, array{score:float, artist:int}> $scored id => score/artist
     * @return array<int, int>
     */
    private function pickCapped(array $scored, int $limit, int $perArtist): array
    {
        uksort($scored, fn($a, $b) => [$scored[$b]['score'], $a] <=> [$scored[$a]['score'], $b]);
        $out = [];
        $count = [];
        foreach ($scored as $id => $s) {
            if (count($out) >= $limit) {
                break;
            }
            if (($count[$s['artist']] ?? 0) >= $perArtist) {
                continue;
            }
            $count[$s['artist']] = ($count[$s['artist']] ?? 0) + 1;
            $out[] = (int) $id;
        }
        return $out;
    }

    /** Deterministic day mix in [0.85, 1.15): same list on reload, another one tomorrow. No mt_srand. */
    private function jitter(int $userId, string $date, int $id): float
    {
        return 0.85 + 0.3 * ((crc32("$userId|$date|$id") % 1000) / 1000);
    }

    /**
     * Fetch similar artists for every library artist without a cache entry.
     * Stops after 3 consecutive network failures. Returns how many artists were warmed.
     */
    public function warmSimilarArtists(): int
    {
        if (!$this->lastfm->isEnabled()) {
            return 0;
        }

        $warmed = 0;
        $failures = 0;
        foreach ($this->artists->findAll() as $artist) {
            $name = (string) ($artist['name'] ?? '');
            if ($name === '' || Normalize::name($name) === self::VARIOUS_ARTISTS) {
                continue;
            }
            if ($this->lastfm->cachedSimilar($name) !== null) {
                continue;
            }
            $this->lastfm->similarArtists($name);
            if ($this->lastfm->lastCallFailed()) {
                if (++$failures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $this->logger?->debug('Last.fm warm-up aborted after consecutive failures');
                    break;
                }
                continue;
            }
            $failures = 0;
            $warmed++;
        }
        return $warmed;
    }
}
