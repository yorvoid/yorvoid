<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Event;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Event\OperationRan;
use YorvoID\Core\Operation\HoldsSecrets;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Core\Storage\RetryableConflict;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Badges;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;
use YorvoID\Tests\Core\Storage\RetriesTest;
use YorvoID\Tests\Support\PermitsEverything;
use YorvoID\Tests\Support\RecordingDispatcher;

/**
 * Every operation that ran dispatches one OperationRan once its transaction committed, and each of its
 * consequences one too, naming the operation's decision; nothing that was refused, failed, rolled back
 * or conflicted does, and a command that holds a Secret is never handed to a listener.
 */
final class OperationRanTest extends TestCase
{
    private Realm $realm;

    private PrincipalId $ana;

    private RecordingDispatcher $events;

    private InMemoryTransactions $transactions;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->ana = new PrincipalId(Ids::next());
        $this->events = new RecordingDispatcher();
        $this->transactions = new InMemoryTransactions();
    }

    /**
     * Every operation of YorvoID's packages: Operations dispatches it, whatever the handler does, so no
     * handler can leave it out.
     */
    public function testEveryOperationDispatchesOneOperationRanAfterTheCommit(): void
    {
        $commands = self::commands();
        $handler = new class(array_keys($commands)) implements OperationHandler {
            /**
             * @param list<class-string<Command<mixed>>> $commands
             */
            public function __construct(private array $commands)
            {
            }

            public function operations(): array
            {
                return array_fill_keys($this->commands, static function (Command $command, Permit $permit): null {
                    $permit->use($command);

                    return null;
                });
            }
        };
        $catalogue = new OperationCatalogue(array_keys($commands));
        $operations = new Operations($catalogue, new PermitsEverything(offline: [] !== array_filter($catalogue->all(), static fn (OperationDescription $operation): bool => $operation->acceptsOffline)), $this->log(), [$handler], transactions: $this->transactions, events: $this->events);

        foreach ($commands as $class => $command) {
            $before = \count($this->events->of(OperationRan::class));
            $this->transactions->run(function () use ($operations, $command, $before, $class): void {
                $operations->run($command, new Operator(), new Origin(OriginKind::Web));
                self::assertCount($before, $this->events->of(OperationRan::class), \sprintf('%s: before the commit.', $class));
            });
            $ran = \array_slice($this->events->of(OperationRan::class), $before);

            self::assertCount(1, $ran, $class);
            self::assertSame(Operation::of($class)->name, $ran[0]->operation, $class);
            self::assertSame(HoldsSecrets::in($class) ? null : $command, $ran[0]->command, $class);
            self::assertNull($ran[0]->parent, $class);
        }

        self::assertCount(63, $commands, 'The operations moved.');
    }

    /**
     * The commands that hold a Secret, by what their properties declare, docblocks too: each is one that
     * HoldsSecrets finds, so its event carries no command.
     */
    public function testNoCommandThatHoldsASecretReachesAListener(): void
    {
        $holding = [];

        foreach (array_keys(self::commands()) as $class) {
            $declared = false;

            foreach (new \ReflectionClass($class)->getProperties() as $property) {
                $declared = $declared || str_contains((string) $property->getType(), 'Secret') || str_contains((string) $property->getDocComment(), 'Secret');
            }

            $constructor = new \ReflectionClass($class)->getConstructor();
            $documented = null !== $constructor && 1 === preg_match('/@param\s+[^$\n]*\bSecret\b/', (string) $constructor->getDocComment());

            if ($declared || $documented) {
                self::assertTrue(HoldsSecrets::in($class), \sprintf('%s holds a Secret its event would carry.', $class));
                $holding[] = new \ReflectionClass($class)->getShortName();
            }
        }

        sort($holding);
        self::assertSame(['AcceptInvitation', 'ClaimSetup', 'RecoverWithCode', 'RedeemRecoveryCode', 'ReportNotMe', 'SignInWithPasskey', 'StartSession', 'UseRecoveryLink', 'VerifyEmail'], $holding);
    }

    public function testHoldsSecretsLooksThroughTypesAndTheObjectsAPropertyHolds(): void
    {
        self::assertTrue(HoldsSecrets::in(HoldsOne::class));
        self::assertTrue(HoldsSecrets::in(HoldsMaybe::class), 'A nullable one.');
        self::assertTrue(HoldsSecrets::in(HoldsEither::class), 'In a union.');
        self::assertTrue(HoldsSecrets::in(HoldsInside::class), 'In an object it holds.');
        self::assertFalse(HoldsSecrets::in(HoldsItself::class), 'A cycle ends.');
        self::assertFalse(HoldsSecrets::in(IssueBadge::class));
    }

    public function testAnOperationAndItsConsequenceDispatchOnceEachAfterTheCommit(): void
    {
        $operations = $this->badges();

        $this->transactions->run(function () use ($operations): void {
            $operations->run(new IssueBadge($this->realm, $this->ana), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));
            self::assertSame([], $this->events->of(OperationRan::class), 'Not before the commit.');
        });
        [$consequence, $operation] = $this->events->of(OperationRan::class);

        self::assertCount(2, $this->events->of(OperationRan::class));
        self::assertSame(['acme.badge.record', 'acme.badge.issue'], [$consequence->operation, $operation->operation], 'The consequence first: it ran first.');
        self::assertSame($operation->decisionId, $consequence->parent);
        self::assertNull($operation->parent);
        self::assertNotSame($operation->decisionId, $consequence->decisionId, 'A decision of its own.');
        self::assertInstanceOf(RecordBadge::class, $consequence->command);
        self::assertTrue($this->ana->equals($operation->actor ?? throw new \LogicException('By nobody.')));
        self::assertNull($operation->about, 'A badge isn\'t about a principal.');
        self::assertNull($operation->created);
    }

    public function testNothingIsDispatchedForWhatRolledBack(): void
    {
        $operations = $this->badges();

        try {
            $this->transactions->run(function () use ($operations): void {
                $operations->run(new ScanBadge($this->realm, 'front-door'), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));

                throw new \RuntimeException('The application\'s work failed.');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->events->of(OperationRan::class));
    }

    public function testNothingIsDispatchedForARefusalOrAHandlerThatThrows(): void
    {
        $refusing = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), new PermitsEverything(Verdict::Deny), $this->log(), [new Badges()], transactions: $this->transactions, events: $this->events);

        try {
            $refusing->run(new ScanBadge($this->realm, 'front-door'), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));
            self::fail('It ran.');
        } catch (NotPermitted) {
        }

        $throwing = new Operations(new OperationCatalogue([ScanBadge::class]), new PermitsEverything(), $this->log(), [new Throwing(static fn (): never => throw new \DomainException('No.'))], transactions: $this->transactions, events: $this->events);

        try {
            $throwing->run(new ScanBadge($this->realm, 'front-door'), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));
            self::fail('It ran.');
        } catch (\DomainException) {
        }

        self::assertSame([], $this->events->of(OperationRan::class));
    }

    public function testAnOperationThatConflictedDispatchesOnceAfterTheAttemptThatCommitted(): void
    {
        $attempts = 0;
        $handler = new Throwing(function () use (&$attempts): void {
            $this->transactions->run(static function () use (&$attempts): void {
                if (1 === ++$attempts) {
                    throw new RetryableConflict(RetriesTest::deadlock());
                }
            });
        });
        $operations = new Operations(new OperationCatalogue([ScanBadge::class]), new PermitsEverything(), $this->log(), [$handler], transactions: $this->transactions, events: $this->events);

        $operations->run(new ScanBadge($this->realm, 'front-door'), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));

        self::assertSame(2, $attempts);
        self::assertCount(1, $this->events->of(OperationRan::class));
    }

    public function testWithoutTransactionsItIsDispatchedWhenTheHandlerReturns(): void
    {
        $operations = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), new PermitsEverything(), $this->log(), [new Badges()], events: $this->events);

        $this->transactions->run(function () use ($operations): void {
            $operations->run(new ScanBadge($this->realm, 'front-door'), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));
            self::assertCount(1, $this->events->of(OperationRan::class), 'Nothing to wait for.');
        });
    }

    public function testWithoutTransactionsAConsequenceOfAnOperationThatFailedDispatchesNothing(): void
    {
        $operations = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), new PermitsEverything(), $this->log(), [new CausesThenThrows()], events: $this->events);

        try {
            $operations->run(new IssueBadge($this->realm, $this->ana), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));
            self::fail('It ran.');
        } catch (\DomainException) {
        }

        self::assertSame([], $this->events->of(OperationRan::class), 'The consequence ran, but the operation that caused it didn\'t.');
    }

    public function testWithoutTransactionsAPermitKeptPastItsOperationIsSpent(): void
    {
        $keeps = new KeepsThePermit();
        $operations = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), new PermitsEverything(), $this->log(), [$keeps], events: $this->events);
        $operations->run(new IssueBadge($this->realm, $this->ana), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));
        $dispatched = \count($this->events->dispatched);
        self::assertSame(1, $dispatched, 'The issue alone.');

        try {
            ($keeps->kept ?? throw new \LogicException('Not kept.'))->cause($keeps->record(...), new RecordBadge($this->realm), new Origin(OriginKind::Web));
            self::fail('Ran on a spent permit.');
        } catch (IntegratorError $error) {
            self::assertStringContainsString('spent', $error->getMessage());
        }

        self::assertCount($dispatched, $this->events->dispatched, 'Nothing dispatched late.');
    }

    public function testWithoutTransactionsAPermitKeptPastAnOperationThatFailedIsSpent(): void
    {
        $keeps = new KeepsThePermit(fails: true);
        $operations = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), new PermitsEverything(), $this->log(), [$keeps], events: $this->events);

        try {
            $operations->run(new IssueBadge($this->realm, $this->ana), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));
            self::fail('It ran.');
        } catch (\DomainException) {
        }

        try {
            ($keeps->kept ?? throw new \LogicException('Not kept.'))->cause($keeps->record(...), new RecordBadge($this->realm), new Origin(OriginKind::Web));
            self::fail('Ran on a spent permit.');
        } catch (IntegratorError $error) {
            self::assertStringContainsString('spent', $error->getMessage());
        }

        self::assertSame([], $this->events->of(OperationRan::class));
    }

    private function badges(): Operations
    {
        return new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), new PermitsEverything(), $this->log(), [new Badges()], transactions: $this->transactions, events: $this->events);
    }

    private function log(): SecurityLog
    {
        return new SecurityLog(new InMemorySecurityLogStore(), $this->transactions);
    }

    /**
     * One command of each operation in YorvoID's packages, made from its constructor's types.
     *
     * @return array<class-string<Command<mixed>>, Command<mixed>>
     */
    private static function commands(): array
    {
        $commands = [];

        foreach (new Finder()->files()->in(__DIR__.'/../../../../packages/*/src')->name('*.php')->contains('#[Operation(')->sortByName() as $file) {
            $class = 1 === preg_match('/^namespace ([^;]+);/m', $file->getContents(), $namespace) ? $namespace[1].'\\'.$file->getBasename('.php') : '';

            if (class_exists($class) && [] !== new \ReflectionClass($class)->getAttributes(Operation::class)) {
                $command = self::sample($class);
                \assert($command instanceof Command && is_a($class, Command::class, true));
                $commands[$class] = $command;
            }
        }

        return $commands;
    }

    /**
     * @param class-string $class
     */
    private static function sample(string $class): object
    {
        if (Realm::class === $class) {
            return new Realm('acme');
        }

        if (Duration::class === $class) {
            return Duration::days(1);
        }

        if (Secret::class === $class) {
            return Secret::fromString('a secret of the test');
        }

        if (is_a($class, \DateTimeInterface::class, true)) {
            return new \DateTimeImmutable('2026-11-25 18:00:00');
        }

        $reflection = new \ReflectionClass($class);

        if (!($reflection->getConstructor()?->isPublic() ?? true) && $reflection->hasMethod('fromString')) {
            $id = $class::fromString((string) Ids::next());
            \assert(\is_object($id));

            return $id;
        }

        if (is_a($class, \UnitEnum::class, true)) {
            return new \ReflectionEnum($class)->getCases()[0]->getValue();
        }

        $arguments = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isOptional() && !$parameter->isVariadic()) {
                break;
            }

            $type = $parameter->getType();

            if ($type instanceof \ReflectionUnionType) {
                $type = $type->getTypes()[0];
            }

            \assert($type instanceof \ReflectionNamedType, \sprintf('%s::$%s', $class, $parameter->getName()));
            $arguments[] = match ($type->getName()) {
                'string' => str_ends_with(strtolower($parameter->getName()), 'id') ? (string) Ids::next() : 'test',
                'int' => 1,
                'bool' => false,
                'array' => [],
                default => self::sample(class_exists($type->getName()) || interface_exists($type->getName()) ? $type->getName() : throw new \LogicException(\sprintf('%s::$%s', $class, $parameter->getName()))),
            };
        }

        return $reflection->newInstanceArgs($arguments);
    }
}

/**
 * Runs ScanBadge with what it's given.
 */
final readonly class Throwing implements OperationHandler
{
    public function __construct(private \Closure $work)
    {
    }

    public function operations(): array
    {
        return [ScanBadge::class => function (ScanBadge $command, Permit $permit): string {
            $permit->use($command);
            ($this->work)();

            return 'scanned';
        }];
    }
}

/**
 * Issues a badge, records it, then fails.
 */
final readonly class CausesThenThrows implements OperationHandler
{
    public function operations(): array
    {
        $record = static function (RecordBadge $command, Permit $permit): string {
            $permit->use($command);

            return 'recorded';
        };

        return [
            IssueBadge::class => static function (IssueBadge $command, Permit $permit, Origin $origin) use ($record): never {
                $permit->use($command);
                $permit->cause($record, new RecordBadge($command->realm), $origin);

                throw new \DomainException('No badge after all.');
            },
            RecordBadge::class => $record,
        ];
    }
}

/**
 * Issues a badge without recording it, and keeps the permit for later: misuse. Then fails, if asked to.
 */
final class KeepsThePermit implements OperationHandler
{
    public ?Permit $kept = null;

    public function __construct(private readonly bool $fails = false)
    {
    }

    public function operations(): array
    {
        return [
            IssueBadge::class => function (IssueBadge $command, Permit $permit): string {
                $permit->use($command);
                $this->kept = $permit;

                return $this->fails ? throw new \DomainException('No badge after all.') : 'issued';
            },
            RecordBadge::class => $this->record(...),
        ];
    }

    public function record(RecordBadge $command, Permit $permit): string
    {
        $permit->use($command);

        return 'recorded';
    }
}

final readonly class HoldsOne
{
    public function __construct(public Secret $secret)
    {
    }
}

final readonly class HoldsMaybe
{
    public function __construct(public ?Secret $secret = null)
    {
    }
}

final readonly class HoldsEither
{
    public function __construct(public string|Secret $secret)
    {
    }
}

final readonly class HoldsInside
{
    public function __construct(public HoldsOne $inside)
    {
    }
}

final class HoldsItself
{
    public ?HoldsItself $next = null;
}
