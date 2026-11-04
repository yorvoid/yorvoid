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

    public function advance(string $key, int $now, int $interval, int $latest): ?int
    {
        $this->refuseInsideATransaction();
        // One statement: the row is created, or moved on only while it allows another attempt (PERF-05).
        $sql = \sprintf(
            'INSERT INTO %s AS stored (key_hash, clear_at) SELECT ?, CAST(? AS BIGINT) WHERE CAST(? AS BIGINT) <= CAST(? AS BIGINT) ON CONFLICT (key_hash) DO UPDATE SET clear_at = %2$s(stored.clear_at, CAST(? AS BIGINT)) + CAST(? AS BIGINT) WHERE %2$s(stored.clear_at, CAST(? AS BIGINT)) <= CAST(? AS BIGINT) RETURNING clear_at',
            $this->database->table(self::TABLE),
            SqlPlatform::PostgreSQL === $this->database->platform ? 'GREATEST' : 'MAX',
        );
        $clearAt = $this->database->connection->fetchOne($sql, [$key, $now + $interval, $now, $latest, $now, $interval, $now, $latest]);

        return false === $clearAt ? null : Row::int(['clear_at' => $clearAt], 'clear_at');
    }

    public function remove(string $key): void
    {
        $this->refuseInsideATransaction();
        $this->database->connection->delete($this->database->table(self::TABLE), ['key_hash' => $key]);
    }

    public function purge(int $now): int
    {
        return $this->database->deleteInBatches(self::TABLE, 'clear_at <= ?', [$now]);
    }

    private function refuseInsideATransaction(): void
    {
        if ($this->database->connection->isTransactionActive()) {
            throw new IntegratorError('Throttling inside a transaction: rolling it back would undo the attempt\'s count.', 'Count attempts before starting the transaction, or give the throttle a database connection of its own.', 'concepts/throttling.md#storage');
        }
    }
}
