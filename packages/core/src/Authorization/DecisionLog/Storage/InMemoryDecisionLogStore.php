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

    /** @var array<string, array<string, int>> When each was written, in microseconds: by realm, then ID */
    private array $written = [];

    /** @var array<string, int> */
    private array $digested = [];

    private int $prunedBefore = 0;

    public function add(array $records, ?bool $apart = null): void
    {
        foreach ($records as $record) {
            $this->records[$record->realm->name][$record->id] = $record;
            // In memory nothing is a caller's transaction: every record is written apart.
            $this->written[$record->realm->name][$record->id] = $this->now();
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

        $this->prunedBefore = max($this->prunedBefore, (int) $before->format('Uu'));

        return $removed;
    }

    public function now(): int
    {
        return (int) (microtime(true) * 1_000_000);
    }

    public function written(Realm $realm, int $after, int $until): array
    {
        $written = array_filter($this->written[$realm->name] ?? [], fn (int $at, string $id): bool => $at > $after && $at <= $until && isset($this->records[$realm->name][$id]), \ARRAY_FILTER_USE_BOTH);
        asort($written);

        return array_map(fn (string $id): array => [$id, $this->records[$realm->name][$id]->hash()], array_map('strval', array_keys($written)));
    }

    public function digestedUntil(Realm $realm): int
    {
        return $this->digested[$realm->name] ?? 0;
    }

    public function digested(Realm $realm, int $until): void
    {
        $this->digested[$realm->name] = $until;
    }

    public function prunedBefore(): int
    {
        return $this->prunedBefore;
    }
}
