<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Repository\Music\AlbumFavoriteRepositoryInterface;
use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\FavoriteRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\AlbumPresenter;
use App\Domain\Services\TrackPresenter;

class FavoriteController extends BaseController
{
    public function __construct(
        private readonly FavoriteRepositoryInterface $favorites,
        private readonly TrackRepositoryInterface $tracks,
        private readonly TrackPresenter $presenter,
        private readonly AlbumRepositoryInterface $albums,
        private readonly AlbumFavoriteRepositoryInterface $albumFavorites,
        private readonly AlbumPresenter $albumPresenter
    ) {}

    public function toggleFavorite(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $trackId = (int) ($data['trackId'] ?? $data['id'] ?? 0);
        if (!$this->tracks->findById($trackId)) {
            return $this->errorResponse('Track not found.', 404);
        }
        $isFav = $this->favorites->toggle($userId, $trackId);
        return $this->successResponse('Favorite toggled', ['is_favorite' => $isFav]);
    }

    public function toggleAlbumFavorite(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $albumId = (int) ($data['albumId'] ?? $data['id'] ?? 0);
        if (!$this->albums->findById($albumId)) {
            return $this->errorResponse('Album not found.', 404);
        }
        $isFav = $this->albumFavorites->toggle($userId, $albumId);
        return $this->successResponse('Album favorite toggled', ['is_favorite' => $isFav]);
    }

    public function getFavorites(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $ids = $this->favorites->trackIds($userId);
        $tracks = $this->presenter->presentMany($this->tracks->findByIds($ids), $userId);
        $albums = $this->albumPresenter->presentMany(
            $this->albums->findByIds($this->albumFavorites->albumIds($userId)),
            $userId
        );
        return $this->successResponse('Favorites', ['tracks' => $tracks, 'albums' => $albums]);
    }
}
