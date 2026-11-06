<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Setup\SetupState;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * What every SetupStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MySetupStoreTest extends TestCase
 * {
 *     use SetupStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function setupStore(string $implementation): SetupStore
 *     {
 *         return new MySetupStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait SetupStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function setupStore(string $implementation): SetupStore;

    #[DataProvider('implementations')]
    public function testReplacesTheCodeUntilTheRealmIsClaimedOnce(string $implementation): void
    {
        $store = $this->setupStore($implementation);
        $created = new \DateTimeImmutable('2026-10-27 18:40:00 UTC');
        $expires = new \DateTimeImmutable('2026-10-28 18:40:00 UTC');
        $owner = new PrincipalId(new UuidV7());

        self::assertNull($store->get(new Realm('initech')), 'A realm nobody created a code for has a setup.');
        self::assertTrue($store->replaceCode(new Realm('acme'), str_repeat('a', 64), $created, $expires));
        self::assertTrue($store->replaceCode(new Realm('acme'), str_repeat('b', 64), $created, $expires));
        self::assertEquals(new SetupState(new Realm('acme'), str_repeat('b', 64), $created, $expires), $store->get(new Realm('acme')));
        self::assertNull($store->get(new Realm('globex')));

        self::assertFalse($store->claim(new Realm('acme'), str_repeat('a', 64), $owner, $expires), 'A replaced code claimed the realm.');
        self::assertFalse($store->claim(new Realm('globex'), str_repeat('b', 64), $owner, $expires), 'A realm without a code was claimed.');
        self::assertTrue($store->claim(new Realm('acme'), str_repeat('b', 64), $owner, new \DateTimeImmutable('2026-10-27 19:00:00 UTC')));
        self::assertFalse($store->claim(new Realm('acme'), str_repeat('b', 64), new PrincipalId(new UuidV7()), $expires), 'A realm was claimed twice.');
        self::assertFalse($store->replaceCode(new Realm('acme'), str_repeat('c', 64), $created, $expires), 'A claimed realm got a new code.');

        self::assertEquals(new SetupState(new Realm('acme'), str_repeat('b', 64), $created, $expires, new \DateTimeImmutable('2026-10-27 19:00:00 UTC'), $owner), $store->get(new Realm('acme')));
        $claimed = $store->get(new Realm('acme'));
        \assert(null !== $claimed);
        self::assertTrue($claimed->isClaimed());
    }
}
