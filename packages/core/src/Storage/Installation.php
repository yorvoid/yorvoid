<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

/**
 * Which installation of YorvoID this is: a random ID made once, when the database is migrated, and kept
 * in it. What a cache shared by several installations tells their entries apart by: realms are named
 * "default" in every one, and their versions count from 1 in every one.
 *
 * @api
 */
final class Installation
{
    public const string TABLE = 'installation';

    private function __construct(
        private readonly ?Database $database,
        private ?string $id,
    ) {
    }

    /**
     * The installation whose ID its database keeps.
     */
    public static function stored(Database $database): self
    {
        return new self($database, null);
    }

    /**
     * An installation with an ID of one's own, e.g. one without a database.
     */
    public static function named(string $id): self
    {
        return new self(null, $id);
    }

    /**
     * @throws StorageException When the database has no ID: its migrations haven't run
     */
    public function id(): string
    {
        if (null !== $this->id) {
            return $this->id;
        }

        \assert(null !== $this->database);
        $id = $this->database->connection->fetchOne(\sprintf('SELECT id FROM %s ORDER BY id', $this->database->table(self::TABLE)));

        if (!\is_string($id) || '' === $id) {
            throw new StorageException('The database has no installation ID: run YorvoID\'s migrations.');
        }

        return $this->id = $id;
    }
}
