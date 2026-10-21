<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Repository\Music\AlbumRequestRepositoryInterface;
use App\Domain\Repository\Music\DownloadRepositoryInterface;
use App\Domain\Services\DownloadReconciler;
use App\Domain\Services\LibraryMatcher;
use App\Infrastructure\Music\SpotifyClient;
use App\Infrastructure\Soulseek\SlskdClient;
use Psr\Log\LoggerInterface;

/**
 * Discover & acquire: Spotify is the discovery layer (rich metadata/covers), slskd (Soulseek) the
 * acquisition layer.
 *
 * Flow: spotify_search → slsk_search (starts, returns an id) → slsk_search_results (client polls)
 * → slsk_download (enqueues in slskd AND records the tracking rows) → slsk_downloads (client polls;
 * the reconciler advances every row and imports what finished). slsk_import is only the manual
 * fallback. The controller stays thin — the work lives in DownloadReconciler / DownloadImporter.
 */
class SoulseekController extends BaseController
{
    private const AUDIO_EXTS = ['flac', 'mp3', 'ogg', 'm4a', 'wav', 'opus', 'aac', 'wma'];

    /** Antigüedad a partir de la cual una búsqueda abandonada se borra de slskd. */
    private const SEARCH_TTL_SECONDS = 300;

    /** Valid values of slsk_download's optional `on_duplicate`. */
    private const ON_DUPLICATE = ['replace', 'skip'];

    public function __construct(
        private readonly SpotifyClient $spotify,
        private readonly SlskdClient $slskd,
        private readonly DownloadRepositoryInterface $downloads,
        private readonly DownloadReconciler $reconciler,
        private readonly LibraryMatcher $matcher,
        private readonly AlbumRequestRepositoryInterface $requests,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function spotifySearch(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $q = trim((string) ($data['q'] ?? ''));
        if ($q === '') {
            return $this->successResponse('Search', ['albums' => [], 'artists' => [], 'tracks' => []]);
        }
        if (!$this->spotify->isEnabled()) {
            return $this->errorResponse('Spotify no está configurado (faltan SPOTIFY_CLIENT_ID/SECRET).', 503);
        }
        $type = (string) ($data['type'] ?? 'album,artist');
        $result = $this->spotify->search($q, $type);

        // Each album says whether it is already in the library (present), was deleted (missing) or not.
        $albums = $result['albums'] ?? [];
        if ($albums) {
            $matches = $this->matcher->matchMany(array_map(
                fn(array $a) => [(string) ($a['artist'] ?? ''), (string) ($a['title'] ?? '')],
                $albums
            ));
            foreach ($albums as $i => $a) {
                $albums[$i]['library'] = $matches[$i] ?? null;
            }
            $result['albums'] = $albums;
        }
        return $this->successResponse('Spotify search', $result);
    }

    public function slskSearch(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        if (!$this->slskd->isConfigured()) {
            return $this->errorResponse('slskd no está configurado (falta SLSKD_API_KEY).', 503);
        }
        $query = trim((string) ($data['q'] ?? trim(($data['artist'] ?? '') . ' ' . ($data['album'] ?? ''))));
        if ($query === '') {
            return $this->errorResponse('Falta el término de búsqueda.', 400);
        }

        // Arrancar y devolver: una búsqueda en slskd tarda segundos y sondearla desde aquí tenía
        // un worker PHP bloqueado todo ese rato. El cliente sondea slsk_search_results.
        $this->sweepStaleSearches();
        try {
            $id = $this->slskd->startSearch($query);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 502);
        }

        return $this->successResponse('Búsqueda iniciada', ['search_id' => $id]);
    }

    /**
     * Estado + resultados de una búsqueda ya arrancada. slskd solo expone /responses cuando la
     * búsqueda está "Completed", así que hasta entonces devolvemos progreso y lista vacía.
     */
    public function slskSearchResults(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        if (!$this->slskd->isConfigured()) {
            return $this->errorResponse('slskd no está configurado (falta SLSKD_API_KEY).', 503);
        }
        $id = trim((string) ($data['search_id'] ?? ''));
        if ($id === '') {
            return $this->errorResponse('Falta search_id.', 400);
        }
        // Lo genera SlskdClient::guid() (GUID v4): cualquier otra forma ('../options') llegaría a
        // la API de slskd con nuestra API key.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return $this->errorResponse('search_id inválido.', 400);
        }

        try {
            $search = $this->slskd->search($id);
            $state = (string) ($search['state'] ?? '');
            $isComplete = str_contains($state, 'Completed');

            $results = [];
            if ($isComplete) {
                $results = $this->flattenResponses($this->slskd->searchResponses($id));
                // Ya tenemos todo: soltar la búsqueda para no dejar estado colgando en slskd.
                try {
                    $this->slskd->deleteSearch($id);
                } catch (\RuntimeException $e) {
                    // best-effort; el barrido de slskSearch la recogerá más adelante
                }
            }
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 502);
        }

        return $this->successResponse('Soulseek search', [
            'search_id'      => $id,
            'state'          => $state,
            'is_complete'    => $isComplete,
            'response_count' => (int) ($search['responseCount'] ?? 0),
            'file_count'     => (int) ($search['fileCount'] ?? 0),
            'results'        => $results,
        ]);
    }

    /**
     * Borra búsquedas viejas que quedaron en slskd (el usuario cerró la vista a mitad del sondeo,
     * así que nadie llegó a leer sus resultados ni a borrarlas). Best-effort: nunca rompe la
     * búsqueda nueva que viene detrás.
     */
    private function sweepStaleSearches(): void
    {
        try {
            $cutoff = time() - self::SEARCH_TTL_SECONDS;
            foreach ($this->slskd->searches() as $s) {
                $id = (string) ($s['id'] ?? '');
                $startedAt = strtotime((string) ($s['startedAt'] ?? '')) ?: 0;
                if ($id !== '' && $startedAt > 0 && $startedAt < $cutoff) {
                    $this->slskd->deleteSearch($id);
                }
            }
        } catch (\RuntimeException $e) {
            // slskd puede no soportar el listado o estar ocupado; no es motivo para fallar.
        }
    }

    public function slskDownload(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        if (!$this->slskd->isConfigured()) {
            return $this->errorResponse('slskd no está configurado.', 503);
        }
        $username = (string) ($data['username'] ?? '');
        $files = $data['files'] ?? [];
        if ($username === '' || !is_array($files) || !$files) {
            return $this->errorResponse('Faltan username o files.', 400);
        }
        // ValidationMiddleware only knows 'required', so the enum check lives here.
        $onDuplicate = $data['on_duplicate'] ?? null;
        if ($onDuplicate !== null && !in_array($onDuplicate, self::ON_DUPLICATE, true)) {
            return $this->errorResponse('on_duplicate debe ser "replace" o "skip".', 400);
        }
        // Normalize to the {filename,size} shape slskd expects, keeping only audio: the frontend
        // already filters, but this endpoint shouldn't take the frontend's word for it.
        $payload = [];
        $remoteDirs = [];
        foreach ($files as $f) {
            if (!isset($f['filename'])) {
                continue;
            }
            $filename = (string) $f['filename'];
            $ext = strtolower(pathinfo(str_replace('\\', '/', $filename), PATHINFO_EXTENSION));
            if (!in_array($ext, self::AUDIO_EXTS, true)) {
                continue;
            }
            $remoteDir = dirname(str_replace('\\', '/', $filename));
            // A file with no folder ("x.flac" -> ".", "\\x.flac" -> "") would make local_folder point
            // at the downloads root itself, and discard/import would then act on all of it.
            $leaf = basename($remoteDir);
            if ($remoteDir === '' || $leaf === '' || $leaf === '.' || $leaf === '..') {
                return $this->errorResponse('La descarga debe estar dentro de una carpeta.', 400);
            }
            $payload[] = ['filename' => $filename, 'size' => (int) ($f['size'] ?? 0)];
            $remoteDirs[$remoteDir] = true;
        }
        if (!$payload) {
            return $this->errorResponse('Lista de ficheros vacía o sin audio.', 400);
        }

        // Duplicate check BEFORE enqueueing: an album already alive in the library is not downloaded
        // again unless the user chose to replace it. A ghost (missing) does not count: downloading
        // it is exactly "re-download".
        $artist = isset($data['artist']) ? (string) $data['artist'] : null;
        $album = isset($data['album']) ? (string) $data['album'] : null;
        $replaceAlbumId = null;
        if ($artist !== null && trim($artist) !== '' && $album !== null && trim($album) !== '') {
            $match = $this->matcher->match($artist, $album);
            if ($match !== null && $match['status'] === 'present') {
                if ($onDuplicate === null) {
                    return $this->successResponse('Ya está en la biblioteca', [
                        'queued'    => 0,
                        'duplicate' => ['album_id' => $match['album_id'], 'title' => $album, 'artist' => $artist],
                    ]);
                }
                if ($onDuplicate === 'skip') {
                    return $this->successResponse('Omitido', ['queued' => 0, 'skipped' => true]);
                }
                $replaceAlbumId = $match['album_id'];   // 'replace'
            }
        }

        try {
            $this->slskd->enqueueDownloads($username, $payload);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 502);
        }

        // Persist the tracking rows: this is what survives an F5, a closed tab or a container
        // restart, and what tells the reconciler how many files to expect.
        $downloadId = null;
        foreach (array_keys($remoteDirs) as $remoteDir) {
            $expected = count(array_filter(
                $payload,
                fn ($p) => dirname(str_replace('\\', '/', $p['filename'])) === $remoteDir
            ));
            $downloadId = $this->downloads->upsertQueued($userId, [
                'slskd_username' => $username,
                'remote_dir'     => $remoteDir,
                'local_folder'   => basename($remoteDir),
                'target_artist'  => $artist,
                'album_title'    => $album,
                'replace_album_id' => $replaceAlbumId,
                'expected_files' => $expected,
            ]);
        }

        // Launched from an album request (admin queue): it is now downloading. The duplicate/skip
        // paths returned above, so they never reach this point and leave the request untouched.
        $requestId = (int) ($data['request_id'] ?? 0);
        if ($requestId > 0 && $downloadId !== null) {
            $this->requests->setStatus($requestId, 'downloading', ['download_id' => $downloadId]);
        }

        return $this->successResponse('Descarga encolada', ['queued' => count($payload)]);
    }

    /**
     * Progress of this user's downloads. Reconciling also imports whatever finished, so the poll
     * from the client is what drives the import — no client-side import logic.
     */
    public function slskDownloads(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        if (!$this->slskd->isConfigured()) {
            return $this->errorResponse('slskd no está configurado.', 503);
        }
        @set_time_limit(0);
        try {
            $downloads = $this->reconciler->reconcile($userId);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 502);
        }
        return $this->successResponse('Downloads', ['downloads' => $downloads]);
    }

    public function slskRemoveDownloads(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        if (!$this->slskd->isConfigured()) {
            return $this->errorResponse('slskd no está configurado.', 503);
        }
        $ids = $data['ids'] ?? [];
        if (!is_array($ids) || !$ids) {
            return $this->errorResponse('Faltan ids.', 400);
        }
        // El cliente solo maneja ids de fila; el reconciliador los traduce a transferencias slskd.
        $removed = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                $this->reconciler->remove($userId, (int) $id);
                $removed++;
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
                $this->logger?->warning('No se pudo quitar la descarga', [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        // Si no se quitó ninguna hay un motivo concreto ("se está importando"), y el usuario que
        // acaba de pulsar Cancelar necesita leerlo en vez de ver la fila seguir ahí.
        if ($removed === 0 && $errors) {
            return $this->errorResponse($errors[0], 409);
        }
        return $this->successResponse('Descargas eliminadas', ['removed' => $removed, 'errors' => $errors]);
    }

    /**
     * Manual import trigger for a tracked download. Goes through the same claim as the automatic
     * path, so triggering it while the poll is importing can't run the move twice.
     *
     * `force` es el "importar igualmente" de una descarga fallida: se lleva a la biblioteca lo que
     * sí bajó (un álbum al que le falta una pista sigue siendo música).
     */
    public function slskImport(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            return $this->errorResponse('Falta el id de la descarga.', 400);
        }
        @set_time_limit(0);
        try {
            $result = $this->reconciler->importRow($userId, $id, (bool) ($data['force'] ?? false));
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
        if ($result === null) {
            return $this->errorResponse('La descarga no está lista para importar.', 409);
        }
        $msg = $result['already_imported'] ? 'Ya estaba en la biblioteca' : 'Importado a la biblioteca';
        return $this->successResponse($msg, $result);
    }

    /**
     * Reintenta en slskd solo las pistas que fallaron, sin volver a bajar las que ya están.
     */
    public function slskRetryMissing(array $data, ?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        if (!$this->slskd->isConfigured()) {
            return $this->errorResponse('slskd no está configurado.', 503);
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            return $this->errorResponse('Falta el id de la descarga.', 400);
        }
        try {
            $queued = $this->reconciler->retryMissing($userId, $id);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
        return $this->successResponse('Pistas reencoladas', ['queued' => $queued]);
    }

    /**
     * Flatten slskd search responses (one per user) into a sorted list of audio files.
     * @param array<int, array<string,mixed>> $responses
     */
    private function flattenResponses(array $responses): array
    {
        $out = [];
        foreach ($responses as $r) {
            $username = (string) ($r['username'] ?? '');
            $hasSlot = (bool) ($r['hasFreeUploadSlot'] ?? false);
            $queue = (int) ($r['queueLength'] ?? 0);
            $speed = (int) ($r['uploadSpeed'] ?? 0);
            foreach (($r['files'] ?? []) as $f) {
                $filename = (string) ($f['filename'] ?? '');
                $ext = strtolower((string) ($f['extension'] ?? pathinfo($filename, PATHINFO_EXTENSION)));
                if (!in_array($ext, self::AUDIO_EXTS, true)) {
                    continue;
                }
                $out[] = [
                    'username'    => $username,
                    'filename'    => $filename,
                    'name'        => basename(str_replace('\\', '/', $filename)),
                    'size'        => (int) ($f['size'] ?? 0),
                    'extension'   => $ext,
                    'bitrate'     => isset($f['bitRate']) ? (int) $f['bitRate'] : null,
                    'length'      => isset($f['length']) ? (int) $f['length'] : null,
                    'has_slot'    => $hasSlot,
                    'queue'       => $queue,
                    'speed'       => $speed,
                ];
            }
        }
        // Quality first: lossless, then free slot, then bitrate, then speed.
        usort($out, function ($a, $b) {
            $la = $a['extension'] === 'flac' ? 1 : 0;
            $lb = $b['extension'] === 'flac' ? 1 : 0;
            return [$lb, (int) $b['has_slot'], $b['bitrate'] ?? 0, $b['speed']]
               <=> [$la, (int) $a['has_slot'], $a['bitrate'] ?? 0, $a['speed']];
        });
        return $out;
    }

}
