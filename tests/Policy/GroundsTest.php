<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\Explanation;
use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\RoleGrant;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateGroup;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\RolePermit;
use YorvoID\Policy\RoleSource;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;
use YorvoID\Policy\StepUpForSensitiveChanges;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Policy\Fixtures\StubStepUpCheck;

/**
 * What decisions rest on (G5): the role permit names every role that has the ability and how, the
 * relation permit the relation, a rule itself and what it read, the authority check the status.
 */
final class GroundsTest extends TestCase
{
    public function testTheRolePermitNamesEveryRoleThatHasTheAbilityAndHow(): void
    {
        $source = new readonly class implements RoleSource {
            public function roles(Request $request): iterable
            {
                return 'acme.sites.update' === $request->ability ? ['manager', 'manager'] : [];
            }
        };
        $world = new PolicyWorld(sources: [$source]);
        $ada = $world->principal('editor', 'member', 'manager');
        $world->asOperator(new GrantAbilities($world->realm, 'member', ['acme.pages.publish']));
        $world->asOperator(new GrantAbilities($world->realm, 'manager', ['acme.pages.publish']));
        $world->asOperator(new CreateGroup($world->realm, 'writers', 'Writers'));
        $world->asOperator(new AddGroupMember($world->realm, 'writers', Subject::principal(self::id($ada))));
        $world->asOperator(new AssignGroupRole($world->realm, 'writers', 'editor', new ResourceRef('site', 'a')));
        $world->asOperator(new AssignRole($world->realm, self::id($ada), 'editor', new ResourceRef('site', 'b')));
        $world->asOperator(new AssignRole($world->realm, self::id($ada), 'editor', new ResourceRef('site', 'a')));
        $world->relationStore->add(new RelationTuple($world->realm, new ResourceRef('page', '1'), 'parent', new Subject(new ResourceRef('site', 'a'))));
        $world->relationStore->add(new RelationTuple($world->realm, new ResourceRef('page', '1'), 'editor', Subject::principal(self::id($ada))));

        $explanation = Explanation::of($world->decide($ada, 'acme.pages.publish', new ResourceRef('page', '1')));

        self::assertSame(Precedence::PermitHolds, $explanation->precedence);
        self::assertEquals([
            Ground::relation(new ResourceRef('page', '1'), 'editor'),
            Ground::role('editor', RoleGrant::Default),
            Ground::role('editor', RoleGrant::Default, new ResourceRef('site', 'a')),
            Ground::role('manager', RoleGrant::Granted),
            Ground::role('member', RoleGrant::Granted),
            Ground::role('editor', RoleGrant::Default, new ResourceRef('site', 'a'), 'writers'),
        ], $explanation->grounds, 'Her relation, then each role as she has it: in the realm, on the site the page is within, granted in the realm, and through the group; not the one on another site.');

        $sourced = Explanation::of($world->decide($ada, 'acme.sites.update'));
        self::assertEquals([Ground::role('manager', RoleGrant::Default), Ground::role('manager', RoleGrant::Default, source: get_debug_type($source))], $sourced->grounds, 'Assigned, and from the source: once.');

        $everyone = Explanation::of($world->decide($ada, 'acme.pages.view'));
        self::assertEquals([Ground::role('everyone', RoleGrant::Default)], $everyone->grounds);
    }

    public function testASuperRoleHasWhatIsntStrict(): void
    {
        $world = new PolicyWorld();
        $root = $world->principal('admin', 'editor');

        self::assertEquals([Ground::role('everyone', RoleGrant::Default), Ground::role('admin', RoleGrant::Super)], Explanation::of($world->decide($root, 'acme.pages.view'))->grounds);
        self::assertEquals([Ground::role('admin', RoleGrant::Super), Ground::role('editor', RoleGrant::Default)], Explanation::of($world->decide($root, 'acme.pages.publish'))->grounds);
        self::assertSame(Precedence::NoPermit, Explanation::of($world->decide($root, 'acme.pages.purge'))->precedence, 'Not a strict one.');

        $world->asOperator(new GrantAbilities($world->realm, 'admin', ['acme.pages.purge']));
        self::assertEquals([Ground::role('admin', RoleGrant::Granted)], Explanation::of($world->decide($root, 'acme.pages.purge'))->grounds, 'Unless it\'s granted.');
    }

    public function testRulesTheAuthorityAndAProofAreNamed(): void
    {
        $rule = new readonly class implements Rule {
            public function name(): string
            {
                return 'acme.departments';
            }

            public function matches(Request $request): bool
            {
                return 'acme.pages.purge' === $request->ability;
            }

            public function decide(Request $request): RuleAnswer
            {
                return null === $request->resource ? RuleAnswer::permit('R&D purges.', Ground::attribute('acme.department')) : RuleAnswer::forbid('Not this one.');
            }
        };
        $stepUp = new StubStepUpCheck();
        $world = new PolicyWorld(rules: [$rule], stepUp: $stepUp);
        $ada = $world->principal('member');

        $permitted = Explanation::of($world->decide($ada, 'acme.pages.purge'));
        self::assertEquals([Ground::rule('acme.departments'), Ground::attribute('acme.department')], $permitted->grounds);
        $refused = Explanation::of($world->decide($ada, 'acme.pages.purge', new ResourceRef('page', '1')));
        self::assertEquals([Ground::rule('acme.departments')], $refused->grounds);

        foreach ([RuleAnswer::permit('yes', read: Ground::attribute('a')), RuleAnswer::forbid('no', read: Ground::attribute('a')), RuleAnswer::unknown(IndeterminateReason::MissingData, '?', read: Ground::attribute('a'))] as $answer) {
            self::assertSame([0], array_keys($answer->grounds), 'A list, named or not.');
        }

        $authority = $world->decide($ada, 'acme.pages.view')->reasons;
        self::assertEquals([Ground::authority('active')], end($authority)->truth->grounds ?? null);

        // A sensitive change: the roles, and the proof.
        $admin = $world->principal('admin');
        $operation = $world->catalogue->forCommand(AssignRole::class);
        $request = Request::forOperation($operation, new AssignRole($world->realm, self::id($ada), 'admin'), $admin, new Origin(OriginKind::Web), $world->clock->now());
        self::assertEquals([Ground::role('admin', RoleGrant::Super)], new RolePermit($world->roles)->evaluate($request)->grounds);
        self::assertEquals([Ground::evidence('step_up')], new StepUpForSensitiveChanges($world->roles, $stepUp)->evaluate($request)->grounds);
    }

    private static function id(ActingPrincipal $principal): PrincipalId
    {
        \assert(null !== $principal->principalId);

        return $principal->principalId;
    }
}
