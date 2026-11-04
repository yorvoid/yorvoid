<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use Doctrine\DBAL\Exception as DbalException;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\SqlPlatform;

/**
 * Whether the application's database user can rewrite the security log. The log's chain detects
 * changes made around YorvoID, not by someone with these privileges, who can cut entries off the end
 * or rewrite the chain and its head together. Recording needs only INSERT on the log, and INSERT and
 * UPDATE on its head.
 *
 * SQLite has no privileges: whoever can write the file can do anything to it.
 *
 * @internal
 */
final readonly class LogGrantsCheck implements Check
{
    /** @var array<string, list<string>> Table => privileges it shouldn't have */
    private const array UNNEEDED = [
        DbalSecurityLogStore::TABLE => ['UPDATE', 'DELETE', 'TRUNCATE'],
        DbalSecurityLogStore::HEAD_TABLE => ['DELETE', 'TRUNCATE'],
    ];

    /**
     * @param \Closure(): Database $database Built here: not reaching it is the migrations check's finding
     */
    public function __construct(private \Closure $database)
    {
    }

    public function findings(): iterable
    {
        try {
            $database = ($this->database)();

            if (SqlPlatform::PostgreSQL !== $database->platform) {
                return;
            }

            $held = [];

            foreach (self::UNNEEDED as $name => $privileges) {
                $table = $database->table($name);

                foreach ($privileges as $privilege) {
                    if ('1' === $database->connection->fetchOne('SELECT has_table_privilege(current_user, ?, ?)::int::text', [$table, $privilege])) {
                        $held[] = \sprintf('%s on %s', $privilege, $table);
                    }
                }
            }
        } catch (DbalException) {
            return;
        }

        if ([] !== $held) {
            yield Finding::warning(
                \sprintf('The application\'s database user can rewrite the security log, and verification wouldn\'t notice: %s.', implode(', ', $held)),
                'Run migrations as another user that owns YorvoID\'s tables, and give the application\'s user only INSERT and SELECT on the log, and INSERT, SELECT and UPDATE on its head.',
                'concepts/security-log.md#what-the-chain-proves-and-what-it-doesnt',
            );
        }
    }
}
