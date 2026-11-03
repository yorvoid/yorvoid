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
