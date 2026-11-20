<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Passkeys\Passkey;
use YorvoID\Testing\Ids;
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
        $first = self::passkey(new CredentialId(Ids::next()), random_bytes(16));

        self::assertTrue($passkeys->add($first));
        self::assertFalse($passkeys->add(self::passkey(new CredentialId(Ids::next()), $first->webauthnId)), 'The key it expects: the WebAuthn ID.');

        $this->expectException(UniqueConstraintViolationException::class);

        $passkeys->add(self::passkey($first->credentialId, random_bytes(16)));
    }

    private static function passkey(CredentialId $id, string $webauthnId): Passkey
    {
        return new Passkey($id, new Realm('acme'), new PrincipalId(Ids::next()), $webauthnId, random_bytes(77), random_bytes(32), 0, [], '00000000-0000-0000-0000-000000000000', false, false, true, 'none');
    }
}
