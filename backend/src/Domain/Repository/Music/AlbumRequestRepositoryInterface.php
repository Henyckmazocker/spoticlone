<?php

declare(strict_types=1);

namespace App\Domain\Repository\Music;

/**
 * Album requests: one row per Spotify album, shared by every user who asks for it.
 * Rows are arrays with the album_requests columns; the list/find methods that say so add
 * `users_count` and `requested_by` (display names, never emails).
 */
interface AlbumRequestRepositoryInterface
{
    public function findById(int $id): ?array;                     // + users_count, requested_by
    public function findBySpotifyId(string $spotifyId): ?array;
    public function create(array $data): int;                       // returns the id
    public function attachUser(int $requestId, int $userId): void;  // INSERT IGNORE
    public function detachUser(int $requestId, int $userId): void;
    public function hasUser(int $requestId, int $userId): bool;
    public function countUsers(int $requestId): int;
    public function countPendingForUser(int $userId): int;          // status IN ('pending','downloading')
    public function listForUser(int $userId): array;                // newest first
    public function listAll(?array $statuses = null): array;        // + users_count, requested_by; newest first
    public function listOpen(): array;                              // pending|downloading, for the matcher
    /**
     * @param array $extra reject_reason, download_id, album_id, resolved_at (true = NOW(), null = NULL)
     */
    public function setStatus(int $id, string $status, array $extra = []): void;
    public function delete(int $id): void;
}
