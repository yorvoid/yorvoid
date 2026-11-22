<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\CredentialStatus;
use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Ids;

/**
 * What every CredentialStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyCredentialStoreTest extends TestCase
 * {
 *     use CredentialStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function credentialStore(string $implementation): CredentialStore
 *     {
 *         return new MyCredentialStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait CredentialStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function credentialStore(string $implementation): CredentialStore;

    /**
     * The transactions the store takes part in: the same instance within a test.
     */
    abstract protected function transactions(string $implementation): Transactions;

    #[DataProvider('implementations')]
    public function testCredentialStoreTakesPartInTheTransactions(string $implementation): void
    {
        $store = $this->credentialStore($implementation);
        $transactions = $this->transactions($implementation);
        $acme = new Realm('acme');
        [$failed, $seen, $kept] = [self::credential($acme, new PrincipalId(Ids::next())), self::credential($acme, new PrincipalId(Ids::next())), self::credential($acme, new PrincipalId(Ids::next()))];

        InTransaction::assertGoneAfterARollback($transactions, static fn () => $store->add($failed), static fn (): bool => null === $store->get($acme, $failed->id));
        InTransaction::assertSeenInsideItsTransaction($transactions, static fn () => $store->add($seen), static fn (): bool => null !== $store->get($acme, $seen->id));
        InTransaction::assertTheTransactionGoesOn($transactions, [
            static fn () => $store->add($seen),
            static fn (): bool => $store->rename($acme, $failed->id, 'Renamed'),
            static fn (): bool => $store->disable($acme, $failed->id),
        ], static fn () => $store->add($kept), static fn (): bool => null !== $store->get($acme, $kept->id));
    }

    #[DataProvider('implementations')]
    public function testKeepsEveryFieldAndListsTheOldestFirst(string $implementation): void
    {
        $store = $this->credentialStore($implementation);
        $principal = new PrincipalId(Ids::next());
        $newer = new Credential(new CredentialId(Ids::next()), new Realm('acme'), $principal, CredentialKind::Passkey, 'MacBook – Touch ID ✓', new \DateTimeImmutable('2026-10-27 19:00:00 UTC'), new \DateTimeImmutable('2026-10-28 08:00:00 UTC'), CredentialStatus::Disabled);
        $older = new Credential(new CredentialId(Ids::next()), new Realm('acme'), $principal, CredentialKind::RecoveryCodes, 'Recovery codes', new \DateTimeImmutable('2026-10-27 18:40:00 UTC'));
        $someoneElses = self::credential(new Realm('acme'), new PrincipalId(Ids::next()));
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
        $store = $this->credentialStore($implementation);
        $credential = self::credential(new Realm('acme'), new PrincipalId(Ids::next()));
        $store->add($credential);

        $this->expectException(StorageException::class);

        $store->add($credential);
    }

    #[DataProvider('implementations')]
    public function testChangesAndRemovesOnlyInItsRealm(string $implementation): void
    {
        $store = $this->credentialStore($implementation);
        $credential = self::credential(new Realm('acme'), new PrincipalId(Ids::next()));
        $store->add($credential);
        $at = new \DateTimeImmutable('2026-10-28 08:00:00 UTC');

        self::assertFalse($store->rename(new Realm('globex'), $credential->id, 'Hijacked'));
        self::assertFalse($store->markUsed(new Realm('globex'), $credential->id, $at));
        self::assertFalse($store->disable(new Realm('globex'), $credential->id));
        self::assertEquals($credential, $store->get(new Realm('acme'), $credential->id));

        self::assertTrue($store->rename(new Realm('acme'), $credential->id, 'YubiKey'));
        self::assertTrue($store->markUsed(new Realm('acme'), $credential->id, $at));
        self::assertTrue($store->disable(new Realm('acme'), $credential->id));
        self::assertEquals(new Credential($credential->id, $credential->realm, $credential->principalId, $credential->kind, 'YubiKey', $credential->createdAt, $at, CredentialStatus::Disabled), $store->get(new Realm('acme'), $credential->id));

        self::assertFalse($store->remove(new Realm('globex'), $credential->id));
        self::assertTrue($store->remove(new Realm('acme'), $credential->id));
        self::assertFalse($store->remove(new Realm('acme'), $credential->id));
        self::assertNull($store->get(new Realm('acme'), $credential->id));
        self::assertFalse($store->rename(new Realm('acme'), $credential->id, 'Gone'));
        self::assertFalse($store->markUsed(new Realm('acme'), $credential->id, $at));
        self::assertFalse($store->disable(new Realm('acme'), $credential->id));
    }

    /**
     * Each change writes only what it's about: a sign-in that read the credential before it was
     * disabled can't make it active again, and neither can a rename.
     */
    #[DataProvider('implementations')]
    public function testADisabledCredentialStaysDisabled(string $implementation): void
    {
        $store = $this->credentialStore($implementation);
        $credential = self::credential(new Realm('acme'), new PrincipalId(Ids::next()));
        $store->add($credential);
        $other = $this->sameCredentialStore($implementation, $store);
        $stale = $other->get(new Realm('acme'), $credential->id);
        self::assertNotNull($stale);
        self::assertTrue($stale->isActive());

        self::assertTrue($store->disable(new Realm('acme'), $credential->id));

        self::assertFalse($other->markUsed(new Realm('acme'), $stale->id, new \DateTimeImmutable('2026-10-28 08:00:00 UTC')), 'A disabled credential was marked as used.');
        self::assertTrue($other->rename(new Realm('acme'), $stale->id, 'YubiKey'));
        $kept = $store->get(new Realm('acme'), $credential->id);
        self::assertSame(CredentialStatus::Disabled, $kept?->status, 'A change from a stale copy made the credential active again.');
        self::assertNull($kept->lastUsedAt);
        self::assertSame('YubiKey', $kept->label);
    }

    /**
     * The store as another process sees it, over the same records: the same instance unless you
     * override it, which you do for a store processes share.
     */
    protected function sameCredentialStore(string $implementation, CredentialStore $store): CredentialStore
    {
        return $store;
    }

    private static function credential(Realm $realm, PrincipalId $principal): Credential
    {
        return new Credential(new CredentialId(Ids::next()), $realm, $principal, CredentialKind::Passkey, 'Passkey', new \DateTimeImmutable('2026-10-27 18:40:00 UTC'));
    }
}
