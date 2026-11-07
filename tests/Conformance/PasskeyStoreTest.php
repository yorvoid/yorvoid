<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Passkeys\CeremonyStore;
use YorvoID\Passkeys\Passkey;
use YorvoID\Passkeys\PasskeyStore;
use YorvoID\Testing\Contract\CeremonyStoreContract;
use YorvoID\Testing\Contract\PasskeyStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every PasskeyStore and CeremonyStore keeps the same promises (PLT-025).
 */
final class PasskeyStoreTest extends DatabaseTestCase
{
    use CeremonyStoreContract;
    use PasskeyStoreContract;

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
        self::assertSame(['hybrid', 'usb'], new Passkey($long->credentialId, $long->realm, $long->principalId, $long->webauthnId, $long->publicKey, $long->userHandle, 0, ['hybrid', 1, ['usb'], null, 'USB', 'carrier-pigeon', 'usb', 'hybrid', str_repeat('x', 10_000)], $long->aaguid, false, false, false, 'none')->transports, 'Only what WebAuthn defines, in the browser\'s order, once.');

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

    #[DataProvider('platforms')]
    public function testRefusesRowsItDidntWrite(string $platform): void
    {
        $stack = $this->stack($platform);
        \assert(null !== $stack->database);
        $passkey = self::passkey(new Realm('acme'), new PrincipalId(new UuidV7()), random_bytes(16));
        $stack->passkeys->add($passkey);
        $table = $stack->database->table('passkey');

        $tamperings = ["transports = '{\"a\": 1}'", "transports = '\"usb\"'", "public_key = '!!!'"];

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

    protected function passkeyStore(string $implementation): PasskeyStore
    {
        return $this->stackOf($implementation)->passkeys;
    }

    protected function ceremonyStore(string $implementation): CeremonyStore
    {
        return $this->stackOf($implementation)->ceremonies;
    }
}
