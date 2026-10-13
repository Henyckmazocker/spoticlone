<?php

declare(strict_types=1);

namespace App\Router;

use App\Controllers\AuthController;
use App\Controllers\LibraryController;
use App\Controllers\PlaylistController;
use App\Controllers\FavoriteController;
use App\Controllers\PlaybackController;
use App\Controllers\RecommendationController;
use App\Controllers\SoulseekController;
use App\Infrastructure\Middleware\MiddlewarePipeline;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ActionRouter - Routes incoming actions to the appropriate controller.
 *
 * Uses the Middleware Pattern (config/routes.php declares the middleware stack
 * per action) so auth/CSRF/validation/logging concerns stay out of controllers.
 */
class ActionRouter
{
    private array $routes;
    private ContainerInterface $container;
    private LoggerInterface $logger;

    public function __construct(
        array $routes,
        ContainerInterface $container,
        LoggerInterface $logger
    ) {
        $this->routes = $routes;
        $this->container = $container;
        $this->logger = $logger;
    }

    public function dispatch(?string $action, array $inputData): array
    {
        try {
            if ($action === null || !isset($this->routes[$action])) {
                return $this->handleUnknownAction($action);
            }

            $route = $this->routes[$action];

            $request = [
                'action' => $action,
                'data' => $inputData,
                'csrf_token' => $inputData['csrf_token'] ?? null,
            ];

            $pipeline = new MiddlewarePipeline();
            foreach ($route['middleware'] as $middlewareConfig) {
                if (is_array($middlewareConfig)) {
                    [$middlewareClass, $config] = $middlewareConfig;
                    $middleware = $this->container->get($middlewareClass);
                    if (method_exists($middleware, 'setConfig')) {
                        $middleware->setConfig($config);
                    }
                    $pipeline->add($middleware);
                } else {
                    $pipeline->add($this->container->get($middlewareConfig));
                }
            }

            return $pipeline->execute($request, function (array $request) use ($route) {
                return $this->executeController($route['controller'], $request);
            });
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('ActionRouter Validation Error', [
                'message' => $e->getMessage(),
                'action' => $action,
            ]);
            return ['status' => 'error', 'message' => $e->getMessage(), 'http_code' => 400];
        } catch (\Throwable $e) {
            $this->logger->error('ActionRouter Unexpected Error', [
                'message' => $e->getMessage(),
                'action' => $action,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['status' => 'error', 'message' => 'An unexpected error occurred.', 'http_code' => 500];
        }
    }

    private function executeController(array $controllerConfig, array $request): array
    {
        [$controllerName, $method] = $controllerConfig;

        // Health check must not depend on any controller (or its config).
        if ($request['action'] === 'ping') {
            return ['status' => 'success', 'message' => 'pong', 'http_code' => 200];
        }

        $controller = $this->getController($controllerName);
        $data = $request['data'];
        $userId = $request['user_id'] ?? null;

        return match ($request['action']) {
            // ---- AUTH ----
            'login' => $controller->login($data),
            'logout' => $controller->logout(),
            'check_auth' => $controller->checkAuth(),
            'me' => $controller->me($userId),
            'update_user_profile' => $controller->updateProfile($data),
            'log_frontend' => $controller->logFrontend($data['log_data'] ?? []),
            'log_frontend_batch' => $controller->logFrontendBatch($data['logs'] ?? []),
            'ping' => ['status' => 'success', 'message' => 'pong', 'http_code' => 200],

            // ---- LIBRARY ----
            'get_artists' => $controller->getArtists($data, $userId),
            'get_artist' => $controller->getArtist($data, $userId),
            'get_album' => $controller->getAlbum($data, $userId),
            'search' => $controller->search($data, $userId),
            'get_stream_url' => $controller->getStreamUrl($data, $userId),
            'issue_external_urls' => $controller->issueExternalUrls($data, $userId),
            'scan_library' => $controller->scanLibrary($data, $userId),
            'get_scan_status' => $controller->getScanStatus($data, $userId),
            'delete_album' => $controller->deleteAlbum($data, $userId),
            'delete_artist' => $controller->deleteArtist($data, $userId),
            'forget_album' => $controller->forgetAlbum($data, $userId),

            // ---- PLAYLISTS ----
            'get_playlists' => $controller->getPlaylists($data, $userId),
            'get_playlist' => $controller->getPlaylist($data, $userId),
            'create_playlist' => $controller->createPlaylist($data, $userId),
            'update_playlist' => $controller->updatePlaylist($data, $userId),
            'delete_playlist' => $controller->deletePlaylist($data, $userId),
            'add_to_playlist' => $controller->addToPlaylist($data, $userId),
            'remove_from_playlist' => $controller->removeFromPlaylist($data, $userId),
            'reorder_playlist' => $controller->reorderPlaylist($data, $userId),

            // ---- FAVORITES ----
            'toggle_favorite' => $controller->toggleFavorite($data, $userId),
            'toggle_album_favorite' => $controller->toggleAlbumFavorite($data, $userId),
            'get_favorites' => $controller->getFavorites($data, $userId),

            // ---- PLAYBACK ----
            'log_play' => $controller->logPlay($data, $userId),
            'get_recently_played' => $controller->getRecentlyPlayed($data, $userId),
            'get_most_played' => $controller->getMostPlayed($data, $userId),

            // ---- RECOMMENDATIONS ----
            'get_recommendations' => $controller->getRecommendations($data, $userId),
            'get_artist_radio' => $controller->getArtistRadio($data, $userId),

            // ---- DISCOVER / SOULSEEK ----
            'spotify_search' => $controller->spotifySearch($data, $userId),
            'slsk_search' => $controller->slskSearch($data, $userId),
            'slsk_search_results' => $controller->slskSearchResults($data, $userId),
            'slsk_download' => $controller->slskDownload($data, $userId),
            'slsk_downloads' => $controller->slskDownloads($data, $userId),
            'slsk_remove_downloads' => $controller->slskRemoveDownloads($data, $userId),
            'slsk_import' => $controller->slskImport($data, $userId),
            'slsk_retry_missing' => $controller->slskRetryMissing($data, $userId),

            default => [
                'status' => 'error',
                'message' => "Controller method not mapped for action: {$request['action']}",
                'http_code' => 500,
            ],
        };
    }

    private function getController(string $controllerName): object
    {
        return match ($controllerName) {
            'AuthController' => $this->container->get(AuthController::class),
            'LibraryController' => $this->container->get(LibraryController::class),
            'PlaylistController' => $this->container->get(PlaylistController::class),
            'FavoriteController' => $this->container->get(FavoriteController::class),
            'PlaybackController' => $this->container->get(PlaybackController::class),
            'RecommendationController' => $this->container->get(RecommendationController::class),
            'SoulseekController' => $this->container->get(SoulseekController::class),
            default => throw new \RuntimeException("Unknown controller: {$controllerName}"),
        };
    }

    private function handleUnknownAction(?string $action): array
    {
        $this->logger->warning('Unknown action requested', [
            'action' => $action,
            'available_routes' => array_keys($this->routes),
        ]);

        return [
            'status' => 'error',
            'message' => 'No valid action specified. Action: ' . ($action ?? 'null'),
            'http_code' => 400,
        ];
    }
}
