<?php

declare(strict_types=1);

namespace App\Domain\Repository\Music;

interface TrackRepositoryInterface
{
    /** @return array<string, mixed>|null */
    public function findByPath(string $filePath): ?array;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /** @param array<string, mixed> $data @return int new track id */
    public function insert(array $data): int;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void;

    /** @return array<int, array<string, mixed>> Tracks of an album ordered by disc/track no. */
    public function findByAlbum(int $albumId): array;

    /** @return array<int, array<string, mixed>> */
    public function findByIds(array $ids): array;

    /** @return array<int, array<string, mixed>> */
    public function search(string $term, int $limit = 50): array;

    /**
     * @param array<int, int> $artistIds
     * @return array<int, array<string, mixed>> Tracks of those artists (rows like findByAlbum, with genre and artist_id).
     */
    public function findByArtists(array $artistIds): array;

    /**
     * A ghost track of the album to reattach a new file to: by (disc, track_no) with disc
     * defaulting to 1 on both sides; if $trackNo is null, by normalised title, and only when
     * exactly one ghost matches. Never matches live tracks.
     *
     * @return array<string, mixed>|null
     */
    public function findGhostInAlbum(int $albumId, ?int $disc, ?int $trackNo, string $titleNorm): ?array;

    /** update() + file_path (if given in $data) + missing_at = NULL. @param array<string, mixed> $data */
    public function reattach(int $trackId, array $data): void;

    /** @return array<int, string> id => file_path of every live track (for the prune). */
    public function liveFilePaths(): array;

    /** @param array<int, int> $ids */
    public function markMissingByIds(array $ids): void;
}
