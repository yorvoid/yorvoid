<?php

declare(strict_types=1);

namespace YorvoID\Tests\Properties;

use Innmind\BlackBox\PHPUnit\BlackBox;
use Innmind\BlackBox\Set;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\AuthorityCheck;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
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
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\SelfServicePolicy;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Badges;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;

/**
 * The decision point (D-38): only a Permit runs anything, and the built-in decisions permit exactly what
 * their rules allow; Indeterminate never permits (P1).
 */
final class DecisionPointPropertiesTest extends TestCase
{
    use BlackBox;

    public function testOnlyAPermitRunsTheHandler(): void
    {
        self::forAll(
            Set::of(...Verdict::cases()),
            Set::of(null, ...AuthorizedBy::cases()),
            Set::of(...OriginKind::cases()),
        )->then(static function (Verdict $verdict, ?AuthorizedBy $basis, OriginKind $kind): void {
            try {
                $decision = new Decision($verdict, $basis, 'decided', indeterminate: Verdict::Indeterminate === $verdict ? [IndeterminateReason::MissingData] : []);
            } catch (IntegratorError) {
                // A permit without a basis, or a refusal with one: no Authorization can give it.
                self::assertSame(null === $basis, \in_array($verdict, [Verdict::Permit, Verdict::PermitOffline], true));

                return;
            }

            $authorization = new readonly class($decision) implements Authorization {
                public function __construct(private Decision $decision)
                {
                }

                public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
                {
                    return $this->decision;
                }
            };
            $realm = new Realm('acme');
            $store = new InMemorySecurityLogStore();
            $badges = new Badges();
            $operations = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), $authorization, new SecurityLog($store, new InMemoryTransactions()), [$badges]);
            $permits = Verdict::Permit === $verdict && null !== $basis;

            try {
                $operations->run(new ScanBadge($realm, 'K7'), new Anonymous(), new Origin($kind));
                self::assertTrue($permits, 'It ran without a Permit.');
                self::assertCount(1, $badges->permits);
                self::assertSame($basis, $badges->permits[0]->basis);
            } catch (NotPermitted $refused) {
                self::assertFalse($permits, 'A Permit was refused.');
                self::assertSame([], $badges->permits, 'A handler ran without a Permit.');
                self::assertSame($decision, $refused->decision);
                $entries = iterator_to_array($store->entries($realm, 1), false);
                self::assertCount(1, $entries);
                self::assertSame(Outcome::Denied, $entries[0]->outcome);
            }
        });
    }

    public function testTheBuiltInDecisionsPermitExactlyWhatTheirRulesAllow(): void
    {
        self::forAll(
            Set::sequence(Set::of(...AuthorizedBy::cases()))->between(1, 4),
            Set::of('operator', 'anonymous', 'own', 'other', 'elsewhere', 'nowhere'),
            Set::of(...OriginKind::cases()),
            Set::of(true, false, null),
            Set::of(...TruthValue::cases()),
            Set::of(true, false),
        )->then(static function (array $rules, string $who, OriginKind $kind, ?bool $holds, TruthValue $selfService, bool $commandLine): void {
            /** @var list<AuthorizedBy> $rules */
            $realm = new Realm('acme');
            $ana = new PrincipalId(new UuidV7());
            $actor = match ($who) {
                'operator' => new Operator(),
                'anonymous' => new Anonymous(),
                'own' => new Member($realm, $ana),
                'other' => new Member($realm, new PrincipalId(new UuidV7())),
                'elsewhere' => new Member(new Realm('globex'), $ana),
                'nowhere' => new Member(null, $ana),
                default => throw new \LogicException($who),
            };
            $rules = array_values(array_unique($rules, \SORT_REGULAR));
            \assert([] !== $rules);
            $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', $rules, \in_array(AuthorizedBy::Ability, $rules, true) ? 'acme.badges.issue' : null, null, IssueBadge::class, []);
            $authorization = Evaluator::builtIn(self::validAuthority(), self::abilities($holds), self::selfService($selfService), commandLine: $commandLine);

            $decision = $authorization->decide($operation, new IssueBadge($realm, $ana), $actor, new Origin($kind));

            $inRealm = \in_array($who, ['own', 'other'], true);
            $allowed = [
                AuthorizedBy::Operator->value => 'operator' === $who && (OriginKind::System === $kind || ($commandLine && \in_array($kind, [OriginKind::Console, OriginKind::Job], true))),
                AuthorizedBy::Self->value => 'own' === $who,
                AuthorizedBy::Ability->value => $inRealm && true === $holds,
                AuthorizedBy::Authentication->value => true,
                AuthorizedBy::Consequence->value => false,
            ];
            $expected = null;
            // One's own account asks the self-service policy whatever permits it (M2-13, H-4).
            $ownRefused = 'own' === $who && TruthValue::Holds !== $selfService;

            foreach ($ownRefused ? [] : $rules as $rule) {
                if ($allowed[$rule->value]) {
                    $expected = $rule;
                    break;
                }
            }

            self::assertSame(null !== $expected, $decision->permits(), \sprintf('%s by %s from %s', implode(', ', array_map(static fn (AuthorizedBy $rule): string => $rule->value, $rules)), $who, $kind->value));
            self::assertSame($expected, $decision->basis, 'The first rule that permits decides.');

            if (!$decision->permits()) {
                self::assertNotSame(Verdict::Permit, $decision->verdict);
            }
        });
    }

    private static function abilities(?bool $holds): AbilityPolicy
    {
        return new readonly class($holds) implements AbilityPolicy {
            public function __construct(private ?bool $holds)
            {
            }

            public function holds(Actor $actor, string $ability, Command $command): ?bool
            {
                return $this->holds;
            }
        };
    }

    private static function selfService(TruthValue $value): SelfServicePolicy
    {
        return new readonly class($value) implements SelfServicePolicy {
            public function __construct(private TruthValue $value)
            {
            }

            public function decide(OperationDescription $operation, Command $command, Actor $actor): Truth
            {
                return match ($this->value) {
                    TruthValue::Holds => Truth::holds('Their own.'),
                    TruthValue::Fails => Truth::fails('No.'),
                    TruthValue::Unknown => Truth::unknown(IndeterminateReason::MissingData, 'Can\'t tell.'),
                };
            }
        };
    }

    /**
     * The bases are what's under test here: every actor's authority is valid.
     */
    private static function validAuthority(): AuthorityCheck
    {
        return new readonly class implements AuthorityCheck {
            public function check(Request $request): Truth
            {
                return Truth::holds('Valid.');
            }
        };
    }
}
