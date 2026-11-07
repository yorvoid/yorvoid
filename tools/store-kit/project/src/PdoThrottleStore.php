<?php

declare(strict_types=1);

namespace Acme\Storage;

use YorvoID\Throttle\ThrottleStore;

final readonly class PdoThrottleStore implements ThrottleStore
{
    public function __construct(
        private \PDO $pdo,
    ) {
    }

    public static function createTable(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE acme_throttle (key TEXT PRIMARY KEY, clear_at INTEGER NOT NULL)');
    }

    public function clearAt(string $key): ?int
    {
        $statement = $this->pdo->prepare('SELECT clear_at FROM acme_throttle WHERE key = ?');
        $statement->execute([$key]);
        $clearAt = $statement->fetchColumn();

        return false === $clearAt ? null : (int) $clearAt;
    }

    public function advance(string $key, int $now, int $interval, int $latest): ?int
    {
        // One statement: a new key is inserted, a known one moved, each only within the limit.
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO acme_throttle (key, clear_at) SELECT :key, :now + :interval WHERE :now <= :latest
            ON CONFLICT (key) DO UPDATE SET clear_at = max(clear_at, :now) + :interval WHERE max(clear_at, :now) <= :latest
            RETURNING clear_at
            SQL);
        // As integers: execute() would bind them as text, which SQLite's max() ranks above any number.
        $statement->bindValue('key', $key);
        $statement->bindValue('now', $now, \PDO::PARAM_INT);
        $statement->bindValue('interval', $interval, \PDO::PARAM_INT);
        $statement->bindValue('latest', $latest, \PDO::PARAM_INT);
        $statement->execute();
        $clearAt = $statement->fetchColumn();
        $statement->closeCursor();

        return false === $clearAt ? null : (int) $clearAt;
    }

    public function remove(string $key): void
    {
        $this->pdo->prepare('DELETE FROM acme_throttle WHERE key = ?')->execute([$key]);
    }

    public function purge(int $now): int
    {
        $statement = $this->pdo->prepare('DELETE FROM acme_throttle WHERE clear_at <= ?');
        $statement->bindValue(1, $now, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }
}
