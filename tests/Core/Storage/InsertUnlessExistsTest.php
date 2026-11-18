<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Passkeys\Passkey;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * An insert that may find its row there (SS-01) names the unique key it expects: a conflict on another
 * key is a bug, and still throws, not "it exists".
 */
final class InsertUnlessExistsTest extends DatabaseTestCase
{
    #[DataProvider('platforms')]
    public function testAConflictOnAnotherUniqueKeyThrows(string $platform): void
    {
        $passkeys = $this->stack($platform)->passkeys;
        $first = self::passkey(new CredentialId(new UuidV7()), random_bytes(16));

        self::assertTrue($passkeys->add($first));
        self::assertFalse($passkeys->add(self::passkey(new CredentialId(new UuidV7()), $first->webauthnId)), 'The key it expects: the WebAuthn ID.');

        $this->expectException(UniqueConstraintViolationException::class);

        $passkeys->add(self::passkey($first->credentialId, random_bytes(16)));
    }

    private static function passkey(CredentialId $id, string $webauthnId): Passkey
    {
        return new Passkey($id, new Realm('acme'), new PrincipalId(new UuidV7()), $webauthnId, random_bytes(77), random_bytes(32), 0, [], Uuid::fromString('00000000-0000-0000-0000-000000000000'), false, false, true, 'none');
    }
}
