<?php

declare(strict_types=1);

namespace YorvoID\Core\Log\Storage;

use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;

/**
 * @implements InMemoryStore<array<string, list<LogEntry>>>
 *
 * @api
 */
final class InMemorySecurityLogStore implements SecurityLogStore, InMemoryStore
{
    /** @var array<string, list<LogEntry>> By realm, in order */
    private array $entries = [];

    public function append(Realm $realm, \Closure $build): LogEntry
    {
        $head = $this->head($realm);
        $entry = $build(null === $head ? 1 : $head['sequence'] + 1, $head['hash'] ?? null);
        $this->entries[$realm->name][] = $entry;

        return $entry;
    }

    public function entries(Realm $realm, int $fromSequence): iterable
    {
        foreach ($this->entries[$realm->name] ?? [] as $entry) {
            if ($entry->sequence >= $fromSequence) {
                yield $entry;
            }
        }
    }

    public function head(Realm $realm): ?array
    {
        $entries = $this->entries[$realm->name] ?? [];
        $last = [] === $entries ? null : $entries[\count($entries) - 1];

        return null === $last ? null : ['sequence' => $last->sequence, 'hash' => $last->hash];
    }

    public function snapshot(): mixed
    {
        return $this->entries;
    }

    public function restore(mixed $snapshot): void
    {
        $this->entries = $snapshot;
    }
}
