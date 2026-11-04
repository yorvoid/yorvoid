<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Flow;

use Symfony\Component\HttpFoundation\RequestStack;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\FlowStore;

/**
 * Keeps flows in the PHP session, so they run in requests: a flow continues only in the browser it began in, and its ID, long
 * and random, can't be guessed by a page elsewhere. A few are kept: see save().
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

    /**
     * Beyond the limit, the flow that goes is one nobody got anywhere in: the newest that hasn't passed
     * a step, other than the one saved. Links from other sites can start flows (a top-level GET carries
     * the session cookie), but they don't push out the one the person is in, nor the page they opened
     * first. Only when every flow has passed a step does the least recently used go.
     */
    public function save(FlowState $state): void
    {
        $flows = $this->flows();

        // A flow that got somewhere is the latest used; one that didn't keeps its place.
        if ([] !== $state->passed) {
            unset($flows[$state->id]);
        }

        $flows[$state->id] = $state->toArray();

        while (\count($flows) > self::LIMIT) {
            unset($flows[self::leaving($flows, $state->id)]);
        }

        $this->requests->getSession()->set(self::KEY, $flows);
    }

    public function delete(string $id): void
    {
        $flows = $this->flows();
        unset($flows[$id]);
        $this->requests->getSession()->set(self::KEY, $flows);
    }

    /**
     * @param array<array-key, mixed> $flows Flows that passed a step in the order they were last saved, others in the order they began
     */
    private static function leaving(array $flows, string $saved): int|string
    {
        $others = array_filter($flows, static fn (int|string $id): bool => $id !== $saved, \ARRAY_FILTER_USE_KEY);

        foreach (array_reverse($others, true) as $id => $flow) {
            $state = FlowState::fromArray($flow);

            if (null === $state || [] === $state->passed) {
                return $id;
            }
        }

        return array_key_first($others) ?? $saved;
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
