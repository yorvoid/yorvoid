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
use YorvoID\Authn\Credential\ForgetCredentialsOfDeletedPrincipals;
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
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;
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

        $credential = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, '  MacBook  '), new Origin(OriginKind::Web), $session);

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
            static fn () => Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Attacker\'s'), new Origin(OriginKind::Web), $session),
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
        $phone = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), new Origin(OriginKind::Web), $session);
        Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), new Origin(OriginKind::Web), $session);
        $this->clock->sleep(300);

        try {
            Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $phone->id), new Origin(OriginKind::Web), $session);
            self::fail('A session that proved nothing recently removed a passkey.');
        } catch (StepUpRequired $exception) {
            self::assertEquals(new AuthenticationRequirement(Duration::minutes(5), phishingResistant: true, operation: 'yorvoid.credential.remove', heldAfterRecovery: true), $exception->requirement);
        }

        self::assertCount(2, $credentials->of(new Realm('acme'), $session->principalId));
    }

    #[DataProvider('implementations')]
    public function testAProofThatCanBePhishedAddsButDoesntRemove(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $owner = $this->session();
        $phone = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), new Origin(OriginKind::Web), $owner);
        // Someone in with one of the owner's recovery codes, a moment ago.
        $recovered = Permits::call($this->sessions->start(...), new StartSession(new Realm('acme'), $owner->principalId, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::RecoveryCode, $this->clock->now()))), new Origin(OriginKind::Web))->session;

        Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), new Origin(OriginKind::Web), $recovered);

        try {
            Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $phone->id), new Origin(OriginKind::Web), $recovered);
            self::fail('A recovery code\'s proof removed a passkey.');
        } catch (StepUpRequired $exception) {
            self::assertTrue($exception->requirement->phishingResistant);
        }

        self::assertCount(2, $credentials->of(new Realm('acme'), $owner->principalId));
        Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $phone->id), new Origin(OriginKind::Web), $owner);
        self::assertCount(1, $credentials->of(new Realm('acme'), $owner->principalId), 'A passkey\'s proof didn\'t remove one.');
    }

    #[DataProvider('implementations')]
    public function testADeletedPrincipalsCredentialsAndCodesAreForgotten(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $other = $this->session();
        $phone = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), new Origin(OriginKind::Web), $session);
        $codes = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::RecoveryCodes, ''), new Origin(OriginKind::Web), $session);
        Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), new Origin(OriginKind::Web), $other);
        $this->stack->recoveryCodes->replace(new Realm('acme'), $session->principalId, [hash('sha256', 'a')], $this->clock->now());
        $this->events->listen(PrincipalStatusChanging::class, new ForgetCredentialsOfDeletedPrincipals($credentials, $this->stack->recoveryCodes, $this->clock)(...));
        $principals = new Principals($this->stack->principals, $this->stack->identifiers, new SecurityLog($this->stack->log, $this->stack->transactions, clock: $this->clock), $this->stack->transactions, events: $this->events, clock: $this->clock);

        Permits::call($principals->suspend(...), new SuspendPrincipal(new Realm('acme'), $session->principalId), new Origin(OriginKind::Console));
        self::assertCount(2, $credentials->of(new Realm('acme'), $session->principalId), 'Suspending forgot credentials.');
        $this->events->dispatched = [];

        Permits::call($principals->delete(...), new DeletePrincipal(new Realm('acme'), $session->principalId), new Origin(OriginKind::Console));

        self::assertSame([], $credentials->of(new Realm('acme'), $session->principalId));
        self::assertSame(0, $this->stack->recoveryCodes->count(new Realm('acme'), $session->principalId));
        self::assertCount(1, $credentials->of(new Realm('acme'), $other->principalId), 'Someone else\'s credential went.');
        self::assertEquals([$phone, $codes], array_map(static fn (CredentialRemoving $event): Credential => $event->credential, $this->events->of(CredentialRemoving::class)), 'The packages weren\'t told, to remove what they keep.');
        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        $forgot = array_values(array_filter($entries, static fn ($entry): bool => 'yorvoid.credential.forget_all' === $entry->action));
        self::assertSame([['principal', (string) $session->principalId, ['count' => 2]]], array_map(static fn ($entry): array => [$entry->subjectType, $entry->subjectId, $entry->details], $forgot));
        self::assertSame(0, $credentials->forgetAllOf(new Realm('acme'), $session->principalId, new Origin(OriginKind::Console)));
        self::assertCount(1, array_filter(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), static fn ($entry): bool => 'yorvoid.credential.forget_all' === $entry->action), 'Nothing forgotten was recorded.');
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
        Permits::call($this->sessions->end(...), new EndSession(new Realm('acme'), $session->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web));

        $this->expectException(StepUpRequired::class);

        Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Passkey'), new Origin(OriginKind::Web), $session);
    }

    #[DataProvider('implementations')]
    public function testAStepUpCountsOnlyForTheChangeItWasFor(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $phone = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), new Origin(OriginKind::Web), $session);
        Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), new Origin(OriginKind::Web), $session);
        $this->clock->sleep(1800);
        $this->sessions->stepUp($session->realm, $session->id, new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true, 'yorvoid.credential.remove'), new Origin(OriginKind::Web));

        try {
            Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Attacker\'s'), new Origin(OriginKind::Web), $session);
            self::fail('Confirming a removal let the session add a passkey.');
        } catch (StepUpRequired) {
        }

        Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $phone->id), new Origin(OriginKind::Web), $session);
        self::assertCount(1, $credentials->of(new Realm('acme'), $session->principalId));
    }

    #[DataProvider('implementations')]
    public function testRemovingWaitsOutTheCoolDownAfterARecovery(string $implementation): void
    {
        $credentials = $this->credentials($implementation, Duration::hours(24));
        $session = $this->session();
        $phone = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), new Origin(OriginKind::Web), $session);
        $this->stepUp->startCoolDown(new Realm('acme'), $session->principalId, new Origin(OriginKind::Web));

        // Adding isn't held: someone who lost their passkeys recovers to add a new one.
        $laptop = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), new Origin(OriginKind::Web), $session);

        try {
            Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $phone->id), new Origin(OriginKind::Web), $session);
            self::fail('A credential was removed during the cool-down.');
        } catch (HeldAfterRecovery $exception) {
            self::assertEquals(new \DateTimeImmutable('2026-10-28 18:45:30 UTC'), $exception->until);
        }

        $this->clock->sleep(86_400);
        $session = Permits::call($this->sessions->start(...), new StartSession(new Realm('acme'), $session->principalId, self::passkey($this->clock)), new Origin(OriginKind::Web))->session;
        Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $phone->id), new Origin(OriginKind::Web), $session);
        self::assertEquals([$laptop->id], array_map(static fn (Credential $credential): CredentialId => $credential->id, $credentials->of(new Realm('acme'), $session->principalId)));
    }

    #[DataProvider('implementations')]
    public function testALabelIsOneLineOfAtMost64Characters(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $add = static fn (string $label): string => Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, $label), new Origin(OriginKind::Web), $session)->label;

        self::assertSame(str_repeat('a', 63), $add(str_repeat('a', 63).' and more'), 'Cut at 64, with no space at the end.');
        self::assertSame('Work laptop', $add("  Work\n\u{202E}laptop\u{202C}  "));
        self::assertSame('Passkey', $add("\u{2028}\t\n"));
    }

    public function testRefusesToAddACredentialInAnotherRealm(): void
    {
        $credentials = $this->credentials('memory');
        $session = $this->session();

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('"yorvoid.credential.add" runs from the signed-in session, in its realm.');

        Permits::call($credentials->add(...), new AddCredential(new Realm('globex'), CredentialKind::Passkey, 'Passkey'), new Origin(OriginKind::Web), $session);
    }

    #[DataProvider('implementations')]
    public function testTheLastWayToSignInCantBeRemoved(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $phone = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), new Origin(OriginKind::Web), $session);
        $laptop = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), new Origin(OriginKind::Web), $session);
        $codes = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::RecoveryCodes, ''), new Origin(OriginKind::Web), $session);
        $this->events->dispatched = [];

        Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $phone->id), new Origin(OriginKind::Web), $session);
        self::assertSame([CredentialRemoving::class, SecurityLogEntryRecorded::class, CredentialRemoved::class], $this->events->classes());

        try {
            Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $laptop->id), new Origin(OriginKind::Web), $session);
            self::fail('The last passkey was removed; recovery codes don\'t sign in.');
        } catch (LastCredential $exception) {
            self::assertEquals($laptop, $exception->credential);
            self::assertSame('This is your last way to sign in. Add another before removing it.', $exception->getMessage());
        }

        self::assertEquals([$laptop, $codes], $credentials->of(new Realm('acme'), $session->principalId));
        self::assertSame('Recovery codes', $codes->label);
        Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $codes->id), new Origin(OriginKind::Web), $session);
        self::assertEquals([$laptop], $credentials->of(new Realm('acme'), $session->principalId));
    }

    #[DataProvider('implementations')]
    public function testADisabledCredentialIsNoWayIn(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $copied = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Copied'), new Origin(OriginKind::Web), $session);
        $other = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Other'), new Origin(OriginKind::Web), $session);

        $disabled = $credentials->disable($copied, 'signature counter went backwards', new Origin(OriginKind::Web));

        self::assertSame(CredentialStatus::Disabled, $disabled->status);
        self::assertFalse($disabled->isActive());
        self::assertEquals($disabled, $credentials->get(new Realm('acme'), $copied->id));
        $entry = \array_slice(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), -1)[0];
        self::assertSame(['kind' => 'passkey', 'principal' => (string) $session->principalId, 'reason' => 'signature counter went backwards'], $entry->details);
        self::assertNull($entry->actor);

        $this->expectException(LastCredential::class);

        Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $other->id), new Origin(OriginKind::Web), $session);
    }

    #[DataProvider('implementations')]
    public function testRemovingCanEndTheOtherSessions(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $lostPhone = Permits::call($this->sessions->start(...), new StartSession(new Realm('acme'), $session->principalId, self::passkey($this->clock)), new Origin(OriginKind::Web));
        $keep = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), new Origin(OriginKind::Web), $session);
        $phone = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), new Origin(OriginKind::Web), $session);
        $other = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Tablet'), new Origin(OriginKind::Web), $session);

        Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $other->id), new Origin(OriginKind::Web), $session);
        self::assertNotNull($this->sessions->validate(new Realm('acme'), $lostPhone->token), 'Other sessions ended without being asked to.');

        Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $phone->id, endOtherSessions: true), new Origin(OriginKind::Web), $session);
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
        $credential = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), new Origin(OriginKind::Web), $owner);

        foreach ([
            static fn () => Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), $credential->id), new Origin(OriginKind::Web), $intruder),
            static fn () => Permits::call($credentials->rename(...), new RenameCredential(new Realm('acme'), $credential->id, 'Mine now'), new Origin(OriginKind::Web), $intruder),
            static fn () => Permits::call($credentials->remove(...), new RemoveCredential(new Realm('acme'), new CredentialId(new UuidV7Generator()->next())), new Origin(OriginKind::Web), $owner),
        ] as $change) {
            try {
                $change();
                self::fail('Someone else\'s credential changed.');
            } catch (UnknownCredential $exception) {
                self::assertStringStartsWith('There is no credential', $exception->getMessage());
            }
        }

        try {
            Permits::call($credentials->rename(...), new RenameCredential(new Realm('globex'), $credential->id, 'Elsewhere'), new Origin(OriginKind::Web), $owner);
            self::fail('A credential changed from another realm.');
        } catch (IntegratorError $error) {
            self::assertSame('"yorvoid.credential.rename" runs from the signed-in session, in its realm.', $error->problem);
        }

        self::assertEquals($credential, $credentials->get(new Realm('acme'), $credential->id));
    }

    #[DataProvider('implementations')]
    public function testRenamingAndUseDontNeedAFreshProof(string $implementation): void
    {
        $credentials = $this->credentials($implementation);
        $session = $this->session();
        $credential = Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), new Origin(OriginKind::Web), $session);
        $this->clock->sleep(3600);

        $renamed = Permits::call($credentials->rename(...), new RenameCredential(new Realm('acme'), $credential->id, str_repeat('ü', 70)."\xC3"), new Origin(OriginKind::Web), $session);
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
        $labels = array_map(static fn (CredentialKind $kind): string => Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), $kind, ' '), new Origin(OriginKind::Web), $session)->label, [CredentialKind::Passkey, CredentialKind::RecoveryCodes]);

        self::assertSame(['Passkey', 'Recovery codes'], $labels);
        self::assertSame([true, true, false, false], array_map(static fn (CredentialKind $kind): bool => $kind->signsIn(), CredentialKind::cases()));
    }

    public function testKindsNoPackageChecksYetCantBeAdded(): void
    {
        $credentials = $this->credentials('memory');
        $session = $this->session();
        self::assertSame([true, false, false, true], array_map(static fn (CredentialKind $kind): bool => $kind->isBuilt(), CredentialKind::cases()));

        foreach ([CredentialKind::Password, CredentialKind::Totp] as $kind) {
            try {
                Permits::call($credentials->add(...), new AddCredential(new Realm('acme'), $kind, 'Mine'), new Origin(OriginKind::Web), $session);
                self::fail('A credential nothing checks was added: '.$kind->value);
            } catch (IntegratorError $error) {
                self::assertSame(\sprintf('YorvoID can\'t check %s credentials yet.', $kind->value), $error->problem);
            }
        }
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
        $principal = Permits::call($this->principals->create(...), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));
        $session = Permits::call($this->sessions->start(...), new StartSession(new Realm('acme'), $principal->id, self::passkey($this->clock)), new Origin(OriginKind::Web))->session;
        $this->events->dispatched = [];

        return $session;
    }

    private static function passkey(MockClock $clock): AuthenticationContext
    {
        return new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $clock->now(), true, true));
    }
}
