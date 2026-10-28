<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every KnownDeviceStore keeps the same promises (PLT-025).
 */
final class KnownDeviceStoreTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testADeviceIsKnownToThePrincipalsThatUsedIt(string $implementation): void
    {
        $store = $this->stack($implementation)->devices;
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
    }

    #[DataProvider('implementations')]
    public function testForgetsDevicesNotUsedSince(string $implementation): void
    {
        $store = $this->stack($implementation)->devices;
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
