<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PrincipalAuthority;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\StoreUnreachable;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Fault injection (XP-02): a store that can't be reached makes a decision Indeterminate with the reason
 * store_unreachable, told apart from contention, errors and missing data.
 */
final class StoreReachabilityTest extends DatabaseTestCase
{
    public function testADatabaseThatRefusesTheConnectionIsUnreachable(): void
    {
        // Nothing listens on port 1.
        $refused = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'yorvoid', 'dbname' => 'yorvoid', 'serverVersion' => '16']);

        $decision = self::decideWith($refused);

        self::assertSame(Verdict::Indeterminate, $decision->verdict);
        self::assertSame([IndeterminateReason::StoreUnreachable], $decision->indeterminate);
        self::assertStringStartsWith('The actor\'s authority couldn\'t be established: The database couldn\'t be reached: ', $decision->reason);
    }

    public function testADatabaseFileThatCantBeOpenedIsUnreachable(): void
    {
        $missing = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => '/nonexistent/yorvoid/yorvoid.sqlite']);

        self::assertSame([IndeterminateReason::StoreUnreachable], self::decideWith($missing)->indeterminate);
    }

    public function testALostConnectionIsUnreachable(): void
    {
        $lost = $this->connect('postgresql');
        $pid = $lost->fetchOne('SELECT pg_backend_pid()');
        $this->connect('postgresql')->executeQuery('SELECT pg_terminate_backend(?)', [$pid]);

        self::assertSame([IndeterminateReason::StoreUnreachable], self::decideWith($lost)->indeterminate);
    }

    public function testAStatementThatTimedOutIsUnreachable(): void
    {
        $slow = $this->connect('postgresql');
        $slow->executeStatement("SET statement_timeout = '10ms'");

        try {
            $slow->executeQuery('SELECT pg_sleep(1)');
            self::fail('The statement didn\'t time out.');
        } catch (\Throwable $exception) {
            self::assertNotNull(StoreUnreachable::of($exception), $exception->getMessage());
        }
    }

    #[DataProvider('platforms')]
    public function testWaitingForALockIsContentionNotUnreachable(string $platform): void
    {
        $holder = $this->connect($platform);
        $waiter = $this->connect($platform);
        $holder->executeStatement('CREATE TABLE IF NOT EXISTS reachability_lock (id INTEGER PRIMARY KEY)');
        'sqlite' === $platform ? $waiter->executeStatement('PRAGMA busy_timeout = 10') : $waiter->executeStatement("SET lock_timeout = '10ms'");
        $holder->beginTransaction();
        $holder->executeStatement('INSERT INTO reachability_lock (id) VALUES (1)');

        try {
            $waiter->beginTransaction();
            'sqlite' === $platform ? $waiter->executeStatement('INSERT INTO reachability_lock (id) VALUES (2)') : $waiter->executeStatement('LOCK TABLE reachability_lock IN EXCLUSIVE MODE');
            self::fail('The second connection didn\'t wait.');
        } catch (\Throwable $exception) {
            self::assertTrue($exception instanceof LockWaitTimeoutException || '55P03' === ($exception instanceof DriverException ? $exception->getSQLState() : null), $exception->getMessage());
            self::assertNull(StoreUnreachable::of($exception));
        } finally {
            if ($waiter->isTransactionActive()) {
                $waiter->rollBack();
            }

            $holder->rollBack();
            $holder->executeStatement('DROP TABLE IF EXISTS reachability_lock');
        }
    }

    public function testOtherFailuresAreNotUnreachable(): void
    {
        $connection = $this->connect('sqlite');

        try {
            $connection->executeQuery('SELECT * FROM no_such_table');
            self::fail('A missing table was read.');
        } catch (\Throwable $exception) {
            self::assertNull(StoreUnreachable::of($exception));
        }

        self::assertNull(StoreUnreachable::of(new \RuntimeException('failed')));
        $own = new StoreUnreachable('Redis didn\'t answer.');
        self::assertSame($own, StoreUnreachable::of($own), 'A store of one\'s own says so itself.');
    }

    private static function decideWith(Connection $connection): Decision
    {
        $evaluator = Evaluator::builtIn(new PrincipalAuthority(new DbalPrincipalStore(new Database($connection))));
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Self], null, null, IssueBadge::class, []);
        $principal = new PrincipalId(new UuidV7());

        return $evaluator->decide($operation, new IssueBadge(new Realm('acme'), $principal), new Member(new Realm('acme'), $principal), new Origin(OriginKind::Web));
    }
}
