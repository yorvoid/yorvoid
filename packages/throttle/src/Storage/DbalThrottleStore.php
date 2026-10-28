<?php

declare(strict_types=1);

namespace YorvoID\Throttle\Storage;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\SqlPlatform;
use YorvoID\Throttle\ThrottleStore;

/**
 * Throttling state in YorvoID's database, shared by every server: no cache server needed.
 *
 * Attempts are counted outside transactions: a transaction rolled back after a failed attempt would
 * undo its count, and with it the limit. Count them before starting one, or give the throttle a
 * connection of its own.
 *
 * @api
 */
final readonly class DbalThrottleStore implements ThrottleStore
{
    public const string TABLE = 'throttle_key';

    public function __construct(
        private Database $database,
    ) {
    }

    public function clearAt(string $key): ?int
    {
        $row = $this->database->connection->fetchAssociative(\sprintf('SELECT clear_at FROM %s WHERE key_hash = ?', $this->database->table(self::TABLE)), [$key]);

        return false === $row ? null : Row::int($row, 'clear_at');
    }

    public function replace(string $key, ?int $expected, int $clearAt): bool
    {
        $this->refuseInsideATransaction();
        $table = $this->database->table(self::TABLE);

        if (null === $expected) {
            return 1 === $this->database->connection->executeStatement(match ($this->database->platform) {
                SqlPlatform::PostgreSQL => \sprintf('INSERT INTO %s (key_hash, clear_at) VALUES (?, ?) ON CONFLICT (key_hash) DO NOTHING', $table),
                SqlPlatform::SQLite => \sprintf('INSERT OR IGNORE INTO %s (key_hash, clear_at) VALUES (?, ?)', $table),
            }, [$key, $clearAt]);
        }

        return 1 === $this->database->connection->executeStatement(\sprintf('UPDATE %s SET clear_at = ? WHERE key_hash = ? AND clear_at = ?', $table), [$clearAt, $key, $expected]);
    }

    public function remove(string $key): void
    {
        $this->refuseInsideATransaction();
        $this->database->connection->delete($this->database->table(self::TABLE), ['key_hash' => $key]);
    }

    public function purge(int $now): int
    {
        return (int) $this->database->connection->executeStatement(\sprintf('DELETE FROM %s WHERE clear_at <= ?', $this->database->table(self::TABLE)), [$now]);
    }

    private function refuseInsideATransaction(): void
    {
        if ($this->database->connection->isTransactionActive()) {
            throw new IntegratorError('Throttling inside a transaction: rolling it back would undo the attempt\'s count.', 'Count attempts before starting the transaction, or give the throttle a database connection of its own.', 'concepts/throttling.md#storage');
        }
    }
}
