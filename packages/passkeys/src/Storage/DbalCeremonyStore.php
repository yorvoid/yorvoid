<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Storage;

use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Core\Id\Id;
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
            'id' => $ceremony->id->toString(),
            'realm' => $ceremony->realm->name,
            'kind' => $ceremony->kind->value,
            'options' => $ceremony->options,
            'session_id' => $ceremony->sessionId?->id->toString(),
            'operation' => $ceremony->operation,
            'expires_at' => $ceremony->expiresAt,
        ], ['expires_at' => Types::DATETIMETZ_IMMUTABLE]);
    }

    public function take(Realm $realm, Id $id, ?SessionId $session = null): ?Ceremony
    {
        $table = $this->database->table(self::TABLE);
        $row = $this->database->connection->fetchAssociative(\sprintf('SELECT kind, options, session_id, operation, expires_at FROM %s WHERE realm = ? AND id = ?', $table), [$realm->name, $id->toString()]);

        // Someone else's ceremony stays, for the session that began it.
        if (false !== $row && Row::nullableString($row, 'session_id') !== $session?->id->toString()) {
            return null;
        }

        // Deleting it is what makes it this request's: another one taking it at once deletes nothing.
        if (false === $row || 1 !== $this->database->connection->delete($table, ['id' => $id->toString()])) {
            return null;
        }

        $sessionId = Row::nullableString($row, 'session_id');
        $expiresAt = $this->database->connection->convertToPHPValue($row['expires_at'], Types::DATETIMETZ_IMMUTABLE);
        \assert($expiresAt instanceof \DateTimeImmutable);

        return new Ceremony($id, $realm, CeremonyKind::from(Row::string($row, 'kind')), Row::string($row, 'options'), $expiresAt, null === $sessionId ? null : SessionId::fromString($sessionId), Row::nullableString($row, 'operation'));
    }

    public function purge(\DateTimeImmutable $now): int
    {
        return $this->database->deleteInBatches(self::TABLE, 'expires_at <= ?', [$now], [Types::DATETIMETZ_IMMUTABLE]);
    }
}
