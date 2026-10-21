<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Services\AlbumRequestException;
use App\Domain\Services\AlbumRequestService;

/**
 * Album requests: a non-admin user asks for a Spotify album, the admin works the queue.
 * Admin-only actions are gated by AdminMiddleware in routes.php; the rules live in the service.
 */
class AlbumRequestController extends BaseController
{
    public function __construct(
        private readonly AlbumRequestService $service
    ) {}

    public function requestAlbum(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $spotifyId = trim((string) ($data['spotify_album_id'] ?? ''));
        if ($spotifyId === '') {
            return $this->errorResponse('Falta spotify_album_id.', 400);
        }
        try {
            $result = $this->service->request($userId, $spotifyId);
        } catch (AlbumRequestException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode());
        }
        return $this->successResponse($result['joined'] ? 'Te has sumado a la petición' : 'Petición creada', $result);
    }

    public function getMyAlbumRequests(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        return $this->successResponse('Mis peticiones', ['requests' => $this->service->mine($userId)]);
    }

    public function cancelAlbumRequest(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $id = (int) ($data['requestId'] ?? 0);
        if ($id <= 0) {
            return $this->errorResponse('Falta requestId.', 400);
        }
        try {
            $this->service->cancel($userId, $id);
        } catch (AlbumRequestException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode());
        }
        return $this->successResponse('Petición cancelada');
    }

    public function getAlbumRequests(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $filter = (string) ($data['status'] ?? 'open');
        if (!in_array($filter, ['open', 'all'], true)) {
            return $this->errorResponse('status debe ser "open" o "all".', 400);
        }
        return $this->successResponse('Peticiones', ['requests' => $this->service->all($filter === 'open')]);
    }

    public function rejectAlbumRequest(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $id = (int) ($data['requestId'] ?? 0);
        if ($id <= 0) {
            return $this->errorResponse('Falta requestId.', 400);
        }
        try {
            $request = $this->service->resolve(
                $id,
                (string) ($data['status'] ?? ''),
                isset($data['reason']) ? (string) $data['reason'] : null
            );
        } catch (AlbumRequestException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode());
        }
        return $this->successResponse('Petición actualizada', ['request' => $request]);
    }
}
