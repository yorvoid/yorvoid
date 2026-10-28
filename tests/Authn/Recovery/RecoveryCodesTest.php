<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Recovery;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\Event\CredentialAdded;
use YorvoID\Authn\Credential\RemoveCredential;
use YorvoID\Authn\Recovery\Event\RecoveryCodesGenerated;
use YorvoID\Authn\Recovery\Event\RecoveryCodeUsed;
use YorvoID\Authn\Recovery\GenerateRecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Recovery\Storage\DbalRecoveryCodeStore;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Event\SessionEnded;
use YorvoID\Authn\Session\Event\SessionStarted;
use YorvoID\Authn\Session\Event\SessionStarting;
use YorvoID\Authn\Session\InactivePrincipal;
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
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\ActivatePrincipal;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\Stack;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;

/**
 * Recovery codes on every implementation (ATN-025, ATN-026, ATN-029).
 */
final class RecoveryCodesTest extends DatabaseTestCase
{
    private Stack $stack;

    private MockClock $clock;

    private RecordingDispatcher $events;

    private Principals $principals;

    private Sessions $sessions;

    private Credentials $credentials;

    private StepUp $stepUp;

    private Attempts $attempts;

    #[DataProvider('implementations')]
    public function testGeneratesTenCodesThatEachWorkOnce(string $implementation): void
    {
        $codes = $this->codes($implementation);
        $session = $this->session();
        $origin = new Origin(OriginKind::Web);

        $generated = $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, $origin);

        self::assertCount(10, $generated);
        self::assertCount(10, array_unique(array_map(static fn (Secret $code): string => $code->reveal(), $generated)));

        foreach ($generated as $code) {
            self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){5}$/D', $code->reveal(), 'Not 24 characters of Crockford\'s base 32, in groups of four.');
        }

        $credential = $this->credentials->of(new Realm('acme'), $session->principalId)[0];
        self::assertSame([CredentialKind::RecoveryCodes, 'Recovery codes'], [$credential->kind, $credential->label]);
        self::assertSame(10, $codes->remaining(new Realm('acme'), $session->principalId));

        $entry = $this->entries()[3];
        self::assertSame(['yorvoid.recovery_codes.generate', 'credential', (string) $credential->id], [$entry->action, $entry->subjectType, $entry->subjectId]);
        self::assertEquals($session->principalId, $entry->actor);
        self::assertSame(['count' => 10, 'principal' => (string) $session->principalId], $entry->details, 'The log holds no code, nor its hash.');
        self::assertSame([SecurityLogEntryRecorded::class, CredentialAdded::class, SecurityLogEntryRecorded::class, RecoveryCodesGenerated::class], $this->events->classes());
        self::assertEquals(new RecoveryCodesGenerated(new Realm('acme'), $session->principalId, 10, $origin), $this->events->last());
    }

    #[DataProvider('platforms')]
    public function testStoresOnlyTheCodesHashes(string $platform): void
    {
        $codes = $this->codes($platform);
        $generated = $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $this->session(), new Origin(OriginKind::Web));
        $database = $this->stack->database;
        \assert($database instanceof Database);

        $stored = $database->connection->fetchFirstColumn(\sprintf('SELECT code_hash FROM %s ORDER BY code_hash', $database->table(DbalRecoveryCodeStore::TABLE)));
        $hashes = array_map(static fn (Secret $code): string => hash('sha256', str_replace('-', '', $code->reveal())), $generated);
        sort($hashes);

        self::assertSame($hashes, $stored);
    }

    #[DataProvider('implementations')]
    public function testACodeRecoversTheAccountOnce(string $implementation): void
    {
        $codes = $this->codes($implementation);
        $session = $this->session();
        $code = $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web))[4];
        $this->clock->sleep(60);
        $this->events->dispatched = [];

        // As someone types it from paper: lower case, spaces for hyphens, an O for a zero.
        $typed = strtolower(str_replace(['-', '0'], [' ', 'O'], $code->reveal()));
        $origin = new Origin(OriginKind::Web);
        $started = $codes->redeem(new Realm('acme'), $session->principalId, Secret::fromString($typed), $origin, 'Firefox on Linux', '192.0.2.10');

        self::assertNotNull($started);
        self::assertSame([AuthenticationMethod::RecoveryCode], $started->session->authentication->methods());
        self::assertEquals(new AuthenticationFactor(AuthenticationMethod::RecoveryCode, $this->clock->now()), $started->session->authentication->factors[0], 'A recovery code is one factor, and can be phished.');
        self::assertSame(['Firefox on Linux', '192.0.2.10'], [$started->session->device, $started->session->address]);
        self::assertNotNull($this->sessions->validate(new Realm('acme'), $started->token));
        self::assertSame(SessionEndReason::Recovered, $this->sessions->get(new Realm('acme'), $session->id)?->endReason, 'A session from before the recovery survived it.');
        self::assertSame(9, $codes->remaining(new Realm('acme'), $session->principalId));
        self::assertEquals($this->clock->now(), $this->credentials->of(new Realm('acme'), $session->principalId)[0]->lastUsedAt);

        $used = array_values(array_filter($this->entries(), static fn (LogEntry $entry): bool => 'yorvoid.recovery_code.use' === $entry->action));
        self::assertCount(1, $used);
        self::assertSame(Outcome::Success, $used[0]->outcome);
        self::assertEquals($session->principalId, $used[0]->actor);
        self::assertSame(['principal' => (string) $session->principalId, 'remaining' => 9], $used[0]->details);
        self::assertContains(RecoveryCodeUsed::class, $this->events->classes());
        self::assertSame([SessionStarting::class, SessionEnded::class, SessionStarted::class, RecoveryCodeUsed::class], array_values(array_filter($this->events->classes(), static fn (string $class): bool => SecurityLogEntryRecorded::class !== $class)));
        self::assertEquals(new RecoveryCodeUsed(new Realm('acme'), $session->principalId, 9, $origin), $this->events->last());

        self::assertNull($codes->redeem(new Realm('acme'), $session->principalId, $code, new Origin(OriginKind::Web)), 'A code worked twice.');
        $failed = $this->entries()[\count($this->entries()) - 1];
        self::assertSame(['yorvoid.recovery_code.use', Outcome::Failed, null], [$failed->action, $failed->outcome, $failed->actor]);
        self::assertSame(['principal' => (string) $session->principalId], $failed->details);
    }

    #[DataProvider('implementations')]
    public function testANewSetReplacesTheOldOne(string $implementation): void
    {
        $codes = $this->codes($implementation);
        $session = $this->session();
        $old = $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web));
        $entries = \count($this->entries());

        $new = $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web));

        self::assertCount(1, $this->credentials->of(new Realm('acme'), $session->principalId));
        self::assertSame(['yorvoid.recovery_codes.generate'], array_map(static fn (LogEntry $entry): string => $entry->action, \array_slice($this->entries(), $entries)), 'A new set added another credential.');
        self::assertNull($codes->redeem(new Realm('acme'), $session->principalId, $old[0], new Origin(OriginKind::Web)), 'An old code still works.');
        self::assertNotNull($codes->redeem(new Realm('acme'), $session->principalId, $new[0], new Origin(OriginKind::Web)));
    }

    #[DataProvider('implementations')]
    public function testGeneratingNeedsARecentProof(string $implementation): void
    {
        $codes = $this->codes($implementation);
        $session = $this->session();
        $this->clock->sleep(300);

        try {
            $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web));
            self::fail('A session that proved nothing recently got recovery codes.');
        } catch (StepUpRequired $exception) {
            self::assertEquals(new AuthenticationRequirement(Duration::minutes(5), operation: 'yorvoid.credential.add'), $exception->requirement, 'The first set is a new credential.');
        }

        $this->sessions->stepUp($session->realm, $session->id, new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true, 'yorvoid.credential.add'), new Origin(OriginKind::Web));
        $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web));
        $this->clock->sleep(300);

        try {
            $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web));
            self::fail('A session that proved nothing recently replaced the recovery codes.');
        } catch (StepUpRequired $exception) {
            self::assertEquals(new AuthenticationRequirement(Duration::minutes(5), operation: RecoveryCodes::GENERATE, heldAfterRecovery: true), $exception->requirement);
        }

        $this->sessions->stepUp($session->realm, $session->id, new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true, RecoveryCodes::GENERATE), new Origin(OriginKind::Web));
        self::assertCount(10, $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web)));
    }

    #[DataProvider('implementations')]
    public function testANewSetWaitsOutTheCoolDownButAFirstOneDoesnt(string $implementation): void
    {
        $codes = $this->codes($implementation, Duration::hours(24));
        $session = $this->session();
        $this->stepUp->startCoolDown(new Realm('acme'), $session->principalId, new Origin(OriginKind::Web));

        // Someone who recovered with a link saves codes at once.
        $code = $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web))[0];
        $this->clock->sleep(86_400);
        $recovered = $codes->redeem(new Realm('acme'), $session->principalId, $code, new Origin(OriginKind::Web));
        self::assertNotNull($recovered);

        $this->expectException(HeldAfterRecovery::class);

        $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $recovered->session, new Origin(OriginKind::Web));
    }

    #[DataProvider('implementations')]
    public function testOnlyThePrincipalsOwnWorkingCodesWork(string $implementation): void
    {
        $codes = $this->codes($implementation);
        $session = $this->session();
        $generated = $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web));
        $someoneElse = $this->session();
        $entries = \count($this->entries());

        self::assertNull($codes->redeem(new Realm('acme'), $someoneElse->principalId, $generated[0], new Origin(OriginKind::Web)), 'Someone else\'s code worked.');
        self::assertNull($codes->redeem(new Realm('globex'), $session->principalId, $generated[0], new Origin(OriginKind::Web)), 'A code worked in another realm.');
        self::assertNull($codes->redeem(new Realm('acme'), new PrincipalId(new UuidV7Generator()->next()), $generated[0], new Origin(OriginKind::Web)));
        self::assertCount($entries, $this->entries(), 'A principal without codes had a failed attempt recorded.');

        $credential = $this->credentials->of(new Realm('acme'), $session->principalId)[0];
        $this->credentials->disable($credential, 'testing', new Origin(OriginKind::Console));
        self::assertNull($codes->redeem(new Realm('acme'), $session->principalId, $generated[0], new Origin(OriginKind::Web)), 'A disabled set worked.');
        self::assertSame(10, $this->stack->recoveryCodes->count(new Realm('acme'), $session->principalId), 'A disabled set lost a code.');
    }

    #[DataProvider('implementations')]
    public function testRemovingTheSetMakesItsCodesUseless(string $implementation): void
    {
        $codes = $this->codes($implementation);
        $session = $this->session();
        $generated = $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web));
        $credential = $this->credentials->of(new Realm('acme'), $session->principalId)[0];

        $this->credentials->remove(new RemoveCredential(new Realm('acme'), $credential->id), $session, new Origin(OriginKind::Web));

        self::assertSame(0, $codes->remaining(new Realm('acme'), $session->principalId));
        self::assertNull($codes->redeem(new Realm('acme'), $session->principalId, $generated[0], new Origin(OriginKind::Web)));

        $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web));
        self::assertNull($codes->redeem(new Realm('acme'), $session->principalId, $generated[1], new Origin(OriginKind::Web)), 'A code of a removed set came back.');
        self::assertSame([CredentialKind::RecoveryCodes], array_map(static fn (Credential $credential): CredentialKind => $credential->kind, $this->credentials->of(new Realm('acme'), $session->principalId)));
    }

    #[DataProvider('implementations')]
    public function testAnInactivePrincipalDoesntRecoverAndKeepsTheCode(string $implementation): void
    {
        $codes = $this->codes($implementation);
        $session = $this->session();
        $code = $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web))[0];
        $this->principals->suspend(new SuspendPrincipal(new Realm('acme'), $session->principalId), new Origin(OriginKind::Console));

        try {
            $codes->redeem(new Realm('acme'), $session->principalId, $code, new Origin(OriginKind::Web));
            self::fail('A suspended principal recovered.');
        } catch (InactivePrincipal) {
        }

        $this->principals->activate(new ActivatePrincipal(new Realm('acme'), $session->principalId), new Origin(OriginKind::Console));
        self::assertNotNull($codes->redeem(new Realm('acme'), $session->principalId, $code, new Origin(OriginKind::Web)));
    }

    #[DataProvider('implementations')]
    public function testAttemptsAreLimitedAndASuccessForgetsThem(string $implementation): void
    {
        $codes = $this->codes($implementation);
        $session = $this->session();
        $generated = $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web));
        $wrong = Secret::fromString('0000-0000-0000-0000-0000-0000');

        for ($i = 0; $i < 9; ++$i) {
            self::assertNull($codes->redeem(new Realm('acme'), $session->principalId, $wrong, new Origin(OriginKind::Web), address: '192.0.2.1'));
        }

        self::assertNotNull($codes->redeem(new Realm('acme'), $session->principalId, $generated[0], new Origin(OriginKind::Web), address: '192.0.2.1'));

        for ($i = 0; $i < 10; ++$i) {
            self::assertNull($codes->redeem(new Realm('acme'), $session->principalId, $wrong, new Origin(OriginKind::Web), address: '192.0.2.1'), 'A success didn\'t forget the attempts before it.');
        }

        try {
            $codes->redeem(new Realm('acme'), $session->principalId, $generated[1], new Origin(OriginKind::Web), address: '192.0.2.1');
            self::fail('The eleventh attempt in 15 minutes was checked.');
        } catch (Throttled $exception) {
            self::assertSame(90, $exception->seconds);
        }

        self::assertSame(9, $codes->remaining(new Realm('acme'), $session->principalId), 'A throttled attempt used a code up.');
        self::assertNotNull($codes->redeem(new Realm('acme'), $session->principalId, $generated[1], new Origin(OriginKind::Web), address: '192.0.2.2'));
    }

    public function testTheSizeOfASetIsConfigurable(): void
    {
        $this->codes('memory');

        foreach ([1, 100] as $count) {
            $codes = new RecoveryCodes($this->stack->recoveryCodes, $this->credentials, $this->sessions, $this->stepUp, $this->attempts, new SecurityLog($this->stack->log, $this->stack->transactions, clock: $this->clock), $this->stack->transactions, clock: $this->clock, count: $count);
            self::assertCount($count, $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $this->session(), new Origin(OriginKind::Web)));
        }
    }

    public function testRefusesASetSizeOutOfRange(): void
    {
        $this->codes('memory');

        foreach ([0, 101] as $count) {
            try {
                new RecoveryCodes($this->stack->recoveryCodes, $this->credentials, $this->sessions, $this->stepUp, $this->attempts, new SecurityLog($this->stack->log, $this->stack->transactions), $this->stack->transactions, count: $count);
                self::fail(\sprintf('A set of %d codes was accepted.', $count));
            } catch (IntegratorError $exception) {
                self::assertStringStartsWith(\sprintf('A set of %d recovery codes is out of range.', $count), $exception->getMessage());
            }
        }
    }

    public function testRefusesToGenerateInAnotherRealm(): void
    {
        $codes = $this->codes('memory');

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The session is in another realm than the recovery codes.');

        $codes->generate(new GenerateRecoveryCodes(new Realm('globex')), $this->session(), new Origin(OriginKind::Web));
    }

    public function testGeneratingIsAnOperation(): void
    {
        $operation = new OperationCatalogue([GenerateRecoveryCodes::class])->get(RecoveryCodes::GENERATE);

        self::assertNull($operation->ability, 'Only the owner generates their own codes.');
    }

    private function codes(string $implementation, ?Duration $coolDown = null): RecoveryCodes
    {
        $this->stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-28 18:45:30 UTC');
        $this->events = new RecordingDispatcher();
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, $this->events, $ids, $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, ids: $ids, clock: $this->clock);
        $this->sessions = new Sessions($this->stack->sessions, $this->principals, $log, $this->stack->transactions, events: $this->events, ids: $ids, clock: $this->clock);
        $this->stepUp = new StepUp($this->sessions, $this->stack->coolDowns, $log, $this->clock, $coolDown);
        $this->credentials = new Credentials($this->stack->credentials, $this->sessions, $this->stepUp, $log, $this->stack->transactions, $this->events, $ids, $this->clock);

        $this->attempts = new Attempts(new Throttle($this->stack->throttle, $this->clock));

        return new RecoveryCodes($this->stack->recoveryCodes, $this->credentials, $this->sessions, $this->stepUp, $this->attempts, $log, $this->stack->transactions, $this->events, $this->clock);
    }

    private function session(): Session
    {
        $principal = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));
        $session = $this->sessions->start(new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true))), new Origin(OriginKind::Web))->session;
        $this->events->dispatched = [];

        return $session;
    }

    /**
     * @return list<LogEntry>
     */
    private function entries(): array
    {
        return iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
    }
}
