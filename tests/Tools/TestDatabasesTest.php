<?php

declare(strict_types=1);

namespace YorvoID\Tests\Tools;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Stack;

/**
 * The process's shared PostgreSQL schema, once reset, is what a fresh migration leaves, table by table:
 * the same tables, the same rows (but for what two fresh migrations don't agree on, e.g. a random
 * installation ID), and the same sequences.
 */
final class TestDatabasesTest extends DatabaseTestCase
{
    public function testAResetSchemaIsAFreshMigration(): void
    {
        if ('1' === getenv('YORVOID_TEST_FRESH_DATABASES')) {
            self::markTestSkipped('Each test migrates its own database: there\'s no shared one to reset.');
        }

        $stack = $this->stack('postgresql');
        $shared = $stack->database;
        \assert($shared instanceof Database);

        // What a test leaves: rows of its own, and a seeded row gone.
        $now = new \DateTimeImmutable('2026-11-18 18:40:00 UTC');
        $stack->principals->add(new Principal(new PrincipalId(new UuidV7()), Realm::default(), PrincipalKind::Human, PrincipalStatus::Active, $now, $now, $now));
        $shared->connection->executeStatement(\sprintf('DELETE FROM %s', $shared->table(Installation::TABLE)));
        $this->resetSharedDatabase();

        $fresh = $this->freshSchema('yorvoid_test_f1_'.bin2hex(random_bytes(3)));
        $other = $this->freshSchema('yorvoid_test_f2_'.bin2hex(random_bytes(3)));
        $location = $shared->location->schema;
        \assert(null !== $location);

        $tables = $this->tables($shared->connection, $location);
        self::assertSame($this->tables($fresh->connection, (string) $fresh->location->schema), $tables);

        foreach ($tables as $table) {
            $freshRows = $this->rows($fresh->connection, (string) $fresh->location->schema, $table);
            $otherRows = $this->rows($other->connection, (string) $other->location->schema, $table);
            $volatile = [...self::volatile($freshRows, $otherRows), ...$this->moments($fresh->connection, (string) $fresh->location->schema, $table)];

            self::assertSame(self::without($freshRows, $volatile), self::without($this->rows($shared->connection, $location, $table), $volatile), \sprintf('The reset left "%s" otherwise than a migration.', $table));
        }

        self::assertSame($this->sequences($fresh->connection, (string) $fresh->location->schema), $this->sequences($shared->connection, $location));
    }

    private function freshSchema(string $schema): Database
    {
        $database = $this->postgresDatabase($schema, StorageLocation::DEFAULT_PREFIX);
        Stack::migrate($database);

        return $database;
    }

    /**
     * @return list<string>
     */
    private function tables(Connection $connection, string $schema): array
    {
        return array_map(static fn (mixed $name): string => \is_string($name) ? $name : throw new \UnexpectedValueException('A table name isn\'t a string.'), $connection->fetchFirstColumn('SELECT tablename FROM pg_tables WHERE schemaname = ? ORDER BY tablename', [$schema]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(Connection $connection, string $schema, string $table): array
    {
        $rows = $connection->fetchAllAssociative(\sprintf('SELECT * FROM "%s"."%s"', $schema, $table));
        usort($rows, static fn (array $a, array $b): int => serialize($a) <=> serialize($b));

        return $rows;
    }

    /**
     * @return array<string, int|null> By sequence, its last value
     */
    private function sequences(Connection $connection, string $schema): array
    {
        $sequences = [];

        foreach ($connection->fetchAllKeyValue('SELECT sequencename, last_value FROM pg_sequences WHERE schemaname = ? ORDER BY sequencename', [$schema]) as $name => $last) {
            $sequences[(string) $name] = is_numeric($last) ? (int) $last : null;
        }

        return $sequences;
    }

    /**
     * The columns that hold a moment: two migrations in the same second write the same, but not a third.
     *
     * @return list<string>
     */
    private function moments(Connection $connection, string $schema, string $table): array
    {
        return array_map(static fn (mixed $name): string => \is_string($name) ? $name : throw new \UnexpectedValueException('A column name isn\'t a string.'), $connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND (data_type LIKE 'timestamp%' OR data_type = 'date')", [$schema, $table]));
    }

    /**
     * The columns two fresh migrations wrote differently: random, or of the moment.
     *
     * @param list<array<string, mixed>> $one
     * @param list<array<string, mixed>> $other
     *
     * @return list<string>
     */
    private static function volatile(array $one, array $other): array
    {
        $columns = [];

        foreach ($one as $index => $row) {
            foreach ($row as $column => $value) {
                if (($other[$index][$column] ?? null) !== $value) {
                    $columns[] = $column;
                }
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $columns
     *
     * @return list<array<string, mixed>>
     */
    private static function without(array $rows, array $columns): array
    {
        return array_map(static fn (array $row): array => array_diff_key($row, array_flip($columns)), $rows);
    }
}
