<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every Transactions implementation keeps the same promises (PLT-025): all or nothing, after-commit
 * callbacks only after the outermost commit, and a failed nested unit undoing only itself.
 */
final class TransactionsTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testCallbacksOutsideATransactionRunAtOnceAndOnlyOnce(string $implementation): void
    {
        $transactions = $this->stack($implementation)->transactions;
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
        $transactions = $this->stack($implementation)->transactions;
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
        $stack = $this->stack($implementation);
        $order = [];

        $value = random_int(1, \PHP_INT_MAX);
        $result = $stack->transactions->run(static function () use ($stack, &$order, $value): int {
            $stack->transactions->afterCommit(static function () use (&$order): void {
                $order[] = 'first';
            });
            $stack->transactions->run(static function () use ($stack, &$order): void {
                $stack->transactions->afterCommit(static function () use (&$order): void {
                    $order[] = 'nested';
                });
            });
            $stack->transactions->afterCommit(static function () use (&$order): void {
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
        $stack = $this->stack($implementation);
        $order = [];

        $stack->transactions->run(static function () use ($stack, &$order): void {
            $stack->transactions->afterCommit(static function () use ($stack, &$order): void {
                $order[] = 'first';
                $stack->transactions->afterCommit(static function () use (&$order): void {
                    $order[] = 'added by first';
                });
            });
        });

        self::assertSame(['first', 'added by first'], $order);
    }

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

    #[DataProvider('implementations')]
    public function testAFailureUndoesEverythingAndDropsTheCallbacks(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principal = self::principal();
        $ran = new \ArrayObject();
        $thrown = null;

        try {
            $stack->transactions->run(static function () use ($stack, $principal, $ran): void {
                $stack->principals->add($principal);
                $stack->transactions->afterCommit(static function () use ($ran): void {
                    $ran->append('callback');
                });

                throw new \RuntimeException('failed');
            });
        } catch (\RuntimeException $exception) {
            $thrown = $exception->getMessage();
        }

        self::assertSame('failed', $thrown, 'The exception was swallowed.');
        self::assertCount(0, $ran);
        self::assertNull($stack->principals->get($principal->realm, $principal->id));

        // The next transaction starts clean.
        $stack->transactions->run(static fn () => $stack->principals->add($principal));
        self::assertNotNull($stack->principals->get($principal->realm, $principal->id));
    }

    #[DataProvider('implementations')]
    public function testAFailedNestedUnitUndoesOnlyItself(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $kept = self::principal();
        $undone = self::principal();
        $order = [];

        $stack->transactions->run(static function () use ($stack, $kept, $undone, &$order): void {
            $stack->principals->add($kept);
            $stack->transactions->afterCommit(static function () use (&$order): void {
                $order[] = 'outer';
            });

            try {
                $stack->transactions->run(static function () use ($stack, $undone, &$order): void {
                    $stack->principals->add($undone);
                    $stack->transactions->afterCommit(static function () use (&$order): void {
                        $order[] = 'failed inner';
                    });

                    throw new \RuntimeException('inner failed');
                });
            } catch (\RuntimeException) {
            }

            $stack->transactions->afterCommit(static function () use (&$order): void {
                $order[] = 'after the failure';
            });
        });

        self::assertSame(['outer', 'after the failure'], $order);
        self::assertNotNull($stack->principals->get($kept->realm, $kept->id));
        self::assertNull($stack->principals->get($undone->realm, $undone->id));
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
    public function testRefusesToQueueCallbacksWithoutTheMiddleware(string $platform): void
    {
        $connection = $this->connect($platform);
        $transactions = new DbalTransactions($connection);

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('can\'t tell when this connection\'s transactions commit');

        $transactions->run(static fn () => $transactions->afterCommit(static function (): void {}));
    }

    private static function principal(): Principal
    {
        $now = new \DateTimeImmutable('2026-10-27 18:40:00 UTC');

        return new Principal(new PrincipalId(new UuidV7()), Realm::default(), PrincipalKind::Human, PrincipalStatus::Active, $now, $now, $now);
    }
}
