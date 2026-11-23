<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\Storage;

use YorvoID\Core\Authorization\VersionStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;

/**
 * The versions in memory, for tests and single-process tools.
 *
 * @implements InMemoryStore<array<string, array<string, int>>>
 *
 * @api
 */
final class InMemoryVersionStore implements VersionStore, InMemoryStore
{
    /** @var array<string, array<string, int>> By realm, then source */
    private array $versions = [];

    public function versions(Realm $realm, array $sources): array
    {
        $versions = [];

        foreach ($sources as $source) {
            $versions[$source] = $this->versions[$realm->name][$source] ?? 0;
        }

        return $versions;
    }

    public function bump(Realm $realm, string $source): int
    {
        return $this->versions[$realm->name][$source] = ($this->versions[$realm->name][$source] ?? 0) + 1;
    }

    public function snapshot(): mixed
    {
        return $this->versions;
    }

    public function restore(mixed $snapshot): void
    {
        /* @var array<string, array<string, int>> $snapshot */
        $this->versions = $snapshot;
    }
}
