<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Policy;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\Roles;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;
use YorvoID\Policy\UnknownAbility;
use YorvoID\Policy\WhyNot;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Policy\Fixtures\AcmeDeclarations;
use YorvoID\Tests\Policy\Fixtures\Page;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;

/**
 * Why-not (AUZ-021): the decision, its explanation and what would permit it, answered from the policy;
 * about oneself, or about someone else with "yorvoid.policy.explain".
 */
final class WhyNotTest extends TestCase
{
    public function testSaysWhatWouldPermitIt(): void
    {
        $world = new PolicyWorld(rules: [self::locked()]);
        $ada = $world->principal('member');

        $why = $this->whyNot($world, $ada, $ada, 'acme.pages.publish', new ResourceRef('page', '1'));

        self::assertSame(Verdict::Deny, $why->decision->verdict);
        self::assertSame(Precedence::NoPermit, $why->explanation->precedence);
        self::assertSame($why->decision->id, $why->explanation->id);
        self::assertSame(['editor'], $why->roles);
        self::assertSame(['admin'], $why->superRoles);
        self::assertSame(['editor'], $why->relations, 'An editor of the page publishes it.');
        self::assertSame([], $why->forbids);
        self::assertSame([], $why->remedies);

        $locked = $this->whyNot($world, $ada, $ada, 'acme.pages.publish', new ResourceRef('page', 'locked'));
        self::assertSame(['zoo.locked:forbid'], $locked->forbids, 'It would refuse whatever else changed.');

        $strict = $this->whyNot($world, $ada, $ada, 'acme.pages.purge', null);
        self::assertSame([[], [], []], [$strict->roles, $strict->superRoles, $strict->relations], 'Strict: no role has it, super roles neither, and no resource.');

        $world->asOperator(new GrantAbilities($world->realm, 'member', ['acme.sites.update']));
        $site = $this->whyNot($world, $ada, $ada, 'acme.sites.update', null, new Page('7', self::id($ada)));
        self::assertSame(Verdict::Permit, $site->decision->verdict, 'It answers a Permit too.');
        self::assertSame(['manager', 'member'], $site->roles);
        self::assertSame([], $site->relations, 'A page has none that give it.');
    }

    public function testCarriesTheRemedies(): void
    {
        $world = new PolicyWorld();
        $policy = new Policy(new Evaluator([new StubPermit('roles', Truth::fails('Prove it.', new StepUpRemedy(Duration::minutes(5))))], new StubAuthority(Truth::holds('valid'))), $world->roles);
        $ada = new ActingPrincipal($world->realm, new PrincipalId(new \Symfony\Component\Uid\UuidV7()));

        self::assertCount(1, $policy->whyNot($ada, $ada, 'acme.pages.publish', $world->realm, null, new Origin(OriginKind::Web))->remedies);

        $withoutRelations = new Policy(new Evaluator([], new StubAuthority(Truth::holds('valid'))), new Roles(PolicyCatalogue::of([new YorvoIDDeclarations(), new AcmeDeclarations()]), $world->policyStore));
        self::assertSame([], $withoutRelations->whyNot($ada, $ada, 'acme.pages.publish', $world->realm, new ResourceRef('page', '1'), new Origin(OriginKind::Web))->relations, 'Roles read without relations: none.');
    }

    public function testAskingAboutSomeoneElseTakesTheAbilityToExplain(): void
    {
        $world = new PolicyWorld();
        $ada = $world->principal('member');
        $bob = $world->principal('manager');
        self::assertTrue($world->roles->ability(YorvoIDDeclarations::POLICY_EXPLAIN)?->recordsPermits, 'Its permits are always recorded.');

        $same = new ActingPrincipal($world->realm, self::id($ada));
        self::assertSame(Verdict::Deny, $this->whyNot($world, $same, $ada, 'acme.pages.publish', null)->decision->verdict, 'The same principal is herself.');

        foreach ([$bob, new ActingPrincipal(new Realm('other'), self::id($ada)), new Anonymous()] as $other) {
            try {
                $this->whyNot($world, $other, $ada, 'acme.pages.publish', null);
                self::fail('Someone else asked without the ability.');
            } catch (NotPermitted $refused) {
                self::assertSame('yorvoid.policy.explain', $refused->operation);
                self::assertFalse($refused->decision->permits());
            }
        }

        $world->asOperator(new GrantAbilities($world->realm, 'manager', [YorvoIDDeclarations::POLICY_EXPLAIN]));
        $why = $this->whyNot($world, $bob, $ada, 'acme.sites.update', null);
        self::assertSame('None of their roles has "acme.sites.update".', $why->decision->reason, 'About her, not him.');
    }

    public function testNeedsTheRolesAndADeclaredAbility(): void
    {
        $world = new PolicyWorld();
        $ada = $world->principal('member');

        try {
            $this->whyNot($world, $ada, $ada, 'acme.nothing', null);
            self::fail('An undeclared ability was explained.');
        } catch (UnknownAbility) {
        }

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('Why-not reads the roles, and the policy has none.');
        new Policy(new Evaluator([], new StubAuthority(Truth::holds('valid'))))->whyNot($ada, $ada, 'acme.pages.publish', $world->realm, null, new Origin(OriginKind::Web));
    }

    private function whyNot(PolicyWorld $world, Actor $asking, Actor $about, string $ability, ?ResourceRef $resource, ?object $subject = null): WhyNot
    {
        return $world->policy->whyNot($asking, $about, $ability, $world->realm, $resource, new Origin(OriginKind::Web), $subject);
    }

    private static function locked(): Rule
    {
        return new readonly class implements Rule {
            public function name(): string
            {
                return 'zoo.locked';
            }

            public function matches(Request $request): bool
            {
                return 'locked' === $request->resource?->id;
            }

            public function decide(Request $request): RuleAnswer
            {
                return RuleAnswer::forbid('It\'s locked.');
            }
        };
    }

    private static function id(ActingPrincipal $principal): PrincipalId
    {
        \assert(null !== $principal->principalId);

        return $principal->principalId;
    }
}
