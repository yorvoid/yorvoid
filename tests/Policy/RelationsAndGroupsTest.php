<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateGroup;
use YorvoID\Policy\Change\DeleteGroup;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\RemoveGroupMember;
use YorvoID\Policy\Change\UnassignGroupRole;
use YorvoID\Policy\GroupRelations;
use YorvoID\Policy\InvalidRole;
use YorvoID\Policy\UnknownGroup;
use YorvoID\Policy\UnknownRole;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Change\RemoveRelation;
use YorvoID\Relations\Subject;
use YorvoID\Relations\UnknownRelation;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Policy\Fixtures\StubStepUpCheck;

/**
 * Relations give abilities on resources, roles on a parent count for its children, and groups give
 * their members roles (AUZ-010, 011; IDN-056).
 */
final class RelationsAndGroupsTest extends TestCase
{
    public function testARelationGivesItsAbilitiesOnItsResource(): void
    {
        $world = new PolicyWorld();
        $ada = $world->principal();
        $page = new ResourceRef('page', '1');
        $world->asOperator(new AddRelation($world->realm, $page, 'editor', self::subject($ada)));
        $world->asOperator(new AddRelation($world->realm, $page, 'editor', self::subject($ada)));

        $decision = $world->decide($ada, 'acme.pages.publish', $page);
        self::assertSame(Verdict::Permit, $decision->verdict);
        self::assertSame('They\'re editor of page:1, which gives "acme.pages.publish".', $decision->reason);
        self::assertTrue($world->allows($ada, 'acme.pages.view_drafts', $page), 'An editor is a viewer.');
        self::assertFalse($world->allows($ada, 'acme.pages.publish', new ResourceRef('page', '2')));
        self::assertFalse($world->allows($ada, 'acme.pages.publish'), 'Not without the resource.');
        self::assertFalse($world->allows(new Anonymous(), 'acme.pages.publish', $page));
        self::assertSame('They have no relation to page:2 that gives "acme.pages.publish".', $world->decide($world->principal('member'), 'acme.pages.publish', new ResourceRef('page', '2'))->reasons[0]->truth->detail ?? null);

        self::assertTrue($world->asOperator(new RemoveRelation($world->realm, $page, 'editor', self::subject($ada))));
        self::assertFalse($world->asOperator(new RemoveRelation($world->realm, $page, 'editor', self::subject($ada))));
        self::assertFalse($world->allows($ada, 'acme.pages.publish', $page));

        self::assertSame([
            ['yorvoid.relation.add', 'page', '1', ['relation' => 'editor', 'subject' => (string) self::subject($ada)]],
            ['yorvoid.relation.add', 'page', '1', ['relation' => 'editor', 'subject' => (string) self::subject($ada)]],
            ['yorvoid.relation.remove', 'page', '1', ['relation' => 'editor', 'subject' => (string) self::subject($ada)]],
        ], array_map(static fn (LogEntry $entry): array => [$entry->action, $entry->subjectType, $entry->subjectId, $entry->details], self::entries($world, 'yorvoid.relation.')));
    }

    public function testOnlyDeclaredRelations(): void
    {
        $world = new PolicyWorld();

        foreach ([new AddRelation($world->realm, new ResourceRef('page', '1'), 'owner', self::subject($world->principal())), new AddRelation($world->realm, new ResourceRef('page', '1'), 'editor', new Subject(new ResourceRef('team', 'x'), 'member'))] as $command) {
            try {
                $world->asOperator($command);
                self::fail('An undeclared relation was added.');
            } catch (UnknownRelation $error) {
                self::assertStringStartsWith('The type "', $error->getMessage());
            }
        }

        self::assertSame('The type "page" has no relation "owner".', new UnknownRelation('page', 'owner')->getMessage());
    }

    public function testARoleOnAParentCountsForWhatsWithinIt(): void
    {
        $world = new PolicyWorld();
        $ada = $world->principal();
        $world->asOperator(new AddRelation($world->realm, new ResourceRef('site', 'a'), 'parent', new Subject(new ResourceRef('server', 's'))));
        $world->asOperator(new AssignRole($world->realm, self::id($ada), 'manager', new ResourceRef('server', 's')));

        self::assertTrue($world->allows($ada, 'acme.sites.update', new ResourceRef('site', 'a')), 'A manager of the server manages its sites.');
        self::assertTrue($world->allows($ada, 'acme.sites.update', new ResourceRef('server', 's')));
        self::assertFalse($world->allows($ada, 'acme.sites.update', new ResourceRef('site', 'b')));
    }

    public function testTooDeepIsNeverAPermit(): void
    {
        $world = new PolicyWorld(maxDepth: 2);
        $ada = $world->principal('editor');

        for ($i = 0; $i < 3; ++$i) {
            $world->asOperator(new AddRelation($world->realm, new ResourceRef('page', 'p'.$i), 'parent', new Subject(new ResourceRef('page', 'p'.($i + 1)))));
        }

        $decision = $world->decide($ada, 'acme.pages.publish', new ResourceRef('page', 'p0'));
        self::assertSame(Verdict::Indeterminate, $decision->verdict, 'Even a role in the whole realm: what it\'s within can\'t be told.');
        self::assertSame([IndeterminateReason::EvaluationError], $decision->indeterminate);
        self::assertTrue($world->allows($ada, 'acme.pages.publish', new ResourceRef('page', 'p2')));
    }

    public function testAGroupsMembersHaveItsRoles(): void
    {
        $world = new PolicyWorld();
        $ada = $world->principal();
        $world->asOperator(new CreateGroup($world->realm, 'writers', 'Writers'));
        $world->asOperator(new CreateGroup($world->realm, 'juniors', 'Juniors'));
        $world->asOperator(new CreateGroup($world->realm, 'juniors', 'Junior writers'));
        $world->asOperator(new AddGroupMember($world->realm, 'writers', GroupRelations::members('juniors')));
        $world->asOperator(new AddGroupMember($world->realm, 'juniors', self::subject($ada)));
        $assignment = $world->asOperator(new AssignGroupRole($world->realm, 'writers', 'editor'));
        $world->asOperator(new AssignGroupRole($world->realm, 'juniors', 'manager', new ResourceRef('site', 'a')));

        self::assertSame(['juniors' => 'Junior writers', 'writers' => 'Writers'], $world->memory->policy->groups($world->realm), 'Created again: relabelled.');
        self::assertSame('editor', $assignment->role);
        self::assertSame(['juniors', 'writers'], $world->roles->groupsOf($world->realm, self::id($ada)));
        self::assertTrue($world->allows($ada, 'acme.pages.publish'), 'From a group the group is within.');
        self::assertTrue($world->allows($ada, 'acme.sites.update', new ResourceRef('site', 'a')));
        self::assertFalse($world->allows($ada, 'acme.sites.update', new ResourceRef('site', 'b')));
        self::assertSame(['manager', 'editor'], $world->roles->rolesOfGroup($world->realm, 'juniors'), 'Its own, then those of the groups it\'s within.');
        self::assertSame(['editor'], $world->roles->rolesOfGroup($world->realm, 'writers'));

        self::assertTrue($world->asOperator(new UnassignGroupRole($world->realm, 'juniors', 'manager', new ResourceRef('site', 'a'))));
        self::assertFalse($world->asOperator(new UnassignGroupRole($world->realm, 'juniors', 'manager', new ResourceRef('site', 'a'))));
        self::assertTrue($world->asOperator(new RemoveGroupMember($world->realm, 'writers', GroupRelations::members('juniors'))));
        self::assertFalse($world->asOperator(new RemoveGroupMember($world->realm, 'writers', GroupRelations::members('juniors'))));
        self::assertFalse($world->allows($ada, 'acme.pages.publish'));

        $world->asOperator(new AddGroupMember($world->realm, 'writers', self::subject($ada)));
        $world->asOperator(new DeleteGroup($world->realm, 'writers'));
        self::assertFalse($world->allows($ada, 'acme.pages.publish'), 'A deleted group gives nothing.');
        self::assertSame(['juniors'], $world->roles->groupsOf($world->realm, self::id($ada)));
        self::assertSame([], $world->memory->policy->groupAssignments($world->realm, ['writers']));
        self::assertSame(['yorvoid.group.create', 'yorvoid.group.create', 'yorvoid.group.create', 'yorvoid.group.add_member', 'yorvoid.group.add_member', 'yorvoid.group.assign_role', 'yorvoid.group.assign_role', 'yorvoid.group.unassign_role', 'yorvoid.group.remove_member', 'yorvoid.group.add_member', 'yorvoid.group.delete'], array_map(static fn (LogEntry $entry): string => $entry->action, self::entries($world, 'yorvoid.group.')));
        self::assertSame(['label' => 'Writers'], self::entries($world, 'yorvoid.group.')[0]->details);
        self::assertSame(['role' => 'manager', 'scope' => 'site:a'], self::entries($world, 'yorvoid.group.')[6]->details);
    }

    public function testGroupsAndTheirMembersAreChecked(): void
    {
        $world = new PolicyWorld();
        $world->asOperator(new CreateGroup($world->realm, 'writers', 'Writers'));
        $cases = [
            UnknownGroup::class => [new AddGroupMember($world->realm, 'ghosts', self::subject($world->principal())), new AddGroupMember($world->realm, 'writers', GroupRelations::members('ghosts')), new DeleteGroup($world->realm, 'ghosts'), new AssignGroupRole($world->realm, 'ghosts', 'editor')],
            UnknownPrincipal::class => [new AddGroupMember($world->realm, 'writers', Subject::principal(new PrincipalId(new \Symfony\Component\Uid\UuidV7())))],
            InvalidRole::class => [new AddGroupMember($world->realm, 'writers', GroupRelations::members('writers')), new AddGroupMember($world->realm, 'writers', new Subject(new ResourceRef('page', '1'))), new AssignGroupRole($world->realm, 'writers', 'everyone'), new CreateGroup($world->realm, 'Bad', 'Bad')],
            UnknownRole::class => [new AssignGroupRole($world->realm, 'writers', 'ghost')],
        ];

        foreach ($cases as $exception => $commands) {
            foreach ($commands as $command) {
                try {
                    $world->asOperator($command);
                    self::fail(\sprintf('%s ran.', $command::class));
                } catch (\Throwable $error) {
                    self::assertInstanceOf($exception, $error, $command::class);
                }
            }
        }

        self::assertSame('There is no group "ghosts" in the realm "acme".', new UnknownGroup($world->realm, 'ghosts')->getMessage());
    }

    public function testNobodyGivesWhatTheyDontHoldThroughAGroupOrARelation(): void
    {
        $world = new PolicyWorld(stepUp: new StubStepUpCheck());
        $world->asOperator(new GrantAbilities($world->realm, 'manager', [YorvoIDDeclarations::ROLES_MANAGE, YorvoIDDeclarations::RELATIONS_MANAGE]));
        $manager = $world->principal('manager');
        $member = $world->principal();
        $world->asOperator(new CreateGroup($world->realm, 'writers', 'Writers'));
        $world->asOperator(new CreateGroup($world->realm, 'admins', 'Admins'));
        $world->asOperator(new AssignGroupRole($world->realm, 'writers', 'editor'));
        $world->asOperator(new AssignGroupRole($world->realm, 'admins', 'admin'));
        $page = new ResourceRef('page', '1');

        $refusals = [
            'They\'d change what they don\'t hold themselves: acme.pages.view_drafts, acme.pages.publish, acme.pages.delete.' => new AddGroupMember($world->realm, 'writers', self::subject($member)),
            '"admin" is a super role: only someone with one gives it or takes it.' => new AddGroupMember($world->realm, 'admins', self::subject($member)),
            'They\'d change what they don\'t hold themselves: acme.pages.view_drafts, acme.pages.publish, acme.pages.delete. ' => new AssignGroupRole($world->realm, 'writers', 'editor'),
            'They\'d change what they don\'t hold themselves: acme.pages.publish, acme.pages.view_drafts.' => new AddRelation($world->realm, $page, 'editor', self::subject($member)),
        ];

        foreach ($refusals as $reason => $command) {
            try {
                $world->as($manager, $command);
                self::fail($reason);
            } catch (NotPermitted $refused) {
                self::assertSame('A forbid applies: '.trim($reason), $refused->decision->reason);
            }
        }

        // An editor of the page gives what it holds there.
        $world->asOperator(new AddRelation($world->realm, $page, 'editor', self::subject($manager)));
        $world->as($manager, new AddRelation($world->realm, $page, 'viewer', self::subject($member)));
        $world->as($manager, new AddRelation($world->realm, $page, 'editor', self::subject($member)));
        self::assertTrue($world->allows($member, 'acme.pages.publish', $page));
    }

    public function testGivingASuperRoleThroughAGroupTakesARecentProof(): void
    {
        $stepUp = new StubStepUpCheck(proven: false);
        $world = new PolicyWorld(stepUp: $stepUp);
        $admin = $world->principal('admin');
        $world->asOperator(new CreateGroup($world->realm, 'admins', 'Admins'));
        $world->asOperator(new AssignGroupRole($world->realm, 'admins', 'admin'));

        foreach (['Giving the super role "admin" through the group "admins"' => new AddGroupMember($world->realm, 'admins', self::subject($world->principal())), 'Giving the super role "admin"' => new AssignGroupRole($world->realm, 'admins', 'admin')] as $what => $command) {
            try {
                $world->as($admin, $command);
                self::fail($what);
            } catch (NotPermitted $refused) {
                self::assertSame('Not proved recently.', $refused->decision->reason, $what);
            }
        }

        self::assertCount(2, $stepUp->asked);
    }

    public function testADeletedPrincipalKeepsNoRelation(): void
    {
        $world = new PolicyWorld();
        $ada = $world->principal();
        $world->asOperator(new AddRelation($world->realm, new ResourceRef('page', '1'), 'editor', self::subject($ada)));
        $world->memory->relations->add(new \YorvoID\Relations\RelationTuple($world->realm, new ResourceRef('page', '2'), 'editor', self::subject($ada)));
        $listener = new \YorvoID\Relations\ForgetRelationsOfDeletedPrincipals($world->memory->relations);
        $events = new \YorvoID\Tests\Support\RecordingDispatcher();
        $events->listen(\YorvoID\Core\Principal\Event\PrincipalStatusChanging::class, $listener(...));
        $principals = new \YorvoID\Core\Principal\Principals($world->memory->principals, $world->memory->identifiers, new \YorvoID\Core\Log\SecurityLog($world->memory->log, $world->memory->transactions), $world->memory->transactions, events: $events);

        \YorvoID\Tests\Support\Permits::call($principals->suspend(...), new \YorvoID\Core\Principal\SuspendPrincipal($world->realm, self::id($ada)), new \YorvoID\Core\Event\Origin(\YorvoID\Core\Event\OriginKind::Console));
        self::assertCount(1, $world->memory->relations->of($world->realm, new ResourceRef('page', '2')), 'Suspending keeps them.');
        \YorvoID\Tests\Support\Permits::call($principals->delete(...), new DeletePrincipal($world->realm, self::id($ada)), new \YorvoID\Core\Event\Origin(\YorvoID\Core\Event\OriginKind::Console));
        self::assertSame([], $world->memory->relations->of($world->realm, new ResourceRef('page', '1')));
        self::assertSame([], $world->memory->relations->of($world->realm, new ResourceRef('page', '2')));
    }

    private static function id(Actor $actor): PrincipalId
    {
        \assert(null !== $actor->principalId);

        return $actor->principalId;
    }

    private static function subject(Actor $actor): Subject
    {
        return Subject::principal(self::id($actor));
    }

    /**
     * @return list<LogEntry>
     */
    private static function entries(PolicyWorld $world, string $prefix): array
    {
        return array_values(array_filter(iterator_to_array($world->memory->log->entries($world->realm, 1), false), static fn (LogEntry $entry): bool => str_starts_with($entry->action, $prefix)));
    }
}
