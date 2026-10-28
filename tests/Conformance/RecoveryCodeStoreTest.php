<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every RecoveryCodeStore keeps the same promises (PLT-025).
 */
final class RecoveryCodeStoreTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testACodeWorksOnceForItsPrincipal(string $implementation): void
    {
        $store = $this->stack($implementation)->recoveryCodes;
        $principal = new PrincipalId(new UuidV7());
        $someoneElse = new PrincipalId(new UuidV7());
        $store->replace(new Realm('acme'), $principal, [self::hash('a'), self::hash('b'), self::hash('a')], new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));
        $store->replace(new Realm('acme'), $someoneElse, [self::hash('c')], new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));
        self::assertSame(2, $store->count(new Realm('acme'), $principal));

        self::assertFalse($store->use(new Realm('acme'), $principal, self::hash('c')), 'Someone else\'s code worked.');
        self::assertFalse($store->use(new Realm('globex'), $principal, self::hash('a')), 'A code worked in another realm.');
        self::assertTrue($store->use(new Realm('acme'), $principal, self::hash('a')));
        self::assertFalse($store->use(new Realm('acme'), $principal, self::hash('a')), 'A code worked twice.');
        self::assertSame(1, $store->count(new Realm('acme'), $principal));
        self::assertSame(1, $store->count(new Realm('acme'), $someoneElse));
        self::assertSame(0, $store->count(new Realm('globex'), $principal));
    }

    #[DataProvider('implementations')]
    public function testReplacingRemovesTheOldCodes(string $implementation): void
    {
        $store = $this->stack($implementation)->recoveryCodes;
        $principal = new PrincipalId(new UuidV7());
        $elsewhere = new PrincipalId(new UuidV7());
        $store->replace(new Realm('acme'), $principal, [self::hash('a'), self::hash('b')], new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));
        $store->replace(new Realm('globex'), $principal, [self::hash('a')], new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));
        $store->replace(new Realm('acme'), $elsewhere, [self::hash('a')], new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));

        $store->replace(new Realm('acme'), $principal, [self::hash('c')], new \DateTimeImmutable('2026-10-28 19:00:00 UTC'));

        self::assertFalse($store->use(new Realm('acme'), $principal, self::hash('a')), 'An old code still works.');
        self::assertTrue($store->use(new Realm('acme'), $principal, self::hash('c')));
        self::assertTrue($store->use(new Realm('globex'), $principal, self::hash('a')), 'Replacing reached another realm.');
        self::assertTrue($store->use(new Realm('acme'), $elsewhere, self::hash('a')), 'Replacing reached another principal.');

        $store->replace(new Realm('acme'), $principal, [], new \DateTimeImmutable('2026-10-28 20:00:00 UTC'));
        self::assertSame(0, $store->count(new Realm('acme'), $principal));
    }

    private static function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
