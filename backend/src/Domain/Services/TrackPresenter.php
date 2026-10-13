<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Repository\Music\FavoriteRepositoryInterface;
use App\Infrastructure\Auth\SignedUrlService;

/**
 * Turns raw track rows (from the repositories) into the JSON shape the frontend consumes:
 * adds a signed `stream_url`, `cover_url` (signed cover.php for local art, the remote URL as is otherwise), and `is_favorite`.
 * A ghost track (its own `missing_at` or its album's is set) gets `missing: true` and `stream_url: null`:
 * its file is gone, there is nothing to sign.
 */
class TrackPresenter
{
    public function __construct(
        private readonly SignedUrlService $signedUrls,
        private readonly FavoriteRepositoryInterface $favorites
    ) {}

    /**
     * @param array<int, array<string, mixed>> $rows track rows joined with album/artist
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(array $rows, int $userId): array
    {
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $favMap = $this->favorites->favoriteMap($userId, $ids);

        return array_map(function (array $r) use ($userId, $favMap) {
            $id = (int) $r['id'];
            $missing = ($r['missing_at'] ?? null) !== null || ($r['album_missing_at'] ?? null) !== null;
            // Local art -> signed cover.php; remote-only art (Last.fm/Spotify) -> its URL as is.
            $coverUrl = !empty($r['cover_path'])
                ? $this->signedUrls->coverUrl((int) $r['album_id'], $userId)
                : (!empty($r['cover_url']) ? (string) $r['cover_url'] : null);
            return [
                'id'         => $id,
                'title'      => $r['title'],
                'artist'     => $r['artist_name'] ?? null,
                'artist_id'  => isset($r['artist_id']) ? (int) $r['artist_id'] : null,
                'album'      => $r['album_title'] ?? null,
                'album_id'   => isset($r['album_id']) ? (int) $r['album_id'] : null,
                'track_no'   => isset($r['track_no']) ? (int) $r['track_no'] : null,
                'disc_no'    => isset($r['disc_no']) ? (int) $r['disc_no'] : null,
                'duration'   => isset($r['duration_seconds']) ? (int) $r['duration_seconds'] : null,
                'format'     => $r['file_format'] ?? null,
                'genre'      => $r['genre'] ?? null,
                'stream_url' => $missing ? null : $this->signedUrls->streamUrl($id, $userId),
                'cover_url'  => $coverUrl,
                'is_favorite' => $favMap[$id] ?? false,
                'missing'    => $missing,
            ];
        }, $rows);
    }
}
