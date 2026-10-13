<?php

declare(strict_types=1);

namespace App\Infrastructure\Music;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Best-effort metadata enrichment for albums that have no local cover art.
 *
 * Queries Last.fm (album.getInfo) — and, if configured, Spotify — for a remote cover image and
 * stores it in albums.cover_url. Entirely optional and fault-tolerant: with no API keys or no
 * network it simply does nothing, so the scanner keeps working from embedded tags alone.
 */
class MetadataEnrichmentService
{
    public function __construct(
        private readonly AlbumRepositoryInterface $albums,
        private readonly LastfmClient $lastfm,
        private readonly SpotifyClient $spotify,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->lastfm->isEnabled() || $this->spotify->isEnabled();
    }

    /**
     * Fill cover_url for albums missing any cover. Returns the number of albums updated.
     */
    public function enrichMissingCovers(int $max = 200): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $updated = 0;
        foreach ($this->albums->findWithoutCover() as $album) {
            if ($updated >= $max) {
                break;
            }
            $artist = (string) ($album['artist_name'] ?? '');
            $title = (string) ($album['title'] ?? '');
            if ($artist === '' || $title === '') {
                continue;
            }
            $url = $this->lastfm->albumCover($artist, $title) ?? $this->spotify->albumCover($artist, $title);
            if ($url !== null) {
                $this->albums->setCoverUrl((int) $album['id'], $url);
                $updated++;
            }
        }
        return $updated;
    }
}
