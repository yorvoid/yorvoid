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

    public function add(array $records): void
    {
        if ($this->failing) {
            throw new \RuntimeException('The database went away.');
        }

        ++$this->writes;
        $this->records->add($records);
    }

    public function find(Realm $realm, string $id): ?DecisionRecord
    {
        return $this->records->find($realm, $id);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        return $this->records->prune($before);
    }
}
