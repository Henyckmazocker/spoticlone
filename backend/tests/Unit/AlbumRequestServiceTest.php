<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\Music\AlbumRepositoryInterface;
use App\Domain\Repository\Music\AlbumRequestRepositoryInterface;
use App\Domain\Services\AlbumRequestException;
use App\Domain\Services\AlbumRequestService;
use App\Domain\Services\LibraryMatcher;
use App\Infrastructure\Music\SpotifyClient;
use PHPUnit\Framework\TestCase;

/**
 * AlbumRequestService with an in-memory repository, the real LibraryMatcher over a mocked album
 * repository (LibraryMatcher is final) and a mocked SpotifyClient.
 */
class AlbumRequestServiceTest extends TestCase
{
    private const LIBRARY = [
        ['id' => 12, 'title' => 'Abbey Road', 'missing_at' => null, 'artist_name' => 'The Beatles'],
        ['id' => 30, 'title' => 'OK Computer', 'missing_at' => '2026-10-01 10:00:00', 'artist_name' => 'Radiohead'],
    ];

    private const SPOTIFY = [
        'kid-a'   => ['id' => 'kid-a', 'title' => 'Kid A', 'artist' => 'Radiohead', 'year' => 2000, 'cover' => 'https://i.scdn.co/kida'],
        'abbey'   => ['id' => 'abbey', 'title' => 'Abbey Road', 'artist' => 'The Beatles', 'year' => 1969, 'cover' => null],
        'amnesia' => ['id' => 'amnesia', 'title' => 'Amnesiac', 'artist' => 'Radiohead', 'year' => 2001, 'cover' => null],
    ];

    private FakeAlbumRequestRepository $repo;
    private AlbumRequestService $service;

    protected function setUp(): void
    {
        $this->repo = new FakeAlbumRequestRepository();
        $this->service = $this->makeService(self::LIBRARY);
    }

    private function makeService(array $libraryRows): AlbumRequestService
    {
        $albums = $this->createMock(AlbumRepositoryInterface::class);
        $albums->method('allForMatching')->willReturn($libraryRows);
        $spotify = $this->createMock(SpotifyClient::class);
        $spotify->method('isEnabled')->willReturn(true);
        $spotify->method('getAlbum')->willReturnCallback(fn(string $id) => self::SPOTIFY[$id] ?? null);

        return new AlbumRequestService($this->repo, new LibraryMatcher($albums), $spotify);
    }

    private function assertRefused(int $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected AlbumRequestException');
        } catch (AlbumRequestException $e) {
            $this->assertSame($code, $e->getCode());
        }
    }

    public function test_new_request_takes_metadata_from_spotify(): void
    {
        $res = $this->service->request(2, 'kid-a');

        $this->assertFalse($res['joined']);
        $this->assertSame('Kid A', $res['request']['title']);
        $this->assertSame('Radiohead', $res['request']['artist']);
        $this->assertSame(2000, $res['request']['year']);
        $this->assertSame('pending', $res['request']['status']);
    }

    public function test_second_user_joins_the_existing_request(): void
    {
        $first = $this->service->request(2, 'kid-a');
        $second = $this->service->request(3, 'kid-a');

        $this->assertTrue($second['joined']);
        $this->assertSame($first['request']['id'], $second['request']['id']);
        $this->assertCount(1, $this->repo->rows);
        $this->assertSame(2, $this->repo->countUsers($first['request']['id']));
    }

    public function test_unknown_spotify_id_is_404(): void
    {
        $this->assertRefused(404, fn() => $this->service->request(2, 'nope'));
    }

    public function test_limit_of_ten_open_requests(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->repo->seed("x{$i}", 'pending', [2]);
        }

        $this->assertRefused(429, fn() => $this->service->request(2, 'kid-a'));
        $this->assertCount(10, $this->repo->rows);
        // Another user can still join one of the existing ones, and user 2 asking again for a
        // request they already have is not "new for them".
        $this->service->request(3, 'x0');
        $this->repo->seed('kid-a', 'pending', [2]);
        $this->assertTrue($this->service->request(2, 'kid-a')['joined']);
    }

    public function test_closed_requests_do_not_count_towards_the_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->repo->seed("x{$i}", $i % 2 ? 'rejected' : 'fulfilled', [2]);
        }

        $this->assertFalse($this->service->request(2, 'kid-a')['joined']);
    }

    public function test_rejected_request_is_reopened_and_the_user_joins(): void
    {
        $id = $this->repo->seed('kid-a', 'rejected', [3], ['reject_reason' => 'No hay fuentes']);

        $res = $this->service->request(2, 'kid-a');

        $this->assertTrue($res['joined']);
        $this->assertSame('pending', $res['request']['status']);
        $this->assertNull($res['request']['reject_reason']);
        $this->assertNull($res['request']['resolved_at']);
        $this->assertSame(2, $this->repo->countUsers($id));
    }

    public function test_present_album_is_409_and_creates_nothing(): void
    {
        $this->assertRefused(409, fn() => $this->service->request(2, 'abbey'));
        $this->assertSame([], $this->repo->rows);
    }

    public function test_ghost_album_can_be_requested(): void
    {
        $this->assertFalse($this->service->request(2, 'kid-a')['joined']);
        $this->repo->rows = [];
        $service = $this->makeService([
            ['id' => 31, 'title' => 'Kid A', 'missing_at' => '2026-10-01 10:00:00', 'artist_name' => 'Radiohead'],
        ]);

        $this->assertFalse($service->request(2, 'kid-a')['joined']);
    }

    public function test_fulfilled_request_is_409(): void
    {
        $this->repo->seed('kid-a', 'fulfilled', [3]);

        $this->assertRefused(409, fn() => $this->service->request(2, 'kid-a'));
    }

    public function test_reconcile_fulfils_the_present_ones_and_leaves_the_rest(): void
    {
        $present = $this->repo->seed('abbey', 'pending', [2], ['artist' => 'Beatles', 'title' => 'abbey road']);
        $downloading = $this->repo->seed('abbey2', 'downloading', [2], ['artist' => 'The Beatles', 'title' => 'Abbey Road']);
        $absent = $this->repo->seed('kid-a', 'pending', [2], ['artist' => 'Radiohead', 'title' => 'Kid A']);
        $ghost = $this->repo->seed('ok', 'downloading', [2], ['artist' => 'Radiohead', 'title' => 'OK Computer']);
        $rejected = $this->repo->seed('rej', 'rejected', [2], ['artist' => 'The Beatles', 'title' => 'Abbey Road']);

        $this->service->reconcile();

        foreach ([$present, $downloading] as $id) {
            $this->assertSame('fulfilled', $this->repo->rows[$id]['status']);
            $this->assertSame(12, $this->repo->rows[$id]['album_id']);
            $this->assertNotNull($this->repo->rows[$id]['resolved_at']);
        }
        $this->assertSame('pending', $this->repo->rows[$absent]['status']);
        $this->assertSame('downloading', $this->repo->rows[$ghost]['status']);
        $this->assertSame('rejected', $this->repo->rows[$rejected]['status']);
    }

    public function test_listings_reconcile_first(): void
    {
        $id = $this->repo->seed('abbey', 'pending', [2], ['artist' => 'The Beatles', 'title' => 'Abbey Road']);

        $mine = $this->service->mine(2);

        $this->assertSame('fulfilled', $mine[0]['status']);
        $this->assertSame(12, $mine[0]['album_id']);
        $this->assertSame([], $this->service->all());
        $this->assertCount(1, $this->service->all(false));
        $this->assertSame($id, $this->service->all(false)[0]['id']);
    }

    public function test_cancelling_the_last_pending_request_deletes_the_row(): void
    {
        $id = $this->service->request(2, 'kid-a')['request']['id'];

        $this->service->cancel(2, $id);

        $this->assertArrayNotHasKey($id, $this->repo->rows);
    }

    public function test_cancelling_with_other_users_left_keeps_the_row(): void
    {
        $id = $this->service->request(2, 'kid-a')['request']['id'];
        $this->service->request(3, 'kid-a');

        $this->service->cancel(2, $id);

        $this->assertArrayHasKey($id, $this->repo->rows);
        $this->assertSame(1, $this->repo->countUsers($id));
    }

    public function test_cancelling_a_downloading_request_keeps_the_row(): void
    {
        $id = $this->repo->seed('kid-a', 'downloading', [2]);

        $this->service->cancel(2, $id);

        $this->assertArrayHasKey($id, $this->repo->rows);
    }

    public function test_cancelling_someone_elses_request_is_404(): void
    {
        $id = $this->repo->seed('kid-a', 'pending', [3]);

        $this->assertRefused(404, fn() => $this->service->cancel(2, $id));
        $this->assertArrayHasKey($id, $this->repo->rows);
    }

    public function test_admin_can_reject_with_a_reason_and_mark_fulfilled(): void
    {
        $id = $this->repo->seed('kid-a', 'pending', [2, 3]);

        $res = $this->service->resolve($id, 'rejected', 'Sin fuentes');
        $this->assertSame('rejected', $res['status']);
        $this->assertSame('Sin fuentes', $res['reject_reason']);
        $this->assertSame(2, $res['users_count']);

        $res = $this->service->resolve($id, 'fulfilled', 'ignored');
        $this->assertSame('fulfilled', $res['status']);
        $this->assertNull($res['reject_reason']);
    }

    public function test_resolve_validates_status_reason_and_existence(): void
    {
        $id = $this->repo->seed('kid-a', 'pending', [2]);

        $this->assertRefused(400, fn() => $this->service->resolve($id, 'pending', null));
        $this->assertRefused(400, fn() => $this->service->resolve($id, 'rejected', str_repeat('x', 256)));
        $this->assertRefused(404, fn() => $this->service->resolve(999, 'rejected', null));
    }
}

/** In-memory AlbumRequestRepositoryInterface; `$rows` is keyed by request id. */
class FakeAlbumRequestRepository implements AlbumRequestRepositoryInterface
{
    /** @var array<int, array> */
    public array $rows = [];
    /** @var array<int, int[]> request id => user ids */
    public array $users = [];
    private int $next = 1;

    public function seed(string $spotifyId, string $status, array $userIds, array $extra = []): int
    {
        $id = $this->next++;
        $this->rows[$id] = $extra + [
            'id' => $id, 'spotify_album_id' => $spotifyId, 'artist' => 'Radiohead', 'title' => 'Kid A',
            'year' => 2000, 'cover_url' => null, 'status' => $status, 'reject_reason' => null,
            'download_id' => null, 'album_id' => null, 'created_at' => '2026-10-21 10:00:00', 'resolved_at' => null,
        ];
        $this->users[$id] = $userIds;
        return $id;
    }

    public function findById(int $id): ?array
    {
        return isset($this->rows[$id])
            ? $this->rows[$id] + ['users_count' => count($this->users[$id]), 'requested_by' => []]
            : null;
    }

    public function findBySpotifyId(string $spotifyId): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['spotify_album_id'] === $spotifyId) {
                return $row;
            }
        }
        return null;
    }

    public function create(array $data): int
    {
        return $this->seed($data['spotify_album_id'], 'pending', [], $data);
    }

    public function attachUser(int $requestId, int $userId): void
    {
        $this->users[$requestId] = array_values(array_unique([...$this->users[$requestId], $userId]));
    }

    public function detachUser(int $requestId, int $userId): void
    {
        $this->users[$requestId] = array_values(array_diff($this->users[$requestId], [$userId]));
    }

    public function hasUser(int $requestId, int $userId): bool
    {
        return in_array($userId, $this->users[$requestId] ?? [], true);
    }

    public function countUsers(int $requestId): int
    {
        return count($this->users[$requestId] ?? []);
    }

    public function countPendingForUser(int $userId): int
    {
        $n = 0;
        foreach ($this->rows as $id => $row) {
            if (in_array($row['status'], ['pending', 'downloading'], true) && $this->hasUser($id, $userId)) {
                $n++;
            }
        }
        return $n;
    }

    public function listForUser(int $userId): array
    {
        return array_values(array_filter($this->rows, fn($r) => $this->hasUser($r['id'], $userId)));
    }

    public function listAll(?array $statuses = null): array
    {
        $rows = array_filter($this->rows, fn($r) => $statuses === null || in_array($r['status'], $statuses, true));
        return array_values(array_map(fn($r) => $this->findById($r['id']), $rows));
    }

    public function listOpen(): array
    {
        return $this->listAll(['pending', 'downloading']);
    }

    public function setStatus(int $id, string $status, array $extra = []): void
    {
        $this->rows[$id]['status'] = $status;
        foreach (['reject_reason', 'download_id', 'album_id'] as $col) {
            if (array_key_exists($col, $extra)) {
                $this->rows[$id][$col] = $extra[$col];
            }
        }
        if (array_key_exists('resolved_at', $extra)) {
            $this->rows[$id]['resolved_at'] = $extra['resolved_at'] === null ? null : '2026-10-21 12:00:00';
        }
    }

    public function delete(int $id): void
    {
        unset($this->rows[$id], $this->users[$id]);
    }
}
