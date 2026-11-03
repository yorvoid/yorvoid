<?php

declare(strict_types=1);

namespace YorvoID\Flows;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Authn\StepUp\HeldAfterRecovery;
use YorvoID\Authn\StepUp\StepUpRequired;
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
 * A session a step starts is held back until every required step is done: the steps run in it, but the
 * person gets it only then. So a required step after signing in, such as adding the owner's passkey or
 * accepting terms, can't be skipped by leaving the flow; steps that aren't required don't hold it back.
 *
 * @api
 */
final class Flows
{
    /** Where the state keeps a session a step started, until the flow completes */
    private const string TOKEN = 'flows.signed_in.token';

    private const string DEVICE_COOKIE = 'flows.signed_in.device_cookie';

    private const string NEW_DEVICE = 'flows.signed_in.new_device';

    private const string CONFIRM_AGAIN = 'yorvoid.flow.confirm_again';

    private const string HELD = 'yorvoid.flow.held_after_recovery';

    /** @var array<string, Flow> */
    private array $flows = [];

    /**
     * @param iterable<Flow> $flows
     * @param Attempts|null  $attempts Limits how many flows one address starts, as for the forms that check secrets:
     *                                 each flow is stored, and the first step may begin a ceremony
     */
    public function __construct(
        iterable $flows,
        private readonly FlowStore $store,
        private readonly Sessions $sessions,
        private readonly EventDispatcherInterface $events = new NoEventListeners(),
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly ?Attempts $attempts = null,
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
     * @throws Throttled       When the context's address started too many flows; nothing was started
     */
    public function start(string $flow, Realm $realm, FlowContext $context, array $data = []): Progress
    {
        $this->attempts?->count('flow_start', $realm, null, $context->address);
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
        $context = $this->signedIn($state, $context);

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

        $context = $this->signedIn($state, $context);

        try {
            $result = $current->submit($state, $context, $input);
        } catch (Throttled $exception) {
            $result = StepResult::throttled(new Problem('yorvoid.flow.too_many_attempts', ['minutes' => (int) ceil($exception->seconds / 60)]), $exception->seconds);
        } catch (StepUpRequired) {
            $result = StepResult::refused(new Problem(self::CONFIRM_AGAIN));
        } catch (HeldAfterRecovery) {
            $result = StepResult::failed(new Problem(self::HELD));
        }

        if (null !== $result->failure) {
            return $this->fail($state, $result->failure);
        }

        if ([] !== $result->reopen) {
            $progress = $this->advance($state->reopening($result->reopen), $context);

            return new Progress($progress->status, $progress->state, $progress->step, $progress->view, $result->problems, $progress->retryAfter, $progress->signedIn, $progress->renewedToken, $progress->failure);
        }

        if (!$result->done) {
            try {
                $state = $this->enter($current, $state, $context);
            } catch (StepFailed $failed) {
                return $this->fail($state, $failed->problem);
            }

            $this->store->save($state);

            return new Progress(FlowStatus::InProgress, $state, $current->name(), $current->view($state), $result->problems, $result->retryAfter);
        }

        self::refuseReservedKeys($current, [...array_keys($result->data), ...$result->forget]);
        $state = $state->with($result->data)->without($result->forget)->passing($current->name());

        if (null !== $result->signedIn) {
            $context = $context->withSession($result->signedIn->session);
            $state = $state->with([
                self::TOKEN => $result->signedIn->token->reveal(),
                self::DEVICE_COOKIE => $result->signedIn->deviceCookie?->reveal(),
                self::NEW_DEVICE => $result->signedIn->newDevice,
            ]);
        }

        $renewed = $result->renewedToken;

        if (null !== $renewed) {
            $context = $context->withSession($this->sessions->validate($state->realm, $renewed));

            // Renewing the session the flow holds back: the person gets the new token with it.
            if (null !== $state->string(self::TOKEN)) {
                $state = $state->with([self::TOKEN => $renewed->reveal()]);
                $renewed = null;
            }
        }

        return $this->advance($state, $context, $renewed);
    }

    /**
     * The request's context, in the session a step of the flow started, if one did.
     */
    private function signedIn(FlowState $state, FlowContext $context): FlowContext
    {
        $token = $state->string(self::TOKEN);

        return null === $token ? $context : $context->withSession($this->sessions->validate($state->realm, Secret::fromString($token)));
    }

    /**
     * Moves on to the first step not done or passed over that applies, and prepares it; or, after the
     * last, completes the flow.
     */
    private function advance(FlowState $state, FlowContext $context, ?Secret $renewedToken = null): Progress
    {
        $flow = $this->flow($state->flow);

        foreach ($flow->steps as $step) {
            if (\in_array($step->name(), $state->passed, true)) {
                continue;
            }

            if (!$step->applies($state, $context)) {
                $state = $state->passing($step->name());

                continue;
            }

            [$state, $signedIn] = [] === array_diff($flow->required, $state->passed) ? $this->release($state, $context) : [$state, null];

            try {
                $state = $this->enter($step, $state->at($step->name()), $context);
            } catch (StepFailed $failed) {
                return $this->fail($state, $failed->problem);
            }

            $this->store->save($state);

            return new Progress(FlowStatus::InProgress, $state, $step->name(), $step->view($state), signedIn: $signedIn, renewedToken: $renewedToken);
        }

        $this->store->delete($state->id);
        [$state, $signedIn] = $this->release($state, $context);
        $this->events->dispatch(new FlowCompleted($state, $context->origin));

        return new Progress(FlowStatus::Completed, $state, signedIn: $signedIn, renewedToken: $renewedToken);
    }

    /**
     * The session a step started, for the person, now that every required step is done; unless it ended
     * meanwhile. The state forgets it.
     *
     * @return array{FlowState, StartedSession|null}
     */
    private function release(FlowState $state, FlowContext $context): array
    {
        $token = $state->string(self::TOKEN);
        $deviceCookie = $state->string(self::DEVICE_COOKIE);
        $signedIn = null === $token || null === $context->session ? null : new StartedSession($context->session, Secret::fromString($token), null === $deviceCookie ? null : Secret::fromString($deviceCookie), true === $state->get(self::NEW_DEVICE));

        return [$state->without([self::TOKEN, self::DEVICE_COOKIE, self::NEW_DEVICE]), $signedIn];
    }

    /**
     * A step that can't be prepared fails the flow, with a problem rather than an error: a step-up's proof
     * that lapsed, or an operation held after a recovery, included.
     *
     * @throws StepFailed
     */
    private function enter(Step $step, FlowState $state, FlowContext $context): FlowState
    {
        try {
            return $step->enter($state, $context);
        } catch (StepUpRequired) {
            throw new StepFailed(new Problem(self::CONFIRM_AGAIN));
        } catch (HeldAfterRecovery) {
            throw new StepFailed(new Problem(self::HELD));
        }
    }

    /**
     * A step keeps its data under a prefix of its own: "flows." is where Flows keeps a session it holds
     * back, and "yorvoid." is YorvoID's for later.
     *
     * @param list<array-key> $keys
     *
     * @throws IntegratorError
     */
    private static function refuseReservedKeys(Step $step, array $keys): void
    {
        foreach ($keys as $key) {
            if (str_starts_with((string) $key, 'flows.') || str_starts_with((string) $key, 'yorvoid.')) {
                throw new IntegratorError(\sprintf('The step "%s" keeps "%s" in the flow\'s state: "flows." and "yorvoid." are reserved.', $step->name(), $key), 'Keep a step\'s data under a prefix of your own, e.g. its name: "acme_terms.accepted".', 'concepts/flows.md#writing-a-step');
            }
        }
    }

    private function fail(FlowState $state, Problem $failure): Progress
    {
        $this->store->delete($state->id);

        return new Progress(FlowStatus::Failed, $state, failure: $failure);
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
