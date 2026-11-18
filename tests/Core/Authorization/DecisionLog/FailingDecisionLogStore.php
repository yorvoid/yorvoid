<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\DecisionLog;

use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore;
use YorvoID\Core\Realm;

/**
 * Counts its writes, and fails them when told to.
 */
final class FailingDecisionLogStore implements DecisionLogStore
{
    public int $writes = 0;

    public bool $failing = false;

    public function __construct(
        public readonly InMemoryDecisionLogStore $records = new InMemoryDecisionLogStore(),
    ) {
    }

    public function add(array $records, ?bool $apart = null): void
    {
        if ($this->failing) {
            throw new \RuntimeException('The database went away.');
        }

        ++$this->writes;
        $this->records->add($records, $apart);
    }

    public function find(Realm $realm, string $id): ?DecisionRecord
    {
        return $this->records->find($realm, $id);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        return $this->records->prune($before);
    }

    public function now(): int
    {
        return $this->records->now();
    }

    public function written(Realm $realm, int $after, int $until): array
    {
        return $this->records->written($realm, $after, $until);
    }

    public function digestedUntil(Realm $realm): int
    {
        return $this->records->digestedUntil($realm);
    }

    public function digested(Realm $realm, int $until): void
    {
        $this->records->digested($realm, $until);
    }

    public function prunedBefore(): int
    {
        return $this->records->prunedBefore();
    }
}
