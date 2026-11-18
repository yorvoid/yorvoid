<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use YorvoID\Core\Exception\IntegratorError;

/**
 * YorvoID's access to the application's database: the application's own DBAL connection, so YorvoID's
 * writes join the application's transactions (PLT-021), and the location of YorvoID's tables in it.
 *
 * @api
 */
final readonly class Database
{
    public SqlPlatform $platform;

    public function __construct(
        public Connection $connection,
        public StorageLocation $location = new StorageLocation(),
    ) {
        $this->platform = SqlPlatform::of($connection->getDatabasePlatform());

        if (null !== $location->schema && !$this->platform->supportsSchemas()) {
            throw new IntegratorError(\sprintf('A schema ("%s") is configured, but %s has no schemas.', $location->schema, $this->platform->name), 'Leave the schema out and use the table prefix to separate YorvoID\'s tables.', 'concepts/storage.md#where-the-tables-live');
        }
    }

    /**
     * One of YorvoID's tables, quoted and qualified with its schema, ready for SQL.
     */
    public function table(string $name): string
    {
        $table = $this->connection->quoteSingleIdentifier($this->location->name($name));

        return null === $this->location->schema ? $table : $this->connection->quoteSingleIdentifier($this->location->schema).'.'.$table;
    }

    /**
     * Inserts the row, unless a row with the same values in the conflict columns exists (SS-01). A row
     * that's there already isn't an error, so the caller's transaction goes on, also on PostgreSQL, where a
     * failed statement aborts it. A conflict on any other unique key still throws.
     *
     * @param string                                   $name       The table, as given to table()
     * @param non-empty-array<string, mixed>           $row        By column
     * @param non-empty-list<string>|null              $conflictOn The columns of the unique key that may conflict;
     *                                                             null for any, only where every unique key of the
     *                                                             table means "it exists"
     * @param array<string, ParameterType|Type|string> $types      By column
     *
     * @return bool Whether it was inserted
     *
     * @internal For YorvoID's stores: an insert here is a write to the database
     */
    public function insertUnlessExists(string $name, array $row, ?array $conflictOn, array $types = []): bool
    {
        // PostgreSQL and SQLite (3.24 and later) have the same form; another platform adds its own here.
        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT %sDO NOTHING',
            $this->table($name),
            implode(', ', array_map($this->connection->quoteSingleIdentifier(...), array_keys($row))),
            implode(', ', array_map(static fn (string $column): string => ':'.$column, array_keys($row))),
            null === $conflictOn ? '' : '('.implode(', ', array_map($this->connection->quoteSingleIdentifier(...), $conflictOn)).') ',
        );

        return 1 === (int) $this->connection->executeStatement($sql, $row, $types);
    }

    /**
     * Increments a counter row, making it at 1 if there's none, in one statement: two changes that both
     * find no row don't collide. The row stays locked until the transaction ends, so changes to it go one
     * after another.
     *
     * @param string $name   The table, as given to table()
     * @param string $key    Its key column
     * @param string $column The counter's column
     *
     * @internal For YorvoID's stores' versions
     */
    public function increment(string $name, string $key, string $value, string $column = 'version'): void
    {
        $table = $this->table($name);
        $key = $this->connection->quoteSingleIdentifier($key);
        $column = $this->connection->quoteSingleIdentifier($column);

        $this->connection->executeStatement(
            \sprintf('INSERT INTO %1$s (%2$s, %3$s) VALUES (?, 1) ON CONFLICT (%2$s) DO UPDATE SET %3$s = %1$s.%3$s + 1', $table, $key, $column),
            [$value],
        );
    }

    /**
     * Deletes the rows of one of YorvoID's tables that match a condition, a batch at a time, each batch a
     * statement of its own: however many rows there are, no statement runs long, holds many locks or
     * writes a burst of WAL. Outside a transaction, each batch commits on its own.
     *
     * @param string                          $name       The table, as given to table()
     * @param string                          $where      The condition, with ? for the parameters
     * @param list<mixed>                     $parameters
     * @param list<ParameterType|Type|string> $types
     * @param positive-int                    $batch      At most this many rows a statement
     *
     * @return int How many were deleted
     *
     * @internal For YorvoID's stores' purges: a delete here is a write to the database
     */
    public function deleteInBatches(string $name, string $where, array $parameters = [], array $types = [], int $batch = 10_000): int
    {
        $table = $this->table($name);
        // Each row by where it's stored, which finds it without another look at the table or an index.
        $sql = match ($this->platform) {
            SqlPlatform::PostgreSQL => \sprintf('DELETE FROM %1$s WHERE ctid = ANY(ARRAY(SELECT ctid FROM %1$s WHERE %2$s LIMIT %3$d))', $table, $where, $batch),
            SqlPlatform::SQLite => \sprintf('DELETE FROM %1$s WHERE rowid IN (SELECT rowid FROM %1$s WHERE %2$s LIMIT %3$d)', $table, $where, $batch),
        };
        $deleted = 0;

        do {
            $count = (int) $this->connection->executeStatement($sql, $parameters, $types);
            $deleted += $count;
        } while ($count === $batch);

        return $deleted;
    }
}
