<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use Doctrine\DBAL\Exception as DbalException;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationState;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Symfony\Migrations;

/**
 * Whether YorvoID reaches its database, and its tables are those this version expects.
 *
 * @internal
 */
final readonly class MigrationsCheck implements Check
{
    /** @var list<Migration> */
    private array $migrations;

    /**
     * @param \Closure(): Database $database   Built here, where not reaching it is a finding
     * @param list<Migration>|null $migrations Every package's, if not given
     */
    public function __construct(
        private \Closure $database,
        ?array $migrations = null,
    ) {
        $this->migrations = $migrations ?? Migrations::all();
    }

    public function findings(): iterable
    {
        try {
            $states = new Migrator(($this->database)(), $this->migrations)->status();
        } catch (DbalException $error) {
            yield Finding::problem(\sprintf('YorvoID can\'t reach its database: %s', $error->getMessage()), 'Check the DoctrineBundle connection in yorvoid.connection, and that the database server is running.', 'concepts/symfony.md#configuration');

            return;
        }

        $pending = array_filter($states, static fn (MigrationState $state): bool => !$state->isUnknown() && !$state->isExecuted());
        $unknown = array_filter($states, static fn (MigrationState $state): bool => $state->isUnknown());

        if ([] !== $pending) {
            yield Finding::problem(\sprintf('%d of YorvoID\'s migrations haven\'t run: %s.', \count($pending), implode(', ', array_map(static fn (MigrationState $state): string => $state->version, $pending))), 'Run yorvoid:migrate; --dry-run shows the SQL first.', 'concepts/storage.md#migrations');
        }

        if ([] !== $unknown) {
            yield Finding::warning(\sprintf('The database has migrations this version of YorvoID doesn\'t know: %s. Was YorvoID downgraded?', implode(', ', array_map(static fn (MigrationState $state): string => $state->version, $unknown))), 'Deploy the version of YorvoID that ran them, or a later one.', 'concepts/storage.md#upgrading-without-downtime');
        }
    }
}
