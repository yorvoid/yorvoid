<?php

declare(strict_types=1);

namespace YorvoID\Tests\Flows;

use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Flows\Event\FlowCompleted;
use YorvoID\Flows\Flow;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\Flows;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\FlowStatus;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\Storage\InMemoryFlowStore;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Throttle\Throttled;

final class FlowsTest extends DatabaseTestCase
{
    private MockClock $clock;

    private InMemoryFlowStore $store;

    private RecordingDispatcher $events;

    private Sessions $sessions;

    private Principals $principals;

    private FlowContext $context;

    protected function setUp(): void
    {
        $stack = $this->stack('memory');
        $this->clock = new MockClock('2026-10-28 18:00:00 UTC');
        $this->store = new InMemoryFlowStore();
        $this->events = new RecordingDispatcher();
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($stack->log, $stack->transactions, $this->events, $ids, $this->clock);
        $this->principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions, ids: $ids, clock: $this->clock);
        $this->sessions = new Sessions($stack->sessions, $this->principals, $log, $stack->transactions, ids: $ids, clock: $this->clock);
        $this->context = new FlowContext(new Origin(OriginKind::Web));
    }

    public function testRunsTheStepsInOrderAndCompletes(): void
    {
        $first = new TestStep('first', static fn (FlowState $state, FlowContext $context, Input $input): StepResult => StepResult::done(['name' => $input->string('name'), 'scratch' => 'x']));
        $second = new TestStep('second', static fn (): StepResult => StepResult::done(forget: ['scratch']));
        $flows = $this->flows(new Flow('acme.onboarding', [$first, $second]));

        $started = $flows->start('acme.onboarding', new Realm('acme'), $this->context, ['invited' => true]);

        self::assertSame([FlowStatus::InProgress, 'first', ['step' => 'first', 'entered' => 1]], [$started->status, $started->step, $started->view?->jsonSerialize()]);
        self::assertSame(['invited' => true, 'first.entered' => 1], $started->state->data);
        self::assertSame('first', $started->state->current);
        self::assertTrue($started->state->realm->equals(new Realm('acme')));
        self::assertEquals(new \DateTimeImmutable('2026-10-28 18:30:00 UTC'), $started->state->expiresAt, 'Flows take at most 30 minutes, unless the flow says otherwise.');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $started->state->id, '256 random bits.');
        self::assertNotSame($started->state->id, $flows->start('acme.onboarding', new Realm('acme'), $this->context)->state->id);
        self::assertEquals($started->state, $this->store->get($started->state->id));

        $next = $flows->submit($started->state->id, 'first', new Input(['name' => 'Ana']), $this->context);
        self::assertSame([FlowStatus::InProgress, 'second', []], [$next?->status, $next?->step, $next?->problems]);
        self::assertSame(['first'], $next?->state->passed);
        self::assertSame('Ana', $next->state->string('name'));
        self::assertSame([], $this->events->of(FlowCompleted::class));

        $done = $flows->submit($started->state->id, 'second', new Input(), $this->context);
        self::assertSame([FlowStatus::Completed, null, null], [$done?->status, $done?->step, $done?->view]);
        self::assertSame(['first', 'second'], $done?->state->passed);
        self::assertFalse($done->state->has('scratch'));
        self::assertNull($this->store->get($started->state->id), 'A completed flow is forgotten.');
        $completed = $this->events->of(FlowCompleted::class);
        self::assertCount(1, $completed);
        self::assertSame('Ana', $completed[0]->state->string('name'));
        self::assertSame($this->context->origin, $completed[0]->origin);
        self::assertNull($flows->submit($started->state->id, 'second', new Input(), $this->context));
    }

    public function testShowsTheCurrentStepAgain(): void
    {
        $flows = $this->flows(new Flow('acme.onboarding', [new TestStep('first')]));
        $started = $flows->start('acme.onboarding', new Realm('acme'), $this->context);

        $resumed = $flows->resume($started->state->id, $this->context);

        self::assertSame([FlowStatus::InProgress, 'first', ['step' => 'first', 'entered' => 1]], [$resumed?->status, $resumed?->step, $resumed?->view?->jsonSerialize()], 'Showing a step again doesn\'t prepare it again.');
        self::assertNull($flows->resume('unknown', $this->context));
    }

    public function testStepsThatDontApplyArePassedOverForGood(): void
    {
        $optional = new TestStep('optional', applies: static fn (FlowState $state): bool => true === $state->get('wanted'));
        $flows = $this->flows(new Flow('acme.onboarding', [new TestStep('first', static fn (): StepResult => StepResult::done(['wanted' => true])), $optional, new TestStep('last')]));
        $started = $flows->start('acme.onboarding', new Realm('acme'), $this->context);

        self::assertSame('optional', $flows->submit($started->state->id, 'first', new Input(), $this->context)?->step);

        $skipping = $this->flows(new Flow('acme.onboarding', [$optional, new TestStep('first', static fn (): StepResult => StepResult::done(['wanted' => true])), new TestStep('last')]));
        $other = $skipping->start('acme.onboarding', new Realm('acme'), $this->context);
        self::assertSame(['optional'], $other->state->passed);
        self::assertSame('last', $skipping->submit($other->state->id, 'first', new Input(), $this->context)?->step, 'A step passed over doesn\'t come back.');

        $none = $this->flows(new Flow('acme.nothing', [new TestStep('only', applies: static fn (): bool => false)]))->start('acme.nothing', new Realm('acme'), $this->context);
        self::assertSame(FlowStatus::Completed, $none->status);
    }

    public function testARefusedAnswerPreparesTheStepAgain(): void
    {
        $step = new TestStep('code', static fn (FlowState $state, FlowContext $context, Input $input): StepResult => 'right' === $input->string('code') ? StepResult::done() : StepResult::refused(new Problem('acme.code.wrong', field: 'code'), new Problem('acme.code.hint'), new Problem('acme.code.last')));
        $flows = $this->flows(new Flow('acme.code', [$step, new TestStep('last')]));
        $started = $flows->start('acme.code', new Realm('acme'), $this->context);

        $refused = $flows->submit($started->state->id, 'code', new Input(['code' => 'wrong']), $this->context);

        self::assertSame([FlowStatus::InProgress, 'code', null], [$refused?->status, $refused?->step, $refused?->retryAfter]);
        self::assertEquals([new Problem('acme.code.wrong', field: 'code'), new Problem('acme.code.hint'), new Problem('acme.code.last')], $refused?->problems);
        self::assertSame(2, $refused?->state->get('code.entered'));
        self::assertSame(['step' => 'code', 'entered' => 2], $refused->view?->jsonSerialize());
        self::assertSame(2, $this->store->get($started->state->id)?->get('code.entered'), 'Prepared again, and kept.');
        self::assertSame('last', $flows->submit($started->state->id, 'code', new Input(['code' => 'right']), $this->context)?->step);
    }

    public function testTooManyAttemptsAreRefusedUntilLater(): void
    {
        $flows = $this->flows(new Flow('acme.code', [new TestStep('code', static fn (): never => throw new Throttled(new \DateTimeImmutable(), 61))]));
        $started = $flows->start('acme.code', new Realm('acme'), $this->context);

        $refused = $flows->submit($started->state->id, 'code', new Input(), $this->context);

        self::assertSame(['minutes' => 2], $refused?->problems[0]->parameters);
        self::assertSame('yorvoid.flow.too_many_attempts', $refused->problems[0]->message);
        self::assertSame([FlowStatus::InProgress, 61], [$refused->status, $refused->retryAfter]);

        $exact = $this->flows(new Flow('acme.code', [new TestStep('code', static fn (): never => throw new Throttled(new \DateTimeImmutable(), 120))]));
        $other = $exact->start('acme.code', new Realm('acme'), $this->context);
        self::assertEquals([new Problem('yorvoid.flow.too_many_attempts', ['minutes' => 2])], $exact->submit($other->state->id, 'code', new Input(), $this->context)?->problems);
    }

    public function testAFailedFlowEnds(): void
    {
        $flows = $this->flows(new Flow('acme.invite', [new TestStep('accept', static fn (): StepResult => StepResult::failed(new Problem('acme.invite.gone'))), new TestStep('last')]));
        $started = $flows->start('acme.invite', new Realm('acme'), $this->context);

        $failed = $flows->submit($started->state->id, 'accept', new Input(), $this->context);

        self::assertSame([FlowStatus::Failed, null, null], [$failed?->status, $failed?->step, $failed?->view]);
        self::assertEquals(new Problem('acme.invite.gone'), $failed?->failure);
        self::assertFalse(StepResult::failed(new Problem('acme.invite.gone'))->done);
        self::assertSame(['a', 'b'], array_map(static fn (Problem $problem): string => $problem->message, StepResult::refused(new Problem('a'), extra: new Problem('b'))->problems), 'Problems are a list, however they were passed.');
        self::assertNull($this->store->get($started->state->id));
        self::assertSame([], $this->events->of(FlowCompleted::class));
    }

    public function testOnlyTheCurrentStepTakesAnswers(): void
    {
        $gate = new TestStep('gate', static fn (): StepResult => StepResult::refused(new Problem('acme.gate.closed')));
        $flows = $this->flows(new Flow('acme.gated', [$gate, new TestStep('inside', static fn (): never => self::fail('A step past the gate took an answer.'))]));
        $started = $flows->start('acme.gated', new Realm('acme'), $this->context);

        foreach (['inside', 'unknown'] as $step) {
            $refused = $flows->submit($started->state->id, $step, new Input(), $this->context);

            self::assertSame([FlowStatus::InProgress, 'gate'], [$refused?->status, $refused?->step]);
            self::assertEquals([new Problem('yorvoid.flow.out_of_date')], $refused?->problems);
            self::assertSame(1, $refused?->state->get('gate.entered'), 'An answer for another step doesn\'t prepare this one again.');
        }
    }

    public function testAFlowThatTookTooLongIsGone(): void
    {
        $flows = $this->flows(new Flow('acme.slow', [new TestStep('first')], lifetime: Duration::minutes(5)));
        $started = $flows->start('acme.slow', new Realm('acme'), $this->context);
        self::assertEquals(new \DateTimeImmutable('2026-10-28 18:05:00 UTC'), $started->state->expiresAt);

        $this->clock->sleep(299);
        self::assertNotNull($flows->resume($started->state->id, $this->context));

        $this->clock->sleep(1);
        self::assertNull($flows->submit($started->state->id, 'first', new Input(), $this->context));
        self::assertNull($this->store->get($started->state->id), 'Forgotten.');
        self::assertNull($flows->resume($started->state->id, $this->context));
    }

    public function testTheStepsAfterASignInRunInItsSession(): void
    {
        $principal = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));
        $started = $this->sessions->start(new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::SetupCode, $this->clock->now()))), new Origin(OriginKind::Web));
        $renewed = $this->sessions->start(new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now()))), new Origin(OriginKind::Web));
        $after = new TestStep('after');
        $later = new TestStep('later');
        $flows = $this->flows(new Flow('acme.sign_in', [
            new TestStep('sign_in', static fn (): StepResult => StepResult::done(signedIn: $started)),
            $after,
            new TestStep('step_up', static fn (): StepResult => StepResult::done(renewedToken: $renewed->token)),
            $later,
        ]));
        $flow = $flows->start('acme.sign_in', new Realm('acme'), $this->context);

        $signedIn = $flows->submit($flow->state->id, 'sign_in', new Input(), $this->context);
        self::assertSame($started, $signedIn?->signedIn);
        self::assertNull($signedIn->renewedToken);
        self::assertSame($started->session, $after->entered[0]->session);
        self::assertSame($this->context->origin, $after->entered[0]->origin);

        $flows->submit($flow->state->id, 'after', new Input(), $this->context->withSession($started->session));
        $stepped = $flows->submit($flow->state->id, 'step_up', new Input(), $this->context->withSession($started->session));
        self::assertSame($renewed->token, $stepped?->renewedToken);
        self::assertNull($stepped->signedIn);
        $session = $later->entered[0]->session;
        self::assertNotNull($session);
        self::assertTrue($renewed->session->id->equals($session->id));

        $last = $flows->submit($flow->state->id, 'later', new Input(), $this->context);
        self::assertSame([FlowStatus::Completed, null, null], [$last?->status, $last?->signedIn, $last?->renewedToken]);
    }

    public function testAStepThatsGoneAfterAnUpgradeIsPassedOver(): void
    {
        $before = $this->flows(new Flow('acme.onboarding', [new TestStep('old'), new TestStep('kept')]));
        $started = $before->start('acme.onboarding', new Realm('acme'), $this->context);
        $after = $this->flows(new Flow('acme.onboarding', [new TestStep('kept')]));

        self::assertSame('kept', $after->resume($started->state->id, $this->context)?->step);

        $refused = $after->submit($started->state->id, 'old', new Input(), $this->context);
        self::assertSame('kept', $refused?->step);
        self::assertEquals([new Problem('yorvoid.flow.out_of_date')], $refused->problems);
    }

    public function testUnknownFlowsAreAnIntegratorsError(): void
    {
        $flows = $this->flows(new Flow('acme.one', [new TestStep('a')]), new Flow('acme.two', [new TestStep('a')]));

        self::assertSame('acme.two', $flows->flow('acme.two')->name);
        $this->expectExceptionObject(new IntegratorError('There\'s no flow "acme.three".', 'Start one of the flows: acme.one, acme.two.', 'concepts/flows.md#the-flows'));

        $flows->start('acme.three', new Realm('acme'), $this->context);
    }

    public function testDefaultsToNoListenersAndTheSystemClock(): void
    {
        $flows = new Flows([new Flow('acme.one', [new TestStep('a')])], $this->store, $this->sessions);

        $started = $flows->start('acme.one', new Realm('acme'), $this->context);

        self::assertEqualsWithDelta(time() + 1800, $started->state->expiresAt->getTimestamp(), 5);
        self::assertSame(FlowStatus::Completed, $flows->submit($started->state->id, 'a', new Input(), $this->context)?->status);
    }

    private function flows(Flow ...$flows): Flows
    {
        return new Flows($flows, $this->store, $this->sessions, $this->events, $this->clock);
    }
}
