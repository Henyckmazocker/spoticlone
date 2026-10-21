<?php

declare(strict_types=1);

namespace App\Domain\Repository\Music;

interface ScanRepositoryInterface
{
    /** @return int new scan_run id (status = running). */
    public function start(): int;

    public function finish(int $runId, array $stats, string $status = 'completed', ?string $message = null): void;

    /**
     * Try to take the single scan lock (MySQL GET_LOCK, bound to the DB connection: it is released
     * if the process dies). Waits up to $timeoutSeconds; 0 fails immediately.
     */
    public function acquireLock(int $timeoutSeconds): bool;

    public function releaseLock(): void;

    /** @return array<string, mixed>|null The most recent scan run, or null if never scanned. */
    public function latest(): ?array;
}
