<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Session\KnownDeviceStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * What every KnownDeviceStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyKnownDeviceStoreTest extends TestCase
 * {
 *     use KnownDeviceStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function knownDeviceStore(string $implementation): KnownDeviceStore
 *     {
 *         return new MyKnownDeviceStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait KnownDeviceStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function knownDeviceStore(string $implementation): KnownDeviceStore;

    #[DataProvider('implementations')]
    public function testADeviceIsKnownToThePrincipalsThatUsedIt(string $implementation): void
    {
        $store = $this->knownDeviceStore($implementation);
        $ana = new PrincipalId(new UuidV7());
        $ben = new PrincipalId(new UuidV7());
        self::assertFalse($store->hasAny(new Realm('acme'), $ana));

        self::assertFalse($store->recordUse(new Realm('acme'), $ana, self::hash('laptop'), self::at('18:00')));
        self::assertTrue($store->recordUse(new Realm('acme'), $ana, self::hash('laptop'), self::at('19:00')));
        self::assertTrue($store->hasAny(new Realm('acme'), $ana));

        self::assertFalse($store->recordUse(new Realm('acme'), $ben, self::hash('laptop'), self::at('19:00')), 'A device known to one principal was known to another.');
        self::assertFalse($store->recordUse(new Realm('globex'), $ana, self::hash('laptop'), self::at('19:00')), 'A device known in one realm was known in another.');
        self::assertFalse($store->recordUse(new Realm('acme'), $ana, self::hash('phone'), self::at('19:00')));
        self::assertFalse($store->hasAny(new Realm('initech'), $ana));

        self::assertTrue($store->isKnown(new Realm('acme'), $ana, self::hash('laptop')));
        self::assertTrue($store->isKnown(new Realm('acme'), $ben, self::hash('laptop')));
        self::assertFalse($store->isKnown(new Realm('acme'), $ana, self::hash('tablet')));
        self::assertFalse($store->isKnown(new Realm('initech'), $ana, self::hash('laptop')));
        self::assertFalse($store->isKnown(new Realm('acme'), new PrincipalId(new UuidV7()), self::hash('laptop')));
    }

    #[DataProvider('implementations')]
    public function testForgetsDevicesNotUsedSince(string $implementation): void
    {
        $store = $this->knownDeviceStore($implementation);
        $ana = new PrincipalId(new UuidV7());
        $store->recordUse(new Realm('acme'), $ana, self::hash('old'), self::at('17:00'));
        $store->recordUse(new Realm('acme'), $ana, self::hash('used again'), self::at('17:00'));
        $store->recordUse(new Realm('acme'), $ana, self::hash('used again'), self::at('18:00'));
        $store->recordUse(new Realm('globex'), $ana, self::hash('elsewhere'), self::at('17:59'));

        self::assertSame(2, $store->purge(self::at('18:00')));
        self::assertTrue($store->recordUse(new Realm('acme'), $ana, self::hash('used again'), self::at('19:00')));
        self::assertFalse($store->recordUse(new Realm('acme'), $ana, self::hash('old'), self::at('19:00')));
        self::assertFalse($store->hasAny(new Realm('globex'), $ana));
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-28 '.$time.':00 UTC');
    }

    private static function hash(string $cookie): string
    {
        return hash('sha256', $cookie);
    }
}
