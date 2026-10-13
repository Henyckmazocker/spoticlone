<?php

declare(strict_types=1);

namespace App\Domain\Repository\Music;

interface ScanRepositoryInterface
{
    /** @return int new scan_run id (status = running). */
    public function start(): int;

    public function finish(int $runId, array $stats, string $status = 'completed', ?string $message = null): void;

    /** @return array<string, mixed>|null The most recent scan run, or null if never scanned. */
    public function latest(): ?array;
}
