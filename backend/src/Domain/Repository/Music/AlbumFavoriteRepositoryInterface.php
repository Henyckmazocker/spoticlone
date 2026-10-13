<?php

declare(strict_types=1);

namespace App\Domain\Repository\Music;

interface AlbumFavoriteRepositoryInterface
{
    /** Add the favorite if missing, remove it if present. @return bool the new state. */
    public function toggle(int $userId, int $albumId): bool;

    public function isFavorite(int $userId, int $albumId): bool;

    /** @param array<int, int> $albumIds @return array<int, bool> keyed by album id ([] for an empty list, no query). */
    public function favoriteMap(int $userId, array $albumIds): array;

    /** @return array<int, int> Ordered album ids the user favorited (newest first). */
    public function albumIds(int $userId): array;
}
