<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Repository\Music\PlaylistRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\TrackPresenter;

class PlaylistController extends BaseController
{
    private const MAX_TRACK_IDS = 500;
    private const MAX_NAME = 100;
    private const MAX_DESCRIPTION = 500;

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
        $invalid = $this->validateTextFields($data, true);
        if ($invalid !== null) {
            return $this->errorResponse($invalid, 400);
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
        $invalid = $this->validateTextFields($data, false);
        if ($invalid !== null) {
            return $this->errorResponse($invalid, 400);
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
        if ($trackIds === null) {
            return $this->errorResponse('trackIds must be an array of 1 to ' . self::MAX_TRACK_IDS . ' ids.', 400);
        }
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
        $order = $this->trackIdsFromInput($data);
        if ($order === null) {
            return $this->errorResponse('trackIds must be an array of 1 to ' . self::MAX_TRACK_IDS . ' ids.', 400);
        }
        $this->playlists->reorder($id, $order);
        return $this->successResponse('Playlist reordered');
    }

    /**
     * name: string 1..100 (obligatorio al crear); description: string 0..500.
     * @return string|null mensaje de error, o null si es válido
     */
    private function validateTextFields(array $data, bool $nameRequired): ?string
    {
        if ($nameRequired || array_key_exists('name', $data)) {
            $name = $data['name'] ?? null;
            if (!is_string($name) || mb_strlen($name) < 1 || mb_strlen($name) > self::MAX_NAME) {
                return 'name must be a string of 1 to ' . self::MAX_NAME . ' characters.';
            }
        }
        if (array_key_exists('description', $data) && $data['description'] !== null) {
            if (!is_string($data['description']) || mb_strlen($data['description']) > self::MAX_DESCRIPTION) {
                return 'description must be a string of up to ' . self::MAX_DESCRIPTION . ' characters.';
            }
        }
        return null;
    }

    /**
     * trackIds: array de 1..500 enteros (o el `trackId` suelto de add_to_playlist).
     * @return array<int, int>|null null si no es válido
     */
    private function trackIdsFromInput(array $data): ?array
    {
        if (isset($data['trackIds'])) {
            $raw = $data['trackIds'];
            if (!is_array($raw) || count($raw) < 1 || count($raw) > self::MAX_TRACK_IDS) {
                return null;
            }
            foreach ($raw as $id) {
                if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                    return null;
                }
            }
            return array_values(array_filter(array_map('intval', $raw)));
        }
        if (isset($data['trackId']) && is_scalar($data['trackId'])) {
            return [(int) $data['trackId']];
        }
        return null;
    }
}
