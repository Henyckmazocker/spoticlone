<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Repository\Music\PlaylistRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\TrackPresenter;

class PlaylistController extends BaseController
{
    public function __construct(
        private readonly PlaylistRepositoryInterface $playlists,
        private readonly TrackRepositoryInterface $tracks,
        private readonly TrackPresenter $presenter
    ) {}

    public function getPlaylists(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $rows = array_map(fn($p) => [
            'id'          => (int) $p['id'],
            'name'        => $p['name'],
            'description' => $p['description'],
            'is_public'   => (bool) $p['is_public'],
            'track_count' => (int) ($p['track_count'] ?? 0),
            'updated_at'  => $p['updated_at'],
        ], $this->playlists->findAllForUser($userId));

        return $this->successResponse('Playlists', ['playlists' => $rows]);
    }

    public function getPlaylist(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $id = (int) ($data['playlistId'] ?? $data['id'] ?? 0);
        $playlist = $this->playlists->findForUser($userId, $id);
        if (!$playlist) {
            return $this->errorResponse('Playlist not found.', 404);
        }
        $ids = $this->playlists->trackIds($id);
        $tracks = $this->presenter->presentMany($this->tracks->findByIds($ids), $userId);
        return $this->successResponse('Playlist', [
            'playlist' => [
                'id'          => (int) $playlist['id'],
                'name'        => $playlist['name'],
                'description' => $playlist['description'],
                'is_public'   => (bool) $playlist['is_public'],
            ],
            'tracks' => $tracks,
        ]);
    }

    public function createPlaylist(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $id = $this->playlists->create(
            $userId,
            (string) $data['name'],
            isset($data['description']) ? (string) $data['description'] : null,
            (bool) ($data['is_public'] ?? false)
        );
        return $this->successResponse('Playlist created', ['id' => $id]);
    }

    public function updatePlaylist(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $id = (int) ($data['playlistId'] ?? $data['id'] ?? 0);
        if (!$this->playlists->findForUser($userId, $id)) {
            return $this->errorResponse('Playlist not found.', 404);
        }
        $this->playlists->update($userId, $id, $data);
        return $this->successResponse('Playlist updated');
    }

    public function deletePlaylist(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $id = (int) ($data['playlistId'] ?? $data['id'] ?? 0);
        $ok = $this->playlists->delete($userId, $id);
        return $ok ? $this->successResponse('Playlist deleted') : $this->errorResponse('Playlist not found.', 404);
    }

    public function addToPlaylist(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $id = (int) ($data['playlistId'] ?? 0);
        if (!$this->playlists->findForUser($userId, $id)) {
            return $this->errorResponse('Playlist not found.', 404);
        }
        $trackIds = $this->trackIdsFromInput($data);
        foreach ($trackIds as $trackId) {
            $this->playlists->addTrack($id, $trackId);
        }
        return $this->successResponse('Tracks added', ['added' => count($trackIds)]);
    }

    public function removeFromPlaylist(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $id = (int) ($data['playlistId'] ?? 0);
        if (!$this->playlists->findForUser($userId, $id)) {
            return $this->errorResponse('Playlist not found.', 404);
        }
        $this->playlists->removeTrack($id, (int) ($data['trackId'] ?? 0));
        return $this->successResponse('Track removed');
    }

    public function reorderPlaylist(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $id = (int) ($data['playlistId'] ?? 0);
        if (!$this->playlists->findForUser($userId, $id)) {
            return $this->errorResponse('Playlist not found.', 404);
        }
        $order = array_map('intval', $data['trackIds'] ?? []);
        $this->playlists->reorder($id, $order);
        return $this->successResponse('Playlist reordered');
    }

    /** @return array<int, int> */
    private function trackIdsFromInput(array $data): array
    {
        if (isset($data['trackIds']) && is_array($data['trackIds'])) {
            return array_values(array_filter(array_map('intval', $data['trackIds'])));
        }
        if (isset($data['trackId'])) {
            return [(int) $data['trackId']];
        }
        return [];
    }
}
