<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\AppliesToConsequences;
use YorvoID\Core\Authorization\ChecksCatalogue;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\RequiresForbids;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Authorization;
use YorvoID\Core\Operation\AuthorizationDecorator;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Core\Authorization\Fixtures\StubOfflineDecider;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Badges;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\SelfService;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ValidAuthority;
use YorvoID\Tests\Core\Operation\Fixtures\Doors\Doors;
use YorvoID\Tests\Core\Operation\Fixtures\Doors\LogDoor;
use YorvoID\Tests\Core\Operation\Fixtures\Doors\OpenDoor;
use YorvoID\Tests\Support\TrustedActors;

/**
 * The decision point asks only its port (D-50): an Authorization around the Evaluator keeps every
 * check Operations makes when it's built, sees each consequence, and can refuse but never permit.
 */
final class AuthorizationDecoratorTest extends TestCase
{
    private Realm $realm;

    private PrincipalId $ana;

    private InMemoryDecisionLogStore $decisions;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->ana = new PrincipalId(Ids::next());
        $this->decisions = new InMemoryDecisionLogStore();
    }

    public function testADecoratorKeepsEveryCheckOperationsMakesWhenItsBuilt(): void
    {
        $log = new SecurityLog(new InMemorySecurityLogStore(), new InMemoryTransactions());
        $doors = new OperationCatalogue([OpenDoor::class, LogDoor::class]);

        // Offline: a decider inside the decorated evaluator is still seen.
        new Operations($doors, new Passing($this->evaluator(offline: new StubOfflineDecider(Truth::holds('yes')))), $log, [new Doors()]);

        try {
            new Operations($doors, new Passing($this->evaluator()), $log, [new Doors()]);
            self::fail('An operation accepts Permit-offline that nothing decides.');
        } catch (IntegratorError $error) {
            self::assertSame('"acme.door.open" accepts Permit-offline, but nothing decides offline.', $error->problem);
        }

        // The catalogue: an element that checks it still does.
        try {
            new Operations($this->catalogue(), new Passing($this->evaluator([new RefusingCatalogue()])), $log, [new Badges()]);
            self::fail('The catalogue wasn\'t checked.');
        } catch (IntegratorError $error) {
            self::assertSame('acme.badge.issue doesn\'t fit.', $error->problem);
        }

        // The forbids a handler needs: missing, it doesn't boot; there, it does.
        try {
            new Operations($this->catalogue(), new Passing($this->evaluator()), $log, [new NeedingForbids()]);
            self::fail('A handler\'s missing forbid wasn\'t found.');
        } catch (IntegratorError $error) {
            self::assertStringContainsString('needs the forbid "acme.no_badges_at_night"', $error->problem);
        }

        new Operations($this->catalogue(), new Passing($this->evaluator([new NoBadgesAtNight(holds: false)])), $log, [new NeedingForbids()]);
        $this->addToAssertionCount(1);
    }

    public function testADecoratorSeesEachConsequence(): void
    {
        $passing = new Passing($this->evaluator());

        self::assertSame('recorded', $this->operations($passing)->run(new IssueBadge($this->realm, $this->ana), $this->ana(), self::web()));

        self::assertSame(['acme.badge.issue'], $passing->decided);
        self::assertSame(['acme.badge.record'], $passing->consequences);
    }

    public function testADecoratorThatCantDecideAConsequenceFailsTheOperation(): void
    {
        $badges = new Badges();

        try {
            $this->operations(new UndecidedConsequences($this->evaluator()), $badges)->run(new IssueBadge($this->realm, $this->ana), $this->ana(), self::web());
            self::fail('A consequence the path couldn\'t decide ran.');
        } catch (NotPermitted $refused) {
            self::assertSame(['acme.badge.record', Verdict::Indeterminate], [$refused->operation, $refused->decision->verdict]);
        }

        self::assertCount(1, $badges->permits);
    }

    public function testADecoratorThatRefusesAConsequenceFailsTheOperation(): void
    {
        $badges = new Badges();
        $refusing = new RefusingConsequences($this->evaluator());

        try {
            $this->operations($refusing, $badges)->run(new IssueBadge($this->realm, $this->ana), $this->ana(), self::web());
            self::fail('A consequence the path refused ran.');
        } catch (NotPermitted $refused) {
            self::assertSame('acme.badge.record', $refused->operation);
            self::assertSame(Verdict::Deny, $refused->decision->verdict);
        }

        self::assertCount(1, $badges->permits, 'The operation ran up to its consequence, and the consequence didn\'t.');
        self::assertSame('acme.badge.issue', $badges->permits[0]->operation);
    }

    /**
     * Only the Evaluator makes a decision that permits: a decorator can't build its own, so it can't
     * turn into a permit what nothing recorded.
     */
    public function testNothingButTheEvaluatorMakesAPermit(): void
    {
        $decision = new \ReflectionClass(Decision::class);

        self::assertTrue($decision->getConstructor()?->isPrivate());
        self::assertFalse($decision->hasMethod('permit'));
        self::assertSame(['deny', 'indeterminate'], array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $decision->getMethods(\ReflectionMethod::IS_STATIC)));

        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/private .*Decision::__construct/');
        // @phpstan-ignore new.privateConstructor (what it shows)
        $make = static fn (): Decision => new Decision(Verdict::Permit, \YorvoID\Core\Operation\AuthorizedBy::Ability, 'Mine.');
        $make();
    }

    /**
     * A decorator that caches decisions gives back a permit made for another request: refused, for
     * another command, another actor, or the same decision a second time.
     */
    public function testAPermitMadeForAnotherRequestIsRefused(): void
    {
        $caching = new Caching($this->evaluator());
        $operations = $this->operations($caching);
        $ana = $this->ana();

        self::assertSame('recorded', $operations->run(new IssueBadge($this->realm, $this->ana), $ana, self::web()));
        self::assertNotNull($caching->command);
        self::assertNotNull($caching->actor);

        $replays = [
            'another command' => [new IssueBadge($this->realm, $this->ana), $ana, 'made for another command or actor'],
            'another actor' => [$caching->command, new Member($this->realm, $this->ana), 'made for another command or actor'],
            'the same request again' => [$caching->command, $caching->actor, 'a permit was issued on already'],
        ];

        foreach ($replays as $what => [$command, $actor, $problem]) {
            try {
                $operations->run($command, $actor, self::web());
                self::fail(\sprintf('A cached permit ran for %s.', $what));
            } catch (IntegratorError $error) {
                self::assertStringContainsString($problem, $error->problem, $what);
            }
        }
    }

    public function testAConsequenceAnsweredWithItsParentsPermitIsRefused(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The authorization permitted "acme.badge.record" with a decision made for another command or actor.');

        $this->operations(new ParentForConsequences($this->evaluator()))->run(new IssueBadge($this->realm, $this->ana), $this->ana(), self::web());
    }

    /**
     * Only YorvoID identifies a permit and gives it its record's hash: something else putting another
     * decision's ID or hash on one would replay it.
     */
    public function testNothingButYorvoIDIdentifiesOrRecordsAPermit(): void
    {
        $passing = new Passing($this->evaluator());
        $this->operations($passing)->run(new IssueBadge($this->realm, $this->ana), $this->ana(), self::web());
        $permit = $passing->last;
        self::assertNotNull($permit);

        foreach (['identified' => static fn (): Decision => $permit->identified('another', new \DateTimeImmutable(), []), 'recorded' => static fn (): Decision => $permit->recorded('another')] as $what => $replay) {
            try {
                $replay();
                self::fail(\sprintf('A permit was %s.', $what));
            } catch (IntegratorError $error) {
                self::assertSame(\sprintf('A decision that permits was %s by something other than YorvoID.', $what), $error->problem);
            }
        }

        // A refusal may be, e.g. for a page's sample.
        self::assertSame('d-1', Decision::deny('No.')->identified('d-1', new \DateTimeImmutable(), [])->recorded('h')->id);

        self::assertFalse(new \ReflectionClass(Decision::class)->isCloneable(), 'A copy would be a second decision no permit was issued on.');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A Decision isn\'t unserialized');
        unserialize(serialize($permit));
    }

    public function testAForbidAppliesToConsequencesOnlyWhenItSaysSo(): void
    {
        // A forbid of its own: evaluated only when the operation is asked for.
        $plain = $this->operations($this->evaluator([new NoRecordsAtAll()]));
        self::assertSame('recorded', $plain->run(new IssueBadge($this->realm, $this->ana), $this->ana(), self::web()));

        // One that applies to consequences refuses the consequence, and the operation fails with it.
        $badges = new Badges();

        try {
            $this->operations($this->evaluator([new NoBadgesAtNight(holds: true)]), $badges)->run(new IssueBadge($this->realm, $this->ana), $this->ana(), self::web());
            self::fail('A forbid that applies to consequences didn\'t.');
        } catch (NotPermitted $refused) {
            self::assertSame(['acme.badge.record', Precedence::ForbidHolds], [$refused->operation, $refused->decision->precedence]);
        }

        // A refusal is written apart from the caller's transaction, so the store lists it as written.
        $records = array_map(fn (array $written): ?\YorvoID\Core\Authorization\DecisionLog\DecisionRecord => $this->decisions->find($this->realm, $written[0]), $this->decisions->written($this->realm, 0, \PHP_INT_MAX));
        $consequence = array_values(array_filter($records, static fn (?\YorvoID\Core\Authorization\DecisionLog\DecisionRecord $record): bool => 'acme.badge.record' === $record?->name && 'deny' === $record->verdict));
        self::assertCount(1, $consequence, 'The refused consequence is recorded.');
        self::assertSame('deny', $consequence[0]->verdict);
        self::assertNotNull($consequence[0]->parent);
    }

    /**
     * A consequence of what was permitted offline runs offline, when the path lets the parent's decision
     * hold as it is.
     */
    public function testAConsequenceOfAnOfflinePermitRunsOffline(): void
    {
        $doors = new Doors();
        $offline = new readonly class implements Authorization {
            use \YorvoID\Tests\Support\DecidesAlone;

            public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
            {
                return \YorvoID\Tests\Support\Decisions::bound(\YorvoID\Tests\Support\Decisions::of(Verdict::PermitOffline, \YorvoID\Core\Operation\AuthorizedBy::Ability, 'Offline.', id: (string) new UuidV7()), $command, $actor);
            }

            public function decidesOffline(): bool
            {
                return true;
            }
        };
        $operations = new Operations(new OperationCatalogue([OpenDoor::class, LogDoor::class]), $offline, new SecurityLog(new InMemorySecurityLogStore(), new InMemoryTransactions()), [$doors]);

        self::assertSame('opened', $operations->run(new OpenDoor($this->realm), $this->ana(), self::web()));
        self::assertSame([true, true], [$doors->permits[0]->offline, $doors->permits[1]->offline]);
    }

    public function testOnlyAPermitHasConsequences(): void
    {
        $evaluator = $this->evaluator();
        $operation = OperationCatalogue::describe(RecordBadge::class);
        $refusals = [
            Decision::deny('No.')->identified('d-1', new \DateTimeImmutable(), []),
            Decision::indeterminate([\YorvoID\Core\Authorization\IndeterminateReason::EvaluationError], 'Unknown.')->identified('d-2', new \DateTimeImmutable(), []),
            \YorvoID\Tests\Support\Decisions::permit(\YorvoID\Core\Operation\AuthorizedBy::Self, 'Never identified.'),
        ];

        foreach ($refusals as $parent) {
            try {
                $evaluator->decideConsequence($parent, $operation, new RecordBadge($this->realm), $this->ana(), self::web());
                self::fail(\sprintf('A consequence of "%s" was decided.', $parent->reason));
            } catch (IntegratorError $error) {
                self::assertSame('"acme.badge.record" was asked as a consequence of a decision that isn\'t a permit.', $error->problem);
            }
        }
    }

    /**
     * Every forbid that applies to consequences is asked, whatever comes before it, and only about what it
     * matches.
     */
    public function testEachForbidThatAppliesToConsequencesIsAskedAboutWhatItMatches(): void
    {
        $issue = new IssueBadge($this->realm, $this->ana);

        self::assertSame('recorded', $this->operations($this->evaluator([new NoRecordsAtAll(), new NotAboutRecords()]))->run($issue, $this->ana(), self::web()), 'One that doesn\'t match isn\'t evaluated.');

        $this->expectException(NotPermitted::class);
        $this->operations($this->evaluator([new NoRecordsAtAll(), new NotAboutRecords(), new NoBadgesAtNight(holds: true)]))->run($issue, $this->ana(), self::web());
    }

    /**
     * @param list<\YorvoID\Core\Authorization\PolicyElement> $elements
     */
    private function evaluator(array $elements = [], ?StubOfflineDecider $offline = null): Evaluator
    {
        return Evaluator::builtIn(new ValidAuthority(), selfService: new SelfService(), elements: $elements, offline: $offline, decisions: new DecisionLog($this->decisions), actors: [new TrustedActors()]);
    }

    private function catalogue(): OperationCatalogue
    {
        return new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]);
    }

    private function operations(Authorization $authorization, Badges $badges = new Badges()): Operations
    {
        return new Operations($this->catalogue(), $authorization, new SecurityLog(new InMemorySecurityLogStore(), new InMemoryTransactions()), [$badges]);
    }

    private function ana(): Member
    {
        return new Member($this->realm, $this->ana);
    }

    private static function web(): Origin
    {
        return new Origin(OriginKind::Web);
    }
}

/**
 * Passes everything on, and notes what it was asked.
 */
final class Passing extends AuthorizationDecorator
{
    /** @var list<string> */
    public array $decided = [];

    /** @var list<string> */
    public array $consequences = [];

    public ?Decision $last = null;

    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        $this->decided[] = $operation->name;

        return $this->last = parent::decide($operation, $command, $actor, $origin);
    }

    public function decideConsequence(Decision $parent, OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        $this->consequences[] = $operation->name;

        return parent::decideConsequence($parent, $operation, $command, $actor, $origin);
    }
}

/**
 * Caches the first decision it's given, and gives it back for everything after.
 */
final class Caching extends AuthorizationDecorator
{
    public ?Decision $cached = null;

    /** @var Command<mixed>|null */
    public ?Command $command = null;

    public ?Actor $actor = null;

    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        if (null === $this->cached) {
            [$this->command, $this->actor] = [$command, $actor];
        }

        return $this->cached ??= parent::decide($operation, $command, $actor, $origin);
    }
}

/**
 * Answers a consequence with the permit of the operation that caused it.
 */
final class ParentForConsequences extends AuthorizationDecorator
{
    public function decideConsequence(Decision $parent, OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        return $parent;
    }
}

/**
 * Narrows: no consequence runs.
 */
final class RefusingConsequences extends AuthorizationDecorator
{
    public function decideConsequence(Decision $parent, OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        return Decision::deny('No consequences today.');
    }
}

/**
 * Can't decide any consequence.
 */
final class UndecidedConsequences extends AuthorizationDecorator
{
    public function decideConsequence(Decision $parent, OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        return Decision::indeterminate([\YorvoID\Core\Authorization\IndeterminateReason::StoreUnreachable], 'The register is down.');
    }
}

final readonly class RefusingCatalogue implements ForbidElement, ChecksCatalogue
{
    public function name(): string
    {
        return 'acme.refusing_catalogue';
    }

    public function matches(Request $request): bool
    {
        return false;
    }

    public function evaluate(Request $request): Truth
    {
        return Truth::fails('Never.');
    }

    public function checkCatalogue(OperationCatalogue $catalogue): void
    {
        throw new IntegratorError('acme.badge.issue doesn\'t fit.', 'Fix it.', 'concepts/authorization.md');
    }
}

final readonly class NoBadgesAtNight implements AppliesToConsequences
{
    public function __construct(
        private bool $holds,
    ) {
    }

    public function name(): string
    {
        return 'acme.no_badges_at_night';
    }

    public function matches(Request $request): bool
    {
        return 'acme.badge.record' === $request->name();
    }

    public function evaluate(Request $request): Truth
    {
        return $this->holds ? Truth::holds('It\'s night.') : Truth::fails('It\'s day.');
    }
}

/**
 * Applies to consequences, but to none of these: if it were evaluated, it would refuse.
 */
final readonly class NotAboutRecords implements AppliesToConsequences
{
    public function name(): string
    {
        return 'acme.not_about_records';
    }

    public function matches(Request $request): bool
    {
        return false;
    }

    public function evaluate(Request $request): Truth
    {
        return Truth::holds('Refused.');
    }
}

final readonly class NoRecordsAtAll implements ForbidElement
{
    public function name(): string
    {
        // Before the others by name, as the evaluator keeps them.
        return 'acme.any_records';
    }

    public function matches(Request $request): bool
    {
        return 'acme.badge.record' === $request->name();
    }

    public function evaluate(Request $request): Truth
    {
        return Truth::holds('No records.');
    }
}

/**
 * A handler that needs a forbid, and runs nothing here.
 */
final readonly class NeedingForbids implements \YorvoID\Core\Operation\OperationHandler, RequiresForbids
{
    public function operations(): array
    {
        return [];
    }

    public function requiredForbids(): array
    {
        return ['acme.no_badges_at_night' => 'Add the forbid.'];
    }
}
