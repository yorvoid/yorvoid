<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Token\OneTimeToken;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every OneTimeTokenStore keeps the same promises (PLT-025).
 */
final class OneTimeTokenStoreTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testKeepsEveryFieldAndFindsByRealmSelectorAndPurpose(string $implementation): void
    {
        $store = $this->stack($implementation)->tokens;
        $bound = self::token(new Realm('acme'), self::id(), email: 'ana@example.com');
        $unbound = self::token(new Realm('acme'), self::id());
        $store->add($bound, str_repeat('a', 64));
        $store->add($unbound, str_repeat('b', 64));

        self::assertEquals([$bound, str_repeat('a', 64)], $store->find(new Realm('acme'), $bound->selector, 'acme.test'));
        self::assertEquals([$unbound, str_repeat('b', 64)], $store->find(new Realm('acme'), $unbound->selector, 'acme.test'));
        self::assertNull($store->find(new Realm('globex'), $bound->selector, 'acme.test'), 'A token was found in another realm.');
        self::assertNull($store->find(new Realm('acme'), $bound->selector, 'acme.other'), 'A token was found for another purpose.');
        self::assertNull($store->find(new Realm('acme'), bin2hex(random_bytes(16)), 'acme.test'));
    }

    #[DataProvider('implementations')]
    public function testRefusesADuplicateSelector(string $implementation): void
    {
        $store = $this->stack($implementation)->tokens;
        $token = self::token(new Realm('acme'), self::id());
        $store->add($token, str_repeat('a', 64));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('exists');

        $store->add(self::token(new Realm('globex'), self::id(), selector: $token->selector), str_repeat('b', 64));
    }

    #[DataProvider('implementations')]
    public function testDeletesATokenOnce(string $implementation): void
    {
        $store = $this->stack($implementation)->tokens;
        $token = self::token(new Realm('acme'), self::id());
        $store->add($token, str_repeat('a', 64));

        self::assertFalse($store->delete(new Realm('globex'), $token->selector), 'A token was deleted from another realm.');
        self::assertTrue($store->delete(new Realm('acme'), $token->selector));
        self::assertFalse($store->delete(new Realm('acme'), $token->selector), 'A token was deleted twice.');
        self::assertNull($store->find(new Realm('acme'), $token->selector, 'acme.test'));
    }

    #[DataProvider('implementations')]
    public function testDeletesAPrincipalsTokensForAPurpose(string $implementation): void
    {
        $store = $this->stack($implementation)->tokens;
        $ana = self::id();
        $mine = [self::token(new Realm('acme'), $ana), self::token(new Realm('acme'), $ana)];
        $otherPurpose = self::token(new Realm('acme'), $ana, purpose: 'acme.other');
        $someoneElses = self::token(new Realm('acme'), self::id());

        foreach ([...$mine, $otherPurpose, $someoneElses] as $token) {
            $store->add($token, str_repeat('a', 64));
        }

        self::assertSame(0, $store->deleteFor(new Realm('globex'), $ana, 'acme.test'));
        self::assertSame(2, $store->deleteFor(new Realm('acme'), $ana, 'acme.test'));
        self::assertNull($store->find(new Realm('acme'), $mine[0]->selector, 'acme.test'));
        self::assertNotNull($store->find(new Realm('acme'), $otherPurpose->selector, 'acme.other'));
        self::assertNotNull($store->find(new Realm('acme'), $someoneElses->selector, 'acme.test'));
    }

    #[DataProvider('implementations')]
    public function testPurgesExpiredTokensInEveryRealm(string $implementation): void
    {
        $store = $this->stack($implementation)->tokens;
        $now = new \DateTimeImmutable('2026-10-27 20:00:00 UTC');
        $expired = self::token(new Realm('acme'), self::id(), expires: '2026-10-27 19:59:59');
        $expiring = self::token(new Realm('globex'), self::id(), expires: '2026-10-27 20:00:00');
        $working = self::token(new Realm('acme'), self::id(), expires: '2026-10-27 20:00:01');

        foreach ([$expired, $expiring, $working] as $token) {
            $store->add($token, str_repeat('a', 64));
        }

        self::assertSame(2, $store->purge($now));
        self::assertNotNull($store->find(new Realm('acme'), $working->selector, 'acme.test'));
        self::assertNull($store->find(new Realm('globex'), $expiring->selector, 'acme.test'));
    }

    private static function token(Realm $realm, PrincipalId $principal, ?string $email = null, string $purpose = 'acme.test', string $expires = '2026-10-28 18:40:00', ?string $selector = null): OneTimeToken
    {
        return new OneTimeToken($selector ?? bin2hex(random_bytes(16)), $realm, $principal, $purpose, $email, new \DateTimeImmutable('2026-10-27 18:40:00 UTC'), new \DateTimeImmutable($expires.' UTC'));
    }

    private static function id(): PrincipalId
    {
        return new PrincipalId(new UuidV7());
    }
}
