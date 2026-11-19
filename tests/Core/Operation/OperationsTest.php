<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\Authorization;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\NoAbilityPolicy;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Operation\ViewingAs;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Badges;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Policy;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\SelfService;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ValidAuthority;
use YorvoID\Tests\Support\TrustedActors;

final class OperationsTest extends TestCase
{
    private Realm $realm;

    private PrincipalId $ana;

    private InMemorySecurityLogStore $log;

    private InMemoryDecisionLogStore $decisions;

    private Badges $badges;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->ana = new PrincipalId(new UuidV7());
        $this->log = new InMemorySecurityLogStore();
        $this->decisions = new InMemoryDecisionLogStore();
        $this->badges = new Badges();
    }

    /**
     * A principal YorvoID verified acts only in what that causes: an application can't run an operation as
     * anyone by naming them (D-45, L-4; verify-1's forged administrator).
     */
    public function testAnActingPrincipalRunsNoOperation(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('was run as an ActingPrincipal');

        $this->operations()->run(new IssueBadge($this->realm, $this->ana), new ActingPrincipal($this->realm, $this->ana), new Origin(OriginKind::Web));
    }

    /**
     * Only YorvoID's own work is the System, which the operator acts as from anywhere (ARC-0016).
     */
    public function testOnlyYorvoIDMakesASystemOrigin(): void
    {
        self::assertSame(OriginKind::System, Origin::system('digest')->kind);
        self::assertSame(['x', 'id-1'], [Origin::restored(OriginKind::System, 'x', 'id-1')->detail, Origin::restored(OriginKind::System, 'x', 'id-1')->correlationId]);

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('An origin of the System kind was made outside YorvoID.');

        new Origin(OriginKind::System);
    }

    /**
     * An origin read back from a record is for reading: as an operation's origin, it makes nobody the
     * operator, System or not (D-45, R1).
     */
    public function testARestoredOriginMakesNobodyTheOperator(): void
    {
        foreach ([OriginKind::System, OriginKind::Console] as $kind) {
            $decision = $this->refused(new IssueBadge($this->realm, $this->ana), new Operator(), Origin::restored($kind, null, 'restored-1'));
            self::assertSame(Verdict::Deny, $decision->verdict, $kind->value);
        }

        // Nor does a copy: an origin isn't cloned, and one unserialized is restored.
        $unserialized = unserialize(serialize(Origin::system('badges')));
        \assert($unserialized instanceof Origin);
        self::assertTrue($unserialized->isRestored());
        self::assertSame(Verdict::Deny, $this->refused(new IssueBadge($this->realm, $this->ana), new Operator(), $unserialized)->verdict);

        self::assertSame('recorded', $this->operations()->run(new IssueBadge($this->realm, $this->ana), new Operator(), Origin::system('badges')));

        $this->expectException(\LogicException::class);
        $copy = clone Origin::restored(OriginKind::System, null, 'restored-1');
    }

    /**
     * An unserialized origin is what was serialized, marked restored, every one of them; a malformed one
     * is refused.
     */
    public function testAnUnserializedOriginIsTheOneSerializedAndRestored(): void
    {
        $origin = new Origin(OriginKind::Web, 'checkout', 'request-1');
        $first = unserialize(serialize($origin));
        $second = unserialize(serialize(new Origin(OriginKind::Job, null, 'job-1')));
        \assert($first instanceof Origin && $second instanceof Origin);

        self::assertSame([OriginKind::Web, 'checkout', 'request-1'], [$first->kind, $first->detail, $first->correlationId]);
        self::assertNull($second->detail);
        self::assertTrue($first->isRestored(), 'Restoring another forgot the first was restored.');
        self::assertTrue($second->isRestored());
        self::assertFalse($origin->isRestored());

        // A detail is cut at 255 characters, not bytes.
        $long = self::unserialized(['kind' => 'web', 'detail' => 'é'.str_repeat('ü', 300), 'correlationId' => 'request-2']);
        self::assertSame('é'.str_repeat('ü', 254), $long->detail);
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('malformedOrigins')]
    public function testAMalformedSerializedOriginIsRefused(array $data, string $message): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::unserialized($data);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function malformedOrigins(): iterable
    {
        yield 'no kind' => [['detail' => null, 'correlationId' => 'request-1'], 'has no kind'];
        yield 'an unknown kind' => [['kind' => 'nowhere', 'detail' => null, 'correlationId' => 'request-1'], 'has no kind'];
        yield 'a detail that isn\'t text' => [['kind' => 'web', 'detail' => 7, 'correlationId' => 'request-1'], 'is malformed'];
        yield 'no correlation ID' => [['kind' => 'web', 'detail' => 'x'], 'is malformed'];
        yield 'a correlation ID that isn\'t text' => [['kind' => 'web', 'detail' => 'x', 'correlationId' => 7], 'is malformed'];
        yield 'a correlation ID out of shape' => [['kind' => 'web', 'detail' => 'x', 'correlationId' => 'not one'], 'is malformed'];
    }

    /**
     * @param array<mixed> $data
     */
    private static function unserialized(array $data): Origin
    {
        $origin = new \ReflectionClass(Origin::class)->newInstanceWithoutConstructor();
        $origin->__unserialize($data);

        return $origin;
    }

    private static function origin(OriginKind $kind, ?string $detail = null, ?string $correlationId = null): Origin
    {
        return OriginKind::System === $kind ? Origin::system($detail ?? 'test', $correlationId) : new Origin($kind, $detail, $correlationId);
    }

    public function testTheOperatorRunsAnOperationFromTheConsole(): void
    {
        $command = new IssueBadge($this->realm, $this->ana);
        $operator = new Operator();

        self::assertSame('recorded', $this->operations()->run($command, $operator, new Origin(OriginKind::Console)));

        [$permit, $consequence] = $this->badges->permits;
        self::assertSame('acme.badge.issue', $permit->operation);
        self::assertSame(AuthorizedBy::Operator, $permit->basis);
        self::assertSame($operator, $permit->actor);
        self::assertTrue($permit->realm->equals($this->realm));
        self::assertNull($permit->parent);
        self::assertSame([], $this->entries());

        self::assertSame('acme.badge.record', $consequence->operation);
        self::assertSame(AuthorizedBy::Consequence, $consequence->basis);
        self::assertSame($permit, $consequence->parent);
        self::assertNotSame($permit->decisionId, $consequence->decisionId, 'A consequence has a decision of its own, under its parent\'s (D-50).');
        self::assertSame($operator, $consequence->actor);
    }

    /**
     * @return iterable<string, array{OriginKind}>
     */
    public static function operatorOrigins(): iterable
    {
        yield 'console' => [OriginKind::Console];
        yield 'job' => [OriginKind::Job];
        yield 'system' => [OriginKind::System];
    }

    #[DataProvider('operatorOrigins')]
    public function testTheOperatorActsFromTheHostOnly(OriginKind $kind): void
    {
        $this->operations()->run(new IssueBadge($this->realm, $this->ana), new Operator(), self::origin($kind));

        self::assertSame(AuthorizedBy::Operator, $this->badges->permits[0]->basis);
    }

    /**
     * @return iterable<string, array{OriginKind}>
     */
    public static function requestOrigins(): iterable
    {
        yield 'web' => [OriginKind::Web];
        yield 'api' => [OriginKind::Api];
    }

    #[DataProvider('requestOrigins')]
    public function testTheOperatorDoesntActFromARequest(OriginKind $kind): void
    {
        try {
            $this->operations()->run(new IssueBadge($this->realm, $this->ana), new Operator(), self::origin($kind));
        } catch (NotPermitted $refused) {
            self::assertSame('Not permitted to run "acme.badge.issue".', $refused->getMessage());
        }

        $this->log = new InMemorySecurityLogStore();
        $decision = $this->refused(new IssueBadge($this->realm, $this->ana), new Operator(), self::origin($kind, 'badges', 'request-1'));

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame([], $this->badges->permits);

        $record = $this->record($decision);
        self::assertSame(['acme.badge.issue', 'deny', null], [$record->name, $record->verdict, $record->actor]);
        self::assertSame([$kind->value, 'request-1'], $record->origin);
        self::assertSame(\sprintf('The host\'s operator acts from the command line or as the system, not from "%s".', $kind->value), $record->reason);
    }

    public function testSomeoneRunsAnOperationOnTheirOwnAccount(): void
    {
        $policy = new Policy(false);
        $this->operations($policy)->run(new IssueBadge($this->realm, $this->ana), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));

        self::assertSame(AuthorizedBy::Self, $this->badges->permits[0]->basis);
        self::assertSame(1, $policy->asked, 'Every element is asked, so reasons never depend on order (P2): the basis named first wins.');
    }

    public function testWithoutASelfServicePolicyNobodyRunsOperationsOnTheirOwnAccount(): void
    {
        $operations = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), Evaluator::builtIn(new ValidAuthority(), actors: [new TrustedActors()]), new SecurityLog($this->log, new InMemoryTransactions()), [$this->badges]);

        try {
            $operations->run(new IssueBadge($this->realm, $this->ana), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));
            self::fail('It was permitted.');
        } catch (NotPermitted $refused) {
            self::assertSame(Verdict::Deny, $refused->decision->verdict);
            self::assertSame(Precedence::ForbidHolds, $refused->decision->precedence);
            self::assertSame('A forbid applies: On one\'s own account: this application has no self-service: someone else changes one\'s own account.', $refused->decision->reason);
            self::assertSame([], $refused->decision->remedies);
            self::assertSame([], $refused->decision->indeterminate, 'A choice, not a gap.');
        }
    }

    public function testWithoutAnAbilityPolicyNobodyElseMayRunIt(): void
    {
        $decision = $this->refused(new IssueBadge($this->realm, $this->ana), new Member($this->realm, new PrincipalId(new UuidV7())), new Origin(OriginKind::Web));

        self::assertSame(Verdict::Indeterminate, $decision->verdict);
        self::assertNull($decision->basis);
        self::assertSame('A permit couldn\'t be evaluated: The ability policy can\'t tell who holds "acme.badges.issue".', $decision->reason);
        $record = $this->record($decision);
        self::assertSame([['missing_data'], 'A permit couldn\'t be evaluated: The ability policy can\'t tell who holds "acme.badges.issue".', 'indeterminate'], [$record->indeterminate, $record->reason, $record->verdict]);
    }

    public function testTheAbilityPolicyLetsSomeoneElseRunIt(): void
    {
        $boss = new PrincipalId(new UuidV7());
        $this->operations(new Policy(true))->run(new IssueBadge($this->realm, $this->ana), new Member($this->realm, $boss), new Origin(OriginKind::Web));

        self::assertSame(AuthorizedBy::Ability, $this->badges->permits[0]->basis);
        self::assertTrue($boss->equals($this->badges->permits[0]->actor->principalId ?? throw new \LogicException()));
    }

    public function testAViewAsRunsNothingAndTheViewerIsLogged(): void
    {
        $boss = new PrincipalId(new UuidV7());
        $decision = $this->refused(new IssueBadge($this->realm, $this->ana), new ViewingAs(new Member($this->realm, $boss), new Member($this->realm, $this->ana)), new Origin(OriginKind::Web), new Policy(true));

        self::assertSame(Verdict::Deny, $decision->verdict);
        $record = $this->record($decision);
        self::assertSame((string) $boss, $record->viewer['actor'] ?? null, 'The viewer tried it.');
        self::assertSame((string) $this->ana, $record->actor, 'As the target.');
        self::assertNull($this->refusedRecord(new Member($this->realm, $boss))->viewer, 'Only viewing as someone.');
        $asNobody = $this->refusedRecord(new ViewingAs(new Member($this->realm, $boss), new Anonymous()));
        self::assertSame([(string) $boss, null], [$asNobody->viewer['actor'] ?? null, $asNobody->actor], 'Viewing as nobody in particular.');
    }

    public function testAnAuthorizationThatPermitsAViewAsIsAMistake(): void
    {
        $permitting = new readonly class implements Authorization {
            use \YorvoID\Tests\Support\DecidesAlone;

            public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
            {
                return \YorvoID\Tests\Support\Decisions::permit(AuthorizedBy::Ability, 'Anything goes.');
            }
        };
        $operations = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), $permitting, new SecurityLog($this->log, new InMemoryTransactions()), [$this->badges]);

        try {
            $operations->run(new IssueBadge($this->realm, $this->ana), new ViewingAs(new Member($this->realm, new PrincipalId(new UuidV7())), new Member($this->realm, $this->ana)), new Origin(OriginKind::Web));
            self::fail('It ran for a view-as.');
        } catch (IntegratorError $error) {
            self::assertStringStartsWith('The authorization permitted "acme.badge.issue" to a view as someone else.', $error->getMessage());
        }

        self::assertSame([], $this->badges->permits);
        self::assertSame([], $this->entries());
    }

    /**
     * The record of its refusal.
     */
    private function refusedRecord(Actor $actor): DecisionRecord
    {
        return $this->record($this->refused(new IssueBadge($this->realm, $this->ana), $actor, new Origin(OriginKind::Web), new Policy(false)));
    }

    private function record(Decision $decision): DecisionRecord
    {
        return $this->decisions->find($this->realm, $decision->id ?? '') ?? throw new \LogicException('The decision wasn\'t recorded.');
    }

    public function testTheAbilityPolicyRefuses(): void
    {
        $decision = $this->refused(new IssueBadge($this->realm, $this->ana), new Member($this->realm, new PrincipalId(new UuidV7())), new Origin(OriginKind::Web), new Policy(false));

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame('The actor doesn\'t hold "acme.badges.issue".', $decision->reason);
    }

    public function testNobodyActsInAnotherRealm(): void
    {
        $policy = new Policy(true);
        $decision = $this->refused(new IssueBadge($this->realm, $this->ana), new Member(new Realm('other'), $this->ana), new Origin(OriginKind::Web), $policy);

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame(0, $policy->asked);
    }

    public function testAnActorWithoutAPrincipalOrRealmIsNeitherSelfNorHoldsAnAbility(): void
    {
        $policy = new Policy(true);
        $this->refused(new IssueBadge($this->realm, $this->ana), new Member(null, $this->ana), new Origin(OriginKind::Web), $policy);
        $this->refused(new IssueBadge($this->realm, $this->ana), new Member($this->realm, null), new Origin(OriginKind::Web), $policy);

        self::assertSame(0, $policy->asked);
    }

    public function testAnyoneMayAuthenticateAndTheHandlerChecks(): void
    {
        self::assertSame('scanned', $this->operations()->run(new ScanBadge($this->realm, 'K7'), new Member(null, null), new Origin(OriginKind::Web)));

        self::assertSame(AuthorizedBy::Authentication, $this->badges->permits[0]->basis);
    }

    public function testAConsequenceDoesntRunByItself(): void
    {
        $decision = $this->refused(new RecordBadge($this->realm), new Operator(), new Origin(OriginKind::Console));

        self::assertSame(Verdict::Deny, $decision->verdict);
    }

    public function testAPermitIsForItsCommandOnly(): void
    {
        $permit = $this->permit();

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The permit for "acme.badge.scan" was given with another command.');

        $permit->use(new ScanBadge($this->realm, 'K7'));
    }

    public function testAPermitIsUsedOnce(): void
    {
        $command = new ScanBadge($this->realm, 'K7');
        $permit = $this->permit($command);

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The permit for "acme.badge.scan" was used already.');

        $permit->use($command);
    }

    public function testAConsequenceFollowsAnOperationThatRuns(): void
    {
        $badges = new class implements OperationHandler {
            public function operations(): array
            {
                return [ScanBadge::class => static fn (ScanBadge $command, Permit $permit): Permit => $permit->consequence(new RecordBadge($command->realm))];
            }
        };

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('A consequence was asked of the permit for "acme.badge.scan" before it was used.');

        $this->operations(handlers: [$badges])->run(new ScanBadge($this->realm, 'K7'), new Operator(), new Origin(OriginKind::Console));
    }

    public function testAConsequenceIsOneTheOperationSaysItCauses(): void
    {
        $badges = new class implements OperationHandler {
            public function operations(): array
            {
                return [ScanBadge::class => static function (ScanBadge $command, Permit $permit): Permit {
                    $permit->use($command);

                    return $permit->consequence(new IssueBadge($command->realm, new PrincipalId(new UuidV7())));
                }];
            }
        };

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('"acme.badge.scan" doesn\'t say it causes "acme.badge.issue".');

        $this->operations(handlers: [$badges])->run(new ScanBadge($this->realm, 'K7'), new Operator(), new Origin(OriginKind::Console));
    }

    public function testTheHostsConsoleAndJobsActFromTheCommandLineOnly(): void
    {
        $log = new SecurityLog($this->log, new InMemoryTransactions());
        $catalogue = new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]);
        $served = new Operations($catalogue, Evaluator::builtIn(new ValidAuthority(), commandLine: false, actors: [new TrustedActors()]), $log, [$this->badges]);

        foreach ([OriginKind::Console, OriginKind::Job] as $kind) {
            try {
                $served->run(new IssueBadge($this->realm, $this->ana), new Operator(), self::origin($kind));
                self::fail(\sprintf('A web server ran an operation as the %s.', $kind->value));
            } catch (NotPermitted) {
            }
        }

        $served->run(new IssueBadge($this->realm, $this->ana), new Operator(), Origin::system('test'));
        self::assertSame(AuthorizedBy::Operator, $this->badges->permits[0]->basis, 'YorvoID itself acts from anywhere.');
    }

    public function testAConsequenceStaysInItsRealm(): void
    {
        $badges = new class implements OperationHandler {
            public function operations(): array
            {
                return [ScanBadge::class => static function (ScanBadge $command, Permit $permit): Permit {
                    $permit->use($command);

                    return $permit->consequence(new RecordBadge(new Realm('other')));
                }];
            }
        };

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('A consequence of "acme.badge.scan" is in another realm.');

        $this->operations(handlers: [$badges])->run(new ScanBadge($this->realm, 'K7'), new Operator(), new Origin(OriginKind::Console));
    }

    public function testOnlyOperationsIssuesPermits(): void
    {
        self::assertTrue(new \ReflectionMethod(Permit::class, '__construct')->isPrivate());
        self::assertSame([], new \ReflectionClass(Permit::class)->getMethods(\ReflectionMethod::IS_STATIC));
    }

    public function testRefusesAnOperationNothingRuns(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('Nothing runs the operation "acme.badge.scan".');

        $this->operations(handlers: [])->run(new ScanBadge($this->realm, 'K7'), new Operator(), new Origin(OriginKind::Console));
    }

    public function testRefusesTwoHandlersForOneCommand(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage(\sprintf('Two handlers run %s.', IssueBadge::class));

        $this->operations(handlers: [new Badges(), new Badges()]);
    }

    private function permit(?ScanBadge $command = null): Permit
    {
        $command ??= new ScanBadge($this->realm, 'K1');
        $this->operations()->run($command, new Operator(), new Origin(OriginKind::Console));

        return $this->badges->permits[0];
    }

    /**
     * @param Command<mixed> $command
     */
    private function refused(Command $command, Actor $actor, Origin $origin, ?AbilityPolicy $policy = null): Decision
    {
        try {
            $this->operations($policy)->run($command, $actor, $origin);
        } catch (NotPermitted $refused) {
            // A refusal changes nothing: the chain has no entry, its decision record is its trace (D-42).
            self::assertSame([], array_filter($this->entries(), static fn (LogEntry $entry): bool => $refused->operation === $entry->action), 'The refusal is in the chain.');
            self::assertSame($refused->operation, $this->record($refused->decision)->name);
            self::assertSame([], $this->badges->permits, 'Nothing ran.');

            return $refused->decision;
        }

        self::fail('It was permitted.');
    }

    /**
     * @param list<OperationHandler>|null $handlers
     */
    private function operations(?AbilityPolicy $policy = null, ?array $handlers = null): Operations
    {
        $catalogue = new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]);
        $log = new SecurityLog($this->log, new InMemoryTransactions());
        $authorization = Evaluator::builtIn(new ValidAuthority(), $policy ?? new NoAbilityPolicy(), new SelfService(), decisions: new DecisionLog($this->decisions), actors: [new TrustedActors()]);

        return new Operations($catalogue, $authorization, $log, $handlers ?? [$this->badges]);
    }

    /**
     * @return list<LogEntry>
     */
    private function entries(): array
    {
        return iterator_to_array($this->log->entries($this->realm, 1), false);
    }
}
