<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every SessionStore keeps the same promises (PLT-025).
 */
final class SessionStoreTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testKeepsEveryField(string $implementation): void
    {
        $store = $this->stack($implementation)->sessions;
        $full = new Session(
            new SessionId(new UuidV7()),
            new Realm('acme'),
            new PrincipalId(new UuidV7()),
            7,
            new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable('2026-10-27 18:40:00 UTC'), true, true), new AuthenticationFactor(AuthenticationMethod::RecoveryCode, new \DateTimeImmutable('2026-10-27 18:50:00 UTC'), false, false)),
            new \DateTimeImmutable('2026-10-27 18:40:00 UTC'),
            new \DateTimeImmutable('2026-10-27 18:55:00 UTC'),
            new \DateTimeImmutable('2026-10-28 18:40:00 UTC'),
            new \DateTimeImmutable('2026-10-27 19:55:00 UTC'),
            'Firefox on Linux, ünïcode',
            '2001:db8::1',
            new \DateTimeImmutable('2026-10-27 19:00:00 UTC'),
            SessionEndReason::EndedByAdministrator,
        );
        $bare = self::session(new Realm('acme'), new PrincipalId(new UuidV7()));

        $store->add($full, self::hash('full'));
        $store->add($bare, self::hash('bare'));

        self::assertEquals($full, $store->get(new Realm('acme'), $full->id));
        self::assertEquals($bare, $store->get(new Realm('acme'), $bare->id));
        self::assertEquals($full, $store->findByTokenHash(self::hash('full')));
        self::assertNull($store->findByTokenHash(self::hash('other')));
    }

    #[DataProvider('implementations')]
    public function testRefusesADuplicateIdOrToken(string $implementation): void
    {
        $store = $this->stack($implementation)->sessions;
        $session = self::session(new Realm('acme'), new PrincipalId(new UuidV7()));
        $store->add($session, self::hash('a'));

        foreach ([[$session, self::hash('b')], [self::session(new Realm('acme'), new PrincipalId(new UuidV7())), self::hash('a')]] as [$duplicate, $hash]) {
            try {
                $store->add($duplicate, $hash);
                self::fail('A duplicate session was added.');
            } catch (StorageException $exception) {
                self::assertStringContainsString('exists', $exception->getMessage());
            }
        }
    }

    #[DataProvider('implementations')]
    public function testFindsNothingInAnotherRealm(string $implementation): void
    {
        $store = $this->stack($implementation)->sessions;
        $session = self::session(new Realm('acme'), new PrincipalId(new UuidV7()));
        $store->add($session, self::hash('a'));

        self::assertNull($store->get(new Realm('globex'), $session->id));
        self::assertSame([], $store->current(new Realm('globex'), $session->principalId, new \DateTimeImmutable('2026-10-27 18:41:00 UTC')));
        self::assertFalse($store->end(new Realm('globex'), $session->id, new \DateTimeImmutable(), SessionEndReason::SignedOut));
        self::assertFalse($store->replaceTokenHash(new Realm('globex'), $session->id, self::hash('b')));
        self::assertFalse($store->stepUp(new Realm('globex'), $session->id, self::steppedUp($session), self::hash('b')));
        $store->touch(new Realm('globex'), $session->id, new \DateTimeImmutable('2026-10-27 18:50:00 UTC'), new \DateTimeImmutable('2026-10-27 19:50:00 UTC'));
        self::assertEquals($session, $store->findByTokenHash(self::hash('a')));
    }

    #[DataProvider('implementations')]
    public function testListsOnlyCurrentSessionsTheMostRecentlyUsedFirst(string $implementation): void
    {
        $store = $this->stack($implementation)->sessions;
        $principal = new PrincipalId(new UuidV7());
        $now = new \DateTimeImmutable('2026-10-27 20:00:00 UTC');
        $older = self::session(new Realm('acme'), $principal, lastSeen: '2026-10-27 19:10:00');
        $newer = self::session(new Realm('acme'), $principal, lastSeen: '2026-10-27 19:50:00');
        $ended = self::session(new Realm('acme'), $principal, lastSeen: '2026-10-27 19:55:00');
        $expired = self::session(new Realm('acme'), $principal, expires: '2026-10-27 20:00:00');
        $idle = self::session(new Realm('acme'), $principal, idleExpires: '2026-10-27 20:00:00');
        $someoneElses = self::session(new Realm('acme'), new PrincipalId(new UuidV7()));

        foreach ([$older, $newer, $ended, $expired, $idle, $someoneElses] as $i => $session) {
            $store->add($session, self::hash((string) $i));
        }

        self::assertTrue($store->end(new Realm('acme'), $ended->id, $now, SessionEndReason::SignedOut));
        self::assertEquals([$newer, $older], $store->current(new Realm('acme'), $principal, $now));
    }

    #[DataProvider('implementations')]
    public function testEndsASessionOnceAndThenNeverRenewsItsToken(string $implementation): void
    {
        $store = $this->stack($implementation)->sessions;
        $session = self::session(new Realm('acme'), new PrincipalId(new UuidV7()));
        $store->add($session, self::hash('a'));

        self::assertTrue($store->replaceTokenHash(new Realm('acme'), $session->id, self::hash('b')));
        self::assertNull($store->findByTokenHash(self::hash('a')), 'The old token still finds the session.');
        self::assertEquals($session, $store->findByTokenHash(self::hash('b')));

        self::assertTrue($store->end(new Realm('acme'), $session->id, new \DateTimeImmutable('2026-10-27 19:00:00 UTC'), SessionEndReason::SignedOut));
        self::assertFalse($store->end(new Realm('acme'), $session->id, new \DateTimeImmutable('2026-10-27 19:30:00 UTC'), SessionEndReason::EndedByAdministrator), 'An ended session ended again.');
        self::assertFalse($store->replaceTokenHash(new Realm('acme'), $session->id, self::hash('c')), 'An ended session got a new token.');
        self::assertFalse($store->stepUp(new Realm('acme'), $session->id, self::steppedUp($session), self::hash('c')), 'An ended session stepped up.');

        $ended = $store->get(new Realm('acme'), $session->id);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 19:00:00 UTC'), $ended?->endedAt);
        self::assertSame(SessionEndReason::SignedOut, $ended?->endReason);
        self::assertNull($store->findByTokenHash(self::hash('c')));
    }

    #[DataProvider('implementations')]
    public function testSteppingUpReplacesTheAuthenticationAndTheToken(string $implementation): void
    {
        $store = $this->stack($implementation)->sessions;
        $session = self::session(new Realm('acme'), new PrincipalId(new UuidV7()));
        $other = self::session(new Realm('acme'), $session->principalId);
        $store->add($session, self::hash('a'));
        $store->add($other, self::hash('other'));
        $authentication = self::steppedUp($session);

        self::assertTrue($store->stepUp(new Realm('acme'), $session->id, $authentication, self::hash('b')));
        self::assertNull($store->findByTokenHash(self::hash('a')), 'The old token still finds the session.');
        $steppedUp = $store->findByTokenHash(self::hash('b'));
        self::assertEquals($authentication, $steppedUp?->authentication);
        self::assertEquals(new Session($session->id, $session->realm, $session->principalId, $session->principalStatusVersion, $authentication, $session->startedAt, $session->lastSeenAt, $session->expiresAt, $session->idleExpiresAt), $steppedUp);
        self::assertEquals($other, $store->findByTokenHash(self::hash('other')), 'Another session changed.');
    }

    #[DataProvider('implementations')]
    public function testTouchingRecordsTheLastUse(string $implementation): void
    {
        $store = $this->stack($implementation)->sessions;
        $session = self::session(new Realm('acme'), new PrincipalId(new UuidV7()));
        $store->add($session, self::hash('a'));

        $store->touch(new Realm('acme'), $session->id, new \DateTimeImmutable('2026-10-27 18:50:00 UTC'), new \DateTimeImmutable('2026-10-27 19:50:00 UTC'));

        $touched = $store->get(new Realm('acme'), $session->id);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 18:50:00 UTC'), $touched?->lastSeenAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 19:50:00 UTC'), $touched?->idleExpiresAt);
        self::assertEquals($session->expiresAt, $touched?->expiresAt);
    }

    #[DataProvider('implementations')]
    public function testPurgesOnlyWhatEndedOrExpiredBefore(string $implementation): void
    {
        $store = $this->stack($implementation)->sessions;
        $principal = new PrincipalId(new UuidV7());
        $before = new \DateTimeImmutable('2026-10-27 20:00:00 UTC');
        $endedLongAgo = self::session(new Realm('acme'), $principal);
        $endedRecently = self::session(new Realm('acme'), $principal);
        $expired = self::session(new Realm('acme'), $principal, expires: '2026-10-27 19:59:59');
        $idle = self::session(new Realm('globex'), $principal, idleExpires: '2026-10-27 19:59:59');
        $current = self::session(new Realm('acme'), $principal, expires: '2026-10-27 20:00:00', idleExpires: '2026-10-27 20:00:00');

        foreach ([$endedLongAgo, $endedRecently, $expired, $idle, $current] as $i => $session) {
            $store->add($session, self::hash((string) $i));
        }

        $store->end(new Realm('acme'), $endedLongAgo->id, new \DateTimeImmutable('2026-10-27 19:59:59 UTC'), SessionEndReason::SignedOut);
        $store->end(new Realm('acme'), $endedRecently->id, $before, SessionEndReason::SignedOut);

        self::assertSame(3, $store->purge($before));
        self::assertNull($store->get(new Realm('acme'), $endedLongAgo->id));
        self::assertNull($store->get(new Realm('acme'), $expired->id));
        self::assertNull($store->get(new Realm('globex'), $idle->id));
        self::assertNotNull($store->get(new Realm('acme'), $endedRecently->id));
        self::assertNotNull($store->get(new Realm('acme'), $current->id));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tamperedAuthentications(): iterable
    {
        foreach (self::platforms() as $name => [$platform]) {
            yield $name.', not a list' => [$platform, '"passkey"'];
            yield $name.', a factor that isn\'t an object' => [$platform, '[5]'];
            yield $name.', no method' => [$platform, '[{"at": "2026-10-27T18:40:00+00:00", "multi_factor": true, "phishing_resistant": true}]'];
            yield $name.', no time' => [$platform, '[{"method": "passkey", "multi_factor": true, "phishing_resistant": true}]'];
            yield $name.', no strength' => [$platform, '[{"method": "passkey", "at": "2026-10-27T18:40:00+00:00", "phishing_resistant": true}]'];
            yield $name.', a strength that isn\'t yes or no' => [$platform, '[{"method": "passkey", "at": "2026-10-27T18:40:00+00:00", "multi_factor": true, "phishing_resistant": 1, "operation": null}]'];
            yield $name.', no operation' => [$platform, '[{"method": "passkey", "at": "2026-10-27T18:40:00+00:00", "multi_factor": true, "phishing_resistant": true}]'];
            yield $name.', an operation that isn\'t text' => [$platform, '[{"method": "passkey", "at": "2026-10-27T18:40:00+00:00", "multi_factor": true, "phishing_resistant": true, "operation": 7}]'];
        }
    }

    #[DataProvider('tamperedAuthentications')]
    public function testRefusesAnAuthenticationItDidntWrite(string $platform, string $json): void
    {
        $stack = $this->stack($platform);
        \assert(null !== $stack->database);
        $session = self::session(new Realm('acme'), new PrincipalId(new UuidV7()));
        $stack->sessions->add($session, self::hash('a'));
        $stack->database->connection->executeStatement(\sprintf('UPDATE %s SET authentication = ?', $stack->database->table(DbalSessionStore::TABLE)), [$json]);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('A session\'s authentication isn\'t what YorvoID wrote: the row was changed.');

        $stack->sessions->get(new Realm('acme'), $session->id);
    }

    private static function session(Realm $realm, PrincipalId $principal, string $lastSeen = '2026-10-27 18:40:00', string $expires = '2026-10-28 18:40:00', string $idleExpires = '2026-10-27 21:40:00'): Session
    {
        $started = new \DateTimeImmutable('2026-10-27 18:40:00 UTC');

        return new Session(new SessionId(new UuidV7()), $realm, $principal, 1, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $started)), $started, new \DateTimeImmutable($lastSeen.' UTC'), new \DateTimeImmutable($expires.' UTC'), new \DateTimeImmutable($idleExpires.' UTC'));
    }

    private static function steppedUp(Session $session): AuthenticationContext
    {
        return $session->authentication->with(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable('2026-10-27 18:50:00 UTC'), true, true, 'yorvoid.credential.remove'));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
