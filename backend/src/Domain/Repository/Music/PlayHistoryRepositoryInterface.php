<?php

declare(strict_types=1);

namespace App\Domain\Repository\Music;

interface PlayHistoryRepositoryInterface
{
    public function record(int $userId, int $trackId): void;

    /** @return array<int, int> Distinct track ids most recently played (newest first). */
    public function recentTrackIds(int $userId, int $limit = 30): array;

    /** @return array<int, array{track_id:int, plays:int}> Most played tracks for the user. */
    public function mostPlayed(int $userId, int $limit = 30): array;

    /** @return array<int, float> artist_id => Σ 0.5^(days/30) over the plays of the last $days days. */
    public function artistAffinity(int $userId, int $days = 180): array;

    /** @return array<int, int> Distinct track ids played in the last $days days. */
    public function playedTrackIdsSince(int $userId, int $days): array;

    /**
     * @return array<int, array{played:int, total:int}> album_id => distinct tracks played / album tracks
     *         (only albums with played >= 1).
     */
    public function albumProgress(int $userId): array;
}
