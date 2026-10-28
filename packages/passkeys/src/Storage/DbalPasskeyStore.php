<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Storage;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Passkeys\Passkey;
use YorvoID\Passkeys\PasskeyStore;

/**
 * Binary values are kept as base64url text, and the authenticator's ID is found by its SHA-256 hash, so
 * the index stays small whatever the ID's length (up to 1023 bytes).
 *
 * @api
 */
final readonly class DbalPasskeyStore implements PasskeyStore
{
    public const string TABLE = 'passkey';

    private const string COLUMNS = 'credential_id, realm, principal_id, webauthn_id, public_key, user_handle, sign_count, transports, aaguid, backup_eligible, backed_up, user_verification_initialized, attestation_type';

    public function __construct(
        private Database $database,
    ) {
    }

    public function add(Passkey $passkey): bool
    {
        try {
            $this->database->connection->insert($this->database->table(self::TABLE), [
                'credential_id' => $passkey->credentialId->uuid->toRfc4122(),
                'realm' => $passkey->realm->name,
                'principal_id' => $passkey->principalId->uuid->toRfc4122(),
                'webauthn_id_hash' => hash('sha256', $passkey->webauthnId),
                'webauthn_id' => self::encode($passkey->webauthnId),
                'public_key' => self::encode($passkey->publicKey),
                'user_handle' => self::encode($passkey->userHandle),
                'sign_count' => $passkey->signCount,
                'transports' => $passkey->transports,
                'aaguid' => $passkey->aaguid->toRfc4122(),
                'backup_eligible' => $passkey->backupEligible,
                'backed_up' => $passkey->backedUp,
                'user_verification_initialized' => $passkey->userVerificationInitialized,
                'attestation_type' => $passkey->attestationType,
            ], ['transports' => Types::JSON, 'backup_eligible' => ParameterType::BOOLEAN, 'backed_up' => ParameterType::BOOLEAN, 'user_verification_initialized' => ParameterType::BOOLEAN]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function find(Realm $realm, string $webauthnId): ?Passkey
    {
        $row = $this->database->connection->fetchAssociative(
            \sprintf('SELECT %s FROM %s WHERE realm = ? AND webauthn_id_hash = ?', self::COLUMNS, $this->database->table(self::TABLE)),
            [$realm->name, hash('sha256', $webauthnId)],
        );

        return false === $row ? null : $this->passkey($row);
    }

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        $passkeys = [];

        foreach ($this->database->connection->iterateAssociative(
            \sprintf('SELECT %s FROM %s WHERE realm = ? AND principal_id = ? ORDER BY credential_id', self::COLUMNS, $this->database->table(self::TABLE)),
            [$realm->name, $principalId->uuid->toRfc4122()],
        ) as $row) {
            $passkeys[] = $this->passkey($row);
        }

        return $passkeys;
    }

    public function update(Passkey $passkey): void
    {
        $this->database->connection->update(
            $this->database->table(self::TABLE),
            ['sign_count' => $passkey->signCount, 'backup_eligible' => $passkey->backupEligible, 'backed_up' => $passkey->backedUp, 'user_verification_initialized' => $passkey->userVerificationInitialized],
            ['realm' => $passkey->realm->name, 'credential_id' => $passkey->credentialId->uuid->toRfc4122()],
            ['backup_eligible' => ParameterType::BOOLEAN, 'backed_up' => ParameterType::BOOLEAN, 'user_verification_initialized' => ParameterType::BOOLEAN],
        );
    }

    public function remove(Realm $realm, CredentialId $credentialId): void
    {
        $this->database->connection->delete($this->database->table(self::TABLE), ['realm' => $realm->name, 'credential_id' => $credentialId->uuid->toRfc4122()]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function passkey(array $row): Passkey
    {
        $stored = Type::getType(Types::JSON)->convertToPHPValue($row['transports'], $this->database->connection->getDatabasePlatform());
        $transports = [];

        foreach (\is_array($stored) && array_is_list($stored) ? $stored : [null] as $transport) {
            $transports[] = \is_string($transport) ? $transport : throw new StorageException('A passkey\'s transports aren\'t what YorvoID wrote: the row was changed.');
        }

        return new Passkey(
            CredentialId::fromString(Row::string($row, 'credential_id')),
            new Realm(Row::string($row, 'realm')),
            PrincipalId::fromString(Row::string($row, 'principal_id')),
            self::decode(Row::string($row, 'webauthn_id')),
            self::decode(Row::string($row, 'public_key')),
            self::decode(Row::string($row, 'user_handle')),
            Row::int($row, 'sign_count'),
            $transports,
            Uuid::fromString(Row::string($row, 'aaguid')),
            self::bool($row['backup_eligible']),
            self::bool($row['backed_up']),
            self::bool($row['user_verification_initialized']),
            Row::string($row, 'attestation_type'),
        );
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return false === $bytes ? throw new StorageException('A passkey\'s binary value isn\'t what YorvoID wrote: the row was changed.') : $bytes;
    }

    /**
     * PostgreSQL's driver returns booleans, SQLite's integers.
     */
    private static function bool(mixed $value): bool
    {
        return match ($value) {
            true, 1 => true,
            false, 0 => false,
            default => throw new StorageException('A passkey\'s flag isn\'t what YorvoID wrote: the row was changed.'),
        };
    }
}
