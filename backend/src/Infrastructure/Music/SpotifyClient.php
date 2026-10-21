<?php

declare(strict_types=1);

namespace App\Infrastructure\Music;

use App\Infrastructure\Cache\CacheService;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Thin Spotify Web API client (Client Credentials flow — no user login).
 *
 * Used both for metadata enrichment (album cover lookup) and for the "Discover" search stage
 * (find an artist/album with rich metadata before searching Soulseek). Tolerant by design: with
 * no credentials or no network it returns empty results instead of throwing.
 */
class SpotifyClient
{
    private string $clientId;
    private string $clientSecret;
    private Client $http;

    public function __construct(
        private readonly CacheService $cache,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->clientId = $_ENV['SPOTIFY_CLIENT_ID'] ?? '';
        $this->clientSecret = $_ENV['SPOTIFY_CLIENT_SECRET'] ?? '';
        $this->http = new Client(['timeout' => 8]);
    }

    public function isEnabled(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /**
     * Search albums/artists/tracks. Returns a normalized shape for the frontend.
     *
     * @param string $type comma list of 'album','artist','track'
     * @return array{albums:array,artists:array,tracks:array}
     */
    public function search(string $q, string $type = 'album,artist', int $limit = 20): array
    {
        $empty = ['albums' => [], 'artists' => [], 'tracks' => []];
        if (!$this->isEnabled() || trim($q) === '') {
            return $empty;
        }
        $token = $this->token();
        if ($token === null) {
            return $empty;
        }
        try {
            $res = $this->http->get('https://api.spotify.com/v1/search', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query'   => ['q' => $q, 'type' => $type, 'limit' => $limit],
            ]);
            $data = json_decode((string) $res->getBody(), true) ?: [];
        } catch (\Throwable $e) {
            $this->logger?->debug('Spotify search failed', ['error' => $e->getMessage()]);
            return $empty;
        }

        return [
            'albums'  => array_map([$this, 'mapAlbum'], $data['albums']['items'] ?? []),
            'artists' => array_map([$this, 'mapArtist'], $data['artists']['items'] ?? []),
            'tracks'  => array_map([$this, 'mapTrack'], $data['tracks']['items'] ?? []),
        ];
    }

    /** First album cover URL for an artist+album, or null. (Reused by enrichment.) */
    public function albumCover(string $artist, string $album): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $token = $this->token();
        if ($token === null) {
            return null;
        }
        try {
            $res = $this->http->get('https://api.spotify.com/v1/search', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query'   => ['q' => "album:{$album} artist:{$artist}", 'type' => 'album', 'limit' => 1],
            ]);
            $data = json_decode((string) $res->getBody(), true);
            return $data['albums']['items'][0]['images'][0]['url'] ?? null;
        } catch (\Throwable $e) {
            $this->logger?->debug('Spotify cover lookup failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * One album by Spotify id in the same shape as search(), or null if the id is malformed,
     * unknown or the lookup fails. (Album requests re-fetch the metadata instead of trusting the client.)
     */
    public function getAlbum(string $id): ?array
    {
        if (!$this->isEnabled() || !preg_match('/^[A-Za-z0-9]{1,64}$/', $id)) {
            return null;
        }
        $token = $this->token();
        if ($token === null) {
            return null;
        }
        try {
            $res = $this->http->get('https://api.spotify.com/v1/albums/' . $id, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
            $data = json_decode((string) $res->getBody(), true);
            return is_array($data) && !empty($data['id']) ? $this->mapAlbum($data) : null;
        } catch (\Throwable $e) {
            $this->logger?->debug('Spotify album lookup failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function mapAlbum(array $a): array
    {
        return [
            'id'       => $a['id'] ?? null,
            'title'    => $a['name'] ?? '',
            'artist'   => implode(', ', array_map(fn($x) => $x['name'] ?? '', $a['artists'] ?? [])),
            'year'     => isset($a['release_date']) ? (int) substr($a['release_date'], 0, 4) : null,
            'tracks'   => $a['total_tracks'] ?? null,
            'cover'    => $a['images'][0]['url'] ?? null,
        ];
    }

    private function mapArtist(array $a): array
    {
        return [
            'id'     => $a['id'] ?? null,
            'name'   => $a['name'] ?? '',
            'image'  => $a['images'][0]['url'] ?? null,
            'genres' => $a['genres'] ?? [],
        ];
    }

    private function mapTrack(array $t): array
    {
        return [
            'id'     => $t['id'] ?? null,
            'title'  => $t['name'] ?? '',
            'artist' => implode(', ', array_map(fn($x) => $x['name'] ?? '', $t['artists'] ?? [])),
            'album'  => $t['album']['name'] ?? '',
            'cover'  => $t['album']['images'][0]['url'] ?? null,
        ];
    }

    public function token(): ?string
    {
        $cached = $this->cache->get('spotify_token');
        if ($cached) {
            return $cached;
        }
        try {
            $res = $this->http->post('https://accounts.spotify.com/api/token', [
                'form_params' => ['grant_type' => 'client_credentials'],
                'headers' => ['Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret)],
            ]);
            $data = json_decode((string) $res->getBody(), true);
            $token = $data['access_token'] ?? null;
            if ($token) {
                $this->cache->set('spotify_token', $token, max(60, (int) ($data['expires_in'] ?? 3600) - 60));
            }
            return $token;
        } catch (\Throwable $e) {
            $this->logger?->debug('Spotify token failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
