<?php

declare(strict_types=1);

namespace YorvoID\Tests\PolicyTesting;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Policy\Attribute\Attributes;
use YorvoID\Policy\Attribute\MissingAttribute;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Policy;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;
use YorvoID\PolicyTesting\PolicyCase;
use YorvoID\PolicyTesting\PolicyResult;
use YorvoID\PolicyTesting\PolicyServices;
use YorvoID\PolicyTesting\PolicyTests;
use YorvoID\PolicyTesting\PolicyUnderTest;
use YorvoID\Testing\InMemoryStorage;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;

/**
 * Policy cases (AUZ-043) on a policy built as production builds it, here in plain PHP: each set up
 * through the operations, decided by the policy, and compared on what it states.
 */
final class PolicyTestsTest extends TestCase
{
    public function testEachCaseIsSetUpAndDecided(): void
    {
        $page = new ResourceRef('page', '1');
        $results = new PolicyTests()->run(self::policy(), [
            PolicyCase::that('anonymous views')->anonymous()->asks('acme.pages.view', $page)->permits(),
            PolicyCase::that('realm role')->given(roles: ['editor'])->asks('acme.pages.publish', $page)->permits(),
            PolicyCase::that('role on the page')->given(on: [['editor', $page]])->asks('acme.pages.publish', $page)->permits(),
            PolicyCase::that('not on another')->given(on: [['editor', $page]])->asks('acme.pages.publish', new ResourceRef('page', '2'))->denies(remedies: [], precedence: Precedence::NoPermit),
            PolicyCase::that('group role')->given(groups: ['writers' => ['editor']])->asks('acme.pages.publish', $page)->permits(),
            PolicyCase::that('group role on the page')->given(groups: ['writers' => [['editor', $page]]])->asks('acme.pages.publish', $page)->permits(),
            PolicyCase::that('a relation')->given(relations: [[$page, 'editor']])->asks('acme.pages.publish', $page)->permits(),
            PolicyCase::that('an attribute')->given(attributes: ['acme.department' => 'rnd'])->asks('acme.pages.publish', $page)->permits(),
            PolicyCase::that('suspended')->given(roles: ['editor'], status: PrincipalStatus::Suspended)->asks('acme.pages.publish', $page)->denies(precedence: Precedence::AuthorityInvalid),
            PolicyCase::that('deleted')->given(roles: ['editor'], status: PrincipalStatus::Deleted)->asks('acme.pages.publish', $page)->denies(),
            PolicyCase::that('invited')->given(roles: ['editor'], status: PrincipalStatus::Invited)->asks('acme.pages.publish', $page)->denies(precedence: Precedence::AuthorityInvalid),
            PolicyCase::that('a created role')->before(
                static fn (Realm $realm): Command => new CreateRole($realm, 'reviewer', 'Reviewer'),
                static fn (Realm $realm): Command => new GrantAbilities($realm, 'reviewer', ['acme.pages.publish']),
            )->given(roles: ['reviewer'])->asks('acme.pages.publish', $page)->permits(),
            PolicyCase::that('a command as it is')->before(new GrantAbilities(new Realm('acme'), 'member', ['acme.pages.publish']))->given(roles: ['member'])->asks('acme.pages.publish', $page)->permits(),
            PolicyCase::that('missing data')->given(roles: ['member'])->asks('acme.pages.delete', $page)->indeterminate([IndeterminateReason::UnknownForbid, IndeterminateReason::MissingData], Precedence::ForbidUnknown),
        ]);

        self::assertSame([], array_values(array_filter(array_map(static fn (PolicyResult $result): ?string => $result->failure, $results), static fn (?string $failure): bool => null !== $failure)));
        self::assertTrue($results[0]->passed());
        self::assertSame('anonymous views', $results[0]->case->description);
        self::assertNotNull($results[0]->decision);
    }

    public function testTheActorIsAPrincipalUnlessItsAnonymous(): void
    {
        $tests = new PolicyTests();
        $policy = self::policy();

        self::assertTrue($tests->one($policy, PolicyCase::that('a principal')->asks('acme.sites.update')->permits())->passed(), 'With nothing given, still a principal of the realm.');
        self::assertTrue($tests->one($policy, PolicyCase::that('a principal, explicitly')->anonymous()->given()->asks('acme.sites.update')->permits())->passed(), 'Given after anonymous.');
        self::assertTrue($tests->one($policy, PolicyCase::that('anonymous')->given()->anonymous()->asks('acme.sites.update')->denies())->passed());
    }

    public function testRunsEveryCommandBefore(): void
    {
        $result = new PolicyTests()->one(self::policy(), PolicyCase::that('two befores')
            ->before(static fn (Realm $realm): Command => new CreateRole($realm, 'reviewer', 'Reviewer'))
            ->before(static fn (Realm $realm): Command => new GrantAbilities($realm, 'reviewer', ['acme.pages.publish']))
            ->given(roles: ['reviewer'])
            ->asks('acme.pages.publish')
            ->permits());

        self::assertNull($result->failure);
        self::assertSame('permit', $result->expected);
    }

    public function testAMistakeInTheSetupIsShown(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The command YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge isn\'t in the operation catalogue.');
        new PolicyTests()->one(self::policy(), PolicyCase::that('not an operation of the policy')->before(static fn (Realm $realm): Command => new IssueBadge($realm, new PrincipalId(new UuidV7())))->asks('acme.pages.view')->permits());
    }

    public function testComparesTheRemediesByName(): void
    {
        $world = new PolicyWorld();
        $policy = new PolicyUnderTest(static fn (): PolicyServices => new PolicyServices($world->operations, new Policy(new Evaluator([new StubPermit('acme.proof', Truth::fails('Prove it.', new StepUpRemedy(Duration::minutes(5))))], new StubAuthority(Truth::holds('valid')))), $world->realm));
        $tests = new PolicyTests();

        self::assertTrue($tests->one($policy, PolicyCase::that('step-up')->anonymous()->asks('acme.pages.publish')->denies(['step_up']))->passed());
        self::assertSame('Expected the remedies [], got [step_up]: Prove it. (none)', $tests->one($policy, PolicyCase::that('none')->anonymous()->asks('acme.pages.publish')->denies([]))->failure);
    }

    public function testEachCaseStartsWithNothingStored(): void
    {
        $policy = self::policy();
        $tests = new PolicyTests();
        $grant = PolicyCase::that('granted')->before(static fn (Realm $realm): Command => new GrantAbilities($realm, 'member', ['acme.pages.publish']))->given(roles: ['member'])->asks('acme.pages.publish')->permits();
        $after = PolicyCase::that('not any more')->given(roles: ['member'])->asks('acme.pages.publish')->denies();

        self::assertTrue($tests->one($policy, $grant)->passed());
        self::assertTrue($tests->one($policy, $after)->passed(), 'The grant went with its case.');
    }

    public function testSaysHowADecisionDiffers(): void
    {
        $policy = self::policy();
        $tests = new PolicyTests();
        $page = new ResourceRef('page', '1');

        foreach ([
            'Expected permit, got deny: The rule doesn\'t permit it (abstain). (a member publishes)' => PolicyCase::that('a member publishes')->given(roles: ['member'])->asks('acme.pages.publish', $page)->permits(),
            'Expected the remedies [step_up], got []: The rule doesn\'t permit it (abstain). (remedies)' => PolicyCase::that('remedies')->given(roles: ['member'])->asks('acme.pages.publish', $page)->denies(['step_up']),
            'Expected the precedence row ForbidHolds, got NoPermit: The rule doesn\'t permit it (abstain). (row)' => PolicyCase::that('row')->given(roles: ['member'])->asks('acme.pages.publish', $page)->denies(precedence: Precedence::ForbidHolds),
            'Expected indeterminate, got deny; expected it couldn\'t decide for [store_unreachable], got []: The rule doesn\'t permit it (abstain). (reasons)' => PolicyCase::that('reasons')->given(roles: ['member'])->asks('acme.pages.publish', $page)->indeterminate([IndeterminateReason::StoreUnreachable]),
        ] as $failure => $case) {
            $result = $tests->one($policy, $case);
            self::assertFalse($result->passed());
            self::assertSame($failure, $result->failure);
        }

        $unknown = $tests->one($policy, PolicyCase::that('a role nobody has')->given(roles: ['nobody'])->asks('acme.pages.publish')->permits());
        self::assertNull($unknown->decision);
        self::assertStringStartsWith('The case couldn\'t be set up: YorvoID\Policy\UnknownRole: ', (string) $unknown->failure);
    }

    public function testRefusesCasesThatDontSayEnough(): void
    {
        foreach ([
            'A policy case has no description.' => static fn (): mixed => PolicyCase::that(' '),
            'The policy case "unfinished" doesn\'t say what\'s asked and what\'s expected.' => static fn (): mixed => new PolicyTests()->one(self::policy(), PolicyCase::that('unfinished')->asks('acme.pages.view')),
            'The policy case "asks nothing" doesn\'t say what\'s asked and what\'s expected.' => static fn (): mixed => new PolicyTests()->one(self::policy(), PolicyCase::that('asks nothing')->permits()),
            'Two policy cases are described as "twice".' => static fn (): mixed => PolicyTests::named([PolicyCase::that('twice'), PolicyCase::that('twice')]),
        ] as $message => $refused) {
            try {
                $refused();
                self::fail($message);
            } catch (IntegratorError $error) {
                self::assertStringStartsWith($message, $error->getMessage());
            }
        }
    }

    /**
     * The policy as the sample's production builds it: PolicyWorld, given the stores.
     */
    private static function policy(): PolicyUnderTest
    {
        return PolicyUnderTest::of(static function (InMemoryStorage $stores): PolicyServices {
            $world = new PolicyWorld(rules: [self::unknownDeletes(), self::signedIn()], rulesOf: static fn (Attributes $attributes): array => [self::rnd($attributes)], memory: $stores);

            return new PolicyServices($world->operations, $world->policy, $world->realm);
        });
    }

    private static function rnd(Attributes $attributes): Rule
    {
        return new readonly class($attributes) implements Rule {
            public function __construct(
                private Attributes $attributes,
            ) {
            }

            public function name(): string
            {
                return 'acme.rnd_publishes';
            }

            public function matches(Request $request): bool
            {
                return 'acme.pages.publish' === $request->ability;
            }

            public function decide(Request $request): RuleAnswer
            {
                try {
                    return 'rnd' === $this->attributes->principal($request, 'acme.department') ? RuleAnswer::permit('R&D publishes.') : RuleAnswer::abstain();
                } catch (MissingAttribute) {
                    return RuleAnswer::abstain();
                }
            }
        };
    }

    /**
     * Sites are updated by principals only, whatever their roles.
     */
    private static function signedIn(): Rule
    {
        return new readonly class implements Rule {
            public function name(): string
            {
                return 'acme.signed_in_update_sites';
            }

            public function matches(Request $request): bool
            {
                return 'acme.sites.update' === $request->ability;
            }

            public function decide(Request $request): RuleAnswer
            {
                return null !== $request->actor->principalId ? RuleAnswer::permit('Signed in.') : RuleAnswer::abstain();
            }
        };
    }

    private static function unknownDeletes(): Rule
    {
        return new readonly class implements Rule {
            public function name(): string
            {
                return 'acme.unknown_deletes';
            }

            public function matches(Request $request): bool
            {
                return 'acme.pages.delete' === $request->ability;
            }

            public function decide(Request $request): RuleAnswer
            {
                return RuleAnswer::unknown(IndeterminateReason::MissingData, 'Nobody knows.');
            }
        };
    }
}
