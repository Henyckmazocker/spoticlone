<?php

declare(strict_types=1);

namespace App\Domain\Repository\Music;

interface PlaylistRepositoryInterface
{
    /** @return array<int, array<string, mixed>> Playlists owned by the user (with track_count). */
    public function findAllForUser(int $userId): array;

    /** @return array<string, mixed>|null Playlist row if owned by the user. */
    public function findForUser(int $userId, int $playlistId): ?array;

    public function create(int $userId, string $name, ?string $description, bool $isPublic): int;

    public function update(int $userId, int $playlistId, array $data): bool;

    public function delete(int $userId, int $playlistId): bool;

    /** @return array<int, int> Ordered track ids of the playlist. */
    public function trackIds(int $playlistId): array;

    public function addTrack(int $playlistId, int $trackId): void;

    public function removeTrack(int $playlistId, int $trackId): void;

    /** @param array<int, int> $orderedTrackIds */
    public function reorder(int $playlistId, array $orderedTrackIds): void;
}
