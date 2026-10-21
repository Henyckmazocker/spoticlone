<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Services\DownloadImporter;
use App\Domain\Services\LibraryScanner;
use PHPUnit\Framework\TestCase;

class DownloadImporterTest extends TestCase
{
    private string $downloads;
    private string $music;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/spoticlone_imp_' . uniqid();
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

    /** Rutas que recibió scan(), para comprobar que el escaneo es incremental. */
    private array $scanned = [];

    private function makeImporter(): DownloadImporter
    {
        // Stub scanner: skip the real (DB-backed) constructor and no-op scan().
        $scanned = &$this->scanned;
        $scanner = new class ($scanned) extends LibraryScanner {
            /** @param array<int, string|null> $scanned */
            public function __construct(private array &$scanned) {}
            public function scan(?string $onlyPath = null): array
            {
                $this->scanned[] = $onlyPath;
                return ['files_seen' => 0, 'tracks_added' => 0];
            }
        };
        return new DownloadImporter($scanner);
    }

    public function test_imports_folder_into_artist_subdir(): void
    {
        mkdir($this->downloads . '/Some Album', 0775, true);
        file_put_contents($this->downloads . '/Some Album/01 - track.flac', 'data');

        $res = $this->makeImporter()->import('Some Album', 'The Artist');

        $this->assertFileExists($this->music . '/The Artist/Some Album/01 - track.flac');
        $this->assertDirectoryDoesNotExist($this->downloads . '/Some Album'); // moved, not copied
        $this->assertStringContainsString($this->music . '/The Artist/Some Album', $res['moved_to']);
        $this->assertArrayHasKey('scan', $res);
    }

    public function test_uses_album_title_for_the_destination_folder(): void
    {
        // slskd names the local folder after the remote directory, which is often junk
        // ("2010 - flac (log+cue)"). The album from the Spotify selection is what we file it under.
        mkdir($this->downloads . '/1998-remaster-flac', 0775, true);
        file_put_contents($this->downloads . '/1998-remaster-flac/01.flac', 'data');

        $res = $this->makeImporter()->import('1998-remaster-flac', 'The Artist', 'The Album');

        $this->assertSame($this->music . '/The Artist/The Album', $res['moved_to']);
        $this->assertFileExists($this->music . '/The Artist/The Album/01.flac');
    }

    public function test_nests_disc_folders_under_the_album(): void
    {
        mkdir($this->downloads . '/CD1', 0775, true);
        file_put_contents($this->downloads . '/CD1/01.flac', 'data');

        $res = $this->makeImporter()->import('CD1', 'The Artist', 'The Album');

        $this->assertSame($this->music . '/The Artist/The Album/CD1', $res['moved_to']);
        $this->assertFileExists($this->music . '/The Artist/The Album/CD1/01.flac');
    }

    public function test_same_disc_folder_name_from_two_albums_does_not_collide(): void
    {
        // The old code keyed the destination on the download folder's basename, so the second
        // "CD1" landed on the first one's path and was silently skipped as already imported.
        mkdir($this->downloads . '/CD1', 0775, true);
        file_put_contents($this->downloads . '/CD1/a.flac', 'album one');
        mkdir($this->downloads . '/other/CD1', 0775, true);
        file_put_contents($this->downloads . '/other/CD1/b.flac', 'album two');

        $importer = $this->makeImporter();
        $first = $importer->import('CD1', 'The Artist', 'Album One');
        $second = $importer->import('other/CD1', 'The Artist', 'Album Two');

        $this->assertNotSame($first['moved_to'], $second['moved_to']);
        $this->assertFalse($second['already_imported']);
        $this->assertFileExists($this->music . '/The Artist/Album One/CD1/a.flac');
        $this->assertFileExists($this->music . '/The Artist/Album Two/CD1/b.flac');
    }

    public function test_finds_the_download_when_slskd_nests_it_under_a_username(): void
    {
        mkdir($this->downloads . '/some-user/Some Album', 0775, true);
        file_put_contents($this->downloads . '/some-user/Some Album/01.flac', 'data');

        $res = $this->makeImporter()->import('Some Album', 'The Artist', 'The Album');

        $this->assertFileExists($this->music . '/The Artist/The Album/01.flac');
        $this->assertFalse($res['already_imported']);
    }

    public function test_rescans_only_the_imported_folder(): void
    {
        mkdir($this->downloads . '/Some Album', 0775, true);
        file_put_contents($this->downloads . '/Some Album/01.flac', 'data');

        $res = $this->makeImporter()->import('Some Album', 'The Artist', 'The Album');

        // Not a full-library scan: getID3 over the whole collection to pick up one album is
        // exactly what the incremental argument exists to avoid.
        $this->assertSame([$res['moved_to']], $this->scanned);
    }

    public function test_rejects_sibling_root_with_matching_prefix(): void
    {
        // "…/downloads-otro" is NOT inside "…/downloads": the old strpos() prefix check accepted it.
        mkdir(dirname($this->downloads) . '/downloads-otro/Album', 0775, true);
        file_put_contents(dirname($this->downloads) . '/downloads-otro/Album/x.flac', 'data');

        $this->expectException(\RuntimeException::class);
        $this->makeImporter()->import('../downloads-otro/Album', 'The Artist');
    }

    public function test_failed_import_leaves_nothing_behind(): void
    {
        // Un fichero ocupa el sitio de la carpeta de artista, así que el destino no se puede crear.
        mkdir($this->downloads . '/Some Album', 0775, true);
        file_put_contents($this->downloads . '/Some Album/01.flac', 'data');
        file_put_contents($this->music . '/The Artist', 'not a directory');

        try {
            $this->makeImporter()->import('Some Album', 'The Artist', 'The Album');
            $this->fail('Se esperaba una RuntimeException');
        } catch (\RuntimeException $e) {
            // La descarga sigue intacta, no se escaneó nada y no queda rastro en la biblioteca:
            // un import a medias es lo que antes se daba luego por "ya importado".
            $this->assertFileExists($this->downloads . '/Some Album/01.flac');
            $this->assertSame([], $this->scanned);
            $this->assertSame([], glob($this->music . '/*/.import-*') ?: []);
            $this->assertFileDoesNotExist($this->music . '/The Artist/The Album');
        }
    }

    public function test_rejects_path_traversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->makeImporter()->import('../../etc/passwd', 'X');
    }

    public function test_rejects_missing_download(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->makeImporter()->import('does-not-exist', 'X');
    }

    public function test_skips_when_already_imported(): void
    {
        mkdir($this->downloads . '/Dup', 0775, true);
        file_put_contents($this->downloads . '/Dup/a.flac', 'one');
        mkdir($this->music . '/Artist/Dup', 0775, true); // destination already exists => already imported

        $res = $this->makeImporter()->import('Dup', 'Artist');

        $this->assertTrue($res['already_imported']);
        $this->assertSame($this->music . '/Artist/Dup', $res['moved_to']);
        $this->assertNull($res['scan']); // no rescan when skipped
        // No "(2)" duplicate is created, and the source download is left untouched.
        $this->assertDirectoryDoesNotExist($this->music . '/Artist/Dup (2)');
        $this->assertFileExists($this->downloads . '/Dup/a.flac');
    }

    public function test_discard_refuses_the_downloads_root(): void
    {
        mkdir($this->downloads . '/Some Album', 0775, true);
        file_put_contents($this->downloads . '/Some Album/01.flac', 'data');
        $importer = $this->makeImporter();

        foreach (['.', '', '/'] as $rel) {
            $this->assertFalse($importer->discard($rel), "discard('$rel')");
        }
        $this->assertFileExists($this->downloads . '/Some Album/01.flac');
        $this->assertDirectoryExists($this->downloads);
    }

    public function test_import_refuses_the_downloads_root(): void
    {
        mkdir($this->downloads . '/Some Album', 0775, true);
        file_put_contents($this->downloads . '/Some Album/01.flac', 'data');

        try {
            $this->makeImporter()->import('.', 'The Artist');
            $this->fail('Se esperaba una RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertFileExists($this->downloads . '/Some Album/01.flac');
            $this->assertSame([], $this->scanned);
        }
    }

    public function test_sanitize_turns_dots_into_the_default_name(): void
    {
        $sanitize = new \ReflectionMethod(DownloadImporter::class, 'sanitize');
        $importer = $this->makeImporter();

        $this->assertSame('Soulseek', $sanitize->invoke($importer, '..'));
        $this->assertSame('Soulseek', $sanitize->invoke($importer, ' . '));
        $this->assertSame('Vol', $sanitize->invoke($importer, 'Vol.'));
    }

    public function test_dotdot_album_lands_in_the_default_folder(): void
    {
        mkdir($this->downloads . '/Some Album', 0775, true);
        file_put_contents($this->downloads . '/Some Album/01.flac', 'data');

        $res = $this->makeImporter()->import('Some Album', 'The Artist', '..');

        $this->assertSame($this->music . '/The Artist/Soulseek', $res['moved_to']);
        $this->assertFileExists($this->music . '/The Artist/Soulseek/01.flac');
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
