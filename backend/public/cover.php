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
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit;
}

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
