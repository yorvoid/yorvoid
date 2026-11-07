<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Core\Realm;
use YorvoID\Passkeys\Ceremony;
use YorvoID\Passkeys\CeremonyKind;
use YorvoID\Passkeys\CeremonyStore;

/**
 * What every CeremonyStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyCeremonyStoreTest extends TestCase
 * {
 *     use CeremonyStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function ceremonyStore(string $implementation): CeremonyStore
 *     {
 *         return new MyCeremonyStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait CeremonyStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function ceremonyStore(string $implementation): CeremonyStore;

    #[DataProvider('implementations')]
    public function testACeremonyIsTakenOnce(string $implementation): void
    {
        $store = $this->ceremonyStore($implementation);
        $registration = new Ceremony(Uuid::v7(), new Realm('acme'), CeremonyKind::Registration, '{"challenge":"x"}', new \DateTimeImmutable('2026-10-27 18:50:00 UTC'), new SessionId(new UuidV7()));
        $signIn = new Ceremony(Uuid::v7(), new Realm('acme'), CeremonyKind::SignIn, '{"challenge":"y"}', new \DateTimeImmutable('2026-10-27 18:50:00 UTC'));
        $store->add($registration);
        $store->add($signIn);

        self::assertNull($store->take(new Realm('globex'), $registration->id, $registration->sessionId));
        self::assertNull($store->take(new Realm('acme'), $registration->id), 'A session\'s ceremony was taken without it.');
        self::assertNull($store->take(new Realm('acme'), $registration->id, new SessionId(new UuidV7())), 'Another session took the ceremony.');
        self::assertNull($store->take(new Realm('acme'), $signIn->id, new SessionId(new UuidV7())), 'A session took a ceremony begun outside one.');
        self::assertEquals($registration, $store->take(new Realm('acme'), $registration->id, $registration->sessionId), 'Someone else\'s attempt used the ceremony up.');
        self::assertNull($store->take(new Realm('acme'), $registration->id, $registration->sessionId), 'A ceremony was taken twice.');
        self::assertEquals($signIn, $store->take(new Realm('acme'), $signIn->id));
    }

    #[DataProvider('implementations')]
    public function testPurgesExpiredCeremonies(string $implementation): void
    {
        $store = $this->ceremonyStore($implementation);
        $expired = new Ceremony(Uuid::v7(), new Realm('acme'), CeremonyKind::SignIn, '{}', new \DateTimeImmutable('2026-10-27 18:50:00 UTC'));
        $current = new Ceremony(Uuid::v7(), new Realm('globex'), CeremonyKind::SignIn, '{}', new \DateTimeImmutable('2026-10-27 18:50:01 UTC'));
        $store->add($expired);
        $store->add($current);

        self::assertSame(1, $store->purge(new \DateTimeImmutable('2026-10-27 18:50:00 UTC')));
        self::assertNotNull($store->take(new Realm('globex'), $current->id));
    }
}
