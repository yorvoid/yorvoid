<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\Event\AuditFailed;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Contract\TransactionsContract;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\RecordingLogger;

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
     * After a commit the application started, DBAL still counts its transaction while the callbacks run:
     * each runs in a transaction of its own, so one that opens a transaction works, and what callbacks
     * add runs after them (ARC-0059, D-43).
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function applicationCommits(): iterable
    {
        foreach (self::platforms() as $name => [$platform]) {
            yield $name.', transactional()' => [$platform, true];
            yield $name.', beginTransaction()' => [$platform, false];
        }
    }

    #[DataProvider('applicationCommits')]
    public function testCallbacksOpenTransactionsAfterTheApplicationsCommit(string $platform, bool $transactional): void
    {
        $stack = $this->stack($platform);
        $database = $stack->database;
        \assert($database instanceof Database);
        $connection = $database->connection;
        [$first, $second, $third] = [self::principal(), self::principal(), self::principal()];
        /** @var \ArrayObject<int, string> $order */
        $order = new \ArrayObject();
        $work = static function () use ($stack, $first, $second, $third, $order): void {
            $stack->transactions->run(static function () use ($stack, $first, $second, $third, $order): void {
                $stack->transactions->afterCommit(static function () use ($stack, $first, $third, $order): void {
                    $stack->transactions->run(static fn () => $stack->principals->add($first));
                    $stack->transactions->afterCommit(static function () use ($order): void {
                        $order->append('added by the callback');
                    });
                    $stack->transactions->afterEnd(static function () use ($stack, $third, $order): void {
                        $stack->transactions->run(static fn () => $stack->principals->add($third));
                        $order->append('end, added by the callback');
                    });
                    $order->append('callback');
                });
                $stack->transactions->afterEnd(static function () use ($stack, $second, $order): void {
                    $stack->transactions->run(static fn () => $stack->principals->add($second));
                    $order->append('end');
                });
            });
        };

        if ($transactional) {
            $connection->transactional($work);
        } else {
            $connection->beginTransaction();
            $work();
            $connection->commit();
        }

        self::assertSame(['callback', 'added by the callback', 'end', 'end, added by the callback'], $order->getArrayCopy());

        foreach ([$first, $second, $third] as $principal) {
            self::assertNotNull($stack->principals->get($principal->realm, $principal->id));
        }

        self::assertSame(0, $connection->getTransactionNestingLevel(), 'A transaction was left open.');
    }

    /**
     * A callback that fails after the application's commit: the commit stands and returns, the failed
     * callback's own writes are undone, the next one runs, and the failure is reported, not thrown
     * (D-43): logged after a commit, the audit alert for the end.
     */
    #[DataProvider('platforms')]
    public function testAFailingCallbackAfterTheApplicationsCommitIsReported(string $platform): void
    {
        $logger = new RecordingLogger();
        $alerts = new RecordingDispatcher();
        $stack = $this->stack($platform, events: $alerts, logger: $logger);
        $database = $stack->database;
        \assert($database instanceof Database);
        [$committed, $undone, $next, $undoneAtTheEnd] = [self::principal(), self::principal(), self::principal(), self::principal()];

        $database->connection->transactional(static function () use ($stack, $committed, $undone, $next, $undoneAtTheEnd): void {
            $stack->transactions->run(static fn () => $stack->principals->add($committed));
            $stack->transactions->afterCommit(static function () use ($stack, $undone): never {
                $stack->transactions->run(static fn () => $stack->principals->add($undone));

                throw new \RuntimeException('The listener failed.');
            });
            $stack->transactions->afterCommit(static fn () => $stack->transactions->run(static fn () => $stack->principals->add($next)));
            $stack->transactions->afterEnd(static function () use ($stack, $undoneAtTheEnd): never {
                $stack->principals->add($undoneAtTheEnd);

                throw new \RuntimeException('The disable failed.');
            });
        });

        // It returned: nothing came out of the application's commit.
        self::assertNotNull($stack->principals->get($committed->realm, $committed->id));
        self::assertNull($stack->principals->get($undone->realm, $undone->id), 'The failed callback\'s write was kept.');
        self::assertNotNull($stack->principals->get($next->realm, $next->id), 'The next callback didn\'t run.');
        self::assertNull($stack->principals->get($undoneAtTheEnd->realm, $undoneAtTheEnd->id), 'The failed callback\'s write was kept.');
        $errors = $logger->of('error');
        self::assertCount(1, $errors);
        self::assertSame(['What YorvoID ran after a commit failed: {message}', 'The listener failed.'], [$errors[0][1], $errors[0][2]['message']]);
        $failed = $alerts->of(AuditFailed::class);
        self::assertCount(1, $failed);
        self::assertSame([AuditFailed::AFTER_END, null, 1, false, 'The disable failed.'], [$failed[0]->log, $failed[0]->realm, $failed[0]->records, $failed[0]->refused, $failed[0]->error->getMessage()]);
        self::assertSame(0, $database->connection->getTransactionNestingLevel());
    }

    /**
     * A callback that leaves a transaction of DBAL's open after the application's commit: DBAL's
     * savepoints are rolled back first, then the callback's own transaction, and it's reported. The
     * connection stays usable, as a long-running worker needs it (D-43, Q3).
     */
    #[DataProvider('platforms')]
    public function testACallbackLeavingATransactionOpenAfterTheApplicationsCommitLeavesTheConnectionUsable(string $platform): void
    {
        $logger = new RecordingLogger();
        $stack = $this->stack($platform, logger: $logger);
        $database = $stack->database;
        \assert($database instanceof Database);
        $connection = $database->connection;
        [$undone, $deeper, $later, $laterStill] = [self::principal(), self::principal(), self::principal(), self::principal()];

        $connection->transactional(static function () use ($stack, $connection, $undone, $deeper): void {
            $stack->transactions->run(static fn (): null => null);
            $stack->transactions->afterCommit(static function () use ($stack, $connection, $undone, $deeper): void {
                $connection->beginTransaction();
                $stack->principals->add($undone);
                $connection->beginTransaction();
                $stack->principals->add($deeper);
            });
        });

        self::assertSame(0, $connection->getTransactionNestingLevel(), 'DBAL still counts a transaction.');
        self::assertNull($stack->principals->get($undone->realm, $undone->id), 'What it left open was committed.');
        self::assertNull($stack->principals->get($deeper->realm, $deeper->id), 'What it left open was committed.');
        self::assertSame(['A callback after the commit left a transaction open.'], array_map(static fn (array $entry): mixed => $entry[2]['message'], $logger->logs));

        // The next job on the same connection: YorvoID's unit, then the application's own transaction.
        $stack->transactions->run(static fn () => $stack->principals->add($later));
        $connection->transactional(static fn () => $stack->principals->add($laterStill));

        self::assertNotNull($stack->principals->get($later->realm, $later->id));
        self::assertNotNull($stack->principals->get($laterStill->realm, $laterStill->id));
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    /**
     * With auto-commit off, DBAL opens the next transaction itself after each commit: the callbacks'
     * transactions have ended by then, and what they wrote isn't undone with the next one.
     */
    #[DataProvider('platforms')]
    public function testCallbacksAfterACommitWithAutoCommitOffAreKept(string $platform): void
    {
        $stack = $this->stack($platform);
        $database = $stack->database;
        \assert($database instanceof Database);
        $connection = $database->connection;
        $written = self::principal();
        $connection->setAutoCommit(false);

        try {
            $stack->transactions->afterCommit(static fn () => $stack->transactions->run(static fn () => $stack->principals->add($written)));
            $connection->commit();
            self::assertSame(1, $connection->getTransactionNestingLevel(), 'DBAL didn\'t open the next one.');
            $connection->rollBack();
        } finally {
            $connection->setAutoCommit(true);
        }

        self::assertNotNull($stack->principals->get($written->realm, $written->id));
    }

    /**
     * YorvoID's own unit of work: a failing after-commit callback is reported the same way, not thrown
     * from run(), since the work is committed (D-43, Q2).
     */
    #[DataProvider('implementations')]
    public function testAFailingCallbackAfterYorvoIDsOwnCommitIsReported(string $implementation): void
    {
        $logger = new RecordingLogger();
        $database = $this->stack($implementation)->database;
        $middleware = new TransactionMiddleware();
        $connection = null === $database ? null : $this->connect($implementation, $middleware);
        $transactions = null === $connection ? new InMemoryTransactions(logger: $logger) : new DbalTransactions($connection, $middleware, logger: $logger);
        $ran = [];

        $result = $transactions->run(static function () use ($transactions, &$ran): string {
            $transactions->afterCommit(static fn (): never => throw new \RuntimeException('The listener failed.'));
            $transactions->afterCommit(static function () use (&$ran): void {
                $ran[] = 'next';
            });

            return 'done';
        });
        // And outside a transaction, at once.
        $transactions->afterCommit(static fn (): never => throw new \RuntimeException('At once.'));

        self::assertSame(['done', ['next']], [$result, $ran]);
        self::assertSame(['The listener failed.', 'At once.'], array_map(static fn (array $entry): mixed => $entry[2]['message'], $logger->of('error')));
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
