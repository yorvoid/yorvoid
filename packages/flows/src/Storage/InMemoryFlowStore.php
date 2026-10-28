<?php

declare(strict_types=1);

namespace YorvoID\Flows\Storage;

use YorvoID\Flows\FlowState;
use YorvoID\Flows\FlowStore;

/**
 * For tests, and for flows that end in the request they began in.
 *
 * @api
 */
final class InMemoryFlowStore implements FlowStore
{
    /** @var array<string, FlowState> */
    private array $states = [];

    public function get(string $id): ?FlowState
    {
        return $this->states[$id] ?? null;
    }

    public function save(FlowState $state): void
    {
        $this->states[$state->id] = $state;
    }

    public function delete(string $id): void
    {
        unset($this->states[$id]);
    }
}
