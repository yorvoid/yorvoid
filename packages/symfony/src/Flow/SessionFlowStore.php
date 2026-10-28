<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Flow;

use Symfony\Component\HttpFoundation\RequestStack;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\FlowStore;

/**
 * Keeps flows in the PHP session, so they run in requests: a flow continues only in the browser it began in, and its ID, long
 * and random, can't be guessed by a page elsewhere. The newest few are kept.
 *
 * @internal
 */
final readonly class SessionFlowStore implements FlowStore
{
    public const string KEY = 'yorvoid_flows';

    /** How many flows one browser keeps going at once */
    public const int LIMIT = 5;

    public function __construct(
        private RequestStack $requests,
    ) {
    }

    public function get(string $id): ?FlowState
    {
        return FlowState::fromArray($this->flows()[$id] ?? null);
    }

    public function save(FlowState $state): void
    {
        $flows = $this->flows();
        unset($flows[$state->id]);
        $flows[$state->id] = $state->toArray();
        $this->requests->getSession()->set(self::KEY, \array_slice($flows, -self::LIMIT, preserve_keys: true));
    }

    public function delete(string $id): void
    {
        $flows = $this->flows();
        unset($flows[$id]);
        $this->requests->getSession()->set(self::KEY, $flows);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function flows(): array
    {
        $flows = $this->requests->getSession()->get(self::KEY);

        return \is_array($flows) ? $flows : [];
    }
}
