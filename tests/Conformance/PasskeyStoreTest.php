<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Passkeys\Ceremony;
use YorvoID\Passkeys\CeremonyKind;
use YorvoID\Passkeys\Passkey;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every PasskeyStore and CeremonyStore keeps the same promises (PLT-025).
 */
final class PasskeyStoreTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testKeepsEveryFieldAndFindsByTheAuthenticatorsId(string $implementation): void
    {
        $store = $this->stack($implementation)->passkeys;
        $principal = new PrincipalId(new UuidV7());
        $long = self::passkey(new Realm('acme'), $principal, random_bytes(1023), ['usb', 'nfc'], true);
        $short = self::passkey(new Realm('acme'), $principal, "\x00\xFF", [], false);

        self::assertTrue($store->add($long));
        self::assertTrue($store->add($short));

        self::assertEquals($long, $store->find(new Realm('acme'), $long->webauthnId));
        self::assertEquals($short, $store->find(new Realm('acme'), "\x00\xFF"));
        self::assertNull($store->find(new Realm('acme'), "\x00\xFE"));
        self::assertNull($store->find(new Realm('globex'), $long->webauthnId));
        self::assertEqualsCanonicalizing([$long, $short], $store->of(new Realm('acme'), $principal));
        self::assertSame(['usb', 'nfc'], new Passkey($long->credentialId, $long->realm, $long->principalId, $long->webauthnId, $long->publicKey, $long->userHandle, 0, ['a' => 'usb', 'b' => 'nfc'], $long->aaguid, false, false, false, 'none')->transports, 'Transports are a list.');

        $database = $this->stack($implementation)->database;

        if (null !== $database) {
            $database->connection->executeStatement(\sprintf('DELETE FROM %s', $database->table('passkey')));
            $this->stack($implementation)->passkeys->add($long);
            $stored = $database->connection->fetchAssociative(\sprintf('SELECT webauthn_id, public_key, user_handle FROM %s', $database->table('passkey')));
            self::assertIsArray($stored);
            self::assertSame([], array_filter($stored, static fn (mixed $value): bool => \is_string($value) && str_contains($value, '=')), 'Binary values are stored as base64url, without padding.');
        }
        self::assertSame([], $store->of(new Realm('globex'), $principal));
    }

    #[DataProvider('implementations')]
    public function testAnAuthenticatorsIdIsRegisteredOncePerRealm(string $implementation): void
    {
        $store = $this->stack($implementation)->passkeys;
        $id = random_bytes(16);

        self::assertTrue($store->add(self::passkey(new Realm('acme'), new PrincipalId(new UuidV7()), $id)));
        self::assertFalse($store->add(self::passkey(new Realm('acme'), new PrincipalId(new UuidV7()), $id)));
        self::assertTrue($store->add(self::passkey(new Realm('globex'), new PrincipalId(new UuidV7()), $id)));
    }

    #[DataProvider('implementations')]
    public function testUpdatesWhatSignInReportsAndRemovesOnlyInItsRealm(string $implementation): void
    {
        $store = $this->stack($implementation)->passkeys;
        $passkey = self::passkey(new Realm('acme'), new PrincipalId(new UuidV7()), random_bytes(16));
        $store->add($passkey);
        $used = new Passkey($passkey->credentialId, $passkey->realm, $passkey->principalId, $passkey->webauthnId, $passkey->publicKey, $passkey->userHandle, 42, $passkey->transports, $passkey->aaguid, true, true, true, $passkey->attestationType);

        $elsewhere = new Passkey($passkey->credentialId, new Realm('globex'), $passkey->principalId, $passkey->webauthnId, $passkey->publicKey, $passkey->userHandle, 99, $passkey->transports, $passkey->aaguid, false, false, false, $passkey->attestationType);
        $store->update($elsewhere);
        self::assertEquals($passkey, $store->find(new Realm('acme'), $passkey->webauthnId), 'A passkey was changed from another realm.');

        $store->update($used);
        self::assertEquals($used, $store->find(new Realm('acme'), $passkey->webauthnId));
        $store->remove(new Realm('acme'), new CredentialId(new UuidV7()));

        $store->remove(new Realm('globex'), $passkey->credentialId);
        self::assertNotNull($store->find(new Realm('acme'), $passkey->webauthnId));
        $store->remove(new Realm('acme'), $passkey->credentialId);
        self::assertNull($store->find(new Realm('acme'), $passkey->webauthnId));
    }

    #[DataProvider('platforms')]
    public function testRefusesRowsItDidntWrite(string $platform): void
    {
        $stack = $this->stack($platform);
        \assert(null !== $stack->database);
        $passkey = self::passkey(new Realm('acme'), new PrincipalId(new UuidV7()), random_bytes(16));
        $stack->passkeys->add($passkey);
        $table = $stack->database->table('passkey');

        $tamperings = ["transports = '{\"a\": 1}'", "transports = '\"usb\"'", "transports = '[5]'", "public_key = '!!!'"];

        if ('sqlite' === $platform) {
            // PostgreSQL keeps a boolean a boolean; SQLite keeps whatever it's given.
            $tamperings[] = 'backed_up = 2';
        }

        foreach ($tamperings as $tampering) {
            $stack->database->connection->executeStatement(\sprintf('UPDATE %s SET %s', $table, $tampering));

            try {
                $stack->passkeys->find(new Realm('acme'), $passkey->webauthnId);
                self::fail('A changed row was read: '.$tampering);
            } catch (StorageException $exception) {
                self::assertStringContainsString('the row was changed', $exception->getMessage());
            }

            $stack->passkeys->remove(new Realm('acme'), $passkey->credentialId);
            $stack->passkeys->add($passkey);
        }
    }

    #[DataProvider('implementations')]
    public function testACeremonyIsTakenOnce(string $implementation): void
    {
        $store = $this->stack($implementation)->ceremonies;
        $registration = new Ceremony(Uuid::v7(), new Realm('acme'), CeremonyKind::Registration, '{"challenge":"x"}', new \DateTimeImmutable('2026-10-27 18:50:00 UTC'), new SessionId(new UuidV7()));
        $signIn = new Ceremony(Uuid::v7(), new Realm('acme'), CeremonyKind::SignIn, '{"challenge":"y"}', new \DateTimeImmutable('2026-10-27 18:50:00 UTC'));
        $store->add($registration);
        $store->add($signIn);

        self::assertNull($store->take(new Realm('globex'), $registration->id));
        self::assertEquals($registration, $store->take(new Realm('acme'), $registration->id));
        self::assertNull($store->take(new Realm('acme'), $registration->id), 'A ceremony was taken twice.');
        self::assertEquals($signIn, $store->take(new Realm('acme'), $signIn->id));
    }

    #[DataProvider('implementations')]
    public function testPurgesExpiredCeremonies(string $implementation): void
    {
        $store = $this->stack($implementation)->ceremonies;
        $expired = new Ceremony(Uuid::v7(), new Realm('acme'), CeremonyKind::SignIn, '{}', new \DateTimeImmutable('2026-10-27 18:50:00 UTC'));
        $current = new Ceremony(Uuid::v7(), new Realm('globex'), CeremonyKind::SignIn, '{}', new \DateTimeImmutable('2026-10-27 18:50:01 UTC'));
        $store->add($expired);
        $store->add($current);

        self::assertSame(1, $store->purge(new \DateTimeImmutable('2026-10-27 18:50:00 UTC')));
        self::assertNotNull($store->take(new Realm('globex'), $current->id));
    }

    /**
     * @param list<string> $transports
     */
    private static function passkey(Realm $realm, PrincipalId $principal, string $webauthnId, array $transports = ['internal'], bool $flags = false): Passkey
    {
        return new Passkey(new CredentialId(new UuidV7()), $realm, $principal, $webauthnId, random_bytes(77), random_bytes(32), 7, $transports, Uuid::fromString('ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4'), $flags, $flags, !$flags, 'none');
    }
}
