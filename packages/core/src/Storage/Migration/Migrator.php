<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Migration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\SqlPlatform;
use YorvoID\Core\Storage\StorageException;

/**
 * Runs YorvoID's migrations, separately from the application's own (PLT-023): their history is in a
 * table of YorvoID's location, and one process at a time runs them, under a lock of that location.
 * Each migration runs in its own transaction with its history row, so a failed one leaves nothing
 * behind on databases with transactional DDL (PostgreSQL, SQLite).
 *
 * @api
 */
final class Migrator
{
    public const string HISTORY_TABLE = 'migration';

    public const int MAX_VERSION_LENGTH = 191;

    public const int MAX_DESCRIPTION_LENGTH = 255;

    private const string VERSION = '/^[0-9]{14}_[a-z][a-z0-9_]*$/D';

    /** @var array<string, Migration> By version, in order */
    private array $migrations = [];

    private readonly Duration $lockTimeout;

    /**
     * @param iterable<Migration> $migrations
     * @param Duration|null       $lockTimeout How long to wait for another process's migrations; 1 minute by default
     */
    public function __construct(
        private readonly Database $database,
        iterable $migrations,
        private readonly ClockInterface $clock = new NativeClock(),
        ?Duration $lockTimeout = null,
    ) {
        $this->lockTimeout = $lockTimeout ?? Duration::minutes(1);
        foreach ($migrations as $migration) {
            $version = $migration->version();

            if (1 !== preg_match(self::VERSION, $version) || \strlen($version) > self::MAX_VERSION_LENGTH) {
                throw new IntegratorError(\sprintf('The migration version "%s" (%s) is invalid.', $version, $migration::class), \sprintf('Use the time it was written and what it does, at most %d characters, e.g. "20261027180000_core_security_log".', self::MAX_VERSION_LENGTH), 'concepts/storage.md#migrations');
            }

            if (mb_strlen($migration->description()) > self::MAX_DESCRIPTION_LENGTH) {
                throw new IntegratorError(\sprintf('The description of the migration %s is too long.', $version), \sprintf('Describe it in at most %d characters.', self::MAX_DESCRIPTION_LENGTH), 'concepts/storage.md#migrations');
            }

            if (isset($this->migrations[$version])) {
                throw new IntegratorError(\sprintf('Two migrations have the version "%s": %s and %s.', $version, $this->migrations[$version]::class, $migration::class), 'Give each migration its own version.', 'concepts/storage.md#migrations');
            }

            $this->migrations[$version] = $migration;
        }

        ksort($this->migrations, \SORT_STRING);
    }

    /**
     * Every migration, known or only recorded, in order.
     *
     * @return list<MigrationState>
     */
    public function status(): array
    {
        $executed = $this->executed();
        $states = [];

        foreach ($this->migrations as $version => $migration) {
            $states[$version] = new MigrationState($version, $migration->description(), $migration->lockProfile(), $executed[$version]['at'] ?? null);
        }

        foreach ($executed as $version => $row) {
            $states[$version] ??= new MigrationState($version, $row['description'], null, $row['at']);
        }

        ksort($states, \SORT_STRING);

        return array_values($states);
    }

    /**
     * @return list<Migration> Not executed yet, in order
     */
    public function pending(): array
    {
        return array_values(array_diff_key($this->migrations, $this->executed()));
    }

    /**
     * The SQL that migrate() would run now, for a database administrator to review or run by hand.
     *
     * @return list<string>
     */
    public function sql(): array
    {
        $statements = $this->historyExists() ? [] : $this->historySetup();

        foreach ($this->pending() as $migration) {
            $context = new MigrationContext($this->database);
            $migration->up($context);
            array_push($statements, ...$context->statements());
            $statements[] = \sprintf(
                'INSERT INTO %s (version, description, executed_at) VALUES (%s, %s, CURRENT_TIMESTAMP)',
                $this->database->table(self::HISTORY_TABLE),
                $this->database->connection->quote($migration->version()),
                $this->database->connection->quote($migration->description()),
            );
        }

        return $statements;
    }

    /**
     * Runs the pending migrations.
     *
     * @param bool $allowBlocking Also run migrations that block a table in use (LockProfile::Blocking),
     *                            e.g. in a maintenance window
     *
     * @return list<string> The versions that ran, in order
     */
    public function migrate(bool $allowBlocking = false): array
    {
        $connection = $this->database->connection;

        if ($connection->isTransactionActive()) {
            throw new IntegratorError('YorvoID\'s migrations were started inside a transaction.', 'Run them on their own, e.g. from a deployment step, not from application code inside a transaction.', 'concepts/storage.md#migrations');
        }

        $this->lock();

        try {
            if (!$this->historyExists()) {
                foreach ($this->historySetup() as $statement) {
                    $connection->executeStatement($statement);
                }
            }

            $pending = $this->pending();

            foreach ($pending as $migration) {
                if (LockProfile::Blocking === $migration->lockProfile() && !$allowBlocking) {
                    throw new IntegratorError(\sprintf('The migration %s blocks tables while it runs.', $migration->version()), 'Run it in a maintenance window, allowing blocking migrations explicitly.', 'concepts/storage.md#upgrading-without-downtime');
                }
            }

            $ran = [];

            foreach ($pending as $migration) {
                $context = new MigrationContext($this->database);
                $migration->up($context);

                $connection->transactional(function () use ($connection, $context, $migration): void {
                    foreach ($context->statements() as $statement) {
                        $connection->executeStatement($statement);
                    }

                    $connection->insert($this->database->table(self::HISTORY_TABLE), [
                        'version' => $migration->version(),
                        'description' => $migration->description(),
                        'executed_at' => $this->clock->now(),
                    ], ['executed_at' => Types::DATETIMETZ_IMMUTABLE]);
                });

                $ran[] = $migration->version();
            }

            return $ran;
        } finally {
            $this->unlock();
        }
    }

    /**
     * @return array<string, array{description: ?string, at: \DateTimeImmutable}> By version
     */
    private function executed(): array
    {
        if (!$this->historyExists()) {
            return [];
        }

        $connection = $this->database->connection;
        $platform = $connection->getDatabasePlatform();
        $time = Type::getType(Types::DATETIMETZ_IMMUTABLE);
        $executed = [];

        foreach ($connection->fetchAllAssociative(\sprintf('SELECT version, description, executed_at FROM %s', $this->database->table(self::HISTORY_TABLE))) as $row) {
            $at = $time->convertToPHPValue($row['executed_at'], $platform);
            \assert($at instanceof \DateTimeImmutable);
            \assert(\is_string($row['version']) && (null === $row['description'] || \is_string($row['description'])));
            $executed[$row['version']] = ['description' => $row['description'], 'at' => $at];
        }

        return $executed;
    }

    private function historyExists(): bool
    {
        $location = $this->database->location;
        $name = $location->name(self::HISTORY_TABLE);

        return false !== match ($this->database->platform) {
            SqlPlatform::PostgreSQL => $this->database->connection->fetchOne('SELECT 1 FROM pg_catalog.pg_tables WHERE schemaname = COALESCE(?, current_schema()) AND tablename = ?', [$location->schema, $name]),
            SqlPlatform::SQLite => $this->database->connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$name]),
        };
    }

    /**
     * @return list<string>
     */
    private function historySetup(): array
    {
        $context = new MigrationContext($this->database);

        if (null !== $this->database->location->schema) {
            $context->execute(\sprintf('CREATE SCHEMA IF NOT EXISTS %s', $this->database->connection->quoteSingleIdentifier($this->database->location->schema)));
        }

        $context->createTable(self::HISTORY_TABLE, static function (TableEditor $table): void {
            $table->addColumn(Column::editor()->setUnquotedName('version')->setTypeName(Types::STRING)->setLength(self::MAX_VERSION_LENGTH)->create());
            $table->addColumn(Column::editor()->setUnquotedName('description')->setTypeName(Types::STRING)->setLength(self::MAX_DESCRIPTION_LENGTH)->setNotNull(false)->create());
            $table->addColumn(Column::editor()->setUnquotedName('executed_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('version')->create());
        });

        return $context->statements();
    }

    private function lock(): void
    {
        if (SqlPlatform::PostgreSQL !== $this->database->platform) {
            return; // SQLite allows one writer at a time anyway.
        }

        $deadline = microtime(true) + $this->lockTimeout->seconds;

        do {
            if (true === $this->database->connection->fetchOne('SELECT pg_try_advisory_lock(?)', [$this->lockKey()])) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new StorageException(\sprintf('Another process is running YorvoID\'s migrations for this database location; gave up after waiting %s.', $this->lockTimeout));
    }

    private function unlock(): void
    {
        if (SqlPlatform::PostgreSQL === $this->database->platform) {
            $this->database->connection->fetchOne('SELECT pg_advisory_unlock(?)', [$this->lockKey()]);
        }
    }

    /**
     * The advisory lock of this location: two YorvoID installations in different schemas or with different prefixes
     * migrate independently.
     */
    private function lockKey(): int
    {
        $location = $this->database->location;
        // The first 8 bytes of the hash, as PostgreSQL's 64-bit lock key.
        $key = unpack('q', hash('sha256', \sprintf('yorvoid-migrations:%s:%s', $location->schema, $location->prefix), true));
        \assert(\is_array($key) && \is_int($key[1]));

        return $key[1];
    }
}
