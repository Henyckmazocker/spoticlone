<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\ArtistRepositoryInterface;
use App\Domain\Repository\Music\ScanRepositoryInterface;
use App\Domain\Repository\Music\TrackRepositoryInterface;
use App\Domain\Services\AlbumPresenter;
use App\Domain\Services\LibraryScanner;
use App\Domain\Services\MediaDeletionService;
use App\Domain\Services\RecommendationService;
use App\Domain\Services\ScanInProgressException;
use App\Domain\Services\TrackPresenter;
use App\Infrastructure\Auth\SignedUrlService;
use App\Infrastructure\Music\MetadataEnrichmentService;

class LibraryController extends BaseController
{
    public function __construct(
        private readonly ArtistRepositoryInterface $artists,
        private readonly AlbumRepositoryInterface $albums,
        private readonly TrackRepositoryInterface $tracks,
        private readonly ScanRepositoryInterface $scans,
        private readonly LibraryScanner $scanner,
        private readonly TrackPresenter $presenter,
        private readonly SignedUrlService $signedUrls,
        private readonly MetadataEnrichmentService $enrichment,
        private readonly MediaDeletionService $deletion,
        private readonly AlbumPresenter $albumPresenter,
        private readonly RecommendationService $recommendations
    ) {}

    public function getArtists(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $rows = array_map(fn($a) => [
            'id'          => (int) $a['id'],
            'name'        => $a['name'],
            'album_count' => (int) ($a['album_count'] ?? 0),
            'image_url'   => $a['image_url'] ?? null,
        ], $this->artists->findAll());

        return $this->successResponse('Artists', ['artists' => $rows]);
    }

    public function getArtist(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $artistId = (int) ($data['artistId'] ?? $data['id'] ?? 0);
        $artist = $this->artists->findById($artistId);
        if (!$artist) {
            return $this->errorResponse('Artist not found.', 404);
        }
        return $this->successResponse('Artist', [
            'artist' => ['id' => (int) $artist['id'], 'name' => $artist['name'], 'image_url' => $artist['image_url'] ?? null],
            'albums' => $this->albumPresenter->presentMany($this->albums->findByArtist($artistId), $userId),
        ]);
    }

    public function getAlbum(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $albumId = (int) ($data['albumId'] ?? $data['id'] ?? 0);
        $album = $this->albums->findById($albumId);
        if (!$album) {
            return $this->errorResponse('Album not found.', 404);
        }
        $tracks = $this->presenter->presentMany($this->tracks->findByAlbum($albumId), $userId);
        return $this->successResponse('Album', [
            'album'  => $this->albumPresenter->present($album, $userId),
            'tracks' => $tracks,
        ]);
    }

    public function deleteAlbum(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $albumId = (int) ($data['albumId'] ?? $data['id'] ?? 0);
        $result = $this->deletion->deleteAlbum($albumId);
        if (!empty($result['incomplete'])) {
            return $this->errorResponse('Could not remove every file of the album; it was kept.', 500);
        }
        if (!$result['deleted']) {
            return $this->errorResponse('Album not found.', 404);
        }
        return $this->successResponse('Album deleted', $result);
    }

    public function forgetAlbum(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $albumId = (int) ($data['albumId'] ?? $data['id'] ?? 0);
        $result = $this->deletion->forgetAlbum($albumId);
        if (($result['error'] ?? null) === 'not_found') {
            return $this->errorResponse('Album not found.', 404);
        }
        if (($result['error'] ?? null) === 'not_ghost') {
            return $this->errorResponse('El álbum no es un fantasma', 409);
        }
        return $this->successResponse('Album forgotten', $result);
    }

    public function deleteArtist(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $artistId = (int) ($data['artistId'] ?? $data['id'] ?? 0);
        $result = $this->deletion->deleteArtist($artistId);
        if (!$result['deleted']) {
            return $this->errorResponse('Artist not found.', 404);
        }
        return $this->successResponse('Artist deleted', $result);
    }

    public function search(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $q = trim((string) ($data['q'] ?? $data['query'] ?? ''));
        if ($q === '') {
            return $this->successResponse('Search', ['artists' => [], 'albums' => [], 'tracks' => []]);
        }
        return $this->successResponse('Search', [
            'artists' => array_map(fn($a) => [
                'id' => (int) $a['id'], 'name' => $a['name'], 'image_url' => $a['image_url'] ?? null,
            ], $this->artists->search($q)),
            'albums' => $this->albumPresenter->presentMany($this->albums->search($q), $userId),
            'tracks' => $this->presenter->presentMany($this->tracks->search($q), $userId),
        ]);
    }

    public function getStreamUrl(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $trackId = (int) ($data['trackId'] ?? $data['id'] ?? 0);
        if (!$this->tracks->findById($trackId)) {
            return $this->errorResponse('Track not found.', 404);
        }
        return $this->successResponse('Stream URL', [
            'stream_url' => $this->signedUrls->streamUrl($trackId, $userId),
        ]);
    }

    /** Max distinct track ids per issue_external_urls request. */
    private const EXTERNAL_URLS_MAX_IDS = 200;

    /**
     * Absolute, expiring stream URLs (kind 'ext') for a third party.
     * All or nothing: if any id is unknown, 404 with `missing` and no URLs.
     */
    public function issueExternalUrls(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        if (!$this->signedUrls->hasPublicBaseUrl()) {
            return $this->errorResponse('PUBLIC_BASE_URL no está configurada.', 503);
        }

        $raw = $data['track_ids'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            return $this->errorResponse('track_ids debe ser una lista no vacía de enteros > 0.', 400);
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = $this->strictPositiveInt($value);
            if ($id === null) {
                return $this->errorResponse('track_ids debe ser una lista no vacía de enteros > 0.', 400);
            }
            $ids[$id] = $id; // dedupe, keeping first-seen order
        }
        $ids = array_values($ids);
        if (count($ids) > self::EXTERNAL_URLS_MAX_IDS) {
            return $this->errorResponse('Como mucho ' . self::EXTERNAL_URLS_MAX_IDS . ' track_ids por petición.', 400);
        }

        $found = [];
        foreach ($this->tracks->findByIds($ids) as $row) {
            $found[(int) $row['id']] = true;
        }
        $missing = array_values(array_filter($ids, fn(int $id) => !isset($found[$id])));
        if ($missing) {
            $response = $this->errorResponse('Tracks not found.', 404);
            $response['data'] = ['missing' => $missing];
            return $response;
        }

        $ttl = $this->signedUrls->clampExternalTtl($this->optionalInt($data['ttl'] ?? null));
        $expiresAt = time() + $ttl;
        $urls = array_map(fn(int $id) => [
            'track_id' => $id,
            'url'      => $this->signedUrls->externalUrl($id, $userId, $ttl),
        ], $ids);

        return $this->successResponse('External URLs', [
            'expires_at' => $expiresAt,
            'urls'       => $urls,
        ]);
    }

    /** JSON int > 0, or a canonical digit string; anything else -> null. */
    private function strictPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    /** Optional ttl from JSON: int, float or numeric string -> int; missing/garbage -> null (max). */
    private function optionalInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if ((is_float($value) || is_string($value)) && is_numeric($value)) {
            $f = (float) $value;
            if ($f >= PHP_INT_MAX) {
                return PHP_INT_MAX;
            }
            return $f <= PHP_INT_MIN ? PHP_INT_MIN : (int) $f;
        }
        return null;
    }

    public function scanLibrary(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        // Long-running: allow more time than the default request budget.
        @set_time_limit(0);
        try {
            $stats = $this->scanner->scan();
        } catch (ScanInProgressException $e) {
            return $this->errorResponse('Ya hay un escaneo en curso.', 409);
        }
        $stats['similar_warmed'] = 0;

        // Best-effort: fill missing covers from Last.fm/Spotify and warm similar artists (no-op without API keys).
        try {
            if ($this->enrichment->isEnabled()) {
                $stats['covers_enriched'] = $this->enrichment->enrichMissingCovers();
            }
            // Pre-warm Last.fm similar artists so recommendations only ever read the cache.
            $stats['similar_warmed'] = $this->recommendations->warmSimilarArtists();
        } catch (\Throwable $e) {
            // never let enrichment break a successful scan
        }

        return $this->successResponse('Scan finished', $stats);
    }

    public function getScanStatus(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        return $this->successResponse('Scan status', ['scan' => $this->scans->latest()]);
    }
}
