<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\Explanation;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\OfflineDecider;
use YorvoID\Core\Authorization\PolicyElement;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\Reason;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Authorization;
use YorvoID\Core\Operation\AuthorizedBy;
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
use YorvoID\Core\Storage\StoreUnreachable;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Authorization\Fixtures\StubForbid;
use YorvoID\Tests\Core\Authorization\Fixtures\StubOfflineDecider;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Badges;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Doors\Doors;
use YorvoID\Tests\Core\Operation\Fixtures\Doors\LogDoor;
use YorvoID\Tests\Core\Operation\Fixtures\Doors\OpenDoor;

/**
 * Permit-offline (XP-03, P1′): only from an Indeterminate whose every reason is store_unreachable, only
 * with an OfflineDecider, only for an operation that accepts it, and never a Permit.
 */
final class OfflineTest extends TestCase
{
    public function testAnUnreachableStoreIsDecidedOfflineForAnOperationThatAcceptsIt(): void
    {
        $decider = self::decider(Truth::holds('The node\'s reserved budget covers it.'));
        $decision = self::decide([new StubPermit('door', new StoreUnreachable('The link home is down.'))], $decider);

        self::assertSame(Verdict::PermitOffline, $decision->verdict);
        self::assertSame(AuthorizedBy::Ability, $decision->basis);
        self::assertFalse($decision->permits(), 'Permit-offline isn\'t a Permit.');
        self::assertSame('Permitted offline: The node\'s reserved budget covers it.', $decision->reason);
        self::assertSame(['door', 'authority', 'offline'], array_map(static fn (Reason $reason): string => $reason->source, $decision->reasons));
        self::assertSame(1, $decider->asked);
        $explanation = Explanation::of($decision);
        self::assertSame([Precedence::Offline, null], [$explanation->precedence, $explanation->precedence->row()]);
        self::assertSame(['offline'], array_map(static fn (Reason $reason): string => $reason->source, $explanation->elements), 'The decider is behind it.');

        // The authority unreachable, a permit that holds: the same.
        $decision = self::decide([new StubPermit('door', Truth::holds('yes'))], $decider, Truth::unknown(IndeterminateReason::StoreUnreachable, 'down'));
        self::assertSame([Verdict::PermitOffline, AuthorizedBy::Ability], [$decision->verdict, $decision->basis]);
    }

    public function testTheBasisIsTheFirstWayTheOperationNamesThatCouldHaveApplied(): void
    {
        $elements = [new StubPermit('by-ability', new StoreUnreachable('down'), AuthorizedBy::Ability), new StubPermit('by-self', new StoreUnreachable('down'), AuthorizedBy::Self)];

        foreach ([[AuthorizedBy::Ability, AuthorizedBy::Self], [AuthorizedBy::Self, AuthorizedBy::Ability]] as $authorizedBy) {
            $operation = new OperationDescription('acme.door.open', 'Open a door', $authorizedBy, 'acme.doors.open', null, OpenDoor::class, [], [], true);
            $decision = new Evaluator($elements, new StubAuthority(Truth::holds('valid')), offline: self::decider(Truth::holds('yes')))->decide($operation, new OpenDoor(new Realm('acme')), self::actor(), new Origin(OriginKind::Web));

            self::assertSame($authorizedBy[0], $decision->basis);
        }

        self::assertFalse(new OperationDescription('acme.door.open', 'Open a door', [AuthorizedBy::Ability], 'acme.doors.open', null, OpenDoor::class, [])->acceptsOffline, 'No operation accepts it by default.');
    }

    public function testTheDeciderIsAskedOnlyWhenEveryReasonIsAnUnreachableStore(): void
    {
        $decider = self::decider(Truth::holds('yes'));

        foreach ([
            'another reason' => self::decide([new StubPermit('door', new StoreUnreachable('down')), new StubPermit('other', Truth::unknown(IndeterminateReason::MissingData, 'no data'))], $decider),
            'an unknown forbid, even unreachable' => self::decide([new StubPermit('door', Truth::holds('yes')), new StubForbid('curfew', new StoreUnreachable('down'))], $decider),
            'no permit that could apply' => self::decide([new StubPermit('door', Truth::fails('no'))], $decider, Truth::unknown(IndeterminateReason::StoreUnreachable, 'down')),
            'a permit for another way to authorize' => self::decide([new StubPermit('door', Truth::holds('yes'), AuthorizedBy::Operator)], $decider, Truth::unknown(IndeterminateReason::StoreUnreachable, 'down')),
            'an operation that doesn\'t accept it' => self::decide([new StubPermit('door', new StoreUnreachable('down'))], $decider, acceptsOffline: false),
        ] as $case => $decision) {
            self::assertSame(Verdict::Indeterminate, $decision->verdict, $case);
        }

        self::assertSame(0, $decider->asked);
        self::assertSame(Verdict::Indeterminate, self::decide([new StubPermit('door', new StoreUnreachable('down'))])->verdict, 'Without a decider.');
    }

    public function testWhatTheDeciderDoesntAllowStaysIndeterminate(): void
    {
        foreach ([Truth::fails('Over the node\'s budget.'), Truth::unknown(IndeterminateReason::MissingData, '?'), new \RuntimeException('broken')] as $answer) {
            $decision = self::decide([new StubPermit('door', new StoreUnreachable('down'))], self::decider($answer));

            self::assertSame([Verdict::Indeterminate, [IndeterminateReason::StoreUnreachable]], [$decision->verdict, $decision->indeterminate]);
        }
    }

    public function testAnOfflineDecisionIsLoggedAsAWarning(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                \assert(\is_string($level) && \is_string($context['operation']) && \is_string($context['reason']));
                $this->messages[] = $level.': '.strtr((string) $message, ['{operation}' => $context['operation'], '{reason}' => $context['reason']]);
            }
        };
        $evaluator = new Evaluator([new StubPermit('door', new StoreUnreachable('down'))], new StubAuthority(Truth::holds('valid')), logger: $logger, offline: self::decider(Truth::holds('budget left')));

        $evaluator->decide(self::operation(), new OpenDoor(new Realm('acme')), self::actor(), new Origin(OriginKind::Web));

        self::assertSame([
            'warning: YorvoID couldn\'t decide whether to run "acme.door.open": A permit couldn\'t be evaluated: down',
            'warning: YorvoID permitted "acme.door.open" offline: Permitted offline: budget left',
        ], $logger->messages);
    }

    public function testOperationsRunsAnOperationThatAcceptsItOnPermitOffline(): void
    {
        $doors = new Doors();
        $log = new InMemorySecurityLogStore();
        $evaluator = new Evaluator([new StubPermit('door', new StoreUnreachable('down'))], new StubAuthority(Truth::holds('valid')), offline: self::decider(Truth::holds('budget left')));
        $operations = new Operations(new OperationCatalogue([OpenDoor::class, LogDoor::class]), $evaluator, new SecurityLog($log, new InMemoryTransactions()), [$doors]);
        $actor = self::actor();

        self::assertSame('opened', $operations->run(new OpenDoor(new Realm('acme')), $actor, new Origin(OriginKind::Web)));
        self::assertTrue($doors->permits[0]->offline);
        self::assertSame(AuthorizedBy::Ability, $doors->permits[0]->basis);
        self::assertTrue($doors->permits[1]->offline, 'Its consequence was offline too.');
        [$entry] = iterator_to_array($log->entries(new Realm('acme'), 1), false);
        self::assertSame(['yorvoid.decision.offline', Outcome::Success, 'operation', 'acme.door.open', ['reason' => 'Permitted offline: budget left']], [$entry->action, $entry->outcome, $entry->subjectType, $entry->subjectId, array_diff_key($entry->details, ['decision' => true])]);
        self::assertEquals($actor->principalId, $entry->actor);
    }

    public function testOperationsRunsNothingElseOnPermitOffline(): void
    {
        $badges = new Badges();
        $offline = new readonly class implements Authorization {
            public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
            {
                return new Decision(Verdict::PermitOffline, AuthorizedBy::Self, 'Permitted offline: whatever it is');
            }
        };
        $operations = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), $offline, new SecurityLog(new InMemorySecurityLogStore(), new InMemoryTransactions()), [$badges]);
        $actor = self::actor();

        try {
            $operations->run(new IssueBadge(new Realm('acme'), $actor->principalId ?? throw new \LogicException()), $actor, new Origin(OriginKind::Web));
            self::fail('An operation that doesn\'t accept it ran on Permit-offline.');
        } catch (NotPermitted $refused) {
            self::assertSame(Verdict::PermitOffline, $refused->decision->verdict);
            self::assertSame([], $badges->permits);
        }

        $scanner = new Badges();
        $evaluated = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), Evaluator::builtIn(new StubAuthority(Truth::holds('valid'))), new SecurityLog(new InMemorySecurityLogStore(), new InMemoryTransactions()), [$scanner]);
        self::assertSame('scanned', $evaluated->run(new ScanBadge(new Realm('acme'), 'K7'), $actor, new Origin(OriginKind::Web)));
        self::assertFalse($scanner->permits[0]->offline, 'A Permit\'s permit isn\'t offline.');
    }

    public function testOfflineIsDecidedWhenYorvoIDIsBuilt(): void
    {
        $log = new SecurityLog(new InMemorySecurityLogStore(), new InMemoryTransactions());

        try {
            new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), new Evaluator([], new StubAuthority(Truth::holds('valid')), offline: self::decider(Truth::holds('yes'))), $log, [new Badges()]);
            self::fail('A decider was registered with no operation to decide for.');
        } catch (IntegratorError $error) {
            self::assertSame('An OfflineDecider is registered, but no operation accepts Permit-offline.', $error->problem);
        }

        try {
            new Operations(new OperationCatalogue([OpenDoor::class, LogDoor::class]), new Evaluator([], new StubAuthority(Truth::holds('valid'))), $log, [new Doors()]);
            self::fail('An operation accepts Permit-offline that nothing decides.');
        } catch (IntegratorError $error) {
            self::assertSame('"acme.door.open" accepts Permit-offline, but nothing decides offline.', $error->problem);
        }

        try {
            new Operations(new OperationCatalogue([OpenDoor::class, LogDoor::class]), new readonly class implements Authorization {
                public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
                {
                    return Decision::deny('no');
                }
            }, $log, [new Doors()]);
            self::fail('An Authorization of one\'s own was taken to decide offline.');
        } catch (IntegratorError $error) {
            self::assertSame('"acme.door.open" accepts Permit-offline, but nothing decides offline.', $error->problem);
        }
    }

    /**
     * @param list<PolicyElement> $elements
     */
    private static function decide(array $elements, ?OfflineDecider $offline = null, ?Truth $authority = null, bool $acceptsOffline = true): Decision
    {
        $evaluator = new Evaluator($elements, new StubAuthority($authority ?? Truth::holds('valid')), offline: $offline);

        return $evaluator->decide(self::operation($acceptsOffline), new OpenDoor(new Realm('acme')), self::actor(), new Origin(OriginKind::Web));
    }

    private static function operation(bool $acceptsOffline = true): OperationDescription
    {
        return new OperationDescription('acme.door.open', 'Open a door', [AuthorizedBy::Ability], 'acme.doors.open', null, OpenDoor::class, [], ['acme.door.log'], $acceptsOffline);
    }

    private static function actor(): Member
    {
        return new Member(new Realm('acme'), new PrincipalId(new UuidV7()));
    }

    private static function decider(Truth|\Throwable $answer): StubOfflineDecider
    {
        return new StubOfflineDecider($answer);
    }
}
