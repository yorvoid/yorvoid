<?php

declare(strict_types=1);

namespace YorvoID\Core\Log;

/**
 * The decisions whose operations are running, innermost last: what the security log's entries cite.
 * Each is taken off when its operation ends, however it ends.
 *
 * @internal
 */
final class CurrentDecision
{
    /** @var list<array{id: string, hash?: string}> */
    private array $stack = [];

    /**
     * @param array{id: string, hash?: string} $decision
     */
    public function push(array $decision): void
    {
        $this->stack[] = $decision;
    }

    public function pop(): void
    {
        array_pop($this->stack);
    }

    /**
     * @return array{id: string, hash?: string}|null
     */
    public function current(): ?array
    {
        return [] === $this->stack ? null : $this->stack[\count($this->stack) - 1];
    }

    /**
     * Between requests of a long-running process: nothing is running.
     */
    public function reset(): void
    {
        $this->stack = [];
    }
}
