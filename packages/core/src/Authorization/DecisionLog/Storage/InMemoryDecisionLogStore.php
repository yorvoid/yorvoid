<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog\Storage;

use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Realm;

/**
 * Decisions in memory. A unit of work that fails doesn't undo them, as in the database, where they're
 * written apart from the change they permitted.
 *
 * @api
 */
final class InMemoryDecisionLogStore implements DecisionLogStore
{
    /** @var array<string, array<string, DecisionRecord>> By realm, then ID */
    private array $records = [];

    public function add(array $records): void
    {
        foreach ($records as $record) {
            $this->records[$record->realm->name][$record->id] = $record;
        }
    }

    public function find(Realm $realm, string $id): ?DecisionRecord
    {
        return $this->records[$realm->name][$id] ?? null;
    }

    public function prune(\DateTimeImmutable $before): int
    {
        $removed = 0;

        foreach ($this->records as $realm => $records) {
            foreach ($records as $id => $record) {
                if ($record->at < $before) {
                    unset($this->records[$realm][$id]);
                    ++$removed;
                }
            }
        }

        return $removed;
    }
}
