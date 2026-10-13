<?php

declare(strict_types=1);

namespace App\Domain\Repository\Music;

interface AlbumRepositoryInterface
{
    /** Insert the album if new, return its id either way (idempotent by artist+title). */
    public function upsert(int $artistId, string $title, string $folderPath, ?int $year): int;

    public function setCoverPath(int $id, ?string $coverPath): void;

    public function setCoverUrl(int $id, ?string $coverUrl): void;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<int, array<string, mixed>> */
    public function findByArtist(int $artistId): array;

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> rows with artist_name and track_count, in the requested order
     */
    public function findByIds(array $ids): array;

    /** @return array<int, array<string, mixed>> */
    public function search(string $term, int $limit = 25): array;

    /** @return array<int, array<string, mixed>> Albums with no cover_path nor cover_url. */
    public function findWithoutCover(): array;

    /** Delete the album row (cascades to its tracks). Returns true if a row was removed. */
    public function delete(int $id): bool;

    /** @return array<int, array<string, mixed>> Newest live albums (created_at DESC), rows like findByIds. */
    public function findRecent(int $limit): array;

    /**
     * Turn the album into a ghost: missing_at = NOW() on the album and all its tracks, and
     * cover_path = NULL (cover_url is kept). Rows, ids and their history stay.
     */
    public function markMissing(int $albumId): void;

    /** Bring a ghost back to life; when $folderPath is given it also replaces folder_path. */
    public function clearMissing(int $albumId, ?string $folderPath = null): void;

    /**
     * First ghost album whose Normalize::name(artist) and Normalize::name(title) match (compared in PHP).
     *
     * @return array<string, mixed>|null row with artist_name
     */
    public function findGhostByNormalized(string $artistNorm, string $titleNorm): ?array;

    /**
     * Every album, live and ghost, with what LibraryMatcher needs to index it (ordered by id).
     *
     * @return array<int, array{id: int|string, title: string, missing_at: ?string, artist_name: string}>
     */
    public function allForMatching(): array;

    /** Mark as ghost every live album that has tracks but none of them live. Returns how many. */
    public function markMissingWhereNoLiveTracks(): int;
}
