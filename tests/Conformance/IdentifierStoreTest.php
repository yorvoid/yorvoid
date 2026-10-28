<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\Identifier;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every IdentifierStore keeps the same promises (PLT-025).
 */
final class IdentifierStoreTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testKeepsEveryFieldAndFindsByTheNormalizedForm(string $implementation): void
    {
        $store = $this->stack($implementation)->identifiers;
        $principal = self::id();
        $email = new Identifier(new Realm('acme'), $principal, IdentifierKind::Email, 'Ana.Kovač@Example.com', new \DateTimeImmutable('2026-10-27 18:40:00 UTC'), new \DateTimeImmutable('2026-10-27 18:45:00 UTC'));
        $username = new Identifier(new Realm('acme'), $principal, IdentifierKind::Username, 'Ana', new \DateTimeImmutable('2026-10-27 18:41:00 UTC'));

        self::assertTrue($store->add($email));
        self::assertTrue($store->add($username));

        self::assertEquals($email, $store->find(new Realm('acme'), IdentifierKind::Email, 'ana.kovač@example.com'));
        self::assertEquals($username, $store->find(new Realm('acme'), IdentifierKind::Username, 'ana'));
        self::assertEquals(['email' => $email, 'username' => $username], $store->of(new Realm('acme'), $principal));
    }

    #[DataProvider('implementations')]
    public function testAnIdentifierBelongsToOnePrincipalPerRealm(string $implementation): void
    {
        $store = $this->stack($implementation)->identifiers;
        $first = self::identifier(new Realm('acme'), self::id(), 'ana@example.com');

        self::assertTrue($store->add($first));
        self::assertFalse($store->add(self::identifier(new Realm('acme'), self::id(), 'ANA@example.com')), 'Two principals have the same email address.');
        self::assertTrue($store->add(self::identifier(new Realm('globex'), self::id(), 'ana@example.com')), 'Realms are separate.');
        self::assertEquals($first, $store->find(new Realm('acme'), IdentifierKind::Email, 'ana@example.com'));
        self::assertNull($store->find(new Realm('acme'), IdentifierKind::Username, 'ana@example.com'), 'Kinds are separate.');
    }

    #[DataProvider('implementations')]
    public function testListsAPrincipalsIdentifiersByKindInAnyOrderAdded(string $implementation): void
    {
        $store = $this->stack($implementation)->identifiers;
        $principal = self::id();
        $username = new Identifier(new Realm('acme'), $principal, IdentifierKind::Username, 'ana', new \DateTimeImmutable('2026-10-27 18:40:00 UTC'));
        $email = self::identifier(new Realm('acme'), $principal, 'ana@example.com');
        $store->add($username);
        $store->add($email);

        self::assertSame(['email', 'username'], array_keys($store->of(new Realm('acme'), $principal)));
    }

    #[DataProvider('implementations')]
    public function testRemovesNothingInAnotherRealm(string $implementation): void
    {
        $store = $this->stack($implementation)->identifiers;
        $principal = self::id();
        $store->add(self::identifier(new Realm('acme'), $principal, 'ana@example.com'));

        $store->remove(new Realm('globex'), $principal, IdentifierKind::Email);
        $store->removeAll(new Realm('globex'), $principal);

        self::assertCount(1, $store->of(new Realm('acme'), $principal));
    }

    #[DataProvider('implementations')]
    public function testAPrincipalHasOneOfEachKind(string $implementation): void
    {
        $store = $this->stack($implementation)->identifiers;
        $principal = self::id();

        self::assertTrue($store->add(self::identifier(new Realm('acme'), $principal, 'ana@example.com')));
        self::assertFalse($store->add(self::identifier(new Realm('acme'), $principal, 'ana@example.org')));
        self::assertNull($store->find(new Realm('acme'), IdentifierKind::Email, 'ana@example.org'));
    }

    #[DataProvider('implementations')]
    public function testRemovesOnlyWhatItsAskedTo(string $implementation): void
    {
        $store = $this->stack($implementation)->identifiers;
        $ana = self::id();
        $bob = self::id();
        $store->add(self::identifier(new Realm('acme'), $ana, 'ana@example.com'));
        $store->add(new Identifier(new Realm('acme'), $ana, IdentifierKind::Username, 'ana', new \DateTimeImmutable('2026-10-27 18:40:00 UTC')));
        $store->add(self::identifier(new Realm('acme'), $bob, 'bob@example.com'));

        $store->remove(new Realm('acme'), $ana, IdentifierKind::Email);
        self::assertSame(['username'], array_keys($store->of(new Realm('acme'), $ana)));

        $store->removeAll(new Realm('acme'), $ana);
        self::assertSame([], $store->of(new Realm('acme'), $ana));
        self::assertCount(1, $store->of(new Realm('acme'), $bob));
        self::assertTrue($store->add(self::identifier(new Realm('acme'), self::id(), 'ana@example.com')), 'A removed email address can be used again.');
    }

    private static function identifier(Realm $realm, PrincipalId $principal, string $email): Identifier
    {
        return new Identifier($realm, $principal, IdentifierKind::Email, $email, new \DateTimeImmutable('2026-10-27 18:40:00 UTC'));
    }

    private static function id(): PrincipalId
    {
        return new PrincipalId(new UuidV7());
    }
}
