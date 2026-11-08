<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\StepUp;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\SessionStepUpCheck;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\StepUpSelfService;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\PrincipalAuthority;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\RemoveIdentifier;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\Stack;

/**
 * Someone changing their own account through Operations, as StepUpSelfService decides: a session, a
 * recent proof where the operation needs one, and the recovery's cool-down.
 */
final class StepUpSelfServiceTest extends DatabaseTestCase
{
    private Stack $stack;
    private MockClock $clock;
    private Principals $principals;
    private Sessions $sessions;
    private StepUp $stepUp;
    private SecurityLog $log;

    #[DataProvider('implementations')]
    public function testChangingOnesOwnEmailNeedsARecentProof(string $implementation): void
    {
        $identifiers = $this->identifiers($implementation);
        $session = $this->session();

        // Within the window: the session proved who it is when it signed in.
        $this->clock->sleep(299);
        $identifier = $identifiers->run(new SetIdentifier(new Realm('acme'), $session->principalId, IdentifierKind::Email, 'ana@example.org'), $session, new Origin(OriginKind::Web));
        self::assertSame('ana@example.org', $identifier->value);
        self::assertFalse($identifier->isVerified());
        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        $last = $entries[\count($entries) - 1];
        self::assertSame('yorvoid.identifier.set', $last->action);
        self::assertEquals($session->principalId, $last->actor);

        $this->clock->sleep(1);

        foreach ([
            'set' => static fn () => $identifiers->run(new SetIdentifier(new Realm('acme'), $session->principalId, IdentifierKind::Email, 'thief@example.net'), $session, new Origin(OriginKind::Web)),
            'remove' => static fn () => $identifiers->run(new RemoveIdentifier(new Realm('acme'), $session->principalId, IdentifierKind::Email), $session, new Origin(OriginKind::Web)),
        ] as $operation => $change) {
            try {
                $change();
                self::fail(\sprintf('An old session could %s the email address.', $operation));
            } catch (NotPermitted $exception) {
                $remedy = $exception->decision->remedies[0] ?? null;
                self::assertInstanceOf(StepUpRemedy::class, $remedy);
                self::assertSame('yorvoid.identifier.'.$operation, $remedy->operation);
                self::assertSame(300, $remedy->within->seconds);
                self::assertSame(Verdict::Deny, $exception->decision->verdict);
            }
        }

        self::assertSame('ana@example.org', $this->principals->identifiers(new Realm('acme'), $session->principalId)['email']->value ?? null);

        $this->sessions->stepUp($session->realm, $session->id, new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true, 'yorvoid.identifier.remove'), new Origin(OriginKind::Web));
        $identifiers->run(new RemoveIdentifier(new Realm('acme'), $session->principalId, IdentifierKind::Email), $session, new Origin(OriginKind::Web));
        self::assertSame([], $this->principals->identifiers(new Realm('acme'), $session->principalId));
    }

    #[DataProvider('implementations')]
    public function testChangingOnesOwnEmailWaitsOutTheCoolDownAfterARecovery(string $implementation): void
    {
        $identifiers = $this->identifiers($implementation, Duration::hours(24));
        $session = $this->session();
        $this->stepUp->startCoolDown(new Realm('acme'), $session->principalId, new Origin(OriginKind::Web));

        try {
            $identifiers->run(new SetIdentifier(new Realm('acme'), $session->principalId, IdentifierKind::Email, 'thief@example.net'), $session, new Origin(OriginKind::Web));
            self::fail('The email address changed during the cool-down.');
        } catch (NotPermitted $exception) {
            self::assertEquals([new WaitRemedy(new \DateTimeImmutable('2026-10-28 18:45:30 UTC'))], $exception->decision->remedies);
        }

        self::assertSame([], $this->principals->identifiers(new Realm('acme'), $session->principalId));
    }

    #[DataProvider('implementations')]
    public function testRemovingOnesOwnEmailWaitsOutTheCoolDownAfterARecovery(string $implementation): void
    {
        $identifiers = $this->identifiers($implementation, Duration::hours(24));
        $session = $this->session();
        Permits::call($this->principals->setIdentifier(...), new SetIdentifier(new Realm('acme'), $session->principalId, IdentifierKind::Email, 'ana@example.com'), new Origin(OriginKind::Console));
        $this->stepUp->startCoolDown(new Realm('acme'), $session->principalId, new Origin(OriginKind::Web));

        try {
            $identifiers->run(new RemoveIdentifier(new Realm('acme'), $session->principalId, IdentifierKind::Email), $session, new Origin(OriginKind::Web));
            self::fail('The email address went during the cool-down.');
        } catch (NotPermitted $exception) {
            self::assertEquals([new WaitRemedy(new \DateTimeImmutable('2026-10-28 18:45:30 UTC'))], $exception->decision->remedies);
        }

        self::assertSame('ana@example.com', $this->principals->identifiers(new Realm('acme'), $session->principalId)['email']->value);
    }

    #[DataProvider('implementations')]
    public function testAnApplicationsRequirementsReplaceYorvoIDs(string $implementation): void
    {
        $this->identifiers($implementation);
        $identifiers = new Operations(OperationCatalogue::of([$this->principals]), Evaluator::builtIn(new PrincipalAuthority($this->stack->principals), selfService: new StepUpSelfService($this->stepUp, ['yorvoid.identifier.set' => null])), $this->log, [$this->principals]);
        $session = $this->session();
        // Long past the window: only YorvoID's requirement would ask for a proof now.
        $this->clock->sleep(3600);

        self::assertSame('ana@example.org', $identifiers->run(new SetIdentifier(new Realm('acme'), $session->principalId, IdentifierKind::Email, 'ana@example.org'), $session, new Origin(OriginKind::Web))->value);
    }

    #[DataProvider('implementations')]
    public function testTheWindowCanBeLonger(string $implementation): void
    {
        $this->identifiers($implementation);
        $identifiers = $this->operations(Duration::minutes(10));
        $session = $this->session();
        $this->clock->sleep(599);

        self::assertSame('ana@example.org', $identifiers->run(new SetIdentifier(new Realm('acme'), $session->principalId, IdentifierKind::Email, 'ana@example.org'), $session, new Origin(OriginKind::Web))->value);
    }

    #[DataProvider('implementations')]
    public function testOnlyOnesOwn(string $implementation): void
    {
        $identifiers = $this->identifiers($implementation);
        $session = $this->session();
        $other = Permits::call($this->principals->create(...), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: 'bo@example.com'), new Origin(OriginKind::Console));

        foreach ([
            static fn () => $identifiers->run(new SetIdentifier(new Realm('acme'), $other->id, IdentifierKind::Email, 'thief@example.net'), $session, new Origin(OriginKind::Web)),
            static fn () => $identifiers->run(new SetIdentifier(new Realm('globex'), $session->principalId, IdentifierKind::Email, 'ana@example.org'), $session, new Origin(OriginKind::Web)),
            static fn () => $identifiers->run(new RemoveIdentifier(new Realm('acme'), $other->id, IdentifierKind::Email), $session, new Origin(OriginKind::Web)),
            static fn () => $identifiers->run(new RemoveIdentifier(new Realm('globex'), $session->principalId, IdentifierKind::Email), $session, new Origin(OriginKind::Web)),
        ] as $i => $change) {
            try {
                $change();
                self::fail(\sprintf('Change %d of someone else\'s identifiers went through.', $i));
            } catch (NotPermitted $refused) {
                self::assertNotSame(Verdict::Permit, $refused->decision->verdict);
            }
        }

        self::assertSame('bo@example.com', $this->principals->identifiers(new Realm('acme'), $other->id)['email']->value ?? null);
    }

    #[DataProvider('implementations')]
    public function testNobodyMarksTheirOwnAddressVerified(string $implementation): void
    {
        $identifiers = $this->identifiers($implementation);
        $session = $this->session();

        $this->expectException(NotPermitted::class);

        $identifiers->run(new SetIdentifier(new Realm('acme'), $session->principalId, IdentifierKind::Email, 'ana@example.org', verified: true), $session, new Origin(OriginKind::Web));
    }

    #[DataProvider('implementations')]
    public function testOnlyFromASession(string $implementation): void
    {
        $identifiers = $this->identifiers($implementation);
        $session = $this->session();

        try {
            $identifiers->run(new SetIdentifier(new Realm('acme'), $session->principalId, IdentifierKind::Email, 'ana@example.org'), new ActingPrincipal(new Realm('acme'), $session->principalId), new Origin(OriginKind::Api));
            self::fail('Someone changed their own address without a session.');
        } catch (NotPermitted $refused) {
            // Not on its own account: no session. Nor as an administrator: without an ability policy, no
            // ability permits anything.
            self::assertSame(Verdict::Deny, $refused->decision->verdict);
            self::assertSame([], $refused->decision->remedies, 'A step-up wouldn\'t help without a session.');
        }

        self::assertSame([], array_values(array_filter(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), static fn ($entry): bool => 'yorvoid.identifier.set' === $entry->action && Outcome::Success === $entry->outcome)));
    }

    #[DataProvider('implementations')]
    public function testAPermitAsksTheSessionForARecentProof(string $implementation): void
    {
        $this->identifiers($implementation, Duration::hours(24));
        $session = $this->session();
        $check = new SessionStepUpCheck($this->stepUp);
        $required = new StepUpRemedy(Duration::minutes(5), operation: 'yorvoid.role.assign');

        self::assertSame([TruthValue::Holds, 'The session proved who it is recently.'], [$check->check($session, $required)->value, $check->check($session, $required)->detail]);

        $this->clock->sleep(301);
        $old = $check->check($session, $required);
        self::assertSame(TruthValue::Fails, $old->value);
        self::assertSame([$required], $old->remedies);
        self::assertSame('Only a session proves who it is.', $check->check(new Operator(), $required)->detail);

        $this->sessions->stepUp($session->realm, $session->id, new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true), new Origin(OriginKind::Web));
        self::assertSame(TruthValue::Holds, $check->check($session, $required)->value);

        $this->stepUp->startCoolDown(new Realm('acme'), $session->principalId, new Origin(OriginKind::Web));
        $held = $check->check($session, $required);
        self::assertSame('The account was recovered recently.', $held->detail);
        self::assertEquals([new WaitRemedy($this->clock->now()->modify('+24 hours'))], $held->remedies, 'Held while a recovery cools down.');
    }

    private function identifiers(string $implementation, ?Duration $coolDown = null): Operations
    {
        $this->stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-27 18:45:30 UTC');
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, ids: $ids, clock: $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, ids: $ids, clock: $this->clock);
        $this->sessions = new Sessions($this->stack->sessions, $this->principals, $log, $this->stack->transactions, ids: $ids, clock: $this->clock);
        $this->stepUp = new StepUp($this->sessions, $this->stack->coolDowns, $log, $this->clock, $coolDown);
        $this->log = $log;

        // The default window: 5 minutes.
        return $this->operations();
    }

    private function operations(?Duration $window = null): Operations
    {
        return new Operations(OperationCatalogue::of([$this->principals]), Evaluator::builtIn(new PrincipalAuthority($this->stack->principals), selfService: new StepUpSelfService($this->stepUp, window: $window)), $this->log, [$this->principals]);
    }

    private function session(): Session
    {
        $principal = Permits::call($this->principals->create(...), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));

        return Permits::call($this->sessions->start(...), new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true))), new Origin(OriginKind::Web))->session;
    }
}
