<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Log;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\Event\AuditFailed;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Migrations\CoreMigrations;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Tests\Support\RecordingDispatcher;

/**
 * Where YorvoID sees the commit, the security log's entries are appended just before it, so the realm's
 * log is locked only for that moment; a change never commits without its entries (D-52, R2).
 */
final class LogAtCommitTest extends TestCase
{
    private Connection $connection;

    private DbalSecurityLogStore $store;

    private DbalTransactions $transactions;

    private RecordingDispatcher $alerts;

    private bool $failing = false;

    private Realm $realm;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->alerts = new RecordingDispatcher();
        [$this->connection, $this->transactions] = $this->connect(new TransactionMiddleware());
        $this->store = new DbalSecurityLogStore(new Database($this->connection));
    }

    public function testInsideATransactionTheEntryIsAppendedAtTheCommit(): void
    {
        $log = $this->log();

        $this->transactions->run(function () use ($log): void {
            $log->record($this->realm, 'acme.thing.change', Outcome::Success, new Origin(OriginKind::Web));
            self::assertNull($this->store->head($this->realm), 'Appended before the commit: the realm stayed locked for the whole unit.');
        });

        self::assertSame(1, $this->store->head($this->realm)['sequence'] ?? null);
    }

    /**
     * Where YorvoID can't see the commit, nothing waits for it: record() refuses, as afterCommit() does,
     * rather than keep an entry for a commit it would miss, and nothing is appended.
     */
    public function testWithoutTheMiddlewareNothingWaitsForTheCommit(): void
    {
        [$connection, $transactions] = $this->connect(null);
        $store = new DbalSecurityLogStore(new Database($connection));
        $log = new SecurityLog($store, $transactions);

        try {
            $transactions->run(fn () => $log->record($this->realm, 'acme.thing.change', Outcome::Success, new Origin(OriginKind::Web)));
            self::fail('It recorded where it can\'t see the commit.');
        } catch (IntegratorError) {
        }

        self::assertNull($store->head($this->realm));
    }

    /**
     * A flush that fails rolls the whole transaction back, raises the alert, and is what's thrown; the
     * next transaction works.
     */
    public function testAFailedAppendCommitsNothing(): void
    {
        $log = $this->log();
        $this->failing = true;
        $thrown = null;

        try {
            $this->transactions->run(function () use ($log): void {
                $this->connection->executeStatement('INSERT INTO change (v) VALUES (1)');
                $log->record($this->realm, 'acme.thing.change', Outcome::Success, new Origin(OriginKind::Web));
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $thrown);
        self::assertSame('The log is full.', $thrown->getMessage());
        $this->assertNothingCommitted();
        self::assertCount(1, $this->alerts->of(AuditFailed::class));

        $this->failing = false;
        $this->transactions->run(fn () => $log->record($this->realm, 'acme.thing.change', Outcome::Success, new Origin(OriginKind::Web)));
        self::assertSame(1, $this->store->head($this->realm)['sequence'] ?? null);
    }

    /**
     * Through the application's DBAL transactional(): nothing commits, the driver has no transaction open
     * after it, and the failure is the cause of what DBAL throws.
     */
    public function testAFailedAppendAtTheApplicationsCommitCommitsNothing(): void
    {
        $log = $this->log();
        $this->failing = true;
        $thrown = null;

        try {
            $this->connection->transactional(function () use ($log): void {
                $this->connection->executeStatement('INSERT INTO change (v) VALUES (1)');
                $log->record($this->realm, 'acme.thing.change', Outcome::Success, new Origin(OriginKind::Web));
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        $causes = [];

        for ($e = $thrown; null !== $e; $e = $e->getPrevious()) {
            $causes[] = $e->getMessage();
        }

        self::assertContains('The log is full.', $causes, 'The cause was lost.');
        self::assertSame('The log is full.', $this->alerts->of(AuditFailed::class)[0]->error->getMessage());
        $this->assertNothingCommitted();
        self::assertFalse($this->connection->isTransactionActive());

        $this->failing = false;
        $this->connection->transactional(fn () => $log->record($this->realm, 'acme.thing.change', Outcome::Success, new Origin(OriginKind::Web)));
        self::assertSame(1, $this->store->head($this->realm)['sequence'] ?? null, 'The next transaction doesn\'t work.');
    }

    /**
     * A savepoint the application rolls back takes its entries with it; the rest commit.
     */
    public function testASavepointRolledBackDropsItsEntries(): void
    {
        $log = $this->log();

        $this->connection->transactional(function () use ($log): void {
            $log->record($this->realm, 'acme.thing.kept', Outcome::Success, new Origin(OriginKind::Web));
            $this->connection->beginTransaction();
            $log->record($this->realm, 'acme.thing.undone', Outcome::Success, new Origin(OriginKind::Web));
            $this->connection->rollBack();
            $log->record($this->realm, 'acme.thing.after', Outcome::Success, new Origin(OriginKind::Web));
        });

        self::assertSame(['acme.thing.kept', 'acme.thing.after'], array_map(static fn (LogEntry $entry): string => $entry->action, iterator_to_array($this->store->entries($this->realm, 1), false)));
        self::assertSame([], $this->alerts->of(AuditFailed::class));
    }

    /**
     * WatchedConnection reads DBAL's savepoint names: if DBAL names them otherwise, this fails, not the log.
     */
    public function testDbalNamesItsSavepointsAfterTheirLevel(): void
    {
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $name = new \ReflectionMethod(Connection::class, '_getNestedTransactionSavePointName')->invoke($this->connection);
        $this->connection->rollBack();
        $this->connection->rollBack();

        self::assertSame('DOCTRINE_2', $name);
    }

    /**
     * A rollback to a savepoint that isn't DBAL's can't be mapped to the entries waiting: they're kept,
     * and the alert says so.
     */
    public function testARollbackToAnUnknownSavepointRaisesTheAlert(): void
    {
        $log = $this->log();

        $this->connection->transactional(function () use ($log): void {
            $log->record($this->realm, 'acme.thing.change', Outcome::Success, new Origin(OriginKind::Web));
            $this->connection->executeStatement('SAVEPOINT mine');
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT mine');
        });

        self::assertCount(1, $this->alerts->of(AuditFailed::class));
        self::assertSame(1, $this->store->head($this->realm)['sequence'] ?? null);
    }

    private function assertNothingCommitted(): void
    {
        self::assertSame([], $this->connection->fetchFirstColumn('SELECT v FROM change'), 'The change committed without its entry.');
        self::assertNull($this->store->head($this->realm));
    }

    private function log(): SecurityLog
    {
        $store = $this->store;
        $failing = &$this->failing;
        $failingStore = new class($store, $failing) implements SecurityLogStore {
            public function __construct(private SecurityLogStore $store, private bool &$failing)
            {
            }

            public function append(Realm $realm, \Closure $build): LogEntry
            {
                return $this->failing ? throw new \RuntimeException('The log is full.') : $this->store->append($realm, $build);
            }

            public function lock(Realm $realm): void
            {
                $this->store->lock($realm);
            }

            public function entries(Realm $realm, int $fromSequence): iterable
            {
                return $this->store->entries($realm, $fromSequence);
            }

            public function head(Realm $realm): ?array
            {
                return $this->store->head($realm);
            }
        };

        return new SecurityLog($failingStore, $this->transactions, $this->alerts);
    }

    /**
     * @return array{Connection, DbalTransactions}
     */
    private function connect(?TransactionMiddleware $middleware): array
    {
        $configuration = new Configuration();

        if (null !== $middleware) {
            $configuration->setMiddlewares([$middleware]);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        new Migrator(new Database($connection), CoreMigrations::all())->migrate();
        $connection->executeStatement('CREATE TABLE change (v INTEGER)');

        return [$connection, new DbalTransactions($connection, $middleware, $this->alerts)];
    }
}
