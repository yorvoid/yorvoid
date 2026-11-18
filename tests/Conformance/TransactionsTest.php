<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\Event\AuditFailed;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Contract\TransactionsContract;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;

/**
 * Every Transactions implementation keeps the same promises (PLT-025): all or nothing, after-commit
 * callbacks only after the outermost commit, and a failed nested unit undoing only itself.
 */
final class TransactionsTest extends DatabaseTestCase
{
    use TransactionsContract;

    #[DataProvider('implementations')]
    public function testCallbacksCanWriteInTransactionsOfTheirOwn(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $written = self::principal();
        $order = [];

        $stack->transactions->run(static function () use ($stack, $written, &$order): void {
            $stack->transactions->afterCommit(static function () use ($stack, $written, &$order): void {
                // A listener of an "-ed" event that writes, e.g. a notification issuing a token.
                $stack->transactions->run(static function () use ($stack, $written, &$order): void {
                    $stack->principals->add($written);
                    $stack->transactions->afterCommit(static function () use (&$order): void {
                        $order[] = 'nested callback';
                    });
                });
                $order[] = 'callback';
            });
            // A nested unit of work before the commit, as most operations have.
            $stack->transactions->run(static fn (): null => null);
        });

        self::assertSame(['nested callback', 'callback'], $order);
        self::assertNotNull($stack->principals->get($written->realm, $written->id));
        self::assertFalse(null !== $stack->database && $stack->database->connection->isTransactionActive(), 'A transaction was left open.');
    }

    /**
     * A callback for the end that fails raises the implementation's audit alert, once for each: after the
     * caller's transaction, and at once outside one (D-42).
     */
    #[DataProvider('implementations')]
    public function testAFailingCallbackForTheEndRaisesTheAuditAlert(string $implementation): void
    {
        $alerts = new RecordingDispatcher();
        $database = $this->stack($implementation)->database;
        $transactions = null === $database ? new InMemoryTransactions($alerts) : new DbalTransactions($database->connection, events: $alerts);
        $failing = static function (): never {
            throw new \RuntimeException('the callback failed');
        };

        try {
            $transactions->run(static function () use ($transactions, $failing): never {
                $transactions->afterEnd($failing);

                throw new \RuntimeException('the caller failed');
            });
        } catch (\RuntimeException) {
        }

        $transactions->afterEnd($failing);
        $raised = $alerts->of(AuditFailed::class);

        self::assertCount(2, $raised);

        foreach ($raised as $alert) {
            self::assertSame([AuditFailed::AFTER_END, null, 1, false, 'the callback failed'], [$alert->log, $alert->realm, $alert->records, $alert->refused, $alert->error->getMessage()]);
        }
    }

    #[DataProvider('platforms')]
    public function testCallbacksWaitForTheApplicationsOwnTransaction(string $platform): void
    {
        $stack = $this->stack($platform);
        $database = $stack->database;
        \assert($database instanceof Database);
        $ran = new \ArrayObject();
        $queue = static function () use ($stack, $ran): void {
            $stack->transactions->run(static function () use ($stack, $ran): void {
                $stack->transactions->afterCommit(static function () use ($ran): void {
                    $ran->append('callback');
                });
            });
        };

        $database->connection->beginTransaction();
        $queue();
        self::assertCount(0, $ran, 'The callback ran before the application committed.');
        $database->connection->commit();
        self::assertCount(1, $ran);

        $database->connection->beginTransaction();
        $queue();
        $database->connection->rollBack();
        self::assertCount(1, $ran, 'The callback ran although the application rolled back.');

        $database->connection->beginTransaction();
        $database->connection->commit();
        self::assertCount(1, $ran, 'A rolled-back callback ran with a later commit.');
    }

    #[DataProvider('platforms')]
    public function testAfterYorvoIDsOwnUnitsTheApplicationsCommitStillRunsTheCallbacks(string $platform): void
    {
        $stack = $this->stack($platform);
        $database = $stack->database;
        \assert($database instanceof Database);
        /** @var \ArrayObject<int, string> $ran */
        $ran = new \ArrayObject();

        // YorvoID's own units first, one that commits and one that fails: neither leaves commits waiting.
        $stack->transactions->run(static fn (): null => null);

        try {
            $stack->transactions->run(static fn (): never => throw new \RuntimeException('Failed.'));
        } catch (\RuntimeException) {
        }

        // Then the application's own transaction, around a unit that queues a notification's event.
        $database->connection->beginTransaction();
        $stack->transactions->run(static function () use ($stack, $ran): void {
            $stack->transactions->afterCommit(static function () use ($ran): void {
                $ran->append('new device');
            });
        });
        $database->connection->commit();

        self::assertSame(['new device'], $ran->getArrayCopy(), 'After the application\'s commit, security notifications stopped.');
    }

    /**
     * The application's own transaction, committed or rolled back: what waits for the end runs after it,
     * on the same connection, and what it writes stays (D-42).
     */
    #[DataProvider('platforms')]
    public function testWhatWaitsForTheEndRunsAfterTheApplicationsOwnTransaction(string $platform): void
    {
        $stack = $this->stack($platform);
        $database = $stack->database;
        \assert($database instanceof Database);
        $written = self::principal();

        $database->connection->beginTransaction();
        $stack->transactions->afterEnd(static function () use ($stack, $written): void {
            $stack->transactions->run(static fn () => $stack->principals->add($written));
        });
        self::assertNull($stack->principals->get($written->realm, $written->id), 'It ran before the application\'s transaction ended.');
        $database->connection->rollBack();

        self::assertNotNull($stack->principals->get($written->realm, $written->id), 'The application\'s rollback undid it, or it never ran.');
        self::assertFalse($database->connection->isTransactionActive(), 'A transaction was left open.');
    }

    /**
     * Without YorvoID's middleware the end can't be seen: it runs at once, inside the transaction, and
     * the rollback undoes it (documented: ARC-0058 then still applies).
     */
    #[DataProvider('platforms')]
    public function testWithoutTheMiddlewareWhatWaitsForTheEndRunsAtOnceAndIsUndone(string $platform): void
    {
        $stack = $this->stack($platform);
        $database = $stack->database;
        \assert($database instanceof Database);
        $transactions = new DbalTransactions($database->connection);
        $written = self::principal();

        try {
            $transactions->run(static function () use ($transactions, $stack, $written): void {
                $transactions->afterEnd(static fn () => $stack->principals->add($written));
                self::assertNotNull($stack->principals->get($written->realm, $written->id), 'It didn\'t run at once.');

                throw new \RuntimeException('failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertNull($stack->principals->get($written->realm, $written->id));
    }

    #[DataProvider('platforms')]
    public function testRefusesToQueueCallbacksWithoutTheMiddleware(string $platform): void
    {
        $connection = $this->connect($platform);
        $transactions = new DbalTransactions($connection);

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('can\'t tell when this connection\'s transactions commit');

        $transactions->run(static fn () => $transactions->afterCommit(static function (): void {}));
    }

    protected function transactions(string $implementation): Transactions
    {
        return $this->stackOf($implementation)->transactions;
    }

    protected function principalStore(string $implementation): PrincipalStore
    {
        return $this->stackOf($implementation)->principals;
    }
}
