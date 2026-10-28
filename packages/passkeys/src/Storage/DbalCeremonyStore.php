<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Storage;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Passkeys\Ceremony;
use YorvoID\Passkeys\CeremonyKind;
use YorvoID\Passkeys\CeremonyStore;

/**
 * @api
 */
final readonly class DbalCeremonyStore implements CeremonyStore
{
    public const string TABLE = 'passkey_ceremony';

    public function __construct(
        private Database $database,
    ) {
    }

    public function add(Ceremony $ceremony): void
    {
        $this->database->connection->insert($this->database->table(self::TABLE), [
            'id' => $ceremony->id->toRfc4122(),
            'realm' => $ceremony->realm->name,
            'kind' => $ceremony->kind->value,
            'options' => $ceremony->options,
            'session_id' => $ceremony->sessionId?->uuid->toRfc4122(),
            'operation' => $ceremony->operation,
            'expires_at' => $ceremony->expiresAt,
        ], ['expires_at' => Types::DATETIMETZ_IMMUTABLE]);
    }

    public function take(Realm $realm, Uuid $id): ?Ceremony
    {
        $table = $this->database->table(self::TABLE);
        $row = $this->database->connection->fetchAssociative(\sprintf('SELECT kind, options, session_id, operation, expires_at FROM %s WHERE realm = ? AND id = ?', $table), [$realm->name, $id->toRfc4122()]);

        // Deleting it is what makes it this request's: another one taking it at once deletes nothing.
        if (false === $row || 1 !== $this->database->connection->delete($table, ['id' => $id->toRfc4122()])) {
            return null;
        }

        $sessionId = Row::nullableString($row, 'session_id');
        $expiresAt = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($row['expires_at'], $this->database->connection->getDatabasePlatform());
        \assert($expiresAt instanceof \DateTimeImmutable);

        return new Ceremony($id, $realm, CeremonyKind::from(Row::string($row, 'kind')), Row::string($row, 'options'), $expiresAt, null === $sessionId ? null : SessionId::fromString($sessionId), Row::nullableString($row, 'operation'));
    }

    public function purge(\DateTimeImmutable $now): int
    {
        return (int) $this->database->connection->executeStatement(\sprintf('DELETE FROM %s WHERE expires_at <= ?', $this->database->table(self::TABLE)), [$now], [Types::DATETIMETZ_IMMUTABLE]);
    }
}
