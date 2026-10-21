<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Repository\Music\AlbumRequestRepositoryInterface;
use App\Infrastructure\Music\SpotifyClient;

/**
 * Rules of the album requests: one row per Spotify album shared by all who ask for it, a cap of
 * open requests per user, and fulfilment detected on read with LibraryMatcher (the same matcher
 * that paints "En biblioteca" in Discover). Errors are AlbumRequestException whose code is the
 * HTTP status.
 */
class AlbumRequestService
{
    public const MAX_OPEN_PER_USER = 10;
    private const REJECT_REASON_MAX = 255;

    public function __construct(
        private readonly AlbumRequestRepositoryInterface $requests,
        private readonly LibraryMatcher $matcher,
        private readonly SpotifyClient $spotify
    ) {}

    /** @return array{request: array, joined: bool} */
    public function request(int $userId, string $spotifyAlbumId): array
    {
        $existing = $this->requests->findBySpotifyId($spotifyAlbumId);

        if ($existing !== null) {
            $album = $existing;
        } else {
            // Metadata comes from Spotify, never from the client.
            if (!$this->spotify->isEnabled()) {
                throw new AlbumRequestException('Spotify no está configurado.', 503);
            }
            $album = $this->spotify->getAlbum($spotifyAlbumId);
            if ($album === null) {
                throw new AlbumRequestException('Spotify no conoce ese álbum.', 404);
            }
            $album = [
                'spotify_album_id' => $spotifyAlbumId,
                'artist'           => mb_substr((string) $album['artist'], 0, 255),
                'title'            => mb_substr((string) $album['title'], 0, 255),
                'year'             => $album['year'] ?: null,
                'cover_url'        => $album['cover'] ?? null,
            ];
        }

        $match = $this->matcher->match((string) $album['artist'], (string) $album['title']);
        if (($existing['status'] ?? null) === 'fulfilled' || ($match !== null && $match['status'] === 'present')) {
            throw new AlbumRequestException('Ya está en la biblioteca.', 409);
        }

        $alreadyIn = $existing !== null && $this->requests->hasUser((int) $existing['id'], $userId);
        if (!$alreadyIn && $this->requests->countPendingForUser($userId) >= self::MAX_OPEN_PER_USER) {
            throw new AlbumRequestException(
                'Tienes ' . self::MAX_OPEN_PER_USER . ' peticiones abiertas.',
                429
            );
        }

        if ($existing === null) {
            $id = $this->requests->create($album);
        } else {
            $id = (int) $existing['id'];
            if ($existing['status'] === 'rejected') {
                $this->requests->setStatus($id, 'pending', ['reject_reason' => null, 'resolved_at' => null]);
            }
        }
        $this->requests->attachUser($id, $userId);

        return ['request' => $this->forUser($this->requests->findById($id)), 'joined' => $existing !== null];
    }

    /** @return array[] the user's requests, newest first */
    public function mine(int $userId): array
    {
        $this->reconcile();
        return array_map([$this, 'forUser'], $this->requests->listForUser($userId));
    }

    /** @return array[] AdminAlbumRequest; $open = pending+downloading only */
    public function all(bool $open = true): array
    {
        $this->reconcile();
        return array_map([$this, 'forAdmin'], $this->requests->listAll($open ? ['pending', 'downloading'] : null));
    }

    public function cancel(int $userId, int $requestId): void
    {
        if (!$this->requests->hasUser($requestId, $userId)) {
            throw new AlbumRequestException('No tienes esa petición.', 404);
        }
        $this->requests->detachUser($requestId, $userId);
        $row = $this->requests->findById($requestId);
        if ($row !== null && $row['status'] === 'pending' && $this->requests->countUsers($requestId) === 0) {
            $this->requests->delete($requestId);
        }
    }

    /** Admin: close a request as rejected or fulfilled by hand. */
    public function resolve(int $requestId, string $status, ?string $reason): array
    {
        if (!in_array($status, ['rejected', 'fulfilled'], true)) {
            throw new AlbumRequestException('status debe ser "rejected" o "fulfilled".', 400);
        }
        $reason = $reason !== null ? trim($reason) : null;
        if ($reason !== null && mb_strlen($reason) > self::REJECT_REASON_MAX) {
            throw new AlbumRequestException('El motivo no puede pasar de ' . self::REJECT_REASON_MAX . ' caracteres.', 400);
        }
        if ($this->requests->findById($requestId) === null) {
            throw new AlbumRequestException('Petición no encontrada.', 404);
        }
        $this->requests->setStatus($requestId, $status, [
            'reject_reason' => $status === 'rejected' && $reason !== '' ? $reason : null,
            'resolved_at'   => true,
        ]);
        return $this->forAdmin($this->requests->findById($requestId));
    }

    /** Open requests whose album is now present in the library become fulfilled. */
    public function reconcile(): void
    {
        $open = $this->requests->listOpen();
        if (!$open) {
            return;
        }
        $matches = $this->matcher->matchMany(array_map(
            fn(array $r) => [(string) $r['artist'], (string) $r['title']],
            $open
        ));
        foreach ($open as $i => $r) {
            $m = $matches[$i] ?? null;
            if ($m !== null && $m['status'] === 'present') {
                $this->requests->setStatus((int) $r['id'], 'fulfilled', [
                    'album_id'    => $m['album_id'],
                    'resolved_at' => true,
                ]);
            }
        }
    }

    private function forUser(array $r): array
    {
        return [
            'id'               => (int) $r['id'],
            'spotify_album_id' => $r['spotify_album_id'],
            'artist'           => $r['artist'],
            'title'            => $r['title'],
            'year'             => $r['year'] !== null ? (int) $r['year'] : null,
            'cover_url'        => $r['cover_url'],
            'status'           => $r['status'],
            'reject_reason'    => $r['reject_reason'],
            'created_at'       => $r['created_at'],
            'resolved_at'      => $r['resolved_at'],
            'album_id'         => $r['album_id'] !== null ? (int) $r['album_id'] : null,
        ];
    }

    private function forAdmin(array $r): array
    {
        return $this->forUser($r) + [
            'users_count'  => (int) ($r['users_count'] ?? 0),
            'requested_by' => $r['requested_by'] ?? [],
            'download_id'  => ($r['download_id'] ?? null) !== null ? (int) $r['download_id'] : null,
            // Moves when the request goes to 'downloading': lets the queue flag stale downloads.
            'updated_at'   => $r['updated_at'] ?? null,
        ];
    }
}
