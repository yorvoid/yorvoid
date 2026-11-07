<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Contract\TransactionsContract;
use YorvoID\Tests\Support\DatabaseTestCase;

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
