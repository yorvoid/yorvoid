<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Contract\SecurityLogStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every SecurityLogStore keeps the same promises (PLT-025).
 */
final class SecurityLogStoreTest extends DatabaseTestCase
{
    use SecurityLogStoreContract;

    #[DataProvider('platforms')]
    public function testReadsInBatches(string $platform): void
    {
        $stack = $this->stack($platform);
        $realm = Realm::default();
        $seen = [];

        for ($i = 0; $i < 5; ++$i) {
            $this->appendTo($stack->log, $stack->transactions, $realm, $seen);
        }

        // Read in batches of two: across their edges, and when the last one is full.
        $database = $stack->database;
        \assert($database instanceof Database);
        $batched = new DbalSecurityLogStore($database, 2);
        self::assertSame([1, 2, 3, 4, 5], array_map(static fn (LogEntry $entry): int => $entry->sequence, iterator_to_array($batched->entries($realm, 1), false)));
        self::assertSame([2, 3, 4, 5], array_map(static fn (LogEntry $entry): int => $entry->sequence, iterator_to_array($batched->entries($realm, 2), false)));
        self::assertEquals(iterator_to_array($stack->log->entries($realm, 1), false), iterator_to_array($batched->entries($realm, \PHP_INT_MIN), false));
    }

    #[DataProvider('platforms')]
    public function testALockHoldsOffAnotherUntilItsTransactionEnds(string $platform): void
    {
        $stack = $this->stack($platform);
        \assert($stack->database instanceof Database);
        $realm = new Realm('acme');
        $seen = [];
        $this->appendTo($stack->log, $stack->transactions, $realm, $seen);
        $holder = $stack->database->connection;
        // Another process's connection, which gives up waiting quickly.
        $other = $this->database($platform);
        $otherLog = new DbalSecurityLogStore($other);

        if ('sqlite' === $platform) {
            $other->connection->executeStatement('PRAGMA busy_timeout = 100');
        }

        $holder->beginTransaction();
        $stack->log->lock($realm);
        $other->connection->beginTransaction();

        if ('postgresql' === $platform) {
            $other->connection->executeStatement("SET LOCAL lock_timeout = '200ms'");
        }

        try {
            $otherLog->lock($realm);
            self::fail('The second lock didn\'t wait for the first.');
        } catch (DriverException $exception) {
            // PostgreSQL's lock_timeout (55P03), or SQLite's busy database.
            self::assertTrue($exception instanceof LockWaitTimeoutException || '55P03' === $exception->getSQLState(), $exception->getMessage());
        } finally {
            $other->connection->rollBack();
            $holder->rollBack();
        }

        $other->connection->beginTransaction();
        $otherLog->lock($realm);
        $other->connection->rollBack();
        self::assertSame(1, $stack->log->head($realm)['sequence'] ?? null);
    }

    #[DataProvider('platforms')]
    public function testRefusesToLockOutsideATransaction(string $platform): void
    {
        $stack = $this->stack($platform);

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('outside a transaction');

        $stack->log->lock(Realm::default());
    }

    #[DataProvider('platforms')]
    public function testRefusesToAppendOutsideATransaction(string $platform): void
    {
        $stack = $this->stack($platform);

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('outside a transaction');

        $stack->log->append(Realm::default(), static fn (): LogEntry => throw new \LogicException('Not reached.'));
    }

    protected function securityLogStore(string $implementation): SecurityLogStore
    {
        return $this->stackOf($implementation)->log;
    }

    protected function transactions(string $implementation): Transactions
    {
        return $this->stackOf($implementation)->transactions;
    }
}
