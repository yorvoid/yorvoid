<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session\Storage;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\StorageException;

/**
 * @api
 */
final readonly class DbalSessionStore implements SessionStore
{
    public const string TABLE = 'authentication_session';

    private const string COLUMNS = 'id, realm, principal_id, principal_status_version, authentication, started_at, last_seen_at, expires_at, idle_expires_at, device, address, ended_at, end_reason';

    private const array TYPES = [
        'authentication' => Types::JSON,
        'started_at' => Types::DATETIMETZ_IMMUTABLE,
        'last_seen_at' => Types::DATETIMETZ_IMMUTABLE,
        'expires_at' => Types::DATETIMETZ_IMMUTABLE,
        'idle_expires_at' => Types::DATETIMETZ_IMMUTABLE,
        'ended_at' => Types::DATETIMETZ_IMMUTABLE,
    ];

    public function __construct(
        private Database $database,
    ) {
    }

    public function add(Session $session, string $tokenHash): void
    {
        try {
            $this->database->connection->insert($this->database->table(self::TABLE), [
                'id' => $session->id->uuid->toRfc4122(),
                'realm' => $session->realm->name,
                'principal_id' => $session->principalId->uuid->toRfc4122(),
                'principal_status_version' => $session->principalStatusVersion,
                'token_hash' => $tokenHash,
                'authentication' => $session->authentication->toArray(),
                'started_at' => $session->startedAt,
                'last_seen_at' => $session->lastSeenAt,
                'expires_at' => $session->expiresAt,
                'idle_expires_at' => $session->idleExpiresAt,
                'device' => $session->device,
                'address' => $session->address,
                'ended_at' => $session->endedAt,
                'end_reason' => $session->endReason?->value,
            ], self::TYPES);
        } catch (UniqueConstraintViolationException $exception) {
            throw new StorageException(\sprintf('A session with the ID %s or the same token exists.', $session->id), previous: $exception);
        }
    }

    public function findByTokenHash(string $tokenHash): ?Session
    {
        return $this->one('token_hash = ?', [$tokenHash]);
    }

    public function get(Realm $realm, SessionId $id): ?Session
    {
        return $this->one('realm = ? AND id = ?', [$realm->name, $id->uuid->toRfc4122()]);
    }

    public function current(Realm $realm, PrincipalId $principalId, \DateTimeImmutable $now): array
    {
        $sessions = [];

        foreach ($this->database->connection->iterateAssociative(
            \sprintf('SELECT %s FROM %s WHERE realm = ? AND principal_id = ? AND ended_at IS NULL AND expires_at > ? AND idle_expires_at > ? ORDER BY last_seen_at DESC, id DESC', self::COLUMNS, $this->database->table(self::TABLE)),
            [$realm->name, $principalId->uuid->toRfc4122(), $now, $now],
            [2 => Types::DATETIMETZ_IMMUTABLE, 3 => Types::DATETIMETZ_IMMUTABLE],
        ) as $row) {
            $sessions[] = $this->session($row);
        }

        return $sessions;
    }

    public function replaceTokenHash(Realm $realm, SessionId $id, string $tokenHash): bool
    {
        return 1 === $this->database->connection->executeStatement(
            \sprintf('UPDATE %s SET token_hash = ? WHERE realm = ? AND id = ? AND ended_at IS NULL', $this->database->table(self::TABLE)),
            [$tokenHash, $realm->name, $id->uuid->toRfc4122()],
        );
    }

    public function stepUp(Realm $realm, SessionId $id, AuthenticationContext $authentication, string $tokenHash): bool
    {
        return 1 === $this->database->connection->executeStatement(
            \sprintf('UPDATE %s SET authentication = ?, token_hash = ? WHERE realm = ? AND id = ? AND ended_at IS NULL', $this->database->table(self::TABLE)),
            [$authentication->toArray(), $tokenHash, $realm->name, $id->uuid->toRfc4122()],
            [0 => Types::JSON],
        );
    }

    public function touch(Realm $realm, SessionId $id, \DateTimeImmutable $lastSeenAt, \DateTimeImmutable $idleExpiresAt): void
    {
        $this->database->connection->update(
            $this->database->table(self::TABLE),
            ['last_seen_at' => $lastSeenAt, 'idle_expires_at' => $idleExpiresAt],
            ['realm' => $realm->name, 'id' => $id->uuid->toRfc4122()],
            self::TYPES,
        );
    }

    public function end(Realm $realm, SessionId $id, \DateTimeImmutable $endedAt, SessionEndReason $reason): bool
    {
        return 1 === $this->database->connection->executeStatement(
            \sprintf('UPDATE %s SET ended_at = ?, end_reason = ? WHERE realm = ? AND id = ? AND ended_at IS NULL', $this->database->table(self::TABLE)),
            [$endedAt, $reason->value, $realm->name, $id->uuid->toRfc4122()],
            [0 => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function purge(\DateTimeImmutable $before): int
    {
        return (int) $this->database->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE ended_at < ? OR expires_at < ? OR idle_expires_at < ?', $this->database->table(self::TABLE)),
            [$before, $before, $before],
            [Types::DATETIMETZ_IMMUTABLE, Types::DATETIMETZ_IMMUTABLE, Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * @param list<string> $parameters
     */
    private function one(string $where, array $parameters): ?Session
    {
        $row = $this->database->connection->fetchAssociative(\sprintf('SELECT %s FROM %s WHERE %s', self::COLUMNS, $this->database->table(self::TABLE), $where), $parameters);

        return false === $row ? null : $this->session($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function session(array $row): Session
    {
        $endReason = Row::nullableString($row, 'end_reason');
        $endedAt = null === $row['ended_at'] ? null : $this->time($row['ended_at']);

        return new Session(
            SessionId::fromString(Row::string($row, 'id')),
            new Realm(Row::string($row, 'realm')),
            PrincipalId::fromString(Row::string($row, 'principal_id')),
            Row::int($row, 'principal_status_version'),
            $this->authentication($row['authentication']),
            $this->time($row['started_at']),
            $this->time($row['last_seen_at']),
            $this->time($row['expires_at']),
            $this->time($row['idle_expires_at']),
            Row::nullableString($row, 'device'),
            Row::nullableString($row, 'address'),
            $endedAt,
            null === $endReason ? null : SessionEndReason::from($endReason),
        );
    }

    private function authentication(mixed $value): AuthenticationContext
    {
        $factors = Type::getType(Types::JSON)->convertToPHPValue($value, $this->database->connection->getDatabasePlatform());
        $valid = [];

        foreach (\is_array($factors) ? $factors : [null] as $factor) {
            if (!\is_array($factor) || !\is_string($factor['method'] ?? null) || !\is_string($factor['at'] ?? null) || !\is_bool($factor['multi_factor'] ?? null) || !\is_bool($factor['phishing_resistant'] ?? null) || !\array_key_exists('operation', $factor) || !(null === $factor['operation'] || \is_string($factor['operation']))) {
                throw new StorageException('A session\'s authentication isn\'t what YorvoID wrote: the row was changed.');
            }

            $valid[] = ['method' => $factor['method'], 'at' => $factor['at'], 'multi_factor' => $factor['multi_factor'], 'phishing_resistant' => $factor['phishing_resistant'], 'operation' => $factor['operation']];
        }

        return AuthenticationContext::fromArray($valid);
    }

    private function time(mixed $value): \DateTimeImmutable
    {
        $time = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($value, $this->database->connection->getDatabasePlatform());
        \assert($time instanceof \DateTimeImmutable);

        return $time;
    }
}
