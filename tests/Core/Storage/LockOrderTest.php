<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Migrations\CoreMigrations;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\LockOrder;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Core\Storage\StorageLocation;

/**
 * Each transaction takes YorvoID's locks in the one order that can't deadlock (D-52): the realm's lock
 * first or not at all, version rows only under it, nothing after the security log's head.
 */
final class LockOrderTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function outOfOrder(): iterable
    {
        yield 'a version row without the realm\'s lock' => [[], 'UPDATE "yorvoid_policy_version" SET version = version + 1 WHERE realm = ?'];
        yield 'a relations version row without it' => [['UPDATE "yorvoid_principal" SET lock_count = lock_count + 1 WHERE id = ?'], 'INSERT INTO "s"."yorvoid_relation_version" (realm, version) VALUES (?, 1)'];
        yield 'the realm\'s lock after a principal\'s' => [['UPDATE "yorvoid_principal" SET lock_count = lock_count + 1 WHERE id = ?'], 'INSERT INTO "yorvoid_realm_lock" AS held (realm, n) VALUES (?, 1) ON CONFLICT (realm) DO UPDATE SET n = held.n + 1'];
        yield 'the realm\'s lock after a row read for update' => [['SELECT id FROM "yorvoid_passkey_ceremony" WHERE id = ? FOR UPDATE'], 'INSERT INTO "yorvoid_realm_lock" (realm, n) VALUES (?, 1) ON CONFLICT (realm) DO UPDATE SET n = n + 1'];
        yield 'a row after the head' => [['SELECT sequence, hash FROM "yorvoid_security_log_head" WHERE realm = ? FOR UPDATE'], 'DELETE FROM "yorvoid_passkey_ceremony" WHERE id = ?'];
        yield 'the realm\'s lock after the head' => [['UPDATE "yorvoid_security_log_head" SET sequence = ? WHERE realm = ?'], 'INSERT INTO "yorvoid_realm_lock" (realm, n) VALUES (?, 1) ON CONFLICT (realm) DO UPDATE SET n = n + 1'];
        yield 'a lock on a table it can\'t tell' => [[], 'UPDATE (SELECT 1) SET x = 1'];
        yield 'a version row written in a query' => [[], 'WITH b AS (UPDATE "yorvoid_policy_version" SET version = version + 1 RETURNING version) SELECT version FROM b'];
        yield 'a version row merged' => [[], 'MERGE INTO "yorvoid_policy_version" v USING (SELECT 1) s ON true WHEN MATCHED THEN UPDATE SET version = v.version + 1'];
        yield 'a version row after a comment' => [[], '/* bump */ UPDATE "yorvoid_policy_version" SET version = version + 1'];
        yield 'a version row read for update, in parentheses' => [[], '(SELECT version FROM "yorvoid_policy_version" WHERE realm = ? FOR UPDATE)'];
        yield 'a statement it doesn\'t know' => [[], 'CALL bump_versions()'];
    }

    /**
     * @param list<string> $before
     */
    #[DataProvider('outOfOrder')]
    public function testALockOutOfOrderThrows(array $before, string $sql): void
    {
        $order = $this->order();

        foreach ($before as $earlier) {
            $order->saw($earlier);
        }

        $this->expectException(\LogicException::class);
        $order->saw($sql);
    }

    public function testTheOrderPasses(): void
    {
        $order = $this->order();

        foreach ([
            'INSERT INTO "yorvoid_realm_lock" AS held (realm, n) VALUES (?, 1) ON CONFLICT (realm) DO UPDATE SET n = held.n + 1',
            // Under the realm's lock, rows and version rows in either order; the realm's lock again.
            'UPDATE "yorvoid_principal" SET lock_count = lock_count + 1 WHERE id = ?',
            'UPDATE "yorvoid_policy_version" SET version = version + 1 WHERE realm = ?',
            'DELETE FROM "yorvoid_policy_role_assignment" WHERE principal_id = ?',
            'INSERT INTO "yorvoid_realm_lock" AS held (realm, n) VALUES (?, 1) ON CONFLICT (realm) DO UPDATE SET n = held.n + 1',
            // Reads, savepoints, another application's tables and YorvoID's bookkeeping lock nothing it orders.
            'SELECT * FROM "yorvoid_principal" WHERE id = ?',
            'SAVEPOINT DOCTRINE_2',
            'UPDATE app_orders SET paid = 1',
            'INSERT INTO "yorvoid_throttle_key" (key_hash) VALUES (?)',
            // The head last, with its entries.
            'SELECT sequence, hash FROM "yorvoid_security_log_head" WHERE realm = ? FOR UPDATE',
            'INSERT INTO "yorvoid_security_log" (realm, sequence) VALUES (?, ?)',
            'UPDATE "yorvoid_security_log_head" SET sequence = ? WHERE realm = ?',
        ] as $sql) {
            $order->saw($sql);
        }

        // A transaction of its own, e.g. a callback's after the commit, starts over.
        $order->begin();
        $order->saw('UPDATE "yorvoid_principal" SET lock_count = lock_count + 1 WHERE id = ?');
        $order->end();
        $order->end();
        $this->addToAssertionCount(1);
    }

    /**
     * Another application's tables, and YorvoID's own bookkeeping, aren't locks it orders: the realm's
     * lock may come after them.
     */
    public function testOtherTablesComeBeforeTheRealmsLock(): void
    {
        $order = $this->order();
        $order->saw('UPDATE app_orders SET paid = 1');
        $order->saw('INSERT INTO "yorvoid_throttle_key" (key_hash) VALUES (?)');
        $order->saw('INSERT INTO "yorvoid_realm_lock" (realm, n) VALUES (?, 1) ON CONFLICT (realm) DO UPDATE SET n = n + 1');
        $this->addToAssertionCount(1);
    }

    public function testOutsideATransactionNothingIsOrdered(): void
    {
        new LockOrder(new StorageLocation())->saw('UPDATE "yorvoid_policy_version" SET version = version + 1');
        $this->addToAssertionCount(1);
    }

    /**
     * Through the connection, as the suite runs with YORVOID_LOCK_ORDER=check: a planted version bump
     * before the realm's lock is refused before it's sent.
     */
    public function testTheSuiteChecksEveryTransaction(): void
    {
        self::assertSame('check', getenv(LockOrder::VARIABLE), 'The suite runs without the check.');
        $middleware = new TransactionMiddleware();
        $configuration = new Configuration();
        $configuration->setMiddlewares([$middleware]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        new Migrator(new Database($connection), CoreMigrations::all())->migrate();
        $connection->executeStatement('CREATE TABLE yorvoid_policy_version (realm TEXT PRIMARY KEY, version INTEGER)');
        $transactions = new DbalTransactions($connection, $middleware);

        $transactions->run(static function () use ($transactions, $connection): void {
            $transactions->lockRealm(new Realm('acme'));
            $connection->executeStatement('INSERT INTO yorvoid_policy_version (realm, version) VALUES (?, 1)', ['acme']);
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A version row written without the realm\'s lock');
        $transactions->run(static fn () => $connection->executeStatement('UPDATE yorvoid_policy_version SET version = version + 1 WHERE realm = ?', ['acme']));
    }

    private function order(): LockOrder
    {
        $order = new LockOrder(new StorageLocation());
        $order->begin();

        return $order;
    }
}
