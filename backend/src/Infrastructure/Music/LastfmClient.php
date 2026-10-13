<?php

declare(strict_types=1);

namespace App\Infrastructure\Music;

use App\Domain\Services\Normalize;
use App\Infrastructure\Cache\CacheService;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Thin Last.fm API client (album covers + similar artists).
 *
 * Tolerant by design, like SpotifyClient: with no API key or no network it returns null / []
 * instead of throwing. similarArtists() hits the network and caches; cachedSimilar() only reads
 * the cache, so request paths (the Home) never wait on Last.fm.
 */
class LastfmClient
{
    private const ENDPOINT = 'https://ws.audioscrobbler.com/2.0/';
    private const TTL = 2592000; // 30 days

    private string $apiKey;
    private Client $http;
    private bool $lastCallFailed = false;

    public function __construct(
        private readonly CacheService $cache,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->apiKey = $_ENV['LASTFM_API_KEY'] ?? '';
        $this->http = new Client(['timeout' => 8]);
    }

    public function isEnabled(): bool
    {
        return $this->apiKey !== '';
    }

    /** Largest album cover URL from album.getInfo, or null. */
    public function albumCover(string $artist, string $album): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $cacheKey = 'lastfm_' . md5($artist . '|' . $album);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached ?: null;
        }

        try {
            $res = $this->http->get(self::ENDPOINT, ['query' => [
                'method'  => 'album.getinfo',
                'api_key' => $this->apiKey,
                'artist'  => $artist,
                'album'   => $album,
                'format'  => 'json',
            ]]);
            $data = json_decode((string) $res->getBody(), true);
            $images = $data['album']['image'] ?? [];
            $best = '';
            foreach ($images as $img) {
                if (!empty($img['#text'])) {
                    $best = $img['#text']; // images are ordered small→extralarge
                }
            }
            $this->cache->set($cacheKey, $best, self::TTL);
            return $best ?: null;
        } catch (\Throwable $e) {
            $this->logger?->debug('Last.fm enrichment failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * artist.getSimilar from the network; caches the result (also "artist not found" as []).
     * A network/HTTP error is NOT cached and returns [] (see lastCallFailed()).
     *
     * @return array<int, array{name:string, match:float}>
     */
    public function similarArtists(string $artist, int $limit = 100): array
    {
        $this->lastCallFailed = false;
        if (!$this->isEnabled() || trim($artist) === '') {
            return [];
        }

        try {
            $res = $this->http->get(self::ENDPOINT, [
                'query' => [
                    'method'      => 'artist.getsimilar',
                    'api_key'     => $this->apiKey,
                    'artist'      => $artist,
                    'limit'       => $limit,
                    'autocorrect' => 1,
                    'format'      => 'json',
                ],
                'http_errors' => false,
            ]);
            $data = json_decode((string) $res->getBody(), true);
        } catch (\Throwable $e) {
            $this->logger?->debug('Last.fm getSimilar failed', ['artist' => $artist, 'error' => $e->getMessage()]);
            $this->lastCallFailed = true;
            return [];
        }

        if (!is_array($data)) {
            $this->lastCallFailed = true;
            return [];
        }
        if (isset($data['error'])) {
            // 6 = artist not found → cache as "no similars"; anything else (rate limit, key…) is transient.
            if ((int) $data['error'] === 6) {
                $this->cache->set($this->similarKey($artist), [], self::TTL);
            } else {
                $this->lastCallFailed = true;
            }
            return [];
        }

        $items = $data['similarartists']['artist'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        $similar = [];
        foreach ($items as $item) {
            $name = is_array($item) ? trim((string) ($item['name'] ?? '')) : '';
            if ($name === '') {
                continue;
            }
            $similar[] = ['name' => $name, 'match' => (float) ($item['match'] ?? 0)];
        }
        $this->cache->set($this->similarKey($artist), $similar, self::TTL);
        return $similar;
    }

    /**
     * Cached similar artists only — never touches the network.
     *
     * @return array<int, array{name:string, match:float}>|null null when there is no cache entry
     */
    public function cachedSimilar(string $artist): ?array
    {
        $cached = $this->cache->get($this->similarKey($artist));
        return is_array($cached) ? $cached : null;
    }

    /** True if the last similarArtists() call failed for a transient (network/API) reason. */
    public function lastCallFailed(): bool
    {
        return $this->lastCallFailed;
    }

    private function similarKey(string $artist): string
    {
        return 'lastfm_similar_' . md5(Normalize::name($artist));
    }
}
