<?php

declare(strict_types=1);

namespace YorvoID\Flows;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Flows\Event\FlowCompleted;
use YorvoID\Throttle\Throttled;

/**
 * Runs flows (PLT-070): starts them, shows their current step, and moves them on with what the person
 * sends. Steps run in their flow's order, and only the current one takes answers: a step can't be
 * skipped by sending another's.
 *
 * @api
 */
final class Flows
{
    /** @var array<string, Flow> */
    private array $flows = [];

    /**
     * @param iterable<Flow> $flows
     */
    public function __construct(
        iterable $flows,
        private readonly FlowStore $store,
        private readonly Sessions $sessions,
        private readonly EventDispatcherInterface $events = new NoEventListeners(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        foreach ($flows as $flow) {
            $this->flows[$flow->name] = $flow;
        }
    }

    /**
     * @throws IntegratorError If there's no such flow
     */
    public function flow(string $name): Flow
    {
        return $this->flows[$name] ?? throw new IntegratorError(\sprintf('There\'s no flow "%s".', $name), \sprintf('Start one of the flows: %s.', implode(', ', array_keys($this->flows))), 'concepts/flows.md#the-flows');
    }

    /**
     * Starts a flow, and prepares its first step.
     *
     * @param array<string, mixed> $data What its steps need, e.g. an invitation's token
     *
     * @throws IntegratorError If there's no such flow
     */
    public function start(string $flow, Realm $realm, FlowContext $context, array $data = []): Progress
    {
        $state = new FlowState(rtrim(strtr(base64_encode(Secret::random(32)->reveal()), '+/', '-_'), '='), $flow, $realm, $this->flow($flow)->lifetime->after($this->clock->now()));

        return $this->advance($state->with($data), $context);
    }

    /**
     * The flow's current step, to show again.
     *
     * @return Progress|null Null if there's no such flow, or it took too long
     */
    public function resume(string $id, FlowContext $context): ?Progress
    {
        $state = $this->load($id);

        if (null === $state) {
            return null;
        }

        $step = null === $state->current ? null : $this->flow($state->flow)->step($state->current);

        // A step an upgrade removed is passed over.
        return null === $step ? $this->advance($state, $context) : new Progress(FlowStatus::InProgress, $state, $step->name(), $step->view($state));
    }

    /**
     * Submits what the person sent for a step.
     *
     * @param string $step The step the answer is for: an answer for any other than the current one is refused
     *
     * @return Progress|null Null if there's no such flow, or it took too long
     */
    public function submit(string $id, string $step, Input $input, FlowContext $context): ?Progress
    {
        $state = $this->load($id);

        if (null === $state) {
            return null;
        }

        $current = null === $state->current ? null : $this->flow($state->flow)->step($state->current);

        if (null === $current || $step !== $current->name()) {
            $progress = $this->resume($id, $context);
            \assert(null !== $progress);

            return new Progress($progress->status, $progress->state, $progress->step, $progress->view, [new Problem('yorvoid.flow.out_of_date')]);
        }

        try {
            $result = $current->submit($state, $context, $input);
        } catch (Throttled $exception) {
            $result = StepResult::throttled(new Problem('yorvoid.flow.too_many_attempts', ['minutes' => (int) ceil($exception->seconds / 60)]), $exception->seconds);
        }

        if (null !== $result->failure) {
            $this->store->delete($state->id);

            return new Progress(FlowStatus::Failed, $state, failure: $result->failure);
        }

        if (!$result->done) {
            $state = $current->enter($state, $context);
            $this->store->save($state);

            return new Progress(FlowStatus::InProgress, $state, $current->name(), $current->view($state), $result->problems, $result->retryAfter);
        }

        $state = $state->with($result->data)->without($result->forget)->passing($current->name());

        if (null !== $result->signedIn) {
            $context = $context->withSession($result->signedIn->session);
        }

        if (null !== $result->renewedToken) {
            $context = $context->withSession($this->sessions->validate($state->realm, $result->renewedToken));
        }

        return $this->advance($state, $context, $result->signedIn, $result->renewedToken);
    }

    /**
     * Moves on to the first step not done or passed over that applies, and prepares it; or, after the
     * last, completes the flow.
     */
    private function advance(FlowState $state, FlowContext $context, ?StartedSession $signedIn = null, ?Secret $renewedToken = null): Progress
    {
        foreach ($this->flow($state->flow)->steps as $step) {
            if (\in_array($step->name(), $state->passed, true)) {
                continue;
            }

            if (!$step->applies($state, $context)) {
                $state = $state->passing($step->name());

                continue;
            }

            $state = $step->enter($state->at($step->name()), $context);
            $this->store->save($state);

            return new Progress(FlowStatus::InProgress, $state, $step->name(), $step->view($state), signedIn: $signedIn, renewedToken: $renewedToken);
        }

        $this->store->delete($state->id);
        $this->events->dispatch(new FlowCompleted($state, $context->origin));

        return new Progress(FlowStatus::Completed, $state, signedIn: $signedIn, renewedToken: $renewedToken);
    }

    private function load(string $id): ?FlowState
    {
        $state = $this->store->get($id);

        if (null !== $state && $this->clock->now() >= $state->expiresAt) {
            $this->store->delete($id);

            return null;
        }

        return $state;
    }
}
