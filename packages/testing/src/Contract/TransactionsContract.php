<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;

/**
 * What every Transactions keeps (PLT-025): all or nothing, after-commit callbacks only after the outermost
 * commit, and a failed nested unit undoing only itself. A principal store that takes part in the
 * transactions shows what was kept:
 *
 * ```php
 * final class MyTransactionsTest extends TestCase
 * {
 *     use TransactionsContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function transactions(string $implementation): Transactions
 *     {
 *         return $this->transactions ??= new MyTransactions(...); // new for each test, the same within it
 *     }
 *
 *     protected function principalStore(string $implementation): PrincipalStore
 *     {
 *         return $this->principals ??= new MyPrincipalStore(...); // taking part in those transactions
 *     }
 * }
 * ```
 *
 * @api
 */
trait TransactionsContract
{
    /**
     * The transactions to check: a new one for each test, the same within it.
     */
    abstract protected function transactions(string $implementation): Transactions;

    /**
     * An empty principal store that takes part in those transactions.
     */
    abstract protected function principalStore(string $implementation): PrincipalStore;

    #[DataProvider('implementations')]
    public function testCallbacksOutsideATransactionRunAtOnceAndOnlyOnce(string $implementation): void
    {
        $transactions = $this->transactions($implementation);
        $ran = new \ArrayObject();
        $transactions->afterCommit(static function () use ($ran): void {
            $ran->append('callback');
        });
        self::assertCount(1, $ran);

        $transactions->run(static fn (): null => null);
        self::assertCount(1, $ran, 'The callback ran again with the next transaction.');
    }

    #[DataProvider('implementations')]
    public function testAfterAFailureTheNextTransactionWorksNormally(string $implementation): void
    {
        $transactions = $this->transactions($implementation);
        /** @var \ArrayObject<int, string> $order */
        $order = new \ArrayObject();

        try {
            $transactions->run(static fn () => throw new \RuntimeException('failed'));
        } catch (\RuntimeException) {
        }

        try {
            $transactions->run(static fn () => $transactions->run(static fn () => throw new \RuntimeException('nested failed')));
        } catch (\RuntimeException) {
        }

        $transactions->run(static function () use ($transactions, $order): void {
            $transactions->afterCommit(static function () use ($order): void {
                $order->append('callback');
            });
            $order->append('work');
        });
        $transactions->afterCommit(static function () use ($order): void {
            $order->append('outside');
        });

        self::assertSame(['work', 'callback', 'outside'], $order->getArrayCopy());
    }

    #[DataProvider('implementations')]
    public function testCallbacksRunAfterTheCommitInOrder(string $implementation): void
    {
        $transactions = $this->transactions($implementation);
        $principals = $this->principalStore($implementation);
        $order = [];

        $value = random_int(1, \PHP_INT_MAX);
        $result = $transactions->run(static function () use ($transactions, &$order, $value): int {
            $transactions->afterCommit(static function () use (&$order): void {
                $order[] = 'first';
            });
            $transactions->run(static function () use ($transactions, &$order): void {
                $transactions->afterCommit(static function () use (&$order): void {
                    $order[] = 'nested';
                });
            });
            $transactions->afterCommit(static function () use (&$order): void {
                $order[] = 'last';
            });
            $order[] = 'work done';

            return $value;
        });

        self::assertSame($value, $result);
        self::assertSame(['work done', 'first', 'nested', 'last'], $order);
    }

    #[DataProvider('implementations')]
    public function testCallbacksCanAddCallbacks(string $implementation): void
    {
        $transactions = $this->transactions($implementation);
        $principals = $this->principalStore($implementation);
        $order = [];

        $transactions->run(static function () use ($transactions, &$order): void {
            $transactions->afterCommit(static function () use ($transactions, &$order): void {
                $order[] = 'first';
                $transactions->afterCommit(static function () use (&$order): void {
                    $order[] = 'added by first';
                });
            });
        });

        self::assertSame(['first', 'added by first'], $order);
    }

    #[DataProvider('implementations')]
    public function testAFailureUndoesEverythingAndDropsTheCallbacks(string $implementation): void
    {
        $transactions = $this->transactions($implementation);
        $principals = $this->principalStore($implementation);
        $principal = self::principal();
        $ran = new \ArrayObject();
        $thrown = null;

        try {
            $transactions->run(static function () use ($transactions, $principals, $principal, $ran): void {
                $principals->add($principal);
                $transactions->afterCommit(static function () use ($ran): void {
                    $ran->append('callback');
                });

                throw new \RuntimeException('failed');
            });
        } catch (\RuntimeException $exception) {
            $thrown = $exception->getMessage();
        }

        self::assertSame('failed', $thrown, 'The exception was swallowed.');
        self::assertCount(0, $ran);
        self::assertNull($principals->get($principal->realm, $principal->id));

        // The next transaction starts clean.
        $transactions->run(static fn () => $principals->add($principal));
        self::assertNotNull($principals->get($principal->realm, $principal->id));
    }

    #[DataProvider('implementations')]
    public function testAFailedNestedUnitUndoesOnlyItself(string $implementation): void
    {
        $transactions = $this->transactions($implementation);
        $principals = $this->principalStore($implementation);
        $kept = self::principal();
        $undone = self::principal();
        $order = [];

        $transactions->run(static function () use ($transactions, $principals, $kept, $undone, &$order): void {
            $principals->add($kept);
            $transactions->afterCommit(static function () use (&$order): void {
                $order[] = 'outer';
            });

            try {
                $transactions->run(static function () use ($transactions, $principals, $undone, &$order): void {
                    $principals->add($undone);
                    $transactions->afterCommit(static function () use (&$order): void {
                        $order[] = 'failed inner';
                    });

                    throw new \RuntimeException('inner failed');
                });
            } catch (\RuntimeException) {
            }

            $transactions->afterCommit(static function () use (&$order): void {
                $order[] = 'after the failure';
            });
        });

        self::assertSame(['outer', 'after the failure'], $order);
        self::assertNotNull($principals->get($kept->realm, $kept->id));
        self::assertNull($principals->get($undone->realm, $undone->id));
    }

    private static function principal(): Principal
    {
        $now = new \DateTimeImmutable('2026-10-27 18:40:00 UTC');

        return new Principal(new PrincipalId(new UuidV7()), Realm::default(), PrincipalKind::Human, PrincipalStatus::Active, $now, $now, $now);
    }
}
