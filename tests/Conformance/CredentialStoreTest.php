<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\CredentialStatus;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every CredentialStore keeps the same promises (PLT-025).
 */
final class CredentialStoreTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testKeepsEveryFieldAndListsTheOldestFirst(string $implementation): void
    {
        $store = $this->stack($implementation)->credentials;
        $principal = new PrincipalId(new UuidV7());
        $newer = new Credential(new CredentialId(new UuidV7()), new Realm('acme'), $principal, CredentialKind::Passkey, 'MacBook – Touch ID ✓', new \DateTimeImmutable('2026-10-27 19:00:00 UTC'), new \DateTimeImmutable('2026-10-28 08:00:00 UTC'), CredentialStatus::Disabled);
        $older = new Credential(new CredentialId(new UuidV7()), new Realm('acme'), $principal, CredentialKind::RecoveryCodes, 'Recovery codes', new \DateTimeImmutable('2026-10-27 18:40:00 UTC'));
        $someoneElses = self::credential(new Realm('acme'), new PrincipalId(new UuidV7()));
        $store->add($newer);
        $store->add($older);
        $store->add($someoneElses);

        self::assertEquals($newer, $store->get(new Realm('acme'), $newer->id));
        self::assertEquals([$older, $newer], $store->of(new Realm('acme'), $principal));
        self::assertNull($store->get(new Realm('globex'), $newer->id));
        self::assertSame([], $store->of(new Realm('globex'), $principal));
    }

    #[DataProvider('implementations')]
    public function testRefusesADuplicateId(string $implementation): void
    {
        $store = $this->stack($implementation)->credentials;
        $credential = self::credential(new Realm('acme'), new PrincipalId(new UuidV7()));
        $store->add($credential);

        $this->expectException(StorageException::class);

        $store->add($credential);
    }

    #[DataProvider('implementations')]
    public function testUpdatesAndRemovesOnlyInItsRealm(string $implementation): void
    {
        $store = $this->stack($implementation)->credentials;
        $credential = self::credential(new Realm('acme'), new PrincipalId(new UuidV7()));
        $store->add($credential);
        $changed = new Credential($credential->id, $credential->realm, $credential->principalId, $credential->kind, 'YubiKey', $credential->createdAt, new \DateTimeImmutable('2026-10-28 08:00:00 UTC'), CredentialStatus::Disabled);
        $elsewhere = new Credential($credential->id, new Realm('globex'), $credential->principalId, $credential->kind, 'Hijacked', $credential->createdAt);

        $store->update($elsewhere);
        self::assertEquals($credential, $store->get(new Realm('acme'), $credential->id));
        $store->update($changed);
        self::assertEquals($changed, $store->get(new Realm('acme'), $credential->id));

        self::assertFalse($store->remove(new Realm('globex'), $credential->id));
        self::assertTrue($store->remove(new Realm('acme'), $credential->id));
        self::assertFalse($store->remove(new Realm('acme'), $credential->id));
        self::assertNull($store->get(new Realm('acme'), $credential->id));
    }

    private static function credential(Realm $realm, PrincipalId $principal): Credential
    {
        return new Credential(new CredentialId(new UuidV7()), $realm, $principal, CredentialKind::Passkey, 'Passkey', new \DateTimeImmutable('2026-10-27 18:40:00 UTC'));
    }
}
