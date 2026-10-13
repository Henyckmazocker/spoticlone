<?php

declare(strict_types=1);

namespace App\Infrastructure\Soulseek;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * Client for the slskd REST API (v0) — the headless Soulseek daemon already running on the server.
 *
 * Auth via X-API-Key (configured in slskd.yml). Reachable at SLSKD_URL (http://slskd:5030 over the
 * shared `mediaserver` Docker network). Throws RuntimeException on transport/HTTP errors so the
 * controller can return a clean error response; never crashes the app when slskd is unconfigured.
 */
class SlskdClient
{
    private string $baseUrl;
    private string $apiKey;
    private Client $http;

    public function __construct()
    {
        $this->baseUrl = rtrim($_ENV['SLSKD_URL'] ?? 'http://slskd:5030', '/');
        $this->apiKey = $_ENV['SLSKD_API_KEY'] ?? '';
        // Ninguna llamada es larga: la búsqueda ya no se sondea desde PHP (lo hace el cliente
        // contra slsk_search_results), así que 10 s sobra y libera el worker antes si slskd cuelga.
        $this->http = new Client(['timeout' => 10]);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /** Start a search; returns the search id (slskd generates a GUID if we don't pass one). */
    public function startSearch(string $text): string
    {
        $id = $this->guid();
        $this->request('POST', '/api/v0/searches', ['json' => [
            'id'            => $id,
            'searchText'    => $text,
            // IMPORTANT: slskd only exposes /responses once the search is "Completed". A low
            // responseLimit makes it complete fast via "ResponseLimitReached" (~2-4s) instead of
            // sitting "InProgress" until the long inactivity timeout (which returned 0 results).
            'searchTimeout' => 8000,
            'responseLimit' => 50,
        ]]);
        return $id;
    }

    /** @return array<int, array<string, mixed>> Raw responses (one per user) for a search. */
    public function searchResponses(string $id): array
    {
        return $this->request('GET', "/api/v0/searches/{$id}/responses");
    }

    /** @return array<string, mixed> The search object (state, fileCount, responseCount, ...). */
    public function search(string $id): array
    {
        return $this->request('GET', "/api/v0/searches/{$id}");
    }

    /** @return array<int, array<string, mixed>> Every search slskd still holds in memory. */
    public function searches(): array
    {
        return $this->request('GET', '/api/v0/searches');
    }

    /**
     * Drop a search from slskd. We delete each search once its responses have been read; without
     * this, abandoned searches (user navigates away mid-poll) pile up in slskd's state forever.
     */
    public function deleteSearch(string $id): void
    {
        $this->request('DELETE', "/api/v0/searches/{$id}");
    }

    /**
     * Enqueue downloads of specific files from a user.
     * @param array<int, array{filename:string, size:int}> $files
     */
    public function enqueueDownloads(string $username, array $files): void
    {
        $this->request('POST', '/api/v0/transfers/downloads/' . rawurlencode($username), ['json' => $files]);
    }

    /** @return array<int, array<string, mixed>> All downloads grouped by user. */
    public function downloads(): array
    {
        return $this->request('GET', '/api/v0/transfers/downloads');
    }

    public function cancelDownload(string $username, string $id, bool $remove = false): void
    {
        $q = $remove ? '?remove=true' : '';
        $this->request('DELETE', '/api/v0/transfers/downloads/' . rawurlencode($username) . '/' . rawurlencode($id) . $q);
    }

    /**
     * @return array<mixed> decoded JSON body ([] for empty/204)
     */
    private function request(string $method, string $path, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('slskd no está configurado (falta SLSKD_API_KEY).');
        }
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'X-API-Key' => $this->apiKey,
            'Accept'    => 'application/json',
        ]);
        $options['http_errors'] = false;

        try {
            $res = $this->http->request($method, $this->baseUrl . $path, $options);
        } catch (\Throwable $e) {
            throw new RuntimeException('No se pudo contactar con slskd: ' . $e->getMessage());
        }

        $code = $res->getStatusCode();
        if ($code >= 400) {
            throw new RuntimeException("slskd respondió {$code} en {$path}");
        }
        $body = (string) $res->getBody();
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function guid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
