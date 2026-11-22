<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * On each database: the security log's entries wait for the commit and follow its savepoints. A nested
 * unit that committed keeps its entry when a later one rolls back, through YorvoID's units and the
 * application's DBAL transactions.
 */
final class LogAtCommitTest extends DatabaseTestCase
{
    #[DataProvider('platforms')]
    public function testAnEntryOfACommittedNestedUnitOutlivesALaterOneRolledBack(string $platform): void
    {
        $stack = $this->stack($platform);
        $database = $stack->database;
        \assert($database instanceof Database);
        $connection = $database->connection;
        $log = new SecurityLog($stack->log, $stack->transactions);
        $realm = new Realm('acme');
        /** @var array<string, \Closure(\Closure(): void): void> $units */
        $units = [
            'run' => static function (\Closure $work) use ($stack): void {
                $stack->transactions->run(static function () use ($work): null {
                    $work();

                    return null;
                });
            },
            'transactional' => static function (\Closure $work) use ($connection): void {
                $connection->transactional(static function () use ($work): null {
                    $work();

                    return null;
                });
            },
        ];

        foreach ($units as $unit) {
            $unit(static function () use ($unit, $log, $realm): void {
                $unit(static fn () => $log->record($realm, 'acme.thing.kept', Outcome::Success, new Origin(OriginKind::Web)));

                try {
                    $unit(static function () use ($log, $realm): never {
                        $log->record($realm, 'acme.thing.undone', Outcome::Success, new Origin(OriginKind::Web));

                        throw new \RuntimeException('the second unit failed');
                    });
                } catch (\RuntimeException) {
                }

                $unit(static fn () => $log->record($realm, 'acme.thing.after', Outcome::Success, new Origin(OriginKind::Web)));
            });
        }

        self::assertSame(['acme.thing.kept', 'acme.thing.after', 'acme.thing.kept', 'acme.thing.after'], array_map(static fn (LogEntry $entry): string => $entry->action, iterator_to_array($stack->log->entries($realm, 1), false)));
        self::assertTrue($log->verify($realm)->isIntact());
        self::assertFalse($connection->isTransactionActive());
    }
}
