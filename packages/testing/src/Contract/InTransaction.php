<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Assert;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Core\Storage\Transactions;

/**
 * How the store contracts check that a store takes part in the transactions it's given (SS-09): a write
 * goes with a transaction that fails, is seen inside its own, and one that changes nothing or is refused
 * leaves the transaction usable.
 *
 * @internal For yorvoid/testing's contracts
 */
final class InTransaction
{
    private const string NOT_TAKING_PART = 'A write was kept after its transaction failed: does the store take part in the transactions it\'s given (the same connection, or registered with InMemoryTransactions)?';

    /**
     * Writes, then fails the transaction: afterwards, $gone must say the write isn't there.
     *
     * @param \Closure(): mixed $write
     * @param \Closure(): bool $gone
     */
    public static function assertGoneAfterARollback(Transactions $transactions, \Closure $write, \Closure $gone, string $message = self::NOT_TAKING_PART): void
    {
        $failure = new \RuntimeException('The transaction fails.');

        try {
            $transactions->run(static function () use ($write, $failure): never {
                $write();

                throw $failure;
            });
        } catch (\RuntimeException $exception) {
            Assert::assertSame($failure, $exception, 'The write itself failed.');
        }

        Assert::assertTrue($gone(), $message);
    }

    /**
     * Writes, then reads inside the same transaction: $seen must say the write is there.
     *
     * @param \Closure(): mixed $write
     * @param \Closure(): bool $seen
     */
    public static function assertSeenInsideItsTransaction(Transactions $transactions, \Closure $write, \Closure $seen): void
    {
        Assert::assertTrue($transactions->run(static function () use ($write, $seen): bool {
            $write();

            return $seen();
        }), 'A write wasn\'t seen inside its own transaction: the guard re-reads there.');
    }

    /**
     * Inside one transaction, each write that changes nothing or is refused (a refusal is a
     * StorageException, caught here), then another write: after the commit, $kept must say the other
     * write is there. On PostgreSQL, a statement that fails aborts the whole transaction, so a store must
     * not let one fail on the way to "nothing to do".
     *
     * @param list<\Closure(): mixed> $nothing
     * @param \Closure(): mixed       $write
     * @param \Closure(): bool        $kept
     */
    public static function assertTheTransactionGoesOn(Transactions $transactions, array $nothing, \Closure $write, \Closure $kept): void
    {
        $transactions->run(static function () use ($nothing, $write): void {
            foreach ($nothing as $step) {
                try {
                    $step();
                } catch (StorageException) {
                    // Refused, as it may be.
                }
            }

            $write();
        });

        Assert::assertTrue($kept(), 'A write after one that changed nothing, or was refused, wasn\'t kept: the store broke the transaction on the way.');
    }
}
