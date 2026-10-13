<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Repository\Music\AlbumRepositoryInterface;

/**
 * Tells whether an (artist, album title) pair from outside the library (Spotify) is already in it:
 * `present` (a live album), `missing` (a ghost: deleted from disk, history kept) or null.
 *
 * Matching is Normalize::name on both sides (accents, case, a leading "The", punctuation), compared
 * in PHP because Normalize uses iconv and cannot be replicated in SQL. Spotify joins several artists
 * with ", ": the whole string is tried first and, if it does not match, only the first artist (local
 * albums are filed under the album artist, usually the main one). A live album beats a ghost.
 */
final class LibraryMatcher
{
    public function __construct(
        private readonly AlbumRepositoryInterface $albums
    ) {}

    /** @return array{status: 'present'|'missing', album_id: int}|null */
    public function match(string $artist, string $title): ?array
    {
        return $this->matchMany([[$artist, $title]])[0];
    }

    /**
     * Same as match() for N pairs with a single repository query (none for an empty list).
     *
     * @param array<int|string, array{0: string, 1: string}> $pairs [artist, title], keys preserved
     * @return array<int|string, array{status: 'present'|'missing', album_id: int}|null>
     */
    public function matchMany(array $pairs): array
    {
        if (!$pairs) {
            return [];
        }
        $index = $this->buildIndex();

        $out = [];
        foreach ($pairs as $i => $pair) {
            $artist = (string) ($pair[0] ?? '');
            $title = (string) ($pair[1] ?? '');
            $match = $this->lookup($index, $artist, $title);
            if ($match === null && str_contains($artist, ', ')) {
                $match = $this->lookup($index, explode(', ', $artist, 2)[0], $title);
            }
            $out[$i] = $match;
        }
        return $out;
    }

    /** @return array<string, array{status: 'present'|'missing', album_id: int}> */
    private function buildIndex(): array
    {
        $index = [];
        foreach ($this->albums->allForMatching() as $row) {
            $key = self::key((string) $row['artist_name'], (string) $row['title']);
            if ($key === null) {
                continue;
            }
            $entry = [
                'status'   => $row['missing_at'] === null ? 'present' : 'missing',
                'album_id' => (int) $row['id'],
            ];
            // Live beats ghost; among equals the first (lowest id) stays.
            if (!isset($index[$key]) || ($index[$key]['status'] === 'missing' && $entry['status'] === 'present')) {
                $index[$key] = $entry;
            }
        }
        return $index;
    }

    /** @param array<string, array{status: string, album_id: int}> $index */
    private function lookup(array $index, string $artist, string $title): ?array
    {
        $key = self::key($artist, $title);
        return $key === null ? null : ($index[$key] ?? null);
    }

    private static function key(string $artist, string $title): ?string
    {
        $a = Normalize::name($artist);
        $t = Normalize::name($title);
        return ($a === '' || $t === '') ? null : $a . '|' . $t;
    }
}
