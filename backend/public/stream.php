<?php

declare(strict_types=1);

/**
 * Audio streaming endpoint (OUTSIDE the action router).
 *
 * Authenticated by a signed URL token (?token=...), because the browser/Capacitor <audio>
 * element cannot send an Authorization header. Implements HTTP Range so the player can seek
 * and buffer: a `Range: bytes=start-end` request gets a 206 Partial Content with the requested
 * slice; the body is streamed in chunks (never readfile() of a whole FLAC) to bound memory.
 *
 * Two token kinds are served the same way:
 *   - 'stream': the in-app player (relative URL from get_stream_url / TrackPresenter, 1 h TTL).
 *   - 'ext':    third parties (absolute PUBLIC_BASE_URL URL from issue_external_urls, ≤ 24 h TTL).
 */

require_once __DIR__ . '/../bootstrap_min.php';

use App\Infrastructure\Auth\SignedUrlService;
use App\Infrastructure\Database\DatabaseConnector;

$token = $_GET['token'] ?? '';
$payload = (new SignedUrlService())->verify($token);

// 'stream' = in-app player; 'ext' = absolute URL issued to a third party (same serving path).
if ($payload === null || !in_array($payload['k'], ['stream', 'ext'], true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid or expired stream token.']);
    exit;
}

try {
    $pdo = (new DatabaseConnector())->getConnection();
    $stmt = $pdo->prepare('SELECT file_path, file_format FROM tracks WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $payload['id']]);
    $track = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    http_response_code(500);
    exit;
}

if (!$track) {
    http_response_code(404);
    exit;
}

$path = $track['file_path'];
$musicRoot = rtrim($_ENV['MUSIC_ROOT'] ?? '/music', '/');
$realRoot = realpath($musicRoot) ?: $musicRoot;
$real = realpath($path);

// Defense in depth: the resolved file must live inside MUSIC_ROOT (no path traversal). The
// separator matters: without it a sibling folder like "/music-other" would pass the prefix check.
if ($real === false || !is_file($real) || strpos($real, $realRoot . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit;
}

$mime = match (strtolower((string) $track['file_format'])) {
    'mp3'  => 'audio/mpeg',
    'flac' => 'audio/flac',
    'ogg', 'opus' => 'audio/ogg',
    'm4a', 'aac'  => 'audio/mp4',
    'wav'  => 'audio/wav',
    'wma'  => 'audio/x-ms-wma',
    default => 'application/octet-stream',
};

streamFileWithRange($real, $mime);

/**
 * Stream a file honouring an optional HTTP Range request.
 */
function streamFileWithRange(string $file, string $mime): void
{
    $size = filesize($file);
    $start = 0;
    $end = $size - 1;
    $isPartial = false;

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $isPartial = true;
        if ($m[1] !== '') {
            $start = (int) $m[1];
        }
        if ($m[2] !== '') {
            $end = (int) $m[2];
        }
        if ($m[1] === '' && $m[2] !== '') {
            // suffix range: last N bytes
            $start = max(0, $size - (int) $m[2]);
            $end = $size - 1;
        }
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416); // Range Not Satisfiable
            exit;
        }
        $end = min($end, $size - 1);
    }

    $length = $end - $start + 1;

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    header('Cache-Control: private, max-age=0');

    if ($isPartial) {
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    } else {
        http_response_code(200);
    }

    // HEAD requests want headers only.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }

    // Clear any output buffering so we don't buffer the whole file in memory.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $fp = fopen($file, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit;
    }
    fseek($fp, $start);

    $chunkSize = 8192;
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $read = ($remaining > $chunkSize) ? $chunkSize : $remaining;
        $buffer = fread($fp, $read);
        if ($buffer === false) {
            break;
        }
        echo $buffer;
        flush();
        $remaining -= strlen($buffer);
    }
    fclose($fp);
    exit;
}
