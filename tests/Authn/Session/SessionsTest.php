<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Session;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSession;
use YorvoID\Authn\Session\EndSessions;
use YorvoID\Authn\Session\EndSessionsOfInactivePrincipals;
use YorvoID\Authn\Session\Event\NewDeviceSignedIn;
use YorvoID\Authn\Session\Event\SessionEnded;
use YorvoID\Authn\Session\Event\SessionRefreshed;
use YorvoID\Authn\Session\Event\SessionStarted;
use YorvoID\Authn\Session\Event\SessionStarting;
use YorvoID\Authn\Session\Event\SessionSteppedUp;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\SessionPolicy;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Event\SecurityLogEntryRecorded;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationCancelled;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\ActivatePrincipal;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\GuardRule;
use YorvoID\Core\Principal\GuardRuleViolated;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalChange;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\Stack;

/**
 * Authentication sessions on every implementation (SES-001…005, SES-011, SES-013).
 */
final class SessionsTest extends DatabaseTestCase
{
    private RecordingDispatcher $events;

    private MockClock $clock;

    private Principals $principals;

    private Stack $stack;

    #[DataProvider('implementations')]
    public function testStartsASessionAndValidatesItsToken(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $principal = $this->principal();
        $passkey = new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now());

        $started = $sessions->start(new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext($passkey), 'Firefox on Linux', '192.0.2.10'), new Origin(OriginKind::Web, 'sign-in', 'req-1'));
        $session = $started->session;

        self::assertTrue($session->principalId->equals($principal->id));
        self::assertSame($principal->statusVersion, $session->principalStatusVersion);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 18:45:30 UTC'), $session->startedAt);
        self::assertEquals($session->startedAt, $session->lastSeenAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-28 02:45:30 UTC'), $session->expiresAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 19:45:30 UTC'), $session->idleExpiresAt);
        self::assertSame(['Firefox on Linux', '192.0.2.10'], [$session->device, $session->address]);
        self::assertSame([AuthenticationMethod::Passkey], $session->authentication->methods());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $started->token->reveal(), 'A token is 256 random bits, URL-safe.');
        self::assertEquals($session, $sessions->validate(new Realm('acme'), $started->token));
        self::assertEquals($session, $sessions->get(new Realm('acme'), $session->id));

        $entry = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false)[1];
        self::assertSame('yorvoid.session.start', $entry->action);
        self::assertSame(['methods' => ['passkey'], 'principal' => (string) $principal->id], $entry->details, 'The log holds no address or device: they\'re personal data.');
        self::assertEquals($principal->id, $entry->actor);
        self::assertSame((string) $session->id, $entry->subjectId);
        self::assertSame([SessionStarting::class, SecurityLogEntryRecorded::class, SessionStarted::class], $this->events->classes());
    }

    #[DataProvider('platforms')]
    public function testStoresOnlyTheTokensHash(string $platform): void
    {
        $sessions = $this->sessions($platform);
        $started = $this->start($sessions, $this->principal());
        $database = $this->stack->database;
        \assert($database instanceof Database);

        $stored = $database->connection->fetchOne(\sprintf('SELECT token_hash FROM %s', $database->table(DbalSessionStore::TABLE)));

        self::assertSame(hash('sha256', $started->token->reveal()), $stored);
    }

    #[DataProvider('implementations')]
    public function testRefusesUnknownTokensAndOtherRealms(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $started = $this->start($sessions, $this->principal());

        self::assertNull($sessions->validate(new Realm('acme'), Secret::fromString('not-a-token')));
        self::assertNull($sessions->validate(new Realm('globex'), $started->token), 'A session was valid in another realm.');
        self::assertNull($sessions->get(new Realm('globex'), $started->session->id));
    }

    #[DataProvider('implementations')]
    public function testSessionsExpireAfterTheirLimits(string $implementation): void
    {
        $sessions = $this->sessions($implementation, new SessionPolicy(Duration::hours(8), Duration::hours(1)));
        $started = $this->start($sessions, $this->principal());

        // Active every 50 minutes: the idle timeout never ends it, the absolute one does, after 8 hours.
        $valid = [];

        for ($minutes = 50; $minutes <= 500; $minutes += 50) {
            $this->clock->sleep(50 * 60);
            $valid[$minutes] = null !== $sessions->validate(new Realm('acme'), $started->token);
        }

        self::assertSame([50 => true, 100 => true, 150 => true, 200 => true, 250 => true, 300 => true, 350 => true, 400 => true, 450 => true, 500 => false], $valid);

        $idle = $this->start($sessions, $this->principal());
        $this->clock->sleep(3600 - 1);
        self::assertNotNull($sessions->validate(new Realm('acme'), $idle->token));
        $this->clock->sleep(3600 - 1);
        self::assertNotNull($sessions->validate(new Realm('acme'), $idle->token), 'Using it didn\'t extend it.');
        $this->clock->sleep(3600);
        self::assertNull($sessions->validate(new Realm('acme'), $idle->token), 'An idle session was still valid.');
    }

    #[DataProvider('implementations')]
    public function testRecordsUseAtMostOnceAMinute(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $started = $this->start($sessions, $this->principal());

        $this->clock->sleep(59);
        self::assertEquals($started->session->lastSeenAt, $sessions->validate(new Realm('acme'), $started->token)?->lastSeenAt);
        self::assertEquals($started->session, $sessions->get(new Realm('acme'), $started->session->id), 'A request within a minute wrote.');

        $this->clock->sleep(1);
        $used = $sessions->validate(new Realm('acme'), $started->token);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 18:46:30 UTC'), $used?->lastSeenAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 19:46:30 UTC'), $used?->idleExpiresAt);
        self::assertEquals($used, $sessions->get(new Realm('acme'), $started->session->id));
    }

    #[DataProvider('implementations')]
    public function testATightenedPolicyAppliesToExistingSessions(string $implementation): void
    {
        $long = $this->sessions($implementation, new SessionPolicy(Duration::hours(24), Duration::hours(8)));
        $started = $this->start($long, $this->principal());
        $tight = new Sessions($this->stack->sessions, $this->principals, new SecurityLog($this->stack->log, $this->stack->transactions), $this->stack->transactions, SessionPolicy::aal3(), clock: $this->clock);

        $this->clock->sleep(15 * 60);

        self::assertNull($tight->validate(new Realm('acme'), $started->token), 'A session outlived the current idle timeout.');
        self::assertSame([], $tight->current(new Realm('acme'), $started->session->principalId));
        self::assertNull($tight->refresh(new Realm('acme'), $started->session->id, new Origin(OriginKind::Web)));
        self::assertNotNull($long->validate(new Realm('acme'), $started->token), 'Under the old policy, it\'s still valid.');
    }

    #[DataProvider('implementations')]
    public function testTheCurrentPolicysLimitsEndAtTheirExactMoment(string $implementation): void
    {
        $long = $this->sessions($implementation, new SessionPolicy(Duration::hours(24), Duration::hours(24)));
        $tight = new Sessions($this->stack->sessions, $this->principals, new SecurityLog($this->stack->log, $this->stack->transactions), $this->stack->transactions, new SessionPolicy(Duration::hours(2), Duration::minutes(30)), clock: $this->clock);
        $principal = $this->principal();
        $old = $this->start($long, $principal);
        $this->clock->sleep(90 * 60);
        $long->validate(new Realm('acme'), $old->token);
        $idle = $this->start($long, $principal);

        $this->clock->sleep(30 * 60 - 1);
        // Listing doesn't count as using it, which would extend its idle limit.
        self::assertCount(2, $tight->current(new Realm('acme'), $principal->id));
        self::assertNotNull($tight->validate(new Realm('acme'), $old->token));
        $this->clock->sleep(1);
        self::assertNull($tight->validate(new Realm('acme'), $idle->token), 'The idle limit is exclusive.');
        self::assertNull($tight->validate(new Realm('acme'), $old->token), 'The absolute limit is exclusive.');
    }

    #[DataProvider('implementations')]
    public function testListsOnlySessionsWithinTheCurrentPolicy(string $implementation): void
    {
        $long = $this->sessions($implementation, new SessionPolicy(Duration::hours(24), Duration::hours(24)));
        $tight = new Sessions($this->stack->sessions, $this->principals, new SecurityLog($this->stack->log, $this->stack->transactions), $this->stack->transactions, new SessionPolicy(Duration::hours(2), Duration::hours(2)), clock: $this->clock);
        $principal = $this->principal();
        $old = $this->start($long, $principal);
        $this->clock->sleep(110 * 60);
        $recent = $this->start($long, $principal);
        $this->clock->sleep(15 * 60);
        $long->validate(new Realm('acme'), $old->token);

        // The most recently used one is past the current absolute limit.
        self::assertSame([(string) $old->session->id, (string) $recent->session->id], array_map(static fn ($session): string => (string) $session->id, $long->current(new Realm('acme'), $principal->id)));
        self::assertSame([(string) $recent->session->id], array_map(static fn ($session): string => (string) $session->id, $tight->current(new Realm('acme'), $principal->id)));
    }

    #[DataProvider('implementations')]
    public function testRefusesASessionWhosePrincipalIsGone(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $started = $this->start($sessions, $this->principal());
        $orphan = $started->session;
        $other = new Session(new SessionId(new UuidV7Generator()->next()), $orphan->realm, new PrincipalId(new UuidV7Generator()->next()), 1, $orphan->authentication, $orphan->startedAt, $orphan->lastSeenAt, $orphan->expiresAt, $orphan->idleExpiresAt);
        $this->stack->sessions->add($other, hash('sha256', 'orphan'));

        self::assertNull($sessions->validate(new Realm('acme'), Secret::fromString('orphan')));
    }

    #[DataProvider('implementations')]
    public function testASessionEndsWithItsPrincipalsActivity(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $principal = $this->principal();
        $started = $this->start($sessions, $principal);

        $this->principals->suspend(new SuspendPrincipal(new Realm('acme'), $principal->id), new Origin(OriginKind::Console));
        self::assertNull($sessions->validate(new Realm('acme'), $started->token), 'A suspended principal\'s session was valid.');

        $this->principals->activate(new ActivatePrincipal(new Realm('acme'), $principal->id), new Origin(OriginKind::Console));
        self::assertNull($sessions->validate(new Realm('acme'), $started->token), 'A session came back with its principal.');

        self::assertNotNull($sessions->validate(new Realm('acme'), $this->start($sessions, $principal)->token), 'A new session after activation isn\'t valid.');
    }

    #[DataProvider('implementations')]
    public function testOnlyActivePrincipalsCanStartSessions(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $invited = $this->principal(PrincipalStatus::Invited);

        try {
            $this->start($sessions, $invited);
            self::fail('An invited principal got a session.');
        } catch (InactivePrincipal $exception) {
            self::assertSame(\sprintf('The principal %s is invited, and can\'t have a session.', $invited->id), $exception->getMessage());
        }

        $this->expectException(UnknownPrincipal::class);

        $sessions->start(new StartSession(new Realm('acme'), new PrincipalId(new UuidV7Generator()->next()), self::passkey($this->clock)), new Origin(OriginKind::Web));
    }

    #[DataProvider('implementations')]
    public function testAListenerCanRefuseASession(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $principal = $this->principal();
        $this->events->listen(SessionStarting::class, static function (SessionStarting $event): void {
            $event->cancel('Not outside office hours.');
        });

        try {
            $this->start($sessions, $principal);
            self::fail('The session started.');
        } catch (OperationCancelled $exception) {
            self::assertSame('yorvoid.session.start', $exception->operation);
        }

        self::assertSame([], $sessions->current(new Realm('acme'), $principal->id));
    }

    #[DataProvider('implementations')]
    public function testRefreshingReplacesTheToken(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $started = $this->start($sessions, $this->principal());
        $this->events->dispatched = [];

        $token = $sessions->refresh(new Realm('acme'), $started->session->id, new Origin(OriginKind::Web));

        self::assertNotNull($token);
        self::assertFalse($token->equals($started->token));
        self::assertNull($sessions->validate(new Realm('acme'), $started->token), 'The old token still works.');
        self::assertEquals($started->session, $sessions->validate(new Realm('acme'), $token));
        self::assertSame([SessionRefreshed::class], $this->events->classes());

        $sessions->end(new EndSession(new Realm('acme'), $started->session->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web));
        self::assertNull($sessions->refresh(new Realm('acme'), $started->session->id, new Origin(OriginKind::Web)), 'An ended session got a new token.');
        self::assertNull($sessions->refresh(new Realm('globex'), $started->session->id, new Origin(OriginKind::Web)));
    }

    #[DataProvider('implementations')]
    public function testSteppingUpAddsTheProofAndReplacesTheToken(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $started = $this->start($sessions, $this->principal());
        $this->clock->sleep(600);
        $sessions->validate(new Realm('acme'), $started->token);
        $this->events->dispatched = [];
        $factor = new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true, 'yorvoid.credential.remove');

        $token = $sessions->stepUp(new Realm('acme'), $started->session->id, $factor, new Origin(OriginKind::Web));

        self::assertNotNull($token);
        self::assertFalse($token->equals($started->token));
        self::assertNull($sessions->validate(new Realm('acme'), $started->token), 'The old token still works.');
        $steppedUp = $sessions->validate(new Realm('acme'), $token);
        self::assertEquals($started->session->authentication->with($factor), $steppedUp?->authentication);
        self::assertEquals($started->session->startedAt, $steppedUp?->startedAt);
        self::assertEquals($this->clock->now(), $steppedUp?->lastSeenAt);

        $entry = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false)[2];
        self::assertSame('yorvoid.session.step_up', $entry->action);
        self::assertSame((string) $started->session->id, $entry->subjectId);
        self::assertEquals($started->session->principalId, $entry->actor);
        self::assertSame(['method' => 'passkey', 'operation' => 'yorvoid.credential.remove', 'principal' => (string) $started->session->principalId], $entry->details);

        self::assertSame([SecurityLogEntryRecorded::class, SessionSteppedUp::class], $this->events->classes());
        $event = $this->events->last();
        \assert($event instanceof SessionSteppedUp);
        self::assertSame($factor, $event->factor);
        self::assertEquals($started->session->authentication->with($factor), $event->session->authentication);
        self::assertEquals($started->session->id, $event->session->id);
    }

    #[DataProvider('implementations')]
    public function testOnlyACurrentSessionOfAnActivePrincipalStepsUp(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $principal = $this->principal();
        $factor = new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true);
        $ended = $this->start($sessions, $principal)->session;
        $sessions->end(new EndSession(new Realm('acme'), $ended->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web));
        $current = $this->start($sessions, $principal)->session;

        self::assertNull($sessions->stepUp(new Realm('acme'), $ended->id, $factor, new Origin(OriginKind::Web)));
        self::assertNull($sessions->stepUp(new Realm('globex'), $current->id, $factor, new Origin(OriginKind::Web)));

        $orphan = new Session(new SessionId(new UuidV7Generator()->next()), $current->realm, new PrincipalId(new UuidV7Generator()->next()), 1, $current->authentication, $current->startedAt, $current->lastSeenAt, $current->expiresAt, $current->idleExpiresAt);
        $this->stack->sessions->add($orphan, hash('sha256', 'orphan'));
        self::assertNull($sessions->stepUp(new Realm('acme'), $orphan->id, $factor, new Origin(OriginKind::Web)), 'A session whose principal is gone stepped up.');

        $this->principals->suspend(new SuspendPrincipal(new Realm('acme'), $principal->id), new Origin(OriginKind::Console));
        self::assertNull($sessions->stepUp(new Realm('acme'), $current->id, $factor, new Origin(OriginKind::Web)), 'A suspended principal stepped up.');
        $this->principals->activate(new ActivatePrincipal(new Realm('acme'), $principal->id), new Origin(OriginKind::Console));
        self::assertNull($sessions->stepUp(new Realm('acme'), $current->id, $factor, new Origin(OriginKind::Web)), 'A session came back with its principal.');

        $idle = $this->start($sessions, $principal)->session;
        $this->clock->sleep(3600);
        self::assertNull($sessions->stepUp(new Realm('acme'), $idle->id, $factor, new Origin(OriginKind::Web)), 'An idle session stepped up.');
        self::assertSame([], array_filter(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), static fn ($entry): bool => 'yorvoid.session.step_up' === $entry->action));
    }

    #[DataProvider('implementations')]
    public function testRecognisesDevicesByTheirCookie(string $implementation): void
    {
        $sessions = $this->sessions($implementation, devices: true);
        $ana = $this->principal();

        $first = $this->start($sessions, $ana);
        self::assertNotNull($first->deviceCookie);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $first->deviceCookie->reveal(), 'A device cookie is 256 random bits, URL-safe.');
        self::assertFalse($first->newDevice, 'A first sign-in is no news.');
        self::assertSame([SessionStarting::class, SessionStarted::class], array_values(array_filter($this->events->classes(), static fn (string $class): bool => SecurityLogEntryRecorded::class !== $class)));

        $again = $this->start($sessions, $ana, $first->deviceCookie);
        self::assertTrue($first->deviceCookie->equals($again->deviceCookie ?? Secret::fromString('')), 'A known device got another cookie.');
        self::assertFalse($again->newDevice);

        $this->events->dispatched = [];
        $elsewhere = $this->start($sessions, $ana);
        self::assertTrue($elsewhere->newDevice);
        self::assertFalse($first->deviceCookie->equals($elsewhere->deviceCookie ?? Secret::fromString('')));
        self::assertSame([SessionStarting::class, SessionStarted::class, NewDeviceSignedIn::class], array_values(array_filter($this->events->classes(), static fn (string $class): bool => SecurityLogEntryRecorded::class !== $class)));
        $event = $this->events->last();
        \assert($event instanceof NewDeviceSignedIn);
        self::assertEquals($elsewhere->session, $event->session);

        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        self::assertSame(['methods' => ['passkey'], 'new_device' => true, 'principal' => (string) $ana->id], $entries[\count($entries) - 1]->details);
        self::assertFalse($entries[\count($entries) - 2]->details['new_device'] ?? null);

        // A cookie YorvoID didn't set is just another device; it's kept, so the browser keeps one cookie.
        $forged = $this->start($sessions, $ana, Secret::fromString('forged'));
        self::assertTrue($forged->newDevice);
        self::assertSame('forged', $forged->deviceCookie?->reveal());
        self::assertFalse($this->start($sessions, $ana, Secret::fromString('forged'))->newDevice);

        // One browser, two accounts: new to the second account, which has no other device.
        self::assertFalse($this->start($sessions, $this->principal(), $first->deviceCookie)->newDevice);
    }

    #[DataProvider('implementations')]
    public function testWithoutAStoreDevicesArentRecognised(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $started = $this->start($sessions, $this->principal(), Secret::fromString('cookie'));

        self::assertNull($started->deviceCookie);
        self::assertFalse($started->newDevice);
        self::assertSame(0, $sessions->purgeDevices(Duration::days(1)));
    }

    #[DataProvider('implementations')]
    public function testForgetsDevicesNotUsedForLong(string $implementation): void
    {
        $sessions = $this->sessions($implementation, devices: true);
        $ana = $this->principal();
        $old = $this->start($sessions, $ana)->deviceCookie;
        $this->clock->sleep(86_400);
        $recent = $this->start($sessions, $ana)->deviceCookie;
        $this->clock->sleep(86_399);

        self::assertSame(1, $sessions->purgeDevices(Duration::days(1)));
        self::assertSame(0, $sessions->purgeDevices(Duration::days(1)));
        self::assertFalse($this->start($sessions, $ana, $recent)->newDevice);
        self::assertTrue($this->start($sessions, $ana, $old)->newDevice, 'A forgotten device was still known.');
    }

    #[DataProvider('implementations')]
    public function testEndingASessionTakesEffectAtOnce(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $started = $this->start($sessions, $this->principal());
        $administrator = new PrincipalId(new UuidV7Generator()->next());
        $endings = [];
        $this->events->listen(SessionEnded::class, static function (SessionEnded $event) use (&$endings): void {
            $endings[] = $event;
        });
        $this->events->dispatched = [];

        self::assertTrue($sessions->end(new EndSession(new Realm('acme'), $started->session->id, SessionEndReason::EndedByAdministrator), new Origin(OriginKind::Web, 'admin.sessions'), $administrator));
        self::assertFalse($sessions->end(new EndSession(new Realm('acme'), $started->session->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web)), 'An ended session ended again.');
        self::assertNull($sessions->validate(new Realm('acme'), $started->token));

        $ended = $sessions->get(new Realm('acme'), $started->session->id);
        \assert(null !== $ended);
        self::assertSame(SessionEndReason::EndedByAdministrator, $ended->endReason);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 18:45:30 UTC'), $ended->endedAt);

        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        self::assertCount(3, $entries);
        self::assertSame('yorvoid.session.end', $entries[2]->action);
        self::assertSame(['principal' => (string) $started->session->principalId, 'reason' => 'ended_by_administrator'], $entries[2]->details);
        self::assertEquals($administrator, $entries[2]->actor);
        self::assertSame([SecurityLogEntryRecorded::class, SessionEnded::class], $this->events->classes());
        self::assertCount(1, $endings);
        self::assertEquals($ended, $endings[0]->session);
        self::assertEquals($administrator, $endings[0]->actor);
    }

    #[DataProvider('implementations')]
    public function testListsAndEndsAPrincipalsOtherSessions(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $principal = $this->principal();
        $laptop = $this->start($sessions, $principal);
        $this->clock->sleep(60);
        $phone = $this->start($sessions, $principal);
        $this->clock->sleep(60);
        $tablet = $this->start($sessions, $principal);
        $someoneElses = $this->start($sessions, $this->principal());

        self::assertSame([(string) $tablet->session->id, (string) $phone->session->id, (string) $laptop->session->id], array_map(static fn ($session): string => (string) $session->id, $sessions->current(new Realm('acme'), $principal->id)));

        self::assertSame(2, $sessions->endAll(new EndSessions(new Realm('acme'), $principal->id, SessionEndReason::EndedByPrincipal, except: $phone->session->id), new Origin(OriginKind::Web)));

        self::assertSame([(string) $phone->session->id], array_map(static fn ($session): string => (string) $session->id, $sessions->current(new Realm('acme'), $principal->id)));
        self::assertNull($sessions->validate(new Realm('acme'), $laptop->token));
        self::assertNotNull($sessions->validate(new Realm('acme'), $someoneElses->token));
        self::assertSame(1, $sessions->endAll(new EndSessions(new Realm('acme'), $principal->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web)));
    }

    #[DataProvider('implementations')]
    public function testSuspendingAPrincipalEndsItsSessionsInTheSameTransaction(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $this->events->listen(PrincipalStatusChanging::class, new EndSessionsOfInactivePrincipals($sessions)(...));
        $principal = $this->principal();
        $started = $this->start($sessions, $principal);
        $actor = new PrincipalId(new UuidV7Generator()->next());

        $this->principals->suspend(new SuspendPrincipal(new Realm('acme'), $principal->id), new Origin(OriginKind::Web), $actor);

        $ended = $sessions->get(new Realm('acme'), $started->session->id);
        self::assertSame(SessionEndReason::PrincipalDeactivated, $ended?->endReason);
        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        self::assertSame(['yorvoid.principal.create', 'yorvoid.session.start', 'yorvoid.session.end', 'yorvoid.principal.suspend'], array_map(static fn ($entry): string => $entry->action, $entries));
        self::assertEquals($actor, $entries[2]->actor);

        // Deleting a suspended principal has no sessions left to end.
        $this->principals->delete(new DeletePrincipal(new Realm('acme'), $principal->id), new Origin(OriginKind::Web));
        self::assertCount(5, iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false));
    }

    #[DataProvider('implementations')]
    public function testSessionsSurviveASuspensionThatWasRefused(string $implementation): void
    {
        $refuse = new class implements GuardRule {
            public function check(PrincipalChange $change): string
            {
                return 'The last owner can\'t be suspended.';
            }
        };
        $sessions = $this->sessions($implementation, guardRules: [$refuse]);
        $this->events->listen(PrincipalStatusChanging::class, new EndSessionsOfInactivePrincipals($sessions)(...));
        $principal = $this->principal();
        $started = $this->start($sessions, $principal);

        try {
            $this->principals->suspend(new SuspendPrincipal(new Realm('acme'), $principal->id), new Origin(OriginKind::Web));
            self::fail('The guard rule didn\'t refuse.');
        } catch (GuardRuleViolated) {
        }

        self::assertNotNull($sessions->validate(new Realm('acme'), $started->token), 'The refused suspension ended the session anyway.');
    }

    #[DataProvider('implementations')]
    public function testPurgesOldSessions(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $old = $this->start($sessions, $this->principal());
        $sessions->end(new EndSession(new Realm('acme'), $old->session->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web));
        $this->clock->sleep(86_400);
        $recent = $this->start($sessions, $this->principal());
        $sessions->end(new EndSession(new Realm('acme'), $recent->session->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web));

        self::assertSame(1, $sessions->purge(Duration::hours(12)));
        self::assertNull($sessions->get(new Realm('acme'), $old->session->id));
        self::assertNotNull($sessions->get(new Realm('acme'), $recent->session->id));
    }

    #[DataProvider('implementations')]
    public function testKeepsWhatTheDeviceSaysWithinLimits(string $implementation): void
    {
        $sessions = $this->sessions($implementation);
        $principal = $this->principal();

        $started = $sessions->start(new StartSession(new Realm('acme'), $principal->id, self::passkey($this->clock), "Fire\xC3fox ".str_repeat('ü', 300)), new Origin(OriginKind::Web));

        self::assertSame(255, mb_strlen((string) $started->session->device));
        self::assertStringStartsWith("Fire\u{FFFD}fox üü", (string) $started->session->device);
        self::assertEquals($started->session, $sessions->get(new Realm('acme'), $started->session->id));
    }

    public function testRefusesAnAddressThatIsntOne(): void
    {
        $sessions = $this->sessions('memory');

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The session\'s address isn\'t an IP address.');

        $sessions->start(new StartSession(new Realm('acme'), $this->principal()->id, self::passkey($this->clock), address: 'example.com'), new Origin(OriginKind::Web));
    }

    public function testSessionCommandsAreOperations(): void
    {
        $catalogue = new OperationCatalogue([StartSession::class, EndSession::class, EndSessions::class]);

        self::assertNull($catalogue->get('yorvoid.session.start')->ability);
        self::assertSame('yorvoid.session.end', $catalogue->get('yorvoid.session.start')->inverse);
        self::assertSame('yorvoid.sessions.manage', $catalogue->get('yorvoid.session.end')->ability);
        self::assertSame('yorvoid.sessions.manage', $catalogue->get('yorvoid.session.end_all')->ability);
    }

    /**
     * @param list<GuardRule> $guardRules
     */
    private function sessions(string $implementation, ?SessionPolicy $policy = null, array $guardRules = [], bool $devices = false): Sessions
    {
        $this->stack = $this->stack($implementation);
        $this->events = new RecordingDispatcher();
        $this->clock = new MockClock('2026-10-27 18:45:30 UTC');
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, $this->events, $ids, $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, $this->events, $ids, $this->clock, $guardRules);

        return new Sessions($this->stack->sessions, $this->principals, $log, $this->stack->transactions, $policy ?? new SessionPolicy(Duration::hours(8), Duration::hours(1)), $this->events, $ids, $this->clock, $devices ? $this->stack->devices : null);
    }

    private function principal(PrincipalStatus $status = PrincipalStatus::Active): Principal
    {
        $principal = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, $status), new Origin(OriginKind::Console));
        $this->events->dispatched = [];

        return $principal;
    }

    private function start(Sessions $sessions, Principal $principal, ?Secret $deviceCookie = null): StartedSession
    {
        return $sessions->start(new StartSession(new Realm('acme'), $principal->id, self::passkey($this->clock), deviceCookie: $deviceCookie), new Origin(OriginKind::Web));
    }

    private static function passkey(MockClock $clock): AuthenticationContext
    {
        return new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $clock->now()));
    }
}
