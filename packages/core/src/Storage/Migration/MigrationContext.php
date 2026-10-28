<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Migration;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\SqlPlatform;

/**
 * What a migration writes its changes with. It collects SQL rather than running it, so the same
 * migration can be shown to a database administrator (`sql()`) or run (`migrate()`), statement for
 * statement.
 *
 * @api
 */
final class MigrationContext
{
    /** @var list<string> */
    private array $statements = [];

    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function platform(): SqlPlatform
    {
        return $this->database->platform;
    }

    /**
     * A table of YorvoID's, quoted and qualified, for SQL.
     */
    public function table(string $name): string
    {
        return $this->database->table($name);
    }

    /**
     * The unquoted, prefixed name of an index or constraint, unique within the schema.
     *
     * @return non-empty-string
     */
    public function name(string $name): string
    {
        return $this->database->location->name($name);
    }

    /**
     * Creates one of YorvoID's tables, described with DBAL's table editor; its name gets the prefix
     * and schema.
     *
     * @param \Closure(TableEditor): void $define
     */
    public function createTable(string $name, \Closure $define): void
    {
        $editor = Table::editor()->setUnquotedName($this->database->location->name($name), $this->database->location->schema);
        $define($editor);

        foreach ($this->database->connection->getDatabasePlatform()->getCreateTableSQL($editor->create()) as $statement) {
            $this->statements[] = $statement;
        }
    }

    public function execute(string $sql): void
    {
        $this->statements[] = $sql;
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }
}
