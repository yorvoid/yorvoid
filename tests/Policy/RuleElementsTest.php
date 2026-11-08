<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\RolePermit;
use YorvoID\Policy\Roles;
use YorvoID\Policy\Rule\RuleAnswer;
use YorvoID\Policy\Rule\RuleElements;
use YorvoID\Policy\Rule\RuleOutcome;
use YorvoID\Policy\Storage\InMemoryPolicyStore;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Policy\Fixtures\AcmeDeclarations;
use YorvoID\Tests\Policy\Fixtures\CountingRule;

/**
 * Rules in PHP (AUZ-006, 007): a refusal wins over every role, super roles included; a permit needs no
 * role; "can't tell" is never a Permit.
 */
final class RuleElementsTest extends TestCase
{
    public function testARefusalWinsOverASuperRole(): void
    {
        $decision = $this->decide(RuleAnswer::forbid('The page is locked.'), 'admin');

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame('A forbid applies: The page is locked.', $decision->reason);
    }

    public function testAPermitNeedsNoRole(): void
    {
        $decision = $this->decide(RuleAnswer::permit('The helper moderates.'), null, 'acme.pages.purge');

        self::assertSame(Verdict::Permit, $decision->verdict);
        self::assertSame(AuthorizedBy::Ability, $decision->basis);
        self::assertSame('The helper moderates.', $decision->reason);
    }

    public function testAbstainingLeavesItToTheRoles(): void
    {
        self::assertTrue($this->decide(RuleAnswer::abstain(), 'editor')->permits());
        self::assertFalse($this->decide(RuleAnswer::abstain(), null)->permits());
        self::assertSame('The rule abstains.', RuleAnswer::abstain()->detail);
        self::assertSame(RuleOutcome::Abstain, RuleAnswer::abstain()->outcome);
    }

    public function testCantTellIsNeverAPermit(): void
    {
        $decision = $this->decide(RuleAnswer::unknown(IndeterminateReason::MissingData, 'The page\'s lock isn\'t known.'), 'admin');

        self::assertSame(Verdict::Indeterminate, $decision->verdict);
        self::assertSame([IndeterminateReason::UnknownForbid, IndeterminateReason::MissingData], $decision->indeterminate);
    }

    public function testTheRuleIsAskedOncePerDecisionAndOnlyWhenItMatches(): void
    {
        $rule = self::rule(RuleAnswer::forbid('no'));
        [$permit, $forbid] = RuleElements::of($rule);
        $request = Request::forAbility('acme.pages.publish', new Realm('acme'), null, self::member(), new Origin(OriginKind::Web), new \DateTimeImmutable());

        self::assertSame('acme.locked:permit', $permit->name());
        self::assertSame('acme.locked:forbid', $forbid->name());
        self::assertSame(AuthorizedBy::Ability, $permit->basis());
        self::assertSame('The rule doesn\'t permit it (forbid).', $permit->evaluate($request)->detail);
        self::assertSame('no', $forbid->evaluate($request)->detail);
        self::assertSame(1, $rule->asked);
        self::assertSame('The rule doesn\'t refuse it (permit).', RuleElements::of(self::rule(RuleAnswer::permit('yes')))[1]->evaluate($request)->detail);

        $rule->matching = false;
        self::assertFalse($permit->matches($request));
        self::assertFalse($forbid->matches($request));
        self::assertTrue(self::rule(RuleAnswer::abstain())->matches($request));
    }

    private function decide(RuleAnswer $answer, ?string $role, string $ability = 'acme.pages.publish'): Decision
    {
        $store = new InMemoryPolicyStore();
        $member = self::member();

        if (null !== $role) {
            $store->assign(new RoleAssignment(new Realm('acme'), self::id($member), $role, new \DateTimeImmutable()));
        }

        $evaluator = new Evaluator([new RolePermit(new Roles(PolicyCatalogue::of([new AcmeDeclarations()]), $store)), ...RuleElements::of(self::rule($answer))], new StubAuthority(Truth::holds('valid')));

        return $evaluator->decideAbility($member, $ability, new Realm('acme'), new ResourceRef('page', 'locked'), new Origin(OriginKind::Web));
    }

    private static function member(): Member
    {
        return new Member(new Realm('acme'), new PrincipalId(new UuidV7()));
    }

    private static function id(Member $member): PrincipalId
    {
        \assert(null !== $member->principalId);

        return $member->principalId;
    }

    private static function rule(RuleAnswer $answer): CountingRule
    {
        return new CountingRule($answer);
    }
}
