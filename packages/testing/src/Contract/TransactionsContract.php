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
use YorvoID\Core\Storage\RetryableConflict;
use YorvoID\Core\Storage\Transactions;

/**
 * What every Transactions keeps (PLT-025): all or nothing, after-commit callbacks only after the outermost
 * commit, a failed nested unit undoing only itself, and retrying() running work that conflicted again. A principal store that takes part in the
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

    /**
     * What waits for the end runs after a commit and after a rollback, outside the transaction, so it
     * outlasts the caller's outcome; outside a transaction, at once (D-42).
     */
    #[DataProvider('implementations')]
    public function testWhatWaitsForTheEndRunsCommittedOrRolledBack(string $implementation): void
    {
        $transactions = $this->transactions($implementation);
        $principals = $this->principalStore($implementation);
        $order = [];

        $transactions->afterEnd(static function () use (&$order): void {
            $order[] = 'outside';
        });
        $transactions->run(static function () use ($transactions, &$order): void {
            $transactions->afterEnd(static function () use (&$order): void {
                $order[] = 'committed';
            });
            $order[] = 'in the transaction';
        });
        $kept = self::principal();

        try {
            $transactions->run(static function () use ($transactions, $principals, $kept, &$order): void {
                $transactions->afterEnd(static function () use ($transactions, $principals, $kept, &$order): void {
                    $order[] = 'rolled back';
                    // It runs in no transaction: what it writes in one of its own stays.
                    $transactions->run(static fn () => $principals->add($kept));
                });

                try {
                    $transactions->run(static function () use ($transactions, &$order): void {
                        $transactions->afterEnd(static function () use (&$order): void {
                            $order[] = 'a failed nested unit\'s';
                        });

                        throw new \RuntimeException('inner failed');
                    });
                } catch (\RuntimeException) {
                }

                throw new \RuntimeException('failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(['outside', 'in the transaction', 'committed', 'rolled back', 'a failed nested unit\'s'], $order);
        self::assertNotNull($principals->get($kept->realm, $kept->id), 'What it wrote was undone with the caller.');
    }

    /**
     * A callback for the end that fails doesn't stop the others, and doesn't replace the caller's own
     * outcome: the implementation raises its audit alert instead (D-42).
     */
    #[DataProvider('implementations')]
    public function testAFailingCallbackForTheEndGoesNoFurther(string $implementation): void
    {
        $transactions = $this->transactions($implementation);
        $ran = [];
        $thrown = null;

        try {
            $transactions->run(static function () use ($transactions, &$ran): void {
                $transactions->afterEnd(static function (): void {
                    throw new \RuntimeException('the callback failed');
                });
                $transactions->afterEnd(static function () use (&$ran): void {
                    $ran[] = 'the next one';
                });

                throw new \RuntimeException('the caller failed');
            });
        } catch (\RuntimeException $exception) {
            $thrown = $exception->getMessage();
        }

        self::assertSame('the caller failed', $thrown);
        self::assertSame(['the next one'], $ran);
        $transactions->afterEnd(static function (): void {
            throw new \RuntimeException('failed at once');
        });
    }

    /**
     * A conflict runs the work again from the start: the attempt's writes and callbacks go with it.
     */
    #[DataProvider('implementations')]
    public function testRetryingRunsWorkThatConflictedAgain(string $implementation): void
    {
        $transactions = $this->transactions($implementation);
        $principals = $this->principalStore($implementation);
        $first = self::principal();
        $second = self::principal();
        /** @var \ArrayObject<int, string> $ran */
        $ran = new \ArrayObject();

        $result = $transactions->retrying(static fn (): string => $transactions->run(static function () use ($transactions, $principals, $first, $second, $ran): string {
            $again = \count($ran) > 0;
            $ran->append($again ? 'second attempt' : 'first attempt');
            $principals->add($again ? $second : $first);
            $transactions->afterCommit(static function () use ($ran, $again): void {
                $ran->append($again ? 'committed second' : 'committed first');
            });
            $transactions->afterEnd(static function () use ($ran, $again): void {
                $ran->append($again ? 'ended second' : 'ended first');
            });

            return $again ? 'done' : throw new RetryableConflict(new \RuntimeException('conflict'));
        }));

        self::assertSame('done', $result); // @phpstan-ignore staticMethod.alreadyNarrowedType (it's what retrying() gives back)
        self::assertEqualsCanonicalizing(['first attempt', 'second attempt', 'committed second', 'ended second'], $ran->getArrayCopy(), 'In whichever order the implementation runs its callbacks.');
        self::assertNull($principals->get($first->realm, $first->id), 'The first attempt was kept.');
        self::assertNotNull($principals->get($second->realm, $second->id));
    }

    /**
     * The third conflict is thrown. Nothing runs the last attempt again: what it left waiting for the end
     * runs, and the earlier attempts' never.
     */
    #[DataProvider('implementations')]
    public function testRetryingThrowsTheThirdConflict(string $implementation): void
    {
        $transactions = $this->transactions($implementation);
        /** @var \ArrayObject<int, string> $ran */
        $ran = new \ArrayObject();
        $thrown = null;

        try {
            $transactions->retrying(static fn (): mixed => $transactions->run(static function () use ($transactions, $ran): never {
                $ran->append('attempt');
                $attempt = \count($ran);
                $transactions->afterEnd(static function () use ($ran, $attempt): void {
                    $ran->append('ended '.$attempt);
                });

                throw new RetryableConflict(new \RuntimeException('conflict'));
            }));
        } catch (RetryableConflict $conflict) {
            $thrown = $conflict;
        }

        self::assertInstanceOf(RetryableConflict::class, $thrown, 'The third conflict wasn\'t thrown.'); // @phpstan-ignore staticMethod.alreadyNarrowedType (that it was thrown)
        self::assertSame(['attempt', 'attempt', 'attempt', 'ended 3'], $ran->getArrayCopy());
    }

    /**
     * Inside a transaction already open the work isn't the unit's own to run again: the conflict is
     * thrown at once.
     */
    #[DataProvider('implementations')]
    public function testRetryingInsideATransactionThrowsAtOnce(string $implementation): void
    {
        $transactions = $this->transactions($implementation);
        $attempts = new \ArrayObject();
        $thrown = null;

        try {
            $transactions->run(static fn (): mixed => $transactions->retrying(static function () use ($attempts): never {
                $attempts->append('attempt');

                throw new RetryableConflict(new \RuntimeException('conflict'));
            }));
        } catch (RetryableConflict $conflict) {
            $thrown = $conflict;
        }

        self::assertInstanceOf(RetryableConflict::class, $thrown, 'The conflict wasn\'t thrown.'); // @phpstan-ignore staticMethod.alreadyNarrowedType (that it was thrown)
        self::assertCount(1, $attempts);
    }

    private static function principal(): Principal
    {
        $now = new \DateTimeImmutable('2026-10-27 18:40:00 UTC');

        return new Principal(new PrincipalId(new UuidV7()), Realm::default(), PrincipalKind::Human, PrincipalStatus::Active, $now, $now, $now);
    }
}
