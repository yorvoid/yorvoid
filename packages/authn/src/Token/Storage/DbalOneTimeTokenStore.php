<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token\Storage;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\Token\OneTimeToken;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\StorageException;

/**
 * @api
 */
final readonly class DbalOneTimeTokenStore implements OneTimeTokenStore
{
    public const string TABLE = 'one_time_token';

    public function __construct(
        private Database $database,
    ) {
    }

    public function add(OneTimeToken $token, string $verifierHash): void
    {
        try {
            $this->database->connection->insert($this->database->table(self::TABLE), [
                'selector' => $token->selector,
                'realm' => $token->realm->name,
                'principal_id' => $token->principalId->uuid->toRfc4122(),
                'purpose' => $token->purpose,
                'verifier_hash' => $verifierHash,
                'email' => $token->email,
                'created_at' => $token->createdAt,
                'expires_at' => $token->expiresAt,
            ], ['created_at' => Types::DATETIMETZ_IMMUTABLE, 'expires_at' => Types::DATETIMETZ_IMMUTABLE]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new StorageException(\sprintf('A token with the selector %s exists.', $token->selector), previous: $exception);
        }
    }

    public function find(Realm $realm, string $selector, string $purpose): ?array
    {
        $row = $this->database->connection->fetchAssociative(
            \sprintf('SELECT principal_id, verifier_hash, email, created_at, expires_at FROM %s WHERE selector = ? AND realm = ? AND purpose = ?', $this->database->table(self::TABLE)),
            [$selector, $realm->name, $purpose],
        );

        if (false === $row) {
            return null;
        }

        $token = new OneTimeToken($selector, $realm, PrincipalId::fromString(Row::string($row, 'principal_id')), $purpose, Row::nullableString($row, 'email'), $this->time($row['created_at']), $this->time($row['expires_at']));

        return [$token, Row::string($row, 'verifier_hash')];
    }

    public function delete(Realm $realm, string $selector): bool
    {
        return 1 === $this->database->connection->delete($this->database->table(self::TABLE), ['selector' => $selector, 'realm' => $realm->name]);
    }

    public function deleteFor(Realm $realm, PrincipalId $principalId, string $purpose): int
    {
        return (int) $this->database->connection->delete($this->database->table(self::TABLE), ['realm' => $realm->name, 'principal_id' => $principalId->uuid->toRfc4122(), 'purpose' => $purpose]);
    }

    public function purge(\DateTimeImmutable $now): int
    {
        return (int) $this->database->connection->executeStatement(\sprintf('DELETE FROM %s WHERE expires_at <= ?', $this->database->table(self::TABLE)), [$now], [Types::DATETIMETZ_IMMUTABLE]);
    }

    private function time(mixed $value): \DateTimeImmutable
    {
        $time = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($value, $this->database->connection->getDatabasePlatform());
        \assert($time instanceof \DateTimeImmutable);

        return $time;
    }
}
