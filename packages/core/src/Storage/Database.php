<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

use Doctrine\DBAL\Connection;
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
}
