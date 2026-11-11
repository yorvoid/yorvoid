<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Policy\Attribute\SetAttribute;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateGroup;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\DeleteGroup;
use YorvoID\Policy\Change\DeleteRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\GroupRelations;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Change\RemoveRelation;
use YorvoID\Relations\ReservedRelation;
use YorvoID\Relations\Subject;
use YorvoID\Relations\UnknownRelation;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Policy\Fixtures\StubStepUpCheck;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * M2-13: no-escalation weighs a change by what it does, not by what it says. The attacks of the
 * authz audit (H-1, H-2, M-3, M-6) are kept as regression tests.
 */
final class NoEscalationByEffectTest extends DatabaseTestCase
{
    private const string RELATER = 'relater';

    #[DataProvider('implementations')]
    public function testAGroupChangesOnlyThroughTheGroupOperations(string $implementation): void
    {
        $world = $this->world($implementation);
        $manager = $this->manager($world);
        $world->asOperator(new CreateGroup($world->realm, 'admins', 'Admins'));
        $world->asOperator(new AssignGroupRole($world->realm, 'admins', 'admin'));
        $admin = $world->principal();
        $world->asOperator(new AddGroupMember($world->realm, 'admins', self::subject($admin)));
        $mine = GroupRelations::members('mine');

        // H-1: a raw relation joins no group, nor puts one within another, nor takes anyone out.
        $raw = [
            new AddRelation($world->realm, GroupRelations::group('admins'), GroupRelations::MEMBER, self::subject($manager)),
            new AddRelation($world->realm, GroupRelations::group('admins'), GroupRelations::MEMBER, $mine),
            new RemoveRelation($world->realm, GroupRelations::group('admins'), GroupRelations::MEMBER, self::subject($admin)),
        ];

        foreach ($raw as $command) {
            foreach ([$manager, null] as $actor) {
                try {
                    null === $actor ? $world->asOperator($command) : $world->as($actor, $command);
                    self::fail(\sprintf('%s changed a group.', $command::class));
                } catch (ReservedRelation $refused) {
                    self::assertSame('group', $refused->type);
                    self::assertSame('The relations of "group" change only through its own operations, not AddRelation or RemoveRelation.', $refused->getMessage());
                }
            }
        }

        self::assertFalse($world->allows($manager, 'acme.pages.publish'), 'The manager didn\'t join the admins.');
        self::assertTrue($world->allows($admin, 'acme.pages.publish'), 'The admin is still one.');

        // A type nothing declares isn't reserved: its relation is unknown.
        try {
            $world->asOperator(new AddRelation($world->realm, new ResourceRef('nothing', 'x'), 'member', self::subject($manager)));
            self::fail('A relation of an undeclared type.');
        } catch (UnknownRelation $unknown) {
            self::assertSame('nothing', $unknown->type);
        }

        // A group's members as a relation's subject are weighed as the relation.
        $world->asOperator(new CreateGroup($world->realm, 'writers', 'Writers'));
        $world->asOperator(new AddRelation($world->realm, new ResourceRef('page', 'mine'), 'editor', self::subject($manager)));
        $world->as($manager, new AddRelation($world->realm, new ResourceRef('page', 'mine'), 'viewer', GroupRelations::members('writers')));
    }

    #[DataProvider('implementations')]
    public function testAParentLinkIsWeighedByWhatItBringsAlong(string $implementation): void
    {
        $world = $this->world($implementation);
        $mine = new ResourceRef('page', 'mine');
        $victim = new ResourceRef('page', 'victim');

        // H-2, the relations half: the relater's view of the drafts of its own page would follow to the victim.
        $relater = $this->manager($world);
        $world->asOperator(new AddRelation($world->realm, $mine, 'editor', self::subject($relater)));
        $this->refused($world, $relater, new AddRelation($world->realm, $victim, 'parent', new Subject($mine)), 'They\'d change what they don\'t hold themselves on page:victim: acme.pages.view_drafts.');

        // The roles half: a role on its own page would count on the victim.
        $editor = $this->manager($world, 'editor');
        $world->asOperator(new AssignRole($world->realm, self::id($editor), 'manager', $mine));
        $this->refused($world, $editor, new AddRelation($world->realm, $victim, 'parent', new Subject($mine)), 'They\'d change what they don\'t hold themselves on page:victim: acme.sites.update.');
        $this->refused($world, $editor, new AddRelation($world->realm, $victim, 'parent', new Subject($mine, 'editor')), 'They\'d change what they don\'t hold themselves on page:victim: acme.sites.update.');

        // Taking the link away takes what it brought: the relater views the victim's drafts only through it,
        // and holds that for what's within the victim through no role.
        $world->asOperator(new AddRelation($world->realm, $victim, 'parent', new Subject($mine)));
        $this->refused($world, $relater, new RemoveRelation($world->realm, $victim, 'parent', new Subject($mine)), 'They\'d change what they don\'t hold themselves on page:victim: acme.pages.view_drafts.');

        // Someone who holds all of it links freely.
        $world->asOperator(new AssignRole($world->realm, self::id($editor), 'manager'));
        $world->as($editor, new RemoveRelation($world->realm, $victim, 'parent', new Subject($mine)));
        $world->as($editor, new AddRelation($world->realm, $victim, 'parent', new Subject($mine)));
        self::assertTrue($world->allows($editor, 'acme.sites.update', $victim));
    }

    public function testRolesOnEveryResourceAboveTheParentCount(): void
    {
        $world = $this->world('memory');
        $top = new ResourceRef('page', 'top');
        $mine = new ResourceRef('page', 'mine');
        $victim = new ResourceRef('page', 'victim');
        $world->asOperator(new AddRelation($world->realm, $mine, 'parent', new Subject($top)));
        $editor = $this->manager($world, 'editor');
        $world->asOperator(new AssignRole($world->realm, self::id($editor), 'manager', $top));
        $this->refused($world, $editor, new AddRelation($world->realm, $victim, 'parent', new Subject($mine)), 'They\'d change what they don\'t hold themselves on page:victim: acme.sites.update.');

        // A super role, anyone's, on the parent: only someone with one links under it.
        $world->asOperator(new AssignRole($world->realm, self::id($world->principal()), 'admin', $mine));
        $world->asOperator(new AssignRole($world->realm, self::id($editor), 'manager'));
        $this->refused($world, $editor, new AddRelation($world->realm, $victim, 'parent', new Subject($mine)), '"admin" is a super role: only someone with one gives it or takes it.');
        $world->asOperator(new AssignRole($world->realm, self::id($editor), 'admin'));
        $world->as($editor, new AddRelation($world->realm, $victim, 'parent', new Subject($mine)));
    }

    public function testTheRolesAParentBringsAreHeldOnlyThroughARole(): void
    {
        $world = $this->world('memory');
        $o = new ResourceRef('page', 'o');
        $s = new ResourceRef('page', 's');

        // R1: a role on S counts on o and what's within it. As o's editor, the relater publishes o, but not
        // what's within o, where the role's holders would.
        $world->asOperator(new AssignRole($world->realm, self::id($world->principal()), 'editor', $s));
        $relater = $this->manager($world, 'member');
        $world->asOperator(new AssignRole($world->realm, self::id($relater), 'member', $o));
        $world->asOperator(new AddRelation($world->realm, $o, 'editor', self::subject($relater)));
        $world->asOperator(new GrantAbilities($world->realm, 'member', ['acme.pages.view_drafts']));
        $this->refused($world, $relater, new AddRelation($world->realm, $o, 'parent', new Subject($s)), 'They\'d change what they don\'t hold themselves on page:o: acme.pages.publish, acme.pages.delete.');

        // A role on o, or in the realm, holds them for every descendant.
        $world->asOperator(new AssignRole($world->realm, self::id($relater), 'editor', $o));
        $world->as($relater, new AddRelation($world->realm, $o, 'parent', new Subject($s)));
        $world->as($relater, new RemoveRelation($world->realm, $o, 'parent', new Subject($s)));
        $realm = $this->manager($world, 'editor');
        $world->as($realm, new AddRelation($world->realm, $o, 'parent', new Subject($s)));
    }

    public function testWhatALinkCarriesDownIsHeldOnlyThroughARole(): void
    {
        $world = $this->world('memory');
        $o = new ResourceRef('page', 'o');
        $s = new ResourceRef('page', 's');

        // C3: as an editor of o, the relater views o's drafts, but not those of what's within o, which
        // would inherit S's viewers.
        $relater = $this->manager($world);
        $world->asOperator(new AddRelation($world->realm, $o, 'editor', self::subject($relater)));
        $this->refused($world, $relater, new AddRelation($world->realm, $o, 'parent', new Subject($s)), 'They\'d change what they don\'t hold themselves on page:o: acme.pages.view_drafts.');

        // A role on o holds it for every descendant.
        $world->asOperator(new AssignRole($world->realm, self::id($relater), 'editor', $o));
        $world->as($relater, new AddRelation($world->realm, $o, 'parent', new Subject($s)));

        // The other way round, it's weighed on the child.
        $this->refused($world, $relater, new AddRelation($world->realm, $s, 'parent', new Subject(new ResourceRef('page', 'elsewhere'))), 'They\'d change what they don\'t hold themselves on page:s: acme.pages.view_drafts.');
    }

    public function testALinkThatClosesACycleIsNeverAPermit(): void
    {
        $world = $this->world('memory');
        $a = new ResourceRef('page', 'a');
        $b = new ResourceRef('page', 'b');
        $world->asOperator(new AddRelation($world->realm, $a, 'parent', new Subject($b)));
        $world->asOperator(new AddRelation($world->realm, $b, 'parent', new Subject($a)));
        $viewer = $world->principal();

        self::assertNotSame(Verdict::Permit, $world->decide($viewer, 'acme.pages.view_drafts', $a)->verdict);
        self::assertNotSame(Verdict::Permit, $world->decide($world->principal('editor'), 'acme.sites.update', $b)->verdict);
    }

    public function testDeletingAGroupIsWeighedByEverythingItTakesAway(): void
    {
        $world = $this->world('memory');
        $roles = $this->manager($world, 'member');
        $x = new ResourceRef('page', 'x');

        // M-6: the super role of every admin.
        $world->asOperator(new CreateGroup($world->realm, 'admins', 'Admins'));
        $world->asOperator(new AssignGroupRole($world->realm, 'admins', 'admin'));
        $this->refused($world, $roles, new DeleteGroup($world->realm, 'admins'), '"admin" is a super role: only someone with one gives it or takes it.');

        // C4: what's given to the group goes with it, weighed on its resource (C2).
        $world->asOperator(new CreateGroup($world->realm, 'writers', 'Writers'));
        $world->asOperator(new AddRelation($world->realm, $x, 'editor', GroupRelations::members('writers')));
        $world->asOperator(new AssignRole($world->realm, self::id($roles), 'editor', new ResourceRef('page', 'y')));
        $this->refused($world, $roles, new DeleteGroup($world->realm, 'writers'), 'They\'d change what they don\'t hold themselves on page:x: acme.pages.publish, acme.pages.view_drafts.');

        // And what's given to the groups it's within, which its members lose.
        $world->asOperator(new CreateGroup($world->realm, 'staff', 'Staff'));
        $world->asOperator(new CreateGroup($world->realm, 'interns', 'Interns'));
        $world->asOperator(new AddGroupMember($world->realm, 'staff', GroupRelations::members('interns')));
        $world->asOperator(new AddRelation($world->realm, $x, 'viewer', GroupRelations::members('staff')));
        $this->refused($world, $roles, new DeleteGroup($world->realm, 'interns'), 'They\'d change what they don\'t hold themselves on page:x: acme.pages.view_drafts.');

        // A role on the resource holds it there.
        $world->asOperator(new AssignRole($world->realm, self::id($roles), 'editor', $x));
        $world->as($roles, new DeleteGroup($world->realm, 'interns'));
        $world->as($roles, new DeleteGroup($world->realm, 'writers'));
    }

    public function testDeletingARoleIsWeighedAsTakingIt(): void
    {
        $world = $this->world('memory');
        $roles = $this->manager($world, 'member');
        $world->asOperator(new CreateRole($world->realm, 'siteadmin', 'Site admin'));
        $world->asOperator(new GrantAbilities($world->realm, 'siteadmin', ['acme.sites.update']));
        $this->refused($world, $roles, new DeleteRole($world->realm, 'siteadmin'), 'They\'d change what they don\'t hold themselves: acme.sites.update.');

        $world->asOperator(new AssignRole($world->realm, self::id($roles), 'manager'));
        $world->as($roles, new DeleteRole($world->realm, 'siteadmin'));
    }

    public function testNobodySetsTheirOwnSecurityAttributes(): void
    {
        $world = $this->world('memory');
        $world->asOperator(new GrantAbilities($world->realm, 'manager', [YorvoIDDeclarations::ATTRIBUTES_MANAGE]));
        $world->asOperator(new GrantAbilities($world->realm, 'member', [YorvoIDDeclarations::PRINCIPALS_MANAGE]));
        $attributes = $world->principal('manager');
        $accounts = $world->principal('member');
        $other = $world->principal();

        // M-3: not even to remove one.
        foreach (['research', null] as $value) {
            $this->refused($world, $attributes, new SetAttribute($world->realm, self::id($attributes), 'acme.department', $value), 'Nobody sets their own security attributes.');
        }

        $world->as($attributes, new SetAttribute($world->realm, self::id($other), 'acme.department', 'research'));

        try {
            $world->as($accounts, new SetAttribute($world->realm, self::id($other), 'acme.department', 'sales'));
            self::fail('Managing accounts set an attribute.');
        } catch (NotPermitted $refused) {
            self::assertSame(Verdict::Deny, $refused->decision->verdict);
        }

        $world->asOperator(new SetAttribute($world->realm, self::id($attributes), 'acme.department', 'research'));
    }

    private function world(string $implementation): PolicyWorld
    {
        return new PolicyWorld(stepUp: new StubStepUpCheck(), stack: 'memory' === $implementation ? null : $this->stack($implementation));
    }

    /**
     * A principal that manages roles and relations, and has these roles in the whole realm.
     */
    private function manager(PolicyWorld $world, string ...$roles): Actor
    {
        if (!$world->roles->has($world->realm, self::RELATER)) {
            $world->asOperator(new CreateRole($world->realm, self::RELATER, 'Relater'));
        }

        $world->asOperator(new GrantAbilities($world->realm, self::RELATER, [YorvoIDDeclarations::ROLES_MANAGE, YorvoIDDeclarations::RELATIONS_MANAGE]));

        return $world->principal(self::RELATER, ...$roles);
    }

    /**
     * @param Command<mixed> $command
     */
    private function refused(PolicyWorld $world, Actor $actor, Command $command, string $reason): void
    {
        try {
            $world->as($actor, $command);
            self::fail(\sprintf('Not refused: %s', $reason));
        } catch (NotPermitted $refused) {
            self::assertSame('A forbid applies: '.$reason, $refused->decision->reason);
        }
    }

    private static function subject(Actor $actor): Subject
    {
        return Subject::principal(self::id($actor));
    }

    private static function id(Actor $actor): PrincipalId
    {
        \assert(null !== $actor->principalId);

        return $actor->principalId;
    }
}
