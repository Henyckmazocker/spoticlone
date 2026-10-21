<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use App\Infrastructure\Database\DatabaseConnector;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Session\SessionManager;

return function (): ContainerInterface {
    $containerBuilder = new ContainerBuilder();

    if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
        $containerBuilder->enableCompilation(__DIR__ . '/../var/cache');
    }

    $containerBuilder->addDefinitions([

        // ===========================
        // INFRASTRUCTURE
        // ===========================
        PDO::class => function () {
            return (new DatabaseConnector())->getConnection();
        },
        'db' => DI\get(PDO::class),

        LoggerInterface::class => function () {
            return LoggerFactory::createDatabaseLogger();
        },

        SessionManager::class => DI\autowire(),
        \App\Infrastructure\Auth\JWTService::class => DI\autowire(),
        \App\Infrastructure\Auth\GoogleOAuthVerifier::class => DI\autowire(),
        \App\Infrastructure\Auth\SignedUrlService::class => DI\autowire(),

        \App\Infrastructure\Cache\CacheService::class => function (ContainerInterface $c) {
            return new \App\Infrastructure\Cache\CacheService(__DIR__ . '/../storage/cache', $c->get(LoggerInterface::class));
        },

        // ===========================
        // MIDDLEWARE
        // ===========================
        \App\Infrastructure\Middleware\AuthMiddleware::class => DI\autowire(),
        \App\Infrastructure\Middleware\AuthenticationMiddleware::class => DI\autowire(),
        \App\Infrastructure\Middleware\AdminMiddleware::class => DI\autowire(),
        \App\Infrastructure\Middleware\LoggingMiddleware::class => DI\autowire(),
        \App\Infrastructure\Middleware\CSRFMiddleware::class => DI\autowire(),
        \App\Infrastructure\Middleware\ValidationMiddleware::class => DI\autowire(),

        // ===========================
        // DATA MAPPERS
        // ===========================
        \App\Infrastructure\Persistence\User\Mappers\UserDataMapper::class => DI\autowire(),

        // ===========================
        // REPOSITORIES (interface -> implementation)
        // ===========================
        \App\Domain\Repository\User\UserRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\User\MySqlUserRepository::class),
        \App\Domain\Repository\Music\ArtistRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Music\MySqlArtistRepository::class),
        \App\Domain\Repository\Music\AlbumRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Music\MySqlAlbumRepository::class),
        \App\Domain\Repository\Music\TrackRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Music\MySqlTrackRepository::class),
        \App\Domain\Repository\Music\PlaylistRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Music\MySqlPlaylistRepository::class),
        \App\Domain\Repository\Music\FavoriteRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Music\MySqlFavoriteRepository::class),
        \App\Domain\Repository\Music\AlbumFavoriteRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Music\MySqlAlbumFavoriteRepository::class),
        \App\Domain\Repository\Music\PlayHistoryRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Music\MySqlPlayHistoryRepository::class),
        \App\Domain\Repository\Music\ScanRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Music\MySqlScanRepository::class),
        \App\Domain\Repository\Music\DownloadRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Music\MySqlDownloadRepository::class),
        \App\Domain\Repository\Music\AlbumRequestRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Music\MySqlAlbumRequestRepository::class),

        \App\Infrastructure\Persistence\User\MySqlUserRepository::class => DI\autowire(),
        \App\Infrastructure\Persistence\Music\MySqlArtistRepository::class => DI\autowire(),
        \App\Infrastructure\Persistence\Music\MySqlAlbumRepository::class => DI\autowire(),
        \App\Infrastructure\Persistence\Music\MySqlTrackRepository::class => DI\autowire(),
        \App\Infrastructure\Persistence\Music\MySqlPlaylistRepository::class => DI\autowire(),
        \App\Infrastructure\Persistence\Music\MySqlFavoriteRepository::class => DI\autowire(),
        \App\Infrastructure\Persistence\Music\MySqlAlbumFavoriteRepository::class => DI\autowire(),
        \App\Infrastructure\Persistence\Music\MySqlPlayHistoryRepository::class => DI\autowire(),
        \App\Infrastructure\Persistence\Music\MySqlScanRepository::class => DI\autowire(),
        \App\Infrastructure\Persistence\Music\MySqlDownloadRepository::class => DI\autowire(),
        \App\Infrastructure\Persistence\Music\MySqlAlbumRequestRepository::class => DI\autowire(),

        // ===========================
        // DOMAIN SERVICES
        // ===========================
        \App\Domain\Services\LibraryScanner::class => DI\autowire(),
        \App\Domain\Services\MediaDeletionService::class => DI\autowire(),
        \App\Domain\Services\LibraryMatcher::class => DI\autowire(),
        \App\Domain\Services\AlbumRequestService::class => DI\autowire(),
        \App\Domain\Services\TrackPresenter::class => DI\autowire(),
        \App\Domain\Services\AlbumPresenter::class => DI\autowire(),
        \App\Domain\Services\DownloadImporter::class => DI\autowire(),
        \App\Domain\Services\DownloadReconciler::class => DI\autowire(),
        \App\Infrastructure\Music\SpotifyClient::class => DI\autowire(),
        \App\Infrastructure\Music\LastfmClient::class => DI\autowire(),
        \App\Domain\Services\RecommendationService::class => DI\autowire(),
        \App\Infrastructure\Music\MetadataEnrichmentService::class => DI\autowire(),
        \App\Infrastructure\Soulseek\SlskdClient::class => DI\autowire(),

        // ===========================
        // USE CASES - Auth
        // ===========================
        \App\Domain\UseCases\Auth\LoginUserUseCase::class => DI\autowire(),
        \App\Domain\UseCases\Auth\UpdateUserProfileUseCase::class => DI\autowire(),

        // ===========================
        // CONTROLLERS
        // ===========================
        \App\Controllers\AuthController::class => DI\autowire(),
        \App\Controllers\LibraryController::class => DI\autowire(),
        \App\Controllers\PlaylistController::class => DI\autowire(),
        \App\Controllers\FavoriteController::class => DI\autowire(),
        \App\Controllers\PlaybackController::class => DI\autowire(),
        \App\Controllers\RecommendationController::class => DI\autowire(),
        \App\Controllers\SoulseekController::class => DI\autowire(),
        \App\Controllers\AlbumRequestController::class => DI\autowire(),

        // ===========================
        // ROUTER
        // ===========================
        \App\Router\ActionRouter::class => DI\autowire()
            ->constructorParameter('routes', require __DIR__ . '/routes.php')
            ->constructorParameter('container', DI\get(ContainerInterface::class))
            ->constructorParameter('logger', DI\get(LoggerInterface::class)),
    ]);

    return $containerBuilder->build();
};
