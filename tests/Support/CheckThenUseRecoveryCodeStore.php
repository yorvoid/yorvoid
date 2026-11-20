<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Authn\Recovery\Storage\DbalRecoveryCodeStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;

/**
 * A recovery code store that loses races of one call (LosingStoreTest): use() looks
 * for the code, then deletes it, and says it was used if it was found. Two requests at once can both
 * find it.
 */
final readonly class CheckThenUseRecoveryCodeStore implements RecoveryCodeStore
{
    public function __construct(
        private Database $database,
        private RecoveryCodeStore $store,
    ) {
    }

    public function replace(Realm $realm, PrincipalId $principalId, array $hashes, \DateTimeImmutable $createdAt): void
    {
        $this->store->replace($realm, $principalId, $hashes, $createdAt);
    }

    public function use(Realm $realm, PrincipalId $principalId, string $hash): bool
    {
        $where = ['realm' => $realm->name, 'principal_id' => $principalId->id->toString(), 'code_hash' => $hash];
        $found = false !== $this->database->connection->fetchOne(\sprintf('SELECT 1 FROM %s WHERE realm = ? AND principal_id = ? AND code_hash = ?', $this->database->table(DbalRecoveryCodeStore::TABLE)), array_values($where));
        $this->database->connection->delete($this->database->table(DbalRecoveryCodeStore::TABLE), $where);

        return $found;
    }

    public function count(Realm $realm, PrincipalId $principalId): int
    {
        return $this->store->count($realm, $principalId);
    }
}
