<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\DownloadRepositoryInterface;
use App\Domain\Services\DownloadImporter;
use App\Domain\Services\DownloadReconciler;
use App\Domain\Services\LibraryScanner;
use App\Domain\Services\MediaDeletionService;
use App\Infrastructure\Soulseek\SlskdClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DownloadReconcilerTest extends TestCase
{
    private string $downloads;
    private string $music;

    /** @var array<int, string> what happened, in order: 'delete:<id>', 'scan' */
    private array $events = [];

    /** @var array<int, array<string, mixed>> album rows seen by the fake album repository */
    private array $albumRows = [];

    /** How the fake deleteAlbum behaves: 'ok', 'incomplete' or 'throw'. */
    private string $deleteMode = 'ok';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/spoticlone_rec_' . uniqid();
        $this->downloads = $base . '/downloads';
        $this->music = $base . '/music';
        mkdir($this->downloads, 0775, true);
        mkdir($this->music, 0775, true);
        $_ENV['DOWNLOADS_ROOT'] = $this->downloads;
        $_ENV['MUSIC_ROOT'] = $this->music;
    }

    protected function tearDown(): void
    {
        $this->rrmdir(dirname($this->downloads));
    }

    public function test_marks_queued_row_as_downloading(): void
    {
        $repo = $this->repo([$this->row(['state' => 'queued', 'expected_files' => 2])]);
        $rec = $this->reconciler($this->slskd([
            $this->transfer('bob', '@@music\\Artist - Album\\01.flac', 'InProgress', 40.0),
            $this->transfer('bob', '@@music\\Artist - Album\\02.flac', 'Queued', 0.0),
        ]), $repo);

        $out = $rec->reconcile(1);

        $this->assertSame('downloading', $out[0]['state']);
        $this->assertSame(0, $out[0]['done_files']);
        $this->assertSame('downloading', $repo->rows[0]['state']);
    }

    public function test_imports_once_every_file_succeeded(): void
    {
        $this->makeDownloadOnDisk('Artist - Album', 2);
        $repo = $this->repo([$this->row(['state' => 'downloading', 'expected_files' => 2])]);
        $rec = $this->reconciler($this->slskd([
            $this->transfer('bob', '@@music\\Artist - Album\\01.flac', 'Completed, Succeeded', 100.0),
            $this->transfer('bob', '@@music\\Artist - Album\\02.flac', 'Completed, Succeeded', 100.0),
        ]), $repo);

        $out = $rec->reconcile(1);

        $this->assertSame('imported', $out[0]['state']);
        $this->assertSame('imported', $repo->rows[0]['state']);
        $this->assertFileExists($this->music . '/The Artist/The Album/01.flac');
    }

    public function test_completes_from_disk_when_slskd_forgot_the_transfers(): void
    {
        // slskd purga las transferencias terminadas: la API deja de mencionarlas aunque la descarga
        // sí terminara. Contar lo que hay en disco es lo único que distingue "acabó" de "se perdió".
        $this->makeDownloadOnDisk('Artist - Album', 3);
        $repo = $this->repo([$this->row(['state' => 'downloading', 'expected_files' => 3])]);
        $rec = $this->reconciler($this->slskd([]), $repo);

        $out = $rec->reconcile(1);

        $this->assertSame('imported', $out[0]['state']);
        $this->assertFileExists($this->music . '/The Artist/The Album/01.flac');
    }

    public function test_stays_pending_when_slskd_forgot_an_unfinished_download(): void
    {
        $this->makeDownloadOnDisk('Artist - Album', 1);   // 1 de 3: no terminó
        $repo = $this->repo([$this->row(['state' => 'downloading', 'expected_files' => 3])]);
        $rec = $this->reconciler($this->slskd([]), $repo);

        $out = $rec->reconcile(1);

        $this->assertSame('downloading', $out[0]['state']);
        $this->assertDirectoryDoesNotExist($this->music . '/The Artist/The Album');
    }

    public function test_marks_failed_when_every_transfer_failed(): void
    {
        $repo = $this->repo([$this->row(['state' => 'downloading', 'expected_files' => 2])]);
        $rec = $this->reconciler($this->slskd([
            $this->transfer('bob', '@@music\\Artist - Album\\01.flac', 'Completed, Errored', 12.0),
            $this->transfer('bob', '@@music\\Artist - Album\\02.flac', 'Completed, TimedOut', 0.0),
        ]), $repo);

        $out = $rec->reconcile(1);

        $this->assertSame('failed', $out[0]['state']);
        $this->assertStringContainsString('Errored', (string) $out[0]['error']);
    }

    public function test_partial_failure_stops_instead_of_downloading_for_ever(): void
    {
        // 2 de 3 pistas bien y la tercera errada: ni "todas fallaron" ni "done >= expected", así que
        // antes la fila se quedaba en 'downloading' eternamente y no se podía ni quitar de la vista.
        $this->makeDownloadOnDisk('Artist - Album', 2);
        $repo = $this->repo([$this->row(['state' => 'downloading', 'expected_files' => 3])]);
        $rec = $this->reconciler($this->slskd([
            $this->transfer('bob', '@@music\\Artist - Album\\01.flac', 'Completed, Succeeded', 100.0),
            $this->transfer('bob', '@@music\\Artist - Album\\02.flac', 'Completed, Succeeded', 100.0),
            $this->transfer('bob', '@@music\\Artist - Album\\03.flac', 'Completed, Errored', 8.0),
        ]), $repo);

        $out = $rec->reconcile(1);

        $this->assertSame('failed', $out[0]['state']);
        $this->assertSame(2, $out[0]['done_files']);
        $this->assertStringContainsString('Faltan 1 de 3 pistas', (string) $out[0]['error']);
        $this->assertDirectoryDoesNotExist($this->music . '/The Artist/The Album');
    }

    public function test_partial_download_still_completes_if_disk_has_everything(): void
    {
        // Una transferencia errada pero el álbum entero en disco (slskd purgó las buenas y el
        // usuario ya tenía los ficheros): no hay nada que reintentar, se importa.
        $this->makeDownloadOnDisk('Artist - Album', 2);
        $repo = $this->repo([$this->row(['state' => 'downloading', 'expected_files' => 2])]);
        $rec = $this->reconciler($this->slskd([
            $this->transfer('bob', '@@music\\Artist - Album\\01.flac', 'Completed, Succeeded', 100.0),
            $this->transfer('bob', '@@music\\Artist - Album\\02.flac', 'Completed, Errored', 0.0),
        ]), $repo);

        $out = $rec->reconcile(1);

        $this->assertSame('imported', $out[0]['state']);
    }

    public function test_retry_missing_reenqueues_only_the_failed_tracks(): void
    {
        $repo = $this->repo([$this->row(['state' => 'failed', 'expected_files' => 3])]);
        $slskd = $this->slskd([
            $this->transfer('bob', '@@music\\Artist - Album\\01.flac', 'Completed, Succeeded', 100.0),
            $this->transfer('bob', '@@music\\Artist - Album\\02.flac', 'Completed, Succeeded', 100.0),
            $this->transfer('bob', '@@music\\Artist - Album\\03.flac', 'Completed, Errored', 8.0),
        ]);

        $queued = $this->reconciler($slskd, $repo)->retryMissing(1, 1);

        $this->assertSame(1, $queued);
        $this->assertSame(['@@music\\Artist - Album\\03.flac'], array_column($slskd->enqueued, 'filename'));
        $this->assertNotEmpty($slskd->cancelled, 'la transferencia muerta se purga antes de reencolar');
        $this->assertSame('queued', $repo->rows[0]['state']);
        $this->assertNull($repo->rows[0]['error']);
    }

    public function test_retry_missing_explains_itself_when_slskd_forgot_the_transfers(): void
    {
        $repo = $this->repo([$this->row(['state' => 'failed', 'expected_files' => 3])]);
        $rec = $this->reconciler($this->slskd([]), $repo);

        $this->expectExceptionMessageMatches('/slskd ya no recuerda/');
        $rec->retryMissing(1, 1);
    }

    public function test_force_import_takes_a_failed_download_into_the_library(): void
    {
        // "Importar igualmente": faltó una pista, pero las que llegaron son música de verdad.
        $this->makeDownloadOnDisk('Artist - Album', 2);
        $repo = $this->repo([$this->row(['state' => 'failed', 'expected_files' => 3])]);
        $rec = $this->reconciler($this->slskd([]), $repo);

        $result = $rec->importRow(1, 1, true);

        $this->assertNotNull($result);
        $this->assertSame('imported', $repo->rows[0]['state']);
        $this->assertFileExists($this->music . '/The Artist/The Album/01.flac');
    }

    public function test_import_without_force_leaves_a_failed_download_alone(): void
    {
        $this->makeDownloadOnDisk('Artist - Album', 2);
        $repo = $this->repo([$this->row(['state' => 'failed', 'expected_files' => 3])]);
        $rec = $this->reconciler($this->slskd([]), $repo);

        $this->assertNull($rec->importRow(1, 1));
        $this->assertSame('failed', $repo->rows[0]['state']);
    }

    public function test_import_rejects_a_row_from_another_user(): void
    {
        $repo = $this->repo([$this->row(['state' => 'complete', 'user_id' => 2])]);
        $rec = $this->reconciler($this->slskd([]), $repo);

        $this->expectExceptionMessageMatches('/no existe/');
        $rec->importRow(1, 1, true);
    }

    public function test_cancelling_kills_the_transfers_and_the_partial_folder(): void
    {
        // Cancelar una descarga que no avanza: se aborta en slskd y lo bajado a medias se va con
        // ella. Dejarlo era lo que llenaba /downloads de álbumes incompletos.
        $this->makeDownloadOnDisk('Artist - Album', 2);
        $repo = $this->repo([$this->row(['state' => 'downloading', 'expected_files' => 5])]);
        $slskd = $this->slskd([
            $this->transfer('bob', '@@music\\Artist - Album\\01.flac', 'InProgress', 30.0),
        ]);

        $this->reconciler($slskd, $repo)->remove(1, 1);

        $this->assertNotEmpty($slskd->cancelled);
        $this->assertDirectoryDoesNotExist($this->downloads . '/Artist - Album');
        $this->assertSame([], $repo->rows);
    }

    public function test_cancelling_refuses_while_the_import_is_moving_files(): void
    {
        $this->makeDownloadOnDisk('Artist - Album', 2);
        $repo = $this->repo([$this->row(['state' => 'importing', 'expected_files' => 2])]);
        $rec = $this->reconciler($this->slskd([]), $repo);

        try {
            $rec->remove(1, 1);
            $this->fail('debería negarse mientras importa');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('importando', $e->getMessage());
        }
        $this->assertDirectoryExists($this->downloads . '/Artist - Album');
        $this->assertCount(1, $repo->rows);
    }

    public function test_concurrent_polls_import_only_once(): void
    {
        $this->makeDownloadOnDisk('Artist - Album', 1);
        $repo = $this->repo([$this->row(['state' => 'complete', 'expected_files' => 1])]);
        $slskd = $this->slskd([
            $this->transfer('bob', '@@music\\Artist - Album\\01.flac', 'Completed, Succeeded', 100.0),
        ]);

        // Dos sondeos solapados (dos pestañas) sobre el mismo repositorio.
        $this->reconciler($slskd, $repo)->reconcile(1);
        $this->reconciler($slskd, $repo)->reconcile(1);

        $this->assertSame(1, $repo->claimsGranted, 'solo un sondeo debe llevarse la fila');
        $this->assertSame('imported', $repo->rows[0]['state']);
    }

    public function test_retries_an_import_orphaned_by_a_crash(): void
    {
        // El proceso murió a media importación: la fila quedó en 'importing' y solo la petición que
        // la reclamó podía soltarla, así que sin esto se quedaba ahí para siempre.
        $this->makeDownloadOnDisk('Artist - Album', 1);
        $repo = $this->repo([$this->row([
            'state'          => 'importing',
            'expected_files' => 1,
            'updated_at'     => date('Y-m-d H:i:s', time() - 3600),
        ])]);
        $rec = $this->reconciler($this->slskd([]), $repo);

        $out = $rec->reconcile(1);

        $this->assertSame('imported', $out[0]['state']);
        $this->assertFileExists($this->music . '/The Artist/The Album/01.flac');
    }

    public function test_leaves_a_recent_import_alone(): void
    {
        // Recién reclamada por otro sondeo: no tocarla, o dos peticiones moverían los mismos ficheros.
        $this->makeDownloadOnDisk('Artist - Album', 1);
        $repo = $this->repo([$this->row([
            'state'          => 'importing',
            'expected_files' => 1,
            'updated_at'     => date('Y-m-d H:i:s'),
        ])]);
        $rec = $this->reconciler($this->slskd([]), $repo);

        $out = $rec->reconcile(1);

        $this->assertSame('importing', $out[0]['state']);
        $this->assertSame(0, $repo->claimsGranted);
    }

    public function test_rows_that_collide_by_basename_are_tracked_apart(): void
    {
        // Dos álbumes distintos cuyo directorio remoto acaba en "CD1": la clave es la ruta remota
        // completa, así que cada fila ve solo sus propias transferencias.
        $repo = $this->repo([
            $this->row(['id' => 1, 'remote_dir' => '@@music\\Album One\\CD1', 'local_folder' => 'CD1', 'expected_files' => 1]),
            $this->row(['id' => 2, 'remote_dir' => '@@music\\Album Two\\CD1', 'local_folder' => 'CD1', 'expected_files' => 1]),
        ]);
        $rec = $this->reconciler($this->slskd([
            $this->transfer('bob', '@@music\\Album One\\CD1\\a.flac', 'InProgress', 50.0),
        ]), $repo);

        $out = $rec->reconcile(1);

        $this->assertSame(50.0, $out[0]['percent']);   // la que sí tiene transferencia
        $this->assertSame(0.0, $out[1]['percent']);    // la otra sigue sin noticias
    }

    public function test_replace_retires_the_old_album_before_importing(): void
    {
        // El álbum viejo vive justo donde va a caer el nuevo: si no se retira antes, el importador
        // ve la carpeta y da "ya importado" sin mover nada, y el escaneo no tendría fantasma al que
        // reenganchar.
        $old = $this->music . '/The Artist/The Album';
        mkdir($old, 0775, true);
        file_put_contents($old . '/01.mp3', 'old');
        $this->albumRows[7] = ['id' => 7, 'folder_path' => $old, 'missing_at' => null];
        $this->makeDownloadOnDisk('Artist - Album', 1);
        $repo = $this->repo([$this->row(['state' => 'complete', 'replace_album_id' => 7])]);

        $out = $this->reconciler($this->slskd([]), $repo)->reconcile(1);

        $this->assertSame('imported', $out[0]['state']);
        $this->assertSame(['delete:7', 'scan'], $this->events, 'primero se borra, luego se importa');
        $this->assertFileExists($old . '/01.flac');
        $this->assertFileDoesNotExist($old . '/01.mp3');
        $this->assertNull($repo->rows[0]['replace_album_id']);
    }

    public function test_sibling_rows_of_a_multi_disc_album_delete_only_once(): void
    {
        // CD1 y CD2 apuntan al mismo álbum: si el segundo volviera a borrarlo, se llevaría lo que
        // acaba de importar el primero.
        $this->albumRows[7] = ['id' => 7, 'folder_path' => $this->music . '/Old/Album', 'missing_at' => null];
        $this->makeDownloadOnDisk('CD1', 1);
        $this->makeDownloadOnDisk('CD2', 1);
        $repo = $this->repo([
            $this->row(['id' => 1, 'remote_dir' => '@@music\\Album\\CD1', 'local_folder' => 'CD1',
                        'album_title' => 'The Album CD1', 'state' => 'complete', 'replace_album_id' => 7]),
            $this->row(['id' => 2, 'remote_dir' => '@@music\\Album\\CD2', 'local_folder' => 'CD2',
                        'album_title' => 'The Album CD2', 'state' => 'complete', 'replace_album_id' => 7]),
        ]);

        $out = $this->reconciler($this->slskd([]), $repo)->reconcile(1);

        $this->assertSame(['imported', 'imported'], array_column($out, 'state'));
        $this->assertSame(['delete:7', 'scan', 'scan'], $this->events);
        $this->assertSame([null, null], array_column($repo->rows, 'replace_album_id'));
    }

    public function test_replace_pointing_at_a_ghost_just_imports(): void
    {
        // Ya es fantasma (lo borró el usuario, o una fila hermana): no hay nada que retirar.
        $this->albumRows[7] = ['id' => 7, 'folder_path' => $this->music . '/Old/Album', 'missing_at' => '2026-10-13 10:00:00'];
        $this->makeDownloadOnDisk('Artist - Album', 1);
        $repo = $this->repo([$this->row(['state' => 'complete', 'replace_album_id' => 7])]);

        $out = $this->reconciler($this->slskd([]), $repo)->reconcile(1);

        $this->assertSame('imported', $out[0]['state']);
        $this->assertSame(['scan'], $this->events);
    }

    /** @return array<string, array{0:string}> */
    public static function failedDeletions(): array
    {
        return ['folder left behind' => ['incomplete'], 'disk exception' => ['throw']];
    }

    #[DataProvider('failedDeletions')]
    public function test_failed_deletion_fails_the_row_and_imports_nothing(string $mode): void
    {
        // Importar con el viejo vivo crearía justo el duplicado que el reemplazo quería evitar.
        $this->deleteMode = $mode;
        $this->albumRows[7] = ['id' => 7, 'folder_path' => $this->music . '/Old/Album', 'missing_at' => null];
        $this->makeDownloadOnDisk('Artist - Album', 1);
        $repo = $this->repo([$this->row(['state' => 'complete', 'replace_album_id' => 7])]);

        $out = $this->reconciler($this->slskd([]), $repo)->reconcile(1);

        $this->assertSame('failed', $out[0]['state']);
        $this->assertSame('No se pudo retirar el álbum anterior', $out[0]['error']);
        $this->assertSame('failed', $repo->rows[0]['state']);
        $this->assertSame(['delete:7'], $this->events, 'sin escaneo: no se importó');
        $this->assertDirectoryDoesNotExist($this->music . '/The Artist/The Album');
        $this->assertDirectoryExists($this->downloads . '/Artist - Album');
        $this->assertSame(7, $repo->rows[0]['replace_album_id'], 'el reintento debe volver a intentarlo');
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<int, array<string, mixed>> $rows */
    private function repo(array $rows): DownloadRepositoryInterface
    {
        return new class ($rows) implements DownloadRepositoryInterface {
            public int $claimsGranted = 0;

            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(public array $rows) {}

            public function upsertQueued(int $userId, array $data): int { return 1; }

            public function findActiveForUser(int $userId): array
            {
                return array_values(array_filter($this->rows, fn ($r) => (int) $r['user_id'] === $userId));
            }

            public function findById(int $id): ?array
            {
                foreach ($this->rows as $r) {
                    if ((int) $r['id'] === $id) return $r;
                }
                return null;
            }

            public function markState(int $id, string $state, ?string $error = null, ?string $importedPath = null): void
            {
                foreach ($this->rows as $i => $r) {
                    if ((int) $r['id'] !== $id) continue;
                    $this->rows[$i]['state'] = $state;
                    $this->rows[$i]['error'] = $error;
                    if ($importedPath !== null) $this->rows[$i]['imported_path'] = $importedPath;
                }
            }

            public function claimForImport(int $id): bool
            {
                foreach ($this->rows as $i => $r) {
                    if ((int) $r['id'] !== $id || $r['state'] !== 'complete') continue;
                    $this->rows[$i]['state'] = 'importing';
                    $this->claimsGranted++;
                    return true;
                }
                return false;
            }

            public function releaseImport(int $id): void { $this->markState($id, 'complete'); }

            public function clearReplace(int $albumId): void
            {
                foreach ($this->rows as $i => $r) {
                    if ((int) ($r['replace_album_id'] ?? 0) === $albumId) $this->rows[$i]['replace_album_id'] = null;
                }
            }

            public function delete(int $id): void
            {
                $this->rows = array_values(array_filter($this->rows, fn ($r) => (int) $r['id'] !== $id));
            }
        };
    }

    /** @param array<int, array<string, mixed>> $transfers */
    private function slskd(array $transfers): SlskdClient
    {
        return new class ($transfers) extends SlskdClient {
            /** @var array<int, array{filename:string, size:int}> */
            public array $enqueued = [];
            /** @var array<int, string> */
            public array $cancelled = [];

            /** @param array<int, array<string, mixed>> $transfers */
            public function __construct(private array $transfers) {}

            public function enqueueDownloads(string $username, array $files): void
            {
                $this->enqueued = array_merge($this->enqueued, $files);
            }

            public function cancelDownload(string $username, string $id, bool $remove = false): void
            {
                $this->cancelled[] = $id;
            }

            public function downloads(): array
            {
                // Reproduce la forma de slskd: usuario → directorios → ficheros.
                $byUser = [];
                foreach ($this->transfers as $t) {
                    $byUser[$t['username']]['username'] = $t['username'];
                    $byUser[$t['username']]['directories'][0]['files'][] = $t;
                }
                return array_values($byUser);
            }
        };
    }

    private function reconciler(SlskdClient $slskd, DownloadRepositoryInterface $repo): DownloadReconciler
    {
        $events = &$this->events;
        $albumRows = &$this->albumRows;
        $scanner = new class ($events, $albumRows) extends LibraryScanner {
            /**
             * @param array<int, string> $events
             * @param array<int, array<string, mixed>> $albumRows
             */
            public function __construct(private array &$events, private array &$albumRows) {}
            public function scan(?string $onlyPath = null): array
            {
                $this->events[] = 'scan';
                // Like the real scanner: reattaching files to a ghost brings its album back to life.
                foreach ($this->albumRows as $id => $row) {
                    $this->albumRows[$id]['missing_at'] = null;
                }
                return ['files_seen' => 0];
            }
        };

        $albums = $this->createStub(AlbumRepositoryInterface::class);
        $albums->method('findById')->willReturnCallback(fn (int $id) => $this->albumRows[$id] ?? null);

        // Stands in for the soft delete: removes the folder and turns the album into a ghost.
        $deletion = new class ($this) extends MediaDeletionService {
            public function __construct(private DownloadReconcilerTest $t) {}
            public function deleteAlbum(int $albumId): array
            {
                return $this->t->fakeDeleteAlbum($albumId);
            }
        };

        return new DownloadReconciler($slskd, $repo, new DownloadImporter($scanner), $deletion, $albums);
    }

    /** @return array{deleted:bool, files_removed:int, ghost:bool, incomplete?:bool} */
    public function fakeDeleteAlbum(int $albumId): array
    {
        $this->events[] = 'delete:' . $albumId;
        if ($this->deleteMode === 'throw') {
            throw new \RuntimeException('Permission denied');
        }
        if ($this->deleteMode === 'incomplete') {
            return ['deleted' => false, 'files_removed' => 0, 'ghost' => false, 'incomplete' => true];
        }
        $this->rrmdir((string) $this->albumRows[$albumId]['folder_path']);
        $this->albumRows[$albumId]['missing_at'] = date('Y-m-d H:i:s');
        return ['deleted' => true, 'files_removed' => 1, 'ghost' => true];
    }

    /** @param array<string, mixed> $over */
    private function row(array $over = []): array
    {
        return $over + [
            'id'             => 1,
            'user_id'        => 1,
            'slskd_username' => 'bob',
            'remote_dir'     => '@@music\\Artist - Album',
            'local_folder'   => 'Artist - Album',
            'target_artist'  => 'The Artist',
            'album_title'    => 'The Album',
            'expected_files' => 1,
            'state'          => 'queued',
            'error'          => null,
            'imported_path'  => null,
        ];
    }

    private function transfer(string $username, string $filename, string $state, float $percent): array
    {
        return [
            'id'              => 't-' . md5($filename),
            'username'        => $username,
            'filename'        => $filename,
            'state'           => $state,
            'percentComplete' => $percent,
        ];
    }

    private function makeDownloadOnDisk(string $folder, int $tracks): void
    {
        mkdir($this->downloads . '/' . $folder, 0775, true);
        for ($i = 1; $i <= $tracks; $i++) {
            file_put_contents(sprintf('%s/%s/%02d.flac', $this->downloads, $folder, $i), 'data');
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$dir/$f";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
