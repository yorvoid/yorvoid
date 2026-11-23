<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\Storage;

use Doctrine\DBAL\ArrayParameterType;
use YorvoID\Core\Authorization\VersionStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;

/**
 * The versions in one table, a row per realm and source (yorvoid_versions). Give one instance to every
 * store that bumps a version and to the evaluator's sources, so a decision reads them in one statement.
 *
 * @api
 */
final readonly class DbalVersionStore implements VersionStore
{
    public const string TABLE = 'versions';

    public function __construct(
        private Database $database,
    ) {
    }

    public function versions(Realm $realm, array $sources): array
    {
        $versions = array_fill_keys($sources, 0);

        if ([] === $sources) {
            return $versions;
        }

        foreach ($this->database->connection->iterateAssociative(\sprintf('SELECT source, version FROM %s WHERE realm = ? AND source IN (?)', $this->database->table(self::TABLE)), [$realm->name, $sources], [\Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::STRING]) as $row) {
            $versions[Row::string($row, 'source')] = Row::int($row, 'version');
        }

        return $versions;
    }

    public function bump(Realm $realm, string $source): int
    {
        $table = $this->database->table(self::TABLE);
        // One statement, so two changes that both find no row don't collide; the row stays locked until
        // the transaction ends.
        $this->database->connection->executeStatement(\sprintf('INSERT INTO %1$s (realm, source, version) VALUES (?, ?, 1) ON CONFLICT (realm, source) DO UPDATE SET version = %1$s.version + 1', $table), [$realm->name, $source]);

        return $this->versions($realm, [$source])[$source];
    }
}
