<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery\Storage;

use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;

/**
 * @api
 */
final readonly class DbalRecoveryCodeStore implements RecoveryCodeStore
{
    public const string TABLE = 'recovery_code';

    public function __construct(
        private Database $database,
    ) {
    }

    public function replace(Realm $realm, PrincipalId $principalId, array $hashes, \DateTimeImmutable $createdAt): void
    {
        $table = $this->database->table(self::TABLE);
        $principal = $principalId->uuid->toRfc4122();
        $this->database->connection->delete($table, ['realm' => $realm->name, 'principal_id' => $principal]);

        foreach (array_unique($hashes) as $hash) {
            $this->database->connection->insert($table, ['realm' => $realm->name, 'principal_id' => $principal, 'code_hash' => $hash, 'created_at' => $createdAt], ['created_at' => Types::DATETIMETZ_IMMUTABLE]);
        }
    }

    public function use(Realm $realm, PrincipalId $principalId, string $hash): bool
    {
        return 1 === $this->database->connection->delete($this->database->table(self::TABLE), ['realm' => $realm->name, 'principal_id' => $principalId->uuid->toRfc4122(), 'code_hash' => $hash]);
    }

    public function count(Realm $realm, PrincipalId $principalId): int
    {
        $row = $this->database->connection->fetchAssociative(\sprintf('SELECT COUNT(*) AS remaining FROM %s WHERE realm = ? AND principal_id = ?', $this->database->table(self::TABLE)), [$realm->name, $principalId->uuid->toRfc4122()]);
        \assert(\is_array($row));

        return Row::int($row, 'remaining');
    }
}
