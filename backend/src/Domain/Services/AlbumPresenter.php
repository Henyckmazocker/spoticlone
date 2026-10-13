<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Repository\Music\AlbumFavoriteRepositoryInterface;
use App\Infrastructure\Auth\SignedUrlService;

/**
 * Turns raw album rows (from the repositories) into the JSON shape the frontend consumes:
 * adds `cover_url` (signed cover.php for local art, the remote URL as is otherwise), `is_favorite` and
 * `missing` (a ghost album: deleted from disk, kept in the DB with its history).
 */
class AlbumPresenter
{
    public function __construct(
        private readonly SignedUrlService $signedUrls,
        private readonly AlbumFavoriteRepositoryInterface $favorites
    ) {}

    /**
     * One favoriteMap call per list (no N+1); none for an empty list.
     *
     * @param array<int, array<string, mixed>> $rows album rows joined with artist
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(array $rows, int $userId): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $favMap = $this->favorites->favoriteMap($userId, $ids);

        return array_map(function (array $a) use ($userId, $favMap) {
            $id = (int) $a['id'];
            // Local art -> signed cover.php; remote-only art (Last.fm/Spotify) -> its URL as is.
            $coverUrl = !empty($a['cover_path'])
                ? $this->signedUrls->coverUrl($id, $userId)
                : (!empty($a['cover_url']) ? (string) $a['cover_url'] : null);
            return [
                'id'          => $id,
                'title'       => $a['title'],
                'year'        => isset($a['year']) ? (int) $a['year'] : null,
                'artist'      => $a['artist_name'] ?? null,
                'artist_id'   => (int) $a['artist_id'],
                'track_count' => isset($a['track_count']) ? (int) $a['track_count'] : null,
                'cover_url'   => $coverUrl,
                'is_favorite' => $favMap[$id] ?? false,
                'missing'     => ($a['missing_at'] ?? null) !== null,
            ];
        }, array_values($rows));
    }

    /** @param array<string, mixed> $row */
    public function present(array $row, int $userId): array
    {
        return $this->presentMany([$row], $userId)[0];
    }
}
