<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Credential;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Credential\AddCredential;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\CredentialStatus;
use YorvoID\Authn\Credential\Event\CredentialAdded;
use YorvoID\Authn\Credential\Event\CredentialRemoved;
use YorvoID\Authn\Credential\Event\CredentialRemoving;
use YorvoID\Authn\Credential\LastCredential;
use YorvoID\Authn\Credential\RemoveCredential;
use YorvoID\Authn\Credential\RenameCredential;
use YorvoID\Authn\Credential\UnknownCredential;
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
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Event\SecurityLogEntryRecorded;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\Stack;

/**
 * The credential registry on every implementation (IDN-013, ATN-005, ATN-027, SES-006).
 */
final class CredentialsTest extends DatabaseTestCase
{
    private Stack $stack;

    private MockClock $clock;

    private RecordingDispatcher $events;

    private Principals $principals;

    private Sessions $sessions;

    private StepUp $stepUp;

    #[DataProvider('implementations')]
    public function testAddsACredentialFromARecentlyAuthenticatedSession(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();

        $credential = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, '  MacBook  '), $session, new Origin(OriginKind::Web));

        self::assertSame('MacBook', $credential->label);
        self::assertTrue($credential->principalId->equals($session->principalId), 'A credential was added to someone else.');
        self::assertSame(CredentialStatus::Active, $credential->status);
        self::assertNull($credential->lastUsedAt);
        self::assertEquals([$credential], $credentials->of(new Realm('acme'), $session->principalId));
        self::assertEquals($credential, $credentials->get(new Realm('acme'), $credential->id));

        $entry = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false)[2];
        self::assertSame('yorvoid.credential.add', $entry->action);
        self::assertSame(['kind' => 'passkey', 'principal' => (string) $session->principalId], $entry->details, 'The log holds no label: people write their names into them.');
        self::assertSame([SecurityLogEntryRecorded::class, CredentialAdded::class], $this->events->classes());
    }

    #[DataProvider('implementations')]
    public function testChangesNeedAProofOfIdentityWithinTheWindow(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $this->clock->sleep(299);
        $credentials->requireStepUp($session, 'yorvoid.credential.add');

        $this->clock->sleep(1);

        foreach ([
            static fn () => $credentials->requireStepUp($session, 'yorvoid.credential.add'),
            static fn () => $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Attacker\'s'), $session, new Origin(OriginKind::Web)),
        ] as $change) {
            try {
                $change();
                self::fail('A session that proved nothing recently changed how its principal signs in.');
            } catch (StepUpRequired $exception) {
                self::assertSame('Confirm it\'s you, then try once more.', $exception->getMessage());
                self::assertEquals(new AuthenticationRequirement(Duration::minutes(5), operation: 'yorvoid.credential.add'), $exception->requirement, 'The remedy names what to prove.');
            }
        }

        self::assertSame([], $credentials->of(new Realm('acme'), $session->principalId));
    }

    #[DataProvider('implementations')]
    public function testRemovingNeedsAProofOfIdentityWithinTheWindowToo(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $phone = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), $session, new Origin(OriginKind::Web));
        $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), $session, new Origin(OriginKind::Web));
        $this->clock->sleep(300);

        try {
            $credentials->remove(new RemoveCredential(new Realm('acme'), $phone->id), $session, new Origin(OriginKind::Web));
            self::fail('A session that proved nothing recently removed a passkey.');
        } catch (StepUpRequired $exception) {
            self::assertEquals(new AuthenticationRequirement(Duration::minutes(5), operation: 'yorvoid.credential.remove', heldAfterRecovery: true), $exception->requirement);
        }

        self::assertCount(2, $credentials->of(new Realm('acme'), $session->principalId));
    }

    public function testTheWindowIsConfigurable(): void
    {
        $this->credentials('memory');
        $credentials = new Credentials($this->stack->credentials, $this->sessions, $this->stepUp, new SecurityLog($this->stack->log, $this->stack->transactions, clock: $this->clock), $this->stack->transactions, clock: $this->clock, reauthenticationWindow: Duration::minutes(10));
        $session = $this->session();

        $this->clock->sleep(599);
        $credentials->requireStepUp($session, 'yorvoid.credential.add');
        $this->clock->sleep(1);

        $this->expectException(StepUpRequired::class);

        $credentials->requireStepUp($session, 'yorvoid.credential.add');
    }

    #[DataProvider('implementations')]
    public function testAnEndedSessionChangesNothing(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $this->sessions->end(new EndSession(new Realm('acme'), $session->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web));

        $this->expectException(StepUpRequired::class);

        $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Passkey'), $session, new Origin(OriginKind::Web));
    }

    #[DataProvider('implementations')]
    public function testAStepUpCountsOnlyForTheChangeItWasFor(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $phone = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), $session, new Origin(OriginKind::Web));
        $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), $session, new Origin(OriginKind::Web));
        $this->clock->sleep(1800);
        $this->sessions->stepUp($session->realm, $session->id, new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true, 'yorvoid.credential.remove'), new Origin(OriginKind::Web));

        try {
            $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Attacker\'s'), $session, new Origin(OriginKind::Web));
            self::fail('Confirming a removal let the session add a passkey.');
        } catch (StepUpRequired) {
        }

        $credentials->remove(new RemoveCredential(new Realm('acme'), $phone->id), $session, new Origin(OriginKind::Web));
        self::assertCount(1, $credentials->of(new Realm('acme'), $session->principalId));
    }

    #[DataProvider('implementations')]
    public function testRemovingWaitsOutTheCoolDownAfterARecovery(string $implementation): void
    {
        $credentials = $this->credentials($implementation, Duration::hours(24));
        $session = $this->session();
        $phone = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), $session, new Origin(OriginKind::Web));
        $this->stepUp->startCoolDown(new Realm('acme'), $session->principalId, new Origin(OriginKind::Web));

        // Adding isn't held: someone who lost their passkeys recovers to add a new one.
        $laptop = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), $session, new Origin(OriginKind::Web));

        try {
            $credentials->remove(new RemoveCredential(new Realm('acme'), $phone->id), $session, new Origin(OriginKind::Web));
            self::fail('A credential was removed during the cool-down.');
        } catch (HeldAfterRecovery $exception) {
            self::assertEquals(new \DateTimeImmutable('2026-10-28 18:45:30 UTC'), $exception->until);
        }

        $this->clock->sleep(86_400);
        $session = $this->sessions->start(new StartSession(new Realm('acme'), $session->principalId, self::passkey($this->clock)), new Origin(OriginKind::Web))->session;
        $credentials->remove(new RemoveCredential(new Realm('acme'), $phone->id), $session, new Origin(OriginKind::Web));
        self::assertEquals([$laptop->id], array_map(static fn (Credential $credential): CredentialId => $credential->id, $credentials->of(new Realm('acme'), $session->principalId)));
    }

    public function testRefusesToAddACredentialInAnotherRealm(): void
    {
        $credentials = $this->credentials('memory');
        $session = $this->session();

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The session is in another realm than the credential.');

        $credentials->add(new AddCredential(new Realm('globex'), CredentialKind::Passkey, 'Passkey'), $session, new Origin(OriginKind::Web));
    }

    #[DataProvider('implementations')]
    public function testTheLastWayToSignInCantBeRemoved(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $phone = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), $session, new Origin(OriginKind::Web));
        $laptop = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), $session, new Origin(OriginKind::Web));
        $codes = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::RecoveryCodes, ''), $session, new Origin(OriginKind::Web));
        $this->events->dispatched = [];

        $credentials->remove(new RemoveCredential(new Realm('acme'), $phone->id), $session, new Origin(OriginKind::Web));
        self::assertSame([CredentialRemoving::class, SecurityLogEntryRecorded::class, CredentialRemoved::class], $this->events->classes());

        try {
            $credentials->remove(new RemoveCredential(new Realm('acme'), $laptop->id), $session, new Origin(OriginKind::Web));
            self::fail('The last passkey was removed; recovery codes don\'t sign in.');
        } catch (LastCredential $exception) {
            self::assertEquals($laptop, $exception->credential);
            self::assertSame('This is your last way to sign in. Add another before removing it.', $exception->getMessage());
        }

        self::assertEquals([$laptop, $codes], $credentials->of(new Realm('acme'), $session->principalId));
        self::assertSame('Recovery codes', $codes->label);
        $credentials->remove(new RemoveCredential(new Realm('acme'), $codes->id), $session, new Origin(OriginKind::Web));
        self::assertEquals([$laptop], $credentials->of(new Realm('acme'), $session->principalId));
    }

    #[DataProvider('implementations')]
    public function testADisabledCredentialIsNoWayIn(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $copied = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Copied'), $session, new Origin(OriginKind::Web));
        $other = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Other'), $session, new Origin(OriginKind::Web));

        $disabled = $credentials->disable($copied, 'signature counter went backwards', new Origin(OriginKind::Web));

        self::assertSame(CredentialStatus::Disabled, $disabled->status);
        self::assertFalse($disabled->isActive());
        self::assertEquals($disabled, $credentials->get(new Realm('acme'), $copied->id));
        $entry = \array_slice(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), -1)[0];
        self::assertSame(['kind' => 'passkey', 'principal' => (string) $session->principalId, 'reason' => 'signature counter went backwards'], $entry->details);
        self::assertNull($entry->actor);

        $this->expectException(LastCredential::class);

        $credentials->remove(new RemoveCredential(new Realm('acme'), $other->id), $session, new Origin(OriginKind::Web));
    }

    #[DataProvider('implementations')]
    public function testRemovingCanEndTheOtherSessions(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $lostPhone = $this->sessions->start(new StartSession(new Realm('acme'), $session->principalId, self::passkey($this->clock)), new Origin(OriginKind::Web));
        $keep = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), $session, new Origin(OriginKind::Web));
        $phone = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), $session, new Origin(OriginKind::Web));
        $other = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Tablet'), $session, new Origin(OriginKind::Web));

        $credentials->remove(new RemoveCredential(new Realm('acme'), $other->id), $session, new Origin(OriginKind::Web));
        self::assertNotNull($this->sessions->validate(new Realm('acme'), $lostPhone->token), 'Other sessions ended without being asked to.');

        $credentials->remove(new RemoveCredential(new Realm('acme'), $phone->id, endOtherSessions: true), $session, new Origin(OriginKind::Web));
        self::assertSame(SessionEndReason::CredentialChanged, $this->sessions->get(new Realm('acme'), $lostPhone->session->id)?->endReason);
        self::assertTrue($this->sessions->get(new Realm('acme'), $session->id)?->isCurrentAt($this->clock->now()), 'The session that asked ended.');
        self::assertCount(1, $credentials->of(new Realm('acme'), $keep->principalId));
    }

    #[DataProvider('implementations')]
    public function testOnlyTheOwnersSessionChangesACredential(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $owner = $this->session();
        $intruder = $this->session();
        $credential = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), $owner, new Origin(OriginKind::Web));

        foreach ([
            static fn () => $credentials->remove(new RemoveCredential(new Realm('acme'), $credential->id), $intruder, new Origin(OriginKind::Web)),
            static fn () => $credentials->rename(new RenameCredential(new Realm('acme'), $credential->id, 'Mine now'), $intruder, new Origin(OriginKind::Web)),
            static fn () => $credentials->rename(new RenameCredential(new Realm('globex'), $credential->id, 'Elsewhere'), $owner, new Origin(OriginKind::Web)),
            static fn () => $credentials->remove(new RemoveCredential(new Realm('acme'), new CredentialId(new UuidV7Generator()->next())), $owner, new Origin(OriginKind::Web)),
        ] as $change) {
            try {
                $change();
                self::fail('Someone else\'s credential changed.');
            } catch (UnknownCredential $exception) {
                self::assertStringStartsWith('There is no credential', $exception->getMessage());
            }
        }

        self::assertEquals($credential, $credentials->get(new Realm('acme'), $credential->id));
    }

    #[DataProvider('implementations')]
    public function testRenamingAndUseDontNeedAFreshProof(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $credential = $credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), $session, new Origin(OriginKind::Web));
        $this->clock->sleep(3600);

        $renamed = $credentials->rename(new RenameCredential(new Realm('acme'), $credential->id, str_repeat('ü', 70)."\xC3"), $session, new Origin(OriginKind::Web));
        self::assertSame($renamed->label, $credentials->get(new Realm('acme'), $credential->id)?->label, 'The new label wasn\'t kept.');
        $used = $credentials->markUsed($renamed);

        self::assertSame(str_repeat('ü', 64), $renamed->label, 'Labels are kept to 64 valid characters.');
        self::assertEquals(new \DateTimeImmutable('2026-10-27 19:45:30 UTC'), $used->lastUsedAt);
        self::assertEquals($used, $credentials->get(new Realm('acme'), $credential->id));
        self::assertSame('yorvoid.credential.rename', \array_slice(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), -1)[0]->action);
    }

    public function testDefaultLabelsNameTheKind(): void
    {
        $credentials = $this->credentials('memory');
        $session = $this->session();
        $labels = array_map(static fn (CredentialKind $kind): string => $credentials->add(new AddCredential(new Realm('acme'), $kind, ' '), $session, new Origin(OriginKind::Web))->label, CredentialKind::cases());

        self::assertSame(['Passkey', 'Password', 'Authenticator app', 'Recovery codes'], $labels);
        self::assertSame([true, true, false, false], array_map(static fn (CredentialKind $kind): bool => $kind->signsIn(), CredentialKind::cases()));
    }

    public function testCredentialCommandsAreOperations(): void
    {
        $catalogue = new OperationCatalogue([AddCredential::class, RemoveCredential::class, RenameCredential::class]);

        self::assertSame('yorvoid.credential.remove', $catalogue->get('yorvoid.credential.add')->inverse);
        self::assertNull($catalogue->get('yorvoid.credential.remove')->ability, 'Only the owner changes their own sign-in methods.');
    }

    private function credentials(string $implementation, ?Duration $coolDown = null): Credentials
    {
        $this->stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-27 18:45:30 UTC');
        $this->events = new RecordingDispatcher();
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, $this->events, $ids, $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, ids: $ids, clock: $this->clock);
        $this->sessions = new Sessions($this->stack->sessions, $this->principals, $log, $this->stack->transactions, ids: $ids, clock: $this->clock);
        $this->stepUp = new StepUp($this->sessions, $this->stack->coolDowns, $log, $this->clock, $coolDown);

        // The default window: 5 minutes.
        return new Credentials($this->stack->credentials, $this->sessions, $this->stepUp, $log, $this->stack->transactions, $this->events, $ids, $this->clock);
    }

    private function session(): Session
    {
        $principal = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));
        $session = $this->sessions->start(new StartSession(new Realm('acme'), $principal->id, self::passkey($this->clock)), new Origin(OriginKind::Web))->session;
        $this->events->dispatched = [];

        return $session;
    }

    private static function passkey(MockClock $clock): AuthenticationContext
    {
        return new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $clock->now(), true, true));
    }
}
