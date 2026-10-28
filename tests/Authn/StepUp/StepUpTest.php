<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\StepUp;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSession;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\AuthenticationRequirement;
use YorvoID\Authn\StepUp\HeldAfterRecovery;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\StepUpRequired;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Stack;

/**
 * Step-up and the cool-down after a recovery, on every implementation (ATN-029, ATN-070, ATN-071).
 */
final class StepUpTest extends DatabaseTestCase
{
    private Stack $stack;

    private MockClock $clock;

    private Sessions $sessions;

    private Principals $principals;

    #[DataProvider('implementations')]
    public function testReadsTheSessionAsItIsNow(string $implementation): void
    {
        $stepUp = $this->stepUp($implementation);
        $session = $this->session();
        $requirement = new AuthenticationRequirement(Duration::minutes(5), operation: 'acme.site.remove');
        $this->clock->sleep(1800);

        try {
            $stepUp->require($session, $requirement);
            self::fail('A half-hour-old proof was recent enough.');
        } catch (StepUpRequired $exception) {
            self::assertSame($requirement, $exception->requirement);
        }

        $this->sessions->stepUp($session->realm, $session->id, new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), operation: 'acme.site.remove'), new Origin(OriginKind::Web));

        // The session as the request found it, before the step-up: what counts is the session now.
        $stepUp->require($session, $requirement);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('implementations')]
    public function testAnEndedOrExpiredSessionNeverMeetsARequirement(string $implementation): void
    {
        $stepUp = $this->stepUp($implementation);
        $ended = $this->session();
        $this->sessions->end(new EndSession(new Realm('acme'), $ended->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web));
        $unknown = new Session($ended->id, new Realm('globex'), $ended->principalId, 1, $ended->authentication, $ended->startedAt, $ended->lastSeenAt, $ended->expiresAt, $ended->idleExpiresAt);

        foreach ([$ended, $unknown] as $session) {
            try {
                $stepUp->require($session, new AuthenticationRequirement(Duration::minutes(5)));
                self::fail('A session that isn\'t current met a requirement.');
            } catch (StepUpRequired) {
            }
        }

        $expired = $this->session();
        $this->clock->sleep(3600);
        $this->expectException(StepUpRequired::class);

        $stepUp->require($expired, new AuthenticationRequirement(Duration::days(1)));
    }

    #[DataProvider('implementations')]
    public function testThereIsNoCoolDownUnlessConfigured(string $implementation): void
    {
        $stepUp = $this->stepUp($implementation);
        $session = $this->session();
        $entries = iterator_count($this->stack->log->entries(new Realm('acme'), 1));

        $stepUp->startCoolDown(new Realm('acme'), $session->principalId, new Origin(OriginKind::Web));

        self::assertNull($stepUp->coolDownEndsAt(new Realm('acme'), $session->principalId));
        self::assertSame($entries, iterator_count($this->stack->log->entries(new Realm('acme'), 1)), 'A cool-down that doesn\'t exist was recorded.');
        $stepUp->require($session, new AuthenticationRequirement(Duration::minutes(5), heldAfterRecovery: true));
    }

    #[DataProvider('implementations')]
    public function testARecoveryHoldsWhatAsksToBeHeldUntilTheCoolDownEnds(string $implementation): void
    {
        $stepUp = $this->stepUp($implementation, Duration::hours(24));
        $session = $this->session();
        $held = new AuthenticationRequirement(Duration::minutes(5), heldAfterRecovery: true);
        $notHeld = new AuthenticationRequirement(Duration::minutes(5));

        $stepUp->startCoolDown(new Realm('acme'), $session->principalId, new Origin(OriginKind::Web));
        $until = new \DateTimeImmutable('2026-10-29 18:45:30 UTC');
        self::assertEquals($until, $stepUp->coolDownEndsAt(new Realm('acme'), $session->principalId));
        self::assertNull($stepUp->coolDownEndsAt(new Realm('globex'), $session->principalId));

        $entry = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false)[2];
        self::assertSame('yorvoid.recovery.cool_down', $entry->action);
        self::assertSame('principal', $entry->subjectType);
        self::assertSame((string) $session->principalId, $entry->subjectId);
        self::assertSame(['until' => '2026-10-29T18:45:30+00:00'], $entry->details);

        $stepUp->require($session, $notHeld);

        try {
            $stepUp->require($session, $held);
            self::fail('A held operation ran during the cool-down.');
        } catch (HeldAfterRecovery $exception) {
            self::assertEquals($until, $exception->until);
            self::assertSame('Your account was recovered recently. For your security, this is possible again from 2026-10-29 18:45 UTC.', $exception->getMessage());
        }

        $this->clock->sleep(86_399);
        $session = $this->signIn($session->principalId);

        try {
            $stepUp->require($session, $held);
            self::fail('The cool-down ended a second early.');
        } catch (HeldAfterRecovery) {
        }

        $this->clock->sleep(1);
        self::assertNull($stepUp->coolDownEndsAt(new Realm('acme'), $session->principalId));
        $stepUp->require($session, $held);
    }

    #[DataProvider('implementations')]
    public function testACoolDownIsHeldEvenWithAFreshProof(string $implementation): void
    {
        $stepUp = $this->stepUp($implementation, Duration::hours(1));
        $session = $this->session();
        $stepUp->startCoolDown(new Realm('acme'), $session->principalId, new Origin(OriginKind::Web));
        $this->clock->sleep(600);

        // Stepping up doesn't help, so the refusal says to wait, not to confirm it's you.
        $this->expectException(HeldAfterRecovery::class);

        $stepUp->require($session, new AuthenticationRequirement(Duration::minutes(5), heldAfterRecovery: true));
    }

    #[DataProvider('implementations')]
    public function testPurgesEndedCoolDowns(string $implementation): void
    {
        $stepUp = $this->stepUp($implementation, Duration::hours(1));
        $session = $this->session();
        $stepUp->startCoolDown(new Realm('acme'), $session->principalId, new Origin(OriginKind::Web));
        self::assertSame(0, $stepUp->purge());

        $this->clock->sleep(3601);

        self::assertSame(1, $stepUp->purge());
        self::assertNull($this->stack->coolDowns->until(new Realm('acme'), $session->principalId));
    }

    private function stepUp(string $implementation, ?Duration $coolDown = null): StepUp
    {
        $this->stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-28 18:45:30 UTC');
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, clock: $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, clock: $this->clock);
        $this->sessions = new Sessions($this->stack->sessions, $this->principals, $log, $this->stack->transactions, clock: $this->clock);

        return new StepUp($this->sessions, $this->stack->coolDowns, $log, $this->clock, $coolDown);
    }

    private function session(): Session
    {
        $principal = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));

        return $this->signIn($principal->id);
    }

    private function signIn(PrincipalId $principalId): Session
    {
        return $this->sessions->start(new StartSession(new Realm('acme'), $principalId, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true))), new Origin(OriginKind::Web))->session;
    }
}
