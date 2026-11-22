<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\ForgetsWhatItRead;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Core\Storage\RetryableConflict;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ValidAuthority;
use YorvoID\Tests\Support\TrustedActors;

/**
 * Work whose transaction conflicted with another runs again from the start (D-52): up to 3 attempts,
 * without what a conflicted attempt left waiting, never inside an application's transaction, and an
 * operation is decided again before it runs again.
 */
final class RetriesTest extends TestCase
{
    private Connection $connection;

    private DbalTransactions $transactions;

    /** @var list<int> */
    private array $waits = [];

    private Forgetful $reads;

    protected function setUp(): void
    {
        $middleware = new TransactionMiddleware();
        $configuration = new Configuration();
        $configuration->setMiddlewares([$middleware]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->connection->executeStatement('CREATE TABLE t (v INTEGER)');
        $this->reads = new Forgetful();
        $this->transactions = $this->transactions($this->connection, $middleware, static fn (int $min, int $max): int => $min);
    }

    public function testWorkThatConflictedOnceCommitsOnItsSecondAttempt(): void
    {
        $attempts = 0;
        /** @var \ArrayObject<int, string> $ran */
        $ran = new \ArrayObject();

        $result = $this->transactions->retrying(function () use (&$attempts, $ran): string {
            return $this->transactions->run(function () use (&$attempts, $ran): string {
                ++$attempts;
                $this->connection->executeStatement('INSERT INTO t (v) VALUES (?)', [$attempts]);
                $this->transactions->afterCommit(static function () use ($ran, $attempts): void {
                    $ran[] = 'committed '.$attempts;
                });
                $this->transactions->afterEnd(static function () use ($ran, $attempts): void {
                    $ran[] = 'ended '.$attempts;
                });

                if (1 === $attempts) {
                    throw self::deadlock();
                }

                return 'done';
            });
        });

        self::assertSame('done', $result); // @phpstan-ignore staticMethod.alreadyNarrowedType (it's what retrying() gives back)
        self::assertEquals([2], $this->connection->fetchFirstColumn('SELECT v FROM t'), 'The first attempt rolled back.');
        self::assertSame(['committed 2', 'ended 2'], $ran->getArrayCopy(), 'The first attempt\'s callbacks went with it.');
        self::assertSame(1, $this->reads->resets, 'What was read was forgotten before the second attempt.');
        self::assertSame([10_000], $this->waits, 'The first wait is 10 to 50 ms.');

        // It's done with: a conflict after it is thrown, not retried.
        $this->expectException(RetryableConflict::class);
        $this->transactions->run(static fn (): mixed => throw self::deadlock());
    }

    public function testAThirdConflictIsThrown(): void
    {
        $attempts = 0;

        try {
            $this->transactions->retrying(function () use (&$attempts): void {
                $this->transactions->run(static function () use (&$attempts): mixed {
                    ++$attempts;

                    throw self::deadlock();
                });
            });
            self::fail('The third conflict is thrown.'); // @phpstan-ignore deadCode.unreachable (unless retrying() gives up without it)
        } catch (RetryableConflict $conflict) {
            self::assertSame(3, $attempts);
            self::assertSame('The work conflicted with another transaction 3 times: run it again.', $conflict->getMessage());
            self::assertInstanceOf(DeadlockException::class, $conflict->getPrevious());
        }

        self::assertSame([10_000, 50_000], $this->waits);

        // The waits' other bounds.
        $this->waits = [];
        $highest = $this->transactions($this->connection, null, static fn (int $min, int $max): int => $max);

        try {
            $highest->retrying(static fn (): mixed => $highest->run(static fn (): mixed => throw self::deadlock()));
        } catch (RetryableConflict) {
        }

        self::assertSame([50_000, 150_000], $this->waits);
    }

    public function testWorkThatConflictedTwiceCommitsOnTheLastAttempt(): void
    {
        foreach ([$this->transactions, new InMemoryTransactions()] as $transactions) {
            $attempts = 0;

            $result = $transactions->retrying(static function () use ($transactions, &$attempts): string {
                return $transactions->run(static function () use (&$attempts): string {
                    return ++$attempts < 3 ? throw new RetryableConflict(self::deadlock()) : 'done';
                });
            });

            self::assertSame(['done', 3], [$result, $attempts], $transactions::class);
        }
    }

    /**
     * A nested unit that lost the connection doesn't end the transaction it's in: what waits for the end
     * runs when the outermost unit ends.
     */
    public function testAConnectionLostInANestedUnitEndsWithTheOutermost(): void
    {
        /** @var \ArrayObject<int, string> $ran */
        $ran = new \ArrayObject();

        try {
            $this->transactions->run(function () use ($ran): never {
                $this->transactions->afterEnd(static function () use ($ran): void {
                    $ran[] = 'ended';
                });

                try {
                    $this->transactions->run(function (): never {
                        $this->connection->close();

                        throw new \RuntimeException('The connection is gone.');
                    });
                } catch (\Throwable) {
                }

                $ran[] = 'the outermost goes on';

                throw new \RuntimeException('It ends.');
            });
        } catch (\Throwable) {
        }

        self::assertSame(['the outermost goes on', 'ended'], $ran->getArrayCopy());
    }

    /**
     * A conflict deep in nested units of work reaches the outermost one as a RetryableConflict: its
     * attempt's callbacks go all the same.
     */
    public function testAConflictInANestedUnitDropsTheAttemptsCallbacks(): void
    {
        $attempts = 0;
        /** @var \ArrayObject<int, int> $ended */
        $ended = new \ArrayObject();
        /** @var \ArrayObject<int, int> $beforeCommit */
        $beforeCommit = new \ArrayObject();

        $this->transactions->retrying(function () use (&$attempts, $ended, $beforeCommit): void {
            $this->transactions->run(function () use (&$attempts, $ended, $beforeCommit): void {
                ++$attempts;
                $this->transactions->afterEnd(static function () use ($ended, $attempts): void {
                    $ended[] = $attempts;
                });
                self::assertTrue($this->transactions->beforeCommit(static function () use ($beforeCommit, $attempts): void {
                    $beforeCommit[] = $attempts;
                }));

                if (1 === $attempts) {
                    $this->transactions->run(static fn (): mixed => throw self::deadlock());
                }
            });
        });

        self::assertSame([2], $ended->getArrayCopy());
        self::assertSame([2], $beforeCommit->getArrayCopy(), 'What the first attempt left for its commit ran.');
    }

    /**
     * A conflict at the commit, as a serializable transaction can fail: nothing ran what the attempt left
     * waiting, and it goes.
     */
    public function testAConflictAtTheCommitDropsTheAttemptsCallbacks(): void
    {
        $middleware = new TransactionMiddleware();
        $configuration = new Configuration();
        $configuration->setMiddlewares([$middleware]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => ConflictingCommit::class], $configuration);
        $transactions = $this->transactions($connection, $middleware, static fn (int $min, int $max): int => $min);
        /** @var \ArrayObject<int, string> $ran */
        $ran = new \ArrayObject();
        $attempts = 0;

        $transactions->retrying(static function () use ($transactions, $ran, &$attempts): void {
            $transactions->run(static function () use ($transactions, $ran, &$attempts): void {
                $attempt = ++$attempts;
                $transactions->afterCommit(static function () use ($ran, $attempt): void {
                    $ran[] = 'committed '.$attempt;
                });
                $transactions->afterEnd(static function () use ($ran, $attempt): void {
                    $ran[] = 'ended '.$attempt;
                });
            });
        });

        self::assertSame(['committed 2', 'ended 2'], $ran->getArrayCopy());
    }

    /**
     * The application's transaction holds more than YorvoID's work: it's the application's to retry.
     */
    public function testInsideTheApplicationsTransactionAConflictIsThrownAtOnce(): void
    {
        $attempts = 0;
        $thrown = null;
        $this->connection->beginTransaction();

        try {
            $this->transactions->retrying(function () use (&$attempts): void {
                $this->transactions->run(static function () use (&$attempts): mixed {
                    ++$attempts;

                    throw self::deadlock();
                });
            });
        } catch (RetryableConflict $conflict) {
            $thrown = $conflict;
        } finally {
            $this->connection->rollBack();
        }

        self::assertInstanceOf(RetryableConflict::class, $thrown); // @phpstan-ignore staticMethod.alreadyNarrowedType (that it was thrown)
        self::assertSame('The work conflicted with another transaction: run it again.', $thrown->getMessage());
        self::assertSame(1, $attempts, 'A conflict inside the application\'s transaction was retried.');
        self::assertSame([], $this->waits);
    }

    /**
     * Without retrying(), a conflict is still a RetryableConflict, and what waits for the end still runs.
     */
    public function testAConflictOutsideRetryingKeepsWhatWaitsForTheEnd(): void
    {
        $ended = false;

        try {
            $this->transactions->run(function () use (&$ended): never {
                $this->transactions->afterEnd(static function () use (&$ended): void {
                    $ended = true;
                });

                throw self::deadlock();
            });
        } catch (RetryableConflict) {
        }

        self::assertTrue($ended);
    }

    /**
     * A commit that failed outside retrying() ended the transaction: what waits for the end runs then,
     * once, and doesn't wait for the next transaction's.
     */
    public function testAFailedCommitOutsideRetryingRunsWhatWaitsForTheEnd(): void
    {
        $middleware = new TransactionMiddleware();
        $configuration = new Configuration();
        $configuration->setMiddlewares([$middleware]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => ConflictingCommit::class], $configuration);
        $transactions = $this->transactions($connection, $middleware, static fn (int $min, int $max): int => $min);
        /** @var \ArrayObject<int, string> $ran */
        $ran = new \ArrayObject();

        try {
            $transactions->run(static function () use ($transactions, $ran): void {
                $transactions->afterEnd(static function () use ($ran): void {
                    $ran[] = 'first';
                });
            });
        } catch (RetryableConflict) {
        }

        self::assertSame(['first'], $ran->getArrayCopy());
        $transactions->run(static fn (): null => null);
        self::assertSame(['first'], $ran->getArrayCopy(), 'Once.');
    }

    /**
     * A lost connection gets no rollback, but the server ended the transaction: DBAL forgets it, what
     * waits for the end runs then, once, and doesn't wait for the next transaction's.
     */
    public function testALostConnectionEndsTheTransaction(): void
    {
        /** @var \ArrayObject<int, string> $ran */
        $ran = new \ArrayObject();

        try {
            $this->transactions->run(function () use ($ran): never {
                $this->transactions->afterEnd(static function () use ($ran): void {
                    $ran[] = 'ended';
                });

                throw new ConnectionLost(self::driverError('server closed the connection', '08006'), null);
            });
        } catch (ConnectionLost) {
        }

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(['ended'], $ran->getArrayCopy());
        $this->transactions->run(static fn (): null => null);
        self::assertSame(['ended'], $ran->getArrayCopy(), 'Once.');
    }

    /**
     * A commit that fails for another reason than a conflict isn't run again: what waits for the end
     * runs, once, also inside retrying().
     */
    public function testAFailedCommitInsideRetryingRunsWhatWaitsForTheEnd(): void
    {
        $middleware = new TransactionMiddleware();
        $configuration = new Configuration();
        $configuration->setMiddlewares([$middleware]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => ViolatingCommit::class], $configuration);
        $transactions = $this->transactions($connection, $middleware, static fn (int $min, int $max): int => $min);
        /** @var \ArrayObject<int, string> $ran */
        $ran = new \ArrayObject();

        $thrown = null;

        try {
            $transactions->retrying(static function () use ($transactions, $ran): void {
                $transactions->run(static function () use ($transactions, $ran): void {
                    $transactions->afterEnd(static function () use ($ran): void {
                        $ran[] = 'ended';
                    });
                });
            });
        } catch (\Exception $failed) {
            $thrown = $failed;
        }

        self::assertInstanceOf(UniqueConstraintViolationException::class, $thrown);
        self::assertSame(['ended'], $ran->getArrayCopy());
        $transactions->run(static fn (): null => null);
        self::assertSame(['ended'], $ran->getArrayCopy(), 'Once.');
    }

    /**
     * Nothing runs the last attempt again: what it left waiting for the end runs, once, and the earlier
     * attempts' never.
     */
    public function testTheLastAttemptKeepsWhatWaitsForTheEnd(): void
    {
        $middleware = new TransactionMiddleware();
        $configuration = new Configuration();
        $configuration->setMiddlewares([$middleware]);
        $atCommit = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => AlwaysConflictingCommit::class], $configuration);
        $cases = [
            'in the work' => [$this->transactions, static fn (): never => throw self::deadlock()],
            'at the commit' => [$this->transactions($atCommit, $middleware, static fn (int $min, int $max): int => $min), static fn (): null => null],
            'in memory' => [new InMemoryTransactions(), static fn (): never => throw new RetryableConflict(self::deadlock())],
        ];

        foreach ($cases as $case => [$transactions, $then]) {
            /** @var \ArrayObject<int, int> $attempts */
            $attempts = new \ArrayObject();
            /** @var \ArrayObject<int, int> $ended */
            $ended = new \ArrayObject();

            try {
                $transactions->retrying(static fn (): mixed => $transactions->run(static function () use ($transactions, $then, $ended, $attempts): mixed {
                    $attempts[] = $attempt = \count($attempts) + 1;
                    $transactions->afterEnd(static function () use ($ended, $attempt): void {
                        $ended[] = $attempt;
                    });

                    return $then();
                }));
                self::fail('No conflict: '.$case);
            } catch (RetryableConflict) {
            }

            self::assertSame([3, [3]], [\count($attempts), $ended->getArrayCopy()], $case);

            try {
                $transactions->run(static fn (): null => null);
            } catch (RetryableConflict) { // Its every commit conflicts.
            }

            self::assertSame([3], $ended->getArrayCopy(), $case.': once.');
        }
    }

    /**
     * Once retrying() has given up, or returned, work isn't retried: a conflict outside it keeps its
     * callbacks.
     */
    public function testRetryingEndsWithTheWorkItRetries(): void
    {
        foreach ([$this->transactions, new InMemoryTransactions()] as $transactions) {
            foreach ([static fn (): string => 'done', static fn (): mixed => throw new RetryableConflict(self::deadlock())] as $work) {
                try {
                    $transactions->retrying(static fn (): mixed => $transactions->run($work));
                } catch (RetryableConflict) {
                }

                $ended = false;

                try {
                    $transactions->run(static function () use ($transactions, &$ended): never {
                        $transactions->afterEnd(static function () use (&$ended): void {
                            $ended = true;
                        });

                        throw new RetryableConflict(self::deadlock());
                    });
                } catch (RetryableConflict) {
                }

                self::assertTrue($ended, $transactions::class);
            }
        }
    }

    /**
     * Memory doesn't conflict, but work that throws a conflict is run again as on a database.
     */
    public function testInMemoryWorkIsRetriedAsOnADatabase(): void
    {
        $transactions = new InMemoryTransactions(reads: [$this->reads]);
        $attempts = 0;
        /** @var \ArrayObject<int, string> $ended */
        $ended = new \ArrayObject();

        $result = $transactions->retrying(static function () use ($transactions, &$attempts, $ended): string {
            return $transactions->run(static function () use ($transactions, &$attempts, $ended): string {
                $attempt = ++$attempts;
                $transactions->afterEnd(static function () use ($ended, $attempt): void {
                    $ended[] = 'ended '.$attempt;
                });

                return 1 === $attempt ? throw new RetryableConflict(self::deadlock()) : 'done';
            });
        });

        self::assertSame(['done', 2, ['ended 2'], 1], [$result, $attempts, $ended->getArrayCopy(), $this->reads->resets]);

        $attempts = 0;

        try {
            $transactions->retrying(static function () use ($transactions, &$attempts): void {
                $transactions->run(static function () use (&$attempts): void {
                    ++$attempts;

                    throw new RetryableConflict(self::deadlock());
                });
            });
            self::fail('The third conflict is thrown.'); // @phpstan-ignore deadCode.unreachable (unless retrying() gives up without it)
        } catch (RetryableConflict $conflict) {
            self::assertSame([3, 'The work conflicted with another transaction 3 times: run it again.', 3], [$attempts, $conflict->getMessage(), $this->reads->resets]);
            self::assertInstanceOf(DeadlockException::class, $conflict->getPrevious());
        }

        // Inside an open transaction, once.
        $attempts = 0;

        try {
            $transactions->run(static function () use ($transactions, &$attempts): void {
                $transactions->retrying(static function () use (&$attempts): void {
                    ++$attempts;

                    throw new RetryableConflict(self::deadlock());
                });
            });
        } catch (RetryableConflict) {
        }

        self::assertSame(1, $attempts);
    }

    /**
     * An operation that conflicted is decided again: a permission taken away meanwhile refuses it.
     */
    public function testAnOperationThatConflictedIsDecidedAgain(): void
    {
        foreach ([$this->transactions, new InMemoryTransactions(reads: [$this->reads])] as $transactions) {
            $policy = new Revocable();
            $handler = new ConflictingOnce($transactions, $policy);
            $operations = new Operations(new OperationCatalogue([Touch::class]), Evaluator::builtIn(new ValidAuthority(), $policy, actors: [new TrustedActors()]), new SecurityLog(new InMemorySecurityLogStore(), new InMemoryTransactions()), [$handler], transactions: $transactions);
            $actor = new Member(new Realm('acme'), new PrincipalId(Ids::next()));

            try {
                $operations->run(new Touch(new Realm('acme')), $actor, new Origin(OriginKind::Web));
                self::fail('The second attempt ran on the first one\'s decision.');
            } catch (NotPermitted $refused) {
                self::assertSame(1, $handler->runs, $transactions::class);
                self::assertSame('acme.thing.touch', $refused->operation);
            }
        }
    }

    /**
     * @param (\Closure(int, int): int) $random
     */
    private function transactions(Connection $connection, ?TransactionMiddleware $middleware, \Closure $random): DbalTransactions
    {
        return new DbalTransactions($connection, $middleware, reads: [$this->reads], wait: function (int $microseconds): void {
            $this->waits[] = $microseconds;
        }, random: $random);
    }

    public static function deadlock(): DeadlockException
    {
        return new DeadlockException(self::driverError('deadlock detected', '40P01'), null);
    }

    public static function driverError(string $message, string $state): Driver\Exception
    {
        return new class($message, $state) extends \Exception implements Driver\Exception {
            public function __construct(string $message, private readonly string $state)
            {
                parent::__construct($message);
            }

            public function getSQLState(): string
            {
                return $this->state;
            }
        };
    }
}

/**
 * Its first commit fails as a serialization failure at the commit does.
 */
final class ConflictingCommit extends Connection
{
    private bool $conflicted = false;

    public function commit(): void
    {
        if (!$this->conflicted && 1 === $this->getTransactionNestingLevel()) {
            $this->conflicted = true;
            Ended::byTheDatabase($this);

            throw RetriesTest::deadlock();
        }

        parent::commit();
    }
}

/**
 * Every commit fails as a serialization failure at the commit does.
 */
final class AlwaysConflictingCommit extends Connection
{
    public function commit(): void
    {
        if (1 === $this->getTransactionNestingLevel()) {
            Ended::byTheDatabase($this);

            throw RetriesTest::deadlock();
        }

        parent::commit();
    }
}

/**
 * Its first commit fails as a deferred constraint does: not a conflict, nothing to run again.
 */
final class ViolatingCommit extends Connection
{
    private bool $violated = false;

    public function commit(): void
    {
        if (!$this->violated && 1 === $this->getTransactionNestingLevel()) {
            $this->violated = true;
            Ended::byTheDatabase($this);

            throw new UniqueConstraintViolationException(RetriesTest::driverError('duplicate key value', '23505'), null);
        }

        parent::commit();
    }
}

/**
 * What a commit that failed in the database leaves: no rollback reaches the driver's hooks, and DBAL
 * counts no transaction once commit() has thrown.
 */
final class Ended
{
    public static function byTheDatabase(Connection $connection): void
    {
        $native = $connection->getNativeConnection();
        \assert($native instanceof \PDO);
        $native->rollBack();
        new \ReflectionProperty(Connection::class, 'transactionNestingLevel')->setValue($connection, 0);
    }
}

final class Forgetful implements ForgetsWhatItRead
{
    public int $resets = 0;

    public function reset(): void
    {
        ++$this->resets;
    }
}

/**
 * Holds the ability until it's revoked.
 */
final class Revocable implements AbilityPolicy
{
    public bool $holds = true;

    public function holds(Actor $actor, string $ability, Command $command): bool
    {
        return $this->holds;
    }
}

/**
 * Its first run revokes the ability, as another request would, and then conflicts.
 */
final class ConflictingOnce implements OperationHandler
{
    public int $runs = 0;

    public function __construct(
        private readonly Transactions $transactions,
        private readonly Revocable $policy,
    ) {
    }

    public function operations(): array
    {
        return [Touch::class => $this->touch(...)];
    }

    public function touch(Touch $command, Permit $permit): string
    {
        $permit->use($command);
        ++$this->runs;

        return $this->transactions->run(function (): never {
            $this->policy->holds = false;

            throw new RetryableConflict(RetriesTest::deadlock());
        });
    }
}

/**
 * @implements Command<string>
 */
#[Operation('acme.thing.touch', 'Touch a thing', AuthorizedBy::Ability, ability: 'acme.things.touch')]
final readonly class Touch implements Command
{
    public function __construct(
        public Realm $realm,
    ) {
    }
}
