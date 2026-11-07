<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Policy\Ability;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\DeleteRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\RelabelRole;
use YorvoID\Policy\Change\ResetAbilities;
use YorvoID\Policy\Change\RevokeAbilities;
use YorvoID\Policy\Change\SetAbilities;
use YorvoID\Policy\Change\UnassignRole;
use YorvoID\Policy\InvalidRole;
use YorvoID\Policy\PolicyDeclarations;
use YorvoID\Policy\Role;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\RoleSource;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;
use YorvoID\Policy\UnknownAbility;
use YorvoID\Policy\UnknownRole;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Tests\Policy\Fixtures\CountingRule;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Policy\Fixtures\StubStepUpCheck;

/**
 * Quire's permission model on YorvoID, case by case after Quire's own tests (PermissionsTest,
 * RolesAndAccessTest, DelegationTest, HostUsersTest), so a Quire preset only adapts names: Quire's
 * "guest" is "everyone" here, and its admin role is a declared super role.
 */
final class QuireModelTest extends TestCase
{
    public function testChangesAreStoredAsDifferencesFromTheDefaults(): void
    {
        $world = new PolicyWorld();

        $world->asOperator(new GrantAbilities($world->realm, 'member', ['acme.pages.view_drafts']));
        $world->asOperator(new RevokeAbilities($world->realm, 'editor', ['acme.pages.delete']));

        $changes = $world->roles->changes($world->realm);
        self::assertSame(['acme.pages.view_drafts' => ['member']], $changes->grant);
        self::assertSame(['acme.pages.delete' => ['editor']], $changes->revoke);

        $world->asOperator(new GrantAbilities($world->realm, 'editor', ['acme.pages.delete']));
        self::assertSame([], $world->roles->changes($world->realm)->revoke, 'Granting a default back leaves no revoke.');

        $world->asOperator(new ResetAbilities($world->realm));
        self::assertTrue($world->roles->changes($world->realm)->isEmpty());

        $this->expectException(UnknownAbility::class);
        $world->asOperator(new GrantAbilities($world->realm, 'member', ['acme.pages.nope']));
    }

    public function testEveryoneMembersEditorsAndAdmins(): void
    {
        $world = new PolicyWorld();
        $member = $world->principal('member');
        $editor = $world->principal('editor');
        $admin = $world->principal('admin');

        self::assertTrue($world->allows(new Anonymous(), 'acme.pages.view'), 'A guest views.');
        self::assertTrue($world->allows($member, 'acme.pages.view'), 'A member has what a guest has.');
        self::assertFalse($world->allows($member, 'acme.pages.publish'));
        self::assertTrue($world->allows($editor, 'acme.pages.publish'));
        self::assertTrue($world->allows($admin, 'acme.pages.publish'), 'An admin has every ability that isn\'t strict.');
        self::assertFalse($world->allows($admin, 'acme.pages.purge'), 'A strict one only when it\'s granted.');

        $world->asOperator(new GrantAbilities($world->realm, 'admin', ['acme.pages.purge']));
        self::assertTrue($world->allows($admin, 'acme.pages.purge'));
        self::assertFalse($world->allows($editor, 'acme.pages.purge'));
    }

    public function testARuleGrantsWithoutARoleAndItsRefusalBeatsAnAdmin(): void
    {
        $locked = new class implements Rule {
            public function name(): string
            {
                return 'acme.locked';
            }

            public function matches(Request $request): bool
            {
                return 'page' === $request->resource?->type;
            }

            public function decide(Request $request): RuleAnswer
            {
                return 'locked' === $request->resource?->id ? RuleAnswer::forbid('The page is locked.') : RuleAnswer::abstain();
            }
        };
        $world = new PolicyWorld(rules: [$locked, new CountingRule(RuleAnswer::permit('The helper moderates.'), 'acme.helper')]);
        $admin = $world->principal('admin');
        $nobody = $world->principal();

        self::assertFalse($world->allows($admin, 'acme.pages.delete', new ResourceRef('page', 'locked')));
        self::assertTrue($world->allows($admin, 'acme.pages.delete', new ResourceRef('page', 'open')));
        self::assertTrue($world->allows($nobody, 'acme.pages.purge'), 'A rule\'s grant needs no role.');
    }

    public function testARoleCreatedFromAnotherHasItsAbilitiesUntilItIsDeleted(): void
    {
        $world = new PolicyWorld();
        $role = $world->asOperator(new CreateRole($world->realm, 'reviewer', 'Reviewer', from: 'editor'));
        $reviewer = $world->principal('reviewer');

        self::assertFalse($role->declared(), 'Its owner is the realm.');
        self::assertSame($world->roles->abilitiesOf($world->realm, 'editor'), $world->roles->abilitiesOf($world->realm, 'reviewer'));
        self::assertSame(['acme.pages.delete', 'acme.pages.publish', 'acme.pages.view_drafts'], $world->roles->changes($world->realm)->abilities(), 'Stored as grants.');
        self::assertTrue($world->allows($reviewer, 'acme.pages.publish'));

        self::assertSame('Senior reviewer', $world->asOperator(new RelabelRole($world->realm, 'reviewer', 'Senior reviewer'))->label);
        self::assertSame('Senior reviewer', $world->roles->roles($world->realm)['reviewer']);

        $world->asOperator(new DeleteRole($world->realm, 'reviewer'));
        self::assertFalse($world->roles->has($world->realm, 'reviewer'));
        self::assertSame([], $world->memory->policy->assignments($world->realm, self::id($reviewer)), 'Taken from its people.');
        self::assertTrue($world->roles->changes($world->realm)->isEmpty(), 'Its grants go too.');
        self::assertFalse($world->allows($reviewer, 'acme.pages.publish'));
    }

    public function testRolesAreValidatedAndDeclaredOnesStay(): void
    {
        $world = new PolicyWorld();
        $cases = [
            'The role name "Bad name" is invalid' => new CreateRole($world->realm, 'Bad name', 'Bad'),
            'The label of the role "x" must be 1 to 50 characters.' => new CreateRole($world->realm, 'x', ' '),
            'The role "editor" exists.' => new CreateRole($world->realm, 'editor', 'Editor'),
            '"editor" is declared by acme: only roles created in the realm are deleted.' => new DeleteRole($world->realm, 'editor'),
            '"admin" is declared by acme: only roles created in the realm are relabelled.' => new RelabelRole($world->realm, 'admin', 'Boss'),
            '"everyone" is declared by YorvoID: only roles created in the realm are deleted.' => new DeleteRole($world->realm, 'everyone'),
            '"everyone" is every actor\'s role: nobody is given it.' => new AssignRole($world->realm, self::id($world->principal()), 'everyone'),
        ];

        foreach ($cases as $message => $command) {
            try {
                $world->asOperator($command);
                self::fail(\sprintf('Ran despite: %s', $message));
            } catch (InvalidRole $error) {
                self::assertStringStartsWith($message, $error->getMessage());
            }
        }

        $this->expectException(UnknownRole::class);
        $world->asOperator(new CreateRole($world->realm, 'x', 'X', from: 'ghost'));
    }

    public function testSettingARolesAbilitiesIsExactAndRestoringLeavesNoDifference(): void
    {
        $world = new PolicyWorld();

        $world->asOperator(new SetAbilities($world->realm, 'editor', ['acme.pages.publish', 'acme.sites.update']));
        self::assertSame(['acme.pages.publish', 'acme.sites.update'], $world->roles->abilitiesOf($world->realm, 'editor'));

        $world->asOperator(new SetAbilities($world->realm, 'editor', ['acme.pages.view_drafts', 'acme.pages.publish', 'acme.pages.delete']));
        self::assertTrue($world->roles->changes($world->realm)->isEmpty(), 'Back to its defaults: nothing stored.');
    }

    public function testNobodyGivesWhatTheyDontHold(): void
    {
        $world = new PolicyWorld([self::declaring([new Ability('acme.users.manage', 'Manage users', 'acme', ['manager'])])], stepUp: new StubStepUpCheck());
        $world->asOperator(new GrantAbilities($world->realm, 'manager', [YorvoIDDeclarations::ROLES_MANAGE, YorvoIDDeclarations::PERMISSIONS_MANAGE]));
        $manager = $world->principal('manager');
        $member = $world->principal('member');
        $admin = $world->principal('admin');

        $refusals = [
            'give the super role' => new AssignRole($world->realm, self::id($member), 'admin'),
            'take the super role' => new UnassignRole($world->realm, self::id($admin), 'admin'),
            'give a role they lack abilities of' => new AssignRole($world->realm, self::id($member), 'editor'),
            'grant what they don\'t hold' => new GrantAbilities($world->realm, 'member', ['acme.pages.publish']),
            'revoke what they don\'t hold' => new RevokeAbilities($world->realm, 'editor', ['acme.pages.publish']),
            'create a role from the super role' => new CreateRole($world->realm, 'boss', 'Boss', from: 'admin'),
            'create a role from one they lack abilities of' => new CreateRole($world->realm, 'writer', 'Writer', from: 'editor'),
        ];

        foreach ($refusals as $what => $command) {
            try {
                $world->as($manager, $command);
                self::fail(\sprintf('A manager could %s.', $what));
            } catch (NotPermitted $refused) {
                self::assertStringStartsWith('A forbid applies: ', $refused->decision->reason, $what);
            }
        }

        $world->as($manager, new AssignRole($world->realm, self::id($member), 'manager'));
        $world->as($manager, new GrantAbilities($world->realm, 'member', ['acme.users.manage']));
        $world->as($manager, new CreateRole($world->realm, 'deputy', 'Deputy', from: 'manager'));
        self::assertTrue($world->allows($member, 'acme.users.manage'), 'What they hold, they give.');
        self::assertTrue($world->roles->has($world->realm, 'deputy'));

        $world->as($admin, new AssignRole($world->realm, self::id($member), 'admin'));
        self::assertTrue($world->allows($member, 'acme.pages.publish'), 'An admin gives the super role.');
    }

    public function testRolesFromTheHostApplicationCountWithoutBeingStored(): void
    {
        $mapped = new class implements RoleSource {
            /** @var list<string> */
            public array $roles = [];

            public function roles(Request $request): iterable
            {
                return $this->roles;
            }
        };
        $world = new PolicyWorld(sources: [$mapped]);
        $host = $world->principal('member');

        $mapped->roles = ['admin', 'editor'];
        self::assertTrue($world->allows($host, 'acme.pages.publish'));
        self::assertSame(['member'], array_map(static fn (RoleAssignment $assignment): string => $assignment->role, $world->memory->policy->assignments($world->realm, self::id($host))), 'Mapped roles aren\'t stored.');

        $mapped->roles = [];
        self::assertFalse($world->allows($host, 'acme.pages.publish'), 'A change of the host\'s roles applies at once.');

        $mapped->roles = ['editor'];
        $world->asOperator(new SuspendPrincipal($world->realm, self::id($host)));
        self::assertFalse($world->allows($host, 'acme.pages.view'), 'Suspended: no roles, mapped or stored.');
    }

    public function testQuireServersOwnerAndSiteManagers(): void
    {
        $world = new PolicyWorld([self::declaring(
            [new Ability('acme.server.settings', 'Change the server\'s settings', 'acme'), new Ability('acme.sites.add', 'Add a site', 'acme')],
            [new Role('owner', 'Owner', 'acme', super: true), new Role('site_manager', 'Site manager', 'acme')],
        )]);
        $world->asOperator(new GrantAbilities($world->realm, 'site_manager', ['acme.sites.update']));
        $owner = $world->principal('owner');
        $manager = $world->principal();
        $world->asOperator(new AssignRole($world->realm, self::id($manager), 'site_manager', new ResourceRef('site', 'a')));

        self::assertTrue($world->allows($owner, 'acme.server.settings'));
        self::assertTrue($world->allows($owner, 'acme.sites.update', new ResourceRef('site', 'b')));
        self::assertTrue($world->allows($manager, 'acme.sites.update', new ResourceRef('site', 'a')));
        self::assertFalse($world->allows($manager, 'acme.sites.update', new ResourceRef('site', 'b')), 'Only their own sites.');
        self::assertFalse($world->allows($manager, 'acme.sites.add'));
        self::assertFalse($world->allows($manager, 'acme.server.settings'), 'Nothing at server level.');
    }

    private static function id(Actor $actor): PrincipalId
    {
        \assert(null !== $actor->principalId);

        return $actor->principalId;
    }

    /**
     * @param list<Ability> $abilities
     * @param list<Role>    $roles
     */
    private static function declaring(array $abilities, array $roles = []): PolicyDeclarations
    {
        return new readonly class($abilities, $roles) implements PolicyDeclarations {
            /**
             * @param list<Ability> $abilities
             * @param list<Role>    $roles
             */
            public function __construct(
                private array $abilities,
                private array $roles,
            ) {
            }

            public function abilities(): iterable
            {
                return $this->abilities;
            }

            public function roles(): iterable
            {
                return $this->roles;
            }
        };
    }
}
