<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every CoolDownStore keeps the same promises (PLT-025).
 */
final class CoolDownStoreTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testACoolDownOnlyGrowsLonger(string $implementation): void
    {
        $store = $this->stack($implementation)->coolDowns;
        $principal = new PrincipalId(new UuidV7());
        $someoneElse = new PrincipalId(new UuidV7());
        self::assertNull($store->until(new Realm('acme'), $principal));

        $store->extend(new Realm('acme'), $principal, new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));
        $store->extend(new Realm('acme'), $someoneElse, new \DateTimeImmutable('2026-10-30 18:00:00 UTC'));
        $store->extend(new Realm('globex'), $principal, new \DateTimeImmutable('2026-10-31 18:00:00 UTC'));
        self::assertEquals(new \DateTimeImmutable('2026-10-28 18:00:00 UTC'), $store->until(new Realm('acme'), $principal));

        $store->extend(new Realm('acme'), $principal, new \DateTimeImmutable('2026-10-29 18:00:00 UTC'));
        self::assertEquals(new \DateTimeImmutable('2026-10-29 18:00:00 UTC'), $store->until(new Realm('acme'), $principal));

        $store->extend(new Realm('acme'), $principal, new \DateTimeImmutable('2026-10-28 20:00:00 UTC'));
        self::assertEquals(new \DateTimeImmutable('2026-10-29 18:00:00 UTC'), $store->until(new Realm('acme'), $principal), 'A shorter cool-down cut a longer one short.');
        self::assertEquals(new \DateTimeImmutable('2026-10-30 18:00:00 UTC'), $store->until(new Realm('acme'), $someoneElse));
        self::assertEquals(new \DateTimeImmutable('2026-10-31 18:00:00 UTC'), $store->until(new Realm('globex'), $principal));
    }

    #[DataProvider('implementations')]
    public function testPurgesOnlyWhatEndedBefore(string $implementation): void
    {
        $store = $this->stack($implementation)->coolDowns;
        $ended = new PrincipalId(new UuidV7());
        $endingNow = new PrincipalId(new UuidV7());
        $store->extend(new Realm('acme'), $ended, new \DateTimeImmutable('2026-10-28 17:59:59 UTC'));
        $store->extend(new Realm('globex'), $endingNow, new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));

        self::assertSame(1, $store->purge(new \DateTimeImmutable('2026-10-28 18:00:00 UTC')));
        self::assertNull($store->until(new Realm('acme'), $ended));
        self::assertNotNull($store->until(new Realm('globex'), $endingNow));
    }
}
