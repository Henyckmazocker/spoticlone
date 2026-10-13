<?php

declare(strict_types=1);

namespace App\Domain\Repository\Music;

interface ArtistRepositoryInterface
{
    /** Insert the artist if new, return its id either way (idempotent by name). */
    public function upsertByName(string $name): int;

    /** @return array<int, array<string, mixed>> All artists ordered by sort name. */
    public function findAll(): array;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    public function setImageUrl(int $id, ?string $url): void;

    /** @return array<int, array<string, mixed>> Artists whose name matches the term. */
    public function search(string $term, int $limit = 25): array;

    /** Delete the artist row (cascades to its albums and tracks). Returns true if a row was removed. */
    public function delete(int $id): bool;
}
