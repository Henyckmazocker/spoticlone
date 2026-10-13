<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Repository\Music\PlayHistoryRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\TrackPresenter;

class PlaybackController extends BaseController
{
    public function __construct(
        private readonly PlayHistoryRepositoryInterface $history,
        private readonly TrackRepositoryInterface $tracks,
        private readonly TrackPresenter $presenter
    ) {}

    public function logPlay(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $trackId = (int) ($data['trackId'] ?? $data['id'] ?? 0);
        if (!$this->tracks->findById($trackId)) {
            return $this->errorResponse('Track not found.', 404);
        }
        $this->history->record($userId, $trackId);
        return $this->successResponse('Play logged');
    }

    public function getRecentlyPlayed(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $ids = $this->history->recentTrackIds($userId, (int) ($data['limit'] ?? 30));
        return $this->successResponse('Recently played', [
            'tracks' => $this->presenter->presentMany($this->tracks->findByIds($ids), $userId),
        ]);
    }

    public function getMostPlayed(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $most = $this->history->mostPlayed($userId, (int) ($data['limit'] ?? 30));
        $ids = array_map(fn($r) => $r['track_id'], $most);
        $tracks = $this->presenter->presentMany($this->tracks->findByIds($ids), $userId);
        // Attach play counts by track id.
        $plays = [];
        foreach ($most as $r) {
            $plays[$r['track_id']] = $r['plays'];
        }
        foreach ($tracks as &$t) {
            $t['plays'] = $plays[$t['id']] ?? 0;
        }
        return $this->successResponse('Most played', ['tracks' => $tracks]);
    }
}
