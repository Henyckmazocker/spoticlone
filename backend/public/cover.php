<?php

declare(strict_types=1);

/**
 * Album cover endpoint (OUTSIDE the action router).
 *
 * Authenticated by a signed URL token (?token=...), like stream.php, so it works in <img src>.
 * Serves the album's cover_path image with long-lived caching headers and an ETag.
 */

require_once __DIR__ . '/../bootstrap_min.php';

use App\Infrastructure\Auth\SignedUrlService;
use App\Infrastructure\Database\DatabaseConnector;

$token = $_GET['token'] ?? '';
$payload = (new SignedUrlService())->verify($token);

if ($payload === null || $payload['k'] !== 'cover') {
    http_response_code(403);
    exit;
}

try {
    $pdo = (new DatabaseConnector())->getConnection();
    $stmt = $pdo->prepare('SELECT cover_path FROM albums WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $payload['id']]);
    $album = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    http_response_code(500);
    exit;
}

$path = $album['cover_path'] ?? null;
if (!$path) {
    http_response_code(404);
    exit;
}

// Defense in depth (same pattern as stream.php): the resolved file must live inside MUSIC_ROOT or
// COVERS_PATH, so a cover_path pointing elsewhere (or a symlink out of the library) is a 404, not
// a 403, to avoid confirming the path exists. An unset/missing root is dropped: realpath('') would
// resolve to the current directory.
$musicRoot = rtrim($_ENV['MUSIC_ROOT'] ?? '/music', '/');
$coversPath = rtrim($_ENV['COVERS_PATH'] ?? (__DIR__ . '/../storage/covers'), '/');
$roots = array_filter([realpath($musicRoot), realpath($coversPath)]);
$real = realpath($path);

$inside = false;
if ($real !== false && is_file($real)) {
    foreach ($roots as $root) {
        if (strpos($real, $root . DIRECTORY_SEPARATOR) === 0) {
            $inside = true;
            break;
        }
    }
}
if (!$inside) {
    http_response_code(404);
    exit;
}
$path = $real;

$mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    default => 'image/jpeg',
};

$etag = '"' . md5($path . '|' . (string) filemtime($path)) . '"';
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
