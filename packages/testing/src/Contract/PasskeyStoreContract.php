<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Passkeys\Passkey;
use YorvoID\Passkeys\PasskeyStore;

/**
 * What every PasskeyStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyPasskeyStoreTest extends TestCase
 * {
 *     use PasskeyStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function passkeyStore(string $implementation): PasskeyStore
 *     {
 *         return new MyPasskeyStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait PasskeyStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function passkeyStore(string $implementation): PasskeyStore;

    #[DataProvider('implementations')]
    public function testAnAuthenticatorsIdIsRegisteredOncePerRealm(string $implementation): void
    {
        $store = $this->passkeyStore($implementation);
        $id = random_bytes(16);

        self::assertTrue($store->add(self::passkey(new Realm('acme'), new PrincipalId(new UuidV7()), $id)));
        self::assertFalse($store->add(self::passkey(new Realm('acme'), new PrincipalId(new UuidV7()), $id)));
        self::assertTrue($store->add(self::passkey(new Realm('globex'), new PrincipalId(new UuidV7()), $id)));
    }

    #[DataProvider('implementations')]
    public function testUpdatesWhatSignInReportsAndRemovesOnlyInItsRealm(string $implementation): void
    {
        $store = $this->passkeyStore($implementation);
        $passkey = self::passkey(new Realm('acme'), new PrincipalId(new UuidV7()), random_bytes(16));
        $store->add($passkey);
        $used = new Passkey($passkey->credentialId, $passkey->realm, $passkey->principalId, $passkey->webauthnId, $passkey->publicKey, $passkey->userHandle, 42, $passkey->transports, $passkey->aaguid, $passkey->backupEligible, true, true, $passkey->attestationType);

        $elsewhere = new Passkey($passkey->credentialId, new Realm('globex'), $passkey->principalId, $passkey->webauthnId, $passkey->publicKey, $passkey->userHandle, 99, $passkey->transports, $passkey->aaguid, false, false, false, $passkey->attestationType);
        self::assertFalse($store->update($elsewhere));
        self::assertEquals($passkey, $store->find(new Realm('acme'), $passkey->webauthnId), 'A passkey was changed from another realm.');

        self::assertTrue($store->update($used));
        self::assertEquals($used, $store->find(new Realm('acme'), $passkey->webauthnId));
        $store->remove(new Realm('acme'), new CredentialId(new UuidV7()));

        $store->remove(new Realm('globex'), $passkey->credentialId);
        self::assertNotNull($store->find(new Realm('acme'), $passkey->webauthnId));
        $store->remove(new Realm('acme'), $passkey->credentialId);
        self::assertNull($store->find(new Realm('acme'), $passkey->webauthnId));
    }

    #[DataProvider('implementations')]
    public function testRecordsACounterOnlyOverALowerOne(string $implementation): void
    {
        $store = $this->passkeyStore($implementation);
        $passkey = self::passkey(new Realm('acme'), new PrincipalId(new UuidV7()), random_bytes(16));
        $store->add($passkey);
        $counted = static fn (int $count, bool $eligible = false): Passkey => new Passkey($passkey->credentialId, $passkey->realm, $passkey->principalId, $passkey->webauthnId, $passkey->publicKey, $passkey->userHandle, $count, $passkey->transports, $passkey->aaguid, $eligible, true, true, $passkey->attestationType);

        self::assertTrue($store->update($counted(8, true)));
        self::assertFalse($store->find($passkey->realm, $passkey->webauthnId)?->backupEligible, 'Backup eligibility changed after registration.');
        self::assertFalse($store->update($counted(8)), 'The same counter was recorded twice.');
        self::assertFalse($store->update($counted(3)));
        self::assertSame(8, $store->find($passkey->realm, $passkey->webauthnId)->signCount);
        self::assertTrue($store->update($counted(0)));
        self::assertTrue($store->update($counted(0)), 'A passkey without a counter was refused.');
    }

    /**
     * @param list<string> $transports
     */
    private static function passkey(Realm $realm, PrincipalId $principal, string $webauthnId, array $transports = ['internal'], bool $flags = false): Passkey
    {
        return new Passkey(new CredentialId(new UuidV7()), $realm, $principal, $webauthnId, random_bytes(77), random_bytes(32), 7, $transports, Uuid::fromString('ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4'), $flags, $flags, !$flags, 'none');
    }
}
