<?php

declare(strict_types=1);

namespace App\Domain\Repository\Music;

interface FavoriteRepositoryInterface
{
    /** Add the favorite if missing, remove it if present. @return bool the new state. */
    public function toggle(int $userId, int $trackId): bool;

    public function isFavorite(int $userId, int $trackId): bool;

    /** @param array<int, int> $trackIds @return array<int, bool> keyed by track id. */
    public function favoriteMap(int $userId, array $trackIds): array;

    /** @return array<int, int> Ordered track ids the user favorited (newest first). */
    public function trackIds(int $userId): array;
}
