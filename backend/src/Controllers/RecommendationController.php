<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\ArtistRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\AlbumPresenter;
use App\Domain\Services\RecommendationService;
use App\Domain\Services\TrackPresenter;

class RecommendationController extends BaseController
{
    public function __construct(
        private readonly RecommendationService $recommendations,
        private readonly TrackRepositoryInterface $tracks,
        private readonly AlbumRepositoryInterface $albums,
        private readonly TrackPresenter $trackPresenter,
        private readonly AlbumPresenter $albumPresenter,
        private readonly ArtistRepositoryInterface $artists
    ) {}

    public function getRecommendations(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $rec = $this->recommendations->forUser($userId);
        return $this->successResponse('Recommendations', [
            'mode'   => $rec['mode'],
            'tracks' => $this->trackPresenter->presentMany($this->tracks->findByIds($rec['track_ids']), $userId),
            'albums' => $this->albumPresenter->presentMany($this->albums->findByIds($rec['album_ids']), $userId),
        ]);
    }

    public function getArtistRadio(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $artistId = (int) ($data['artistId'] ?? 0);
        if (!$this->artists->findById($artistId)) {
            return $this->errorResponse('Artist not found.', 404);
        }
        $ids = $this->recommendations->radio($artistId);
        return $this->successResponse('Artist radio', [
            'tracks' => $this->trackPresenter->presentMany($this->tracks->findByIds($ids), $userId),
        ]);
    }
}
