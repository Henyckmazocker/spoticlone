<?php

declare(strict_types=1);

use App\Infrastructure\Middleware\AuthenticationMiddleware;
use App\Infrastructure\Middleware\CSRFMiddleware;
use App\Infrastructure\Middleware\LoggingMiddleware;
use App\Infrastructure\Middleware\ValidationMiddleware;

/**
 * Routes configuration for spoticlone.
 *
 * Each action maps to [ControllerName, method] plus a middleware stack.
 *  - LoggingMiddleware: always.
 *  - AuthenticationMiddleware: injects user_id (session cookie or JWT bearer).
 *  - CSRFMiddleware: state-changing operations (skipped automatically for JWT auth).
 *  - ValidationMiddleware: required-field checks.
 *
 * NOTE: binary endpoints (audio stream, cover art) are NOT here — they are served by
 * public/stream.php and public/cover.php, authenticated via signed URL tokens.
 */
return [
    // ====================== AUTH (public) ======================
    'login' => [
        'controller' => ['AuthController', 'login'],
        'middleware' => [LoggingMiddleware::class],
    ],
    'logout' => [
        'controller' => ['AuthController', 'logout'],
        'middleware' => [LoggingMiddleware::class],
    ],
    'check_auth' => [
        'controller' => ['AuthController', 'checkAuth'],
        'middleware' => [LoggingMiddleware::class],
    ],
    'ping' => [
        'controller' => ['AuthController', 'ping'],
        'middleware' => [LoggingMiddleware::class],
    ],
    'log_frontend' => [
        'controller' => ['AuthController', 'logFrontend'],
        'middleware' => [LoggingMiddleware::class],
    ],
    'log_frontend_batch' => [
        'controller' => ['AuthController', 'logFrontendBatch'],
        'middleware' => [LoggingMiddleware::class],
    ],
    'me' => [
        'controller' => ['AuthController', 'me'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'update_user_profile' => [
        'controller' => ['AuthController', 'updateProfile'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class, CSRFMiddleware::class],
    ],

    // ====================== LIBRARY ======================
    'get_artists' => [
        'controller' => ['LibraryController', 'getArtists'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'get_artist' => [
        'controller' => ['LibraryController', 'getArtist'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'get_album' => [
        'controller' => ['LibraryController', 'getAlbum'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'search' => [
        'controller' => ['LibraryController', 'search'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'get_stream_url' => [
        'controller' => ['LibraryController', 'getStreamUrl'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['trackId']]],
        ],
    ],
    'issue_external_urls' => [
        'controller' => ['LibraryController', 'issueExternalUrls'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['track_ids']]],
        ],
    ],
    'scan_library' => [
        'controller' => ['LibraryController', 'scanLibrary'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class, CSRFMiddleware::class],
    ],
    'get_scan_status' => [
        'controller' => ['LibraryController', 'getScanStatus'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'delete_album' => [
        'controller' => ['LibraryController', 'deleteAlbum'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['albumId']]],
        ],
    ],
    'forget_album' => [
        'controller' => ['LibraryController', 'forgetAlbum'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['albumId']]],
        ],
    ],
    'delete_artist' => [
        'controller' => ['LibraryController', 'deleteArtist'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['artistId']]],
        ],
    ],

    // ====================== PLAYLISTS ======================
    'get_playlists' => [
        'controller' => ['PlaylistController', 'getPlaylists'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'get_playlist' => [
        'controller' => ['PlaylistController', 'getPlaylist'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'create_playlist' => [
        'controller' => ['PlaylistController', 'createPlaylist'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['name']]],
        ],
    ],
    'update_playlist' => [
        'controller' => ['PlaylistController', 'updatePlaylist'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['playlistId']]],
        ],
    ],
    'delete_playlist' => [
        'controller' => ['PlaylistController', 'deletePlaylist'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['playlistId']]],
        ],
    ],
    'add_to_playlist' => [
        'controller' => ['PlaylistController', 'addToPlaylist'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['playlistId']]],
        ],
    ],
    'remove_from_playlist' => [
        'controller' => ['PlaylistController', 'removeFromPlaylist'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['playlistId', 'trackId']]],
        ],
    ],
    'reorder_playlist' => [
        'controller' => ['PlaylistController', 'reorderPlaylist'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['playlistId', 'trackIds']]],
        ],
    ],

    // ====================== FAVORITES ======================
    'toggle_favorite' => [
        'controller' => ['FavoriteController', 'toggleFavorite'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['trackId']]],
        ],
    ],
    'toggle_album_favorite' => [
        'controller' => ['FavoriteController', 'toggleAlbumFavorite'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['albumId']]],
        ],
    ],
    'get_favorites' => [
        'controller' => ['FavoriteController', 'getFavorites'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],

    // ====================== PLAYBACK (history / stats) ======================
    'log_play' => [
        'controller' => ['PlaybackController', 'logPlay'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['trackId']]],
        ],
    ],
    'get_recently_played' => [
        'controller' => ['PlaybackController', 'getRecentlyPlayed'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'get_most_played' => [
        'controller' => ['PlaybackController', 'getMostPlayed'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],

    // ====================== RECOMMENDATIONS ("for you") ======================
    'get_recommendations' => [
        'controller' => ['RecommendationController', 'getRecommendations'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'get_artist_radio' => [
        'controller' => ['RecommendationController', 'getArtistRadio'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['artistId']]],
        ],
    ],

    // ====================== DISCOVER / SOULSEEK (slskd) ======================
    'spotify_search' => [
        'controller' => ['SoulseekController', 'spotifySearch'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'slsk_search' => [
        'controller' => ['SoulseekController', 'slskSearch'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'slsk_search_results' => [
        'controller' => ['SoulseekController', 'slskSearchResults'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['search_id']]],
        ],
    ],
    'slsk_download' => [
        'controller' => ['SoulseekController', 'slskDownload'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            // on_duplicate (opcional, replace|skip): ValidationMiddleware solo sabe 'required',
            // así que el enum se valida en SoulseekController::slskDownload (400).
            [ValidationMiddleware::class, ['required' => ['username', 'files']]],
        ],
    ],
    'slsk_downloads' => [
        'controller' => ['SoulseekController', 'slskDownloads'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'slsk_remove_downloads' => [
        'controller' => ['SoulseekController', 'slskRemoveDownloads'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['ids']]],
        ],
    ],
    'slsk_import' => [
        'controller' => ['SoulseekController', 'slskImport'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['id']]],
        ],
    ],
    'slsk_retry_missing' => [
        'controller' => ['SoulseekController', 'slskRetryMissing'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            CSRFMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['id']]],
        ],
    ],
];
