<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\AbilityPolicy;
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

final class OperationsTest extends TestCase
{
    private Realm $realm;

    private PrincipalId $ana;

    private InMemorySecurityLogStore $log;

    private Badges $badges;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->ana = new PrincipalId(new UuidV7());
        $this->log = new InMemorySecurityLogStore();
        $this->badges = new Badges();
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
        self::assertSame($permit->decisionId, $consequence->decisionId);
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
        $this->operations()->run(new IssueBadge($this->realm, $this->ana), new Operator(), new Origin($kind));

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
            $this->operations()->run(new IssueBadge($this->realm, $this->ana), new Operator(), new Origin($kind));
        } catch (NotPermitted $refused) {
            self::assertSame('Not permitted to run "acme.badge.issue".', $refused->getMessage());
        }

        $this->log = new InMemorySecurityLogStore();
        $decision = $this->refused(new IssueBadge($this->realm, $this->ana), new Operator(), new Origin($kind, 'badges', 'request-1'));

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame([], $this->badges->permits);

        [$entry] = $this->entries();
        self::assertSame('acme.badge.issue', $entry->action);
        self::assertSame(Outcome::Denied, $entry->outcome);
        self::assertNull($entry->actor);
        self::assertSame('request-1', $entry->origin->correlationId);
        self::assertSame(['reason' => \sprintf('The host\'s operator acts from the command line or as the system, not from "%s".', $kind->value), 'verdict' => 'deny'], array_diff_key($entry->details, ['decision' => true]));
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
        $operations = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), Evaluator::builtIn(new ValidAuthority()), new SecurityLog($this->log, new InMemoryTransactions()), [$this->badges]);

        try {
            $operations->run(new IssueBadge($this->realm, $this->ana), new Member($this->realm, $this->ana), new Origin(OriginKind::Web));
            self::fail('It was permitted.');
        } catch (NotPermitted $refused) {
            self::assertSame(Verdict::Indeterminate, $refused->decision->verdict);
            self::assertSame('A permit couldn\'t be evaluated: No self-service policy says what "acme.badge.issue" needs.', $refused->decision->reason);
            self::assertSame([IndeterminateReason::MissingData], $refused->decision->indeterminate);
        }
    }

    public function testWithoutAnAbilityPolicyNobodyElseMayRunIt(): void
    {
        $decision = $this->refused(new IssueBadge($this->realm, $this->ana), new Member($this->realm, new PrincipalId(new UuidV7())), new Origin(OriginKind::Web));

        self::assertSame(Verdict::Indeterminate, $decision->verdict);
        self::assertNull($decision->basis);
        self::assertSame('A permit couldn\'t be evaluated: The ability policy can\'t tell who holds "acme.badges.issue".', $decision->reason);
        self::assertSame(['indeterminate' => ['missing_data'], 'reason' => 'A permit couldn\'t be evaluated: The ability policy can\'t tell who holds "acme.badges.issue".', 'verdict' => 'indeterminate'], array_diff_key($this->entries()[0]->details, ['decision' => true]));
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
        $entry = $this->entries()[0];
        self::assertTrue($boss->equals($entry->actor ?? throw new \LogicException()), 'The viewer tried it, not the target.');
        self::assertSame((string) $this->ana, $entry->details['viewing_as'] ?? null);
        self::assertArrayNotHasKey('viewing_as', $this->refusedEntry(new Member($this->realm, $boss)), 'Only viewing as someone.');
        self::assertArrayNotHasKey('viewing_as', $this->refusedEntry(new ViewingAs(new Member($this->realm, $boss), new Anonymous())), 'Viewing as nobody in particular.');
    }

    public function testAnAuthorizationThatPermitsAViewAsIsAMistake(): void
    {
        $permitting = new readonly class implements Authorization {
            public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
            {
                return Decision::permit(AuthorizedBy::Ability, 'Anything goes.');
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
     * @return array<mixed> The details of its refusal's entry
     */
    private function refusedEntry(Actor $actor): array
    {
        $this->refused(new IssueBadge($this->realm, $this->ana), $actor, new Origin(OriginKind::Web), new Policy(false));
        $entries = $this->entries();

        return end($entries)->details ?? [];
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
        $served = new Operations($catalogue, Evaluator::builtIn(new ValidAuthority(), commandLine: false), $log, [$this->badges]);

        foreach ([OriginKind::Console, OriginKind::Job] as $kind) {
            try {
                $served->run(new IssueBadge($this->realm, $this->ana), new Operator(), new Origin($kind));
                self::fail(\sprintf('A web server ran an operation as the %s.', $kind->value));
            } catch (NotPermitted) {
            }
        }

        $served->run(new IssueBadge($this->realm, $this->ana), new Operator(), new Origin(OriginKind::System));
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
            $entries = $this->entries();
            self::assertSame($refused->operation, end($entries)->action ?? null);
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
        $authorization = Evaluator::builtIn(new ValidAuthority(), $policy ?? new NoAbilityPolicy(), new SelfService());

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
