<?php

declare(strict_types=1);

namespace App\Domain\Repository\Music;

/**
 * Persistencia del seguimiento de descargas de Soulseek/slskd.
 *
 * Una fila = un directorio remoto encolado por un usuario. La identidad es
 * (user_id, sha256(username|remote_dir)); el basename NO sirve como clave porque distintos álbumes
 * comparten subcarpetas (CD1, Disc 1, Various).
 */
interface DownloadRepositoryInterface
{
    /**
     * Registra (o reactiva) una descarga encolada. Idempotente por (user_id, remote_dir_hash):
     * reencolar la misma carpeta reutiliza la fila y la devuelve a 'queued' limpiando el error.
     *
     * @param array{
     *     slskd_username:string, remote_dir:string, local_folder:string,
     *     target_artist:?string, album_title:?string, expected_files:int,
     *     replace_album_id?:?int
     * } $data  `replace_album_id`: álbum vivo que la descarga sustituye; un reencolado sin él
     *          conserva el que ya tenía la fila.
     * @return int id de la fila
     */
    public function upsertQueued(int $userId, array $data): int;

    /**
     * Quita la marca de reemplazo de TODAS las filas que apuntan a $albumId (las hermanas de un
     * álbum de varios discos): el álbum viejo se retira una sola vez.
     */
    public function clearReplace(int $albumId): void;

    /** @return array<int, array<string, mixed>> Filas no terminales + las importadas/fallidas recientes. */
    public function findActiveForUser(int $userId): array;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /**
     * Cambia el estado de una fila. `error` e `imported_path` se escriben solo si se pasan
     * (null los deja como están, salvo al pasar a un estado sin error, que limpia `error`).
     */
    public function markState(int $id, string $state, ?string $error = null, ?string $importedPath = null): void;

    /**
     * Toma la fila para importar: `UPDATE ... SET state='importing' WHERE id=:id AND state='complete'`.
     *
     * Devuelve true solo si ESTA petición ganó la fila. Es el lock que impide que dos polls
     * solapados (dos pestañas, o un poll lento seguido del siguiente) importen la misma carpeta a
     * la vez. Un import es caro y mueve ficheros: ejecutarlo dos veces deja restos.
     */
    public function claimForImport(int $id): bool;

    /** Vuelve a poner en 'complete' una fila reclamada cuyo import falló antes de marcarla. */
    public function releaseImport(int $id): void;

    /** Olvida una descarga (el usuario la ha quitado de la lista). */
    public function delete(int $id): void;
}
