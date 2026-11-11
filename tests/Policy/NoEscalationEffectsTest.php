<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\Ability;
use YorvoID\Policy\Change\DeleteGroup;
use YorvoID\Policy\GroupRelations;
use YorvoID\Policy\NoEscalation;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyDeclarations;
use YorvoID\Policy\Role;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\Roles;
use YorvoID\Policy\Storage\InMemoryPolicyStore;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\RelationDeclarations;
use YorvoID\Relations\RelationDefinition;
use YorvoID\Relations\Relations;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\ResourceType;
use YorvoID\Relations\Storage\InMemoryRelationStore;
use YorvoID\Relations\Subject;
use YorvoID\Relations\TooDeep;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;

/**
 * M2-13: what no-escalation weighs a link and a deleted group by, on a schema with more to follow than
 * Acme's: folders hold documents, which hold comments.
 *
 * - folder: parent; owner, inherited through parent; member, implied by owner and guest; guest, implied by member
 * - doc: folder, the link, which gives moving it; reader and lister, inherited from the folder's members;
 *   peeker, from its guests; auditor, from a relation no folder has
 * - comment, declared first, so that what follows to it takes a second pass: doc; reader, from the doc's readers;
 *   checker, from its auditors; watcher, implied by reader
 * - reply, declared before it, so that what follows to it takes a third: comment; seer, from the comment's watchers
 * - note: parent, through which nothing is inherited
 */
final class NoEscalationEffectsTest extends TestCase
{
    private const array ABILITIES = [
        'folders-manage' => 'kb.folders.manage',
        'folders-view' => 'kb.folders.view',
        'docs-move' => 'kb.docs.move',
        'docs-read' => 'kb.docs.read',
        'docs-list' => 'kb.docs.list',
        'docs-peek' => 'kb.docs.peek',
        'docs-audit' => 'kb.docs.audit',
        'comments-see' => 'kb.comments.see',
        'comments-audit' => 'kb.comments.audit',
        'replies-see' => 'kb.replies.see',
    ];

    private Realm $realm;

    private InMemoryPolicyStore $policy;

    private InMemoryRelationStore $relations;

    private Roles $roles;

    private PolicyWorld $world;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->policy = new InMemoryPolicyStore();
        $this->relations = new InMemoryRelationStore();
        $this->roles = $this->roles(Relations::MAX_DEPTH);
        $this->world = new PolicyWorld();
    }

    public function testALinkIsWeighedByEverythingThatFollowsThroughIt(): void
    {
        $folder = new ResourceRef('folder', 'f');
        $under = new AddRelation($this->realm, $folder, 'parent', new Subject(new ResourceRef('folder', 'g')));

        // Through owner: managing the folder; through what it implies, the documents' readers, listers and
        // peekers (guest is two steps from owner); and on a second pass, their comments' readers.
        $everything = ['folders-manage', 'folders-view', 'docs-read', 'docs-list', 'docs-peek', 'comments-see', 'replies-see'];
        $this->assertWeighs([], $under, 'folders-manage', 'kb.folders.manage, kb.folders.view');

        foreach (['replies-see' => 'kb.replies.see', 'comments-see' => 'kb.comments.see', 'docs-peek' => 'kb.docs.peek', 'docs-list' => 'kb.docs.list'] as $lacking => $ability) {
            $this->assertWeighs(array_values(array_diff($everything, [$lacking])), $under, $lacking, $ability);
        }

        // Not what no folder relation leads to: the auditor's.
        $this->assertWeighs($everything, $under);
    }

    public function testALinkOtherThanAParentBringsNoRoles(): void
    {
        $doc = new ResourceRef('doc', 'd');
        $folder = new ResourceRef('folder', 'f');
        $into = new AddRelation($this->realm, $doc, 'folder', new Subject($folder));
        $this->policy->assign(new RoleAssignment($this->realm, $this->principal(), 'folders-manage', new \DateTimeImmutable(), $folder));
        $docs = ['docs-move', 'docs-read', 'docs-list', 'docs-peek', 'docs-audit'];

        // What the link gives itself, and what's inherited through it, each with every ability's role but its own.
        foreach ($docs as $lacking) {
            $this->assertWeighs([...array_diff($docs, [$lacking]), 'comments-see', 'comments-audit', 'replies-see'], $into, $lacking, self::ABILITIES[$lacking]);
        }

        // What it carries down to the comments, which only after what it gives on the doc.
        $this->assertWeighs([...$docs, 'comments-audit', 'replies-see'], $into, 'comments-see', 'kb.comments.see');
        $this->assertWeighs([...$docs, 'comments-see', 'replies-see'], $into, 'comments-audit', 'kb.comments.audit');
        $this->assertWeighs([...$docs, 'comments-see', 'comments-audit'], $into, 'replies-see', 'kb.replies.see');
        $this->assertWeighs(['docs-move', 'comments-see', 'comments-audit', 'replies-see'], $into, 'docs-read', 'kb.docs.read, kb.docs.list, kb.docs.peek, kb.docs.audit');
        $this->assertWeighs([...$docs, 'comments-see', 'comments-audit', 'replies-see'], $into);
    }

    public function testAParentThroughWhichNothingIsInheritedStillBringsItsRoles(): void
    {
        $parent = new ResourceRef('folder', 'p');
        $this->policy->assign(new RoleAssignment($this->realm, $this->principal(), 'docs-audit', new \DateTimeImmutable(), $parent));
        $note = new AddRelation($this->realm, new ResourceRef('note', 'n'), 'parent', new Subject($parent));

        $this->assertWeighs([], $note, 'docs-audit', 'kb.docs.audit');
        $this->assertWeighs(['docs-audit'], $note);

        // A type nothing declares is weighed as giving nothing; its handler refuses it.
        $this->assertWeighs([], new AddRelation($this->realm, new ResourceRef('nothing', 'n'), 'editor', new Subject($parent)));
    }

    public function testWhatCantBeToldIsNeverAPermit(): void
    {
        $this->roles = $this->roles(2);

        for ($i = 0; $i < 3; ++$i) {
            $this->relations->add(new RelationTuple($this->realm, new ResourceRef('folder', 'g'.$i), 'parent', new Subject(new ResourceRef('folder', 'g'.($i + 1)))));
            $this->relations->add(new RelationTuple($this->realm, GroupRelations::group('g'.($i + 1)), GroupRelations::MEMBER, GroupRelations::members('g'.$i)));
        }

        foreach ([new AddRelation($this->realm, new ResourceRef('note', 'n'), 'parent', new Subject(new ResourceRef('folder', 'g0'))), new DeleteGroup($this->realm, 'g0')] as $command) {
            try {
                $this->evaluate(array_keys(self::ABILITIES), $command);
                self::fail(\sprintf('%s was told.', $command::class));
            } catch (TooDeep) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDeletingAGroupWeighsEveryRelationOnItsOwnResource(): void
    {
        $group = GroupRelations::group('team');
        $this->relations->add(new RelationTuple($this->realm, GroupRelations::group('all'), GroupRelations::MEMBER, GroupRelations::members('team')));
        $this->relations->add(new RelationTuple($this->realm, new ResourceRef('doc', 'a'), 'peeker', new Subject($group)));
        $this->relations->add(new RelationTuple($this->realm, new ResourceRef('doc', 'b'), 'reader', GroupRelations::members('team')));
        $this->relations->add(new RelationTuple($this->realm, new ResourceRef('doc', 'c'), 'lister', GroupRelations::members('all')));
        $delete = new DeleteGroup($this->realm, 'team');

        // The group itself, its members, and the members of a group it's within: each counts, whichever comes first.
        $this->assertWeighs(['docs-read', 'docs-list'], $delete, 'docs-peek', 'kb.docs.peek', 'doc:a');
        $this->assertWeighs(['docs-peek', 'docs-list'], $delete, 'docs-read', 'kb.docs.read', 'doc:b');
        $this->assertWeighs(['docs-peek', 'docs-read'], $delete, 'docs-list', 'kb.docs.list', 'doc:c');
        $this->assertWeighs(['docs-peek', 'docs-read', 'docs-list'], $delete);

        // A super role of the group is weighed first, whatever the actor holds on the resources.
        $this->policy->assignGroup(new \YorvoID\Policy\GroupRoleAssignment($this->realm, 'all', 'boss', new \DateTimeImmutable()));
        $truth = $this->evaluate(['docs-peek', 'docs-read', 'docs-list'], $delete);
        self::assertSame(TruthValue::Holds, $truth->value);
        self::assertSame('"boss" is a super role: only someone with one gives it or takes it.', $truth->detail);
    }

    /**
     * That an actor with these roles may make the change, or that it's refused for what they lack.
     *
     * @param list<string>   $roles
     * @param Command<mixed> $command
     */
    private function assertWeighs(array $roles, Command $command, ?string $lacking = null, ?string $missing = null, ?string $on = null): void
    {
        $truth = $this->evaluate($roles, $command);

        if (null === $lacking) {
            self::assertSame(TruthValue::Fails, $truth->value, $truth->detail);

            return;
        }

        $resource = $on ?? ($command instanceof AddRelation ? (string) $command->object : null);
        self::assertSame(TruthValue::Holds, $truth->value, \sprintf('Without "%s".', $lacking));
        self::assertSame(\sprintf('They\'d change what they don\'t hold themselves%s: %s.', null === $resource ? '' : ' on '.$resource, $missing), $truth->detail, \sprintf('Without "%s".', $lacking));
    }

    /**
     * @param list<string>   $roles
     * @param Command<mixed> $command
     */
    private function evaluate(array $roles, Command $command): Truth
    {
        $actor = new ActingPrincipal($this->realm, $id = $this->principal());

        foreach ($roles as $role) {
            $this->policy->assign(new RoleAssignment($this->realm, $id, $role, new \DateTimeImmutable()));
        }

        $operation = $this->world->catalogue->get($command instanceof DeleteGroup ? 'yorvoid.group.delete' : 'yorvoid.relation.add');

        return new NoEscalation($this->roles)->evaluate(Request::forOperation($operation, $command, $actor, new Origin(OriginKind::Web), new \DateTimeImmutable()));
    }

    private function principal(): PrincipalId
    {
        return new PrincipalId(new UuidV7());
    }

    private function roles(int $maxDepth): Roles
    {
        $schema = RelationSchema::of([new class implements RelationDeclarations {
            public function types(): iterable
            {
                yield new ResourceType('reply', [
                    new RelationDefinition('comment'),
                    new RelationDefinition('seer', fromParents: ['comment' => 'watcher'], grants: ['kb.replies.see']),
                ]);
                yield new ResourceType('comment', [
                    new RelationDefinition('doc'),
                    new RelationDefinition('reader', fromParents: ['doc' => 'reader'], grants: ['kb.comments.see']),
                    new RelationDefinition('checker', fromParents: ['doc' => 'auditor'], grants: ['kb.comments.audit']),
                    new RelationDefinition('watcher', impliedBy: ['reader']),
                ]);
                yield new ResourceType('folder', [
                    new RelationDefinition('parent'),
                    new RelationDefinition('owner', fromParents: ['parent' => 'owner'], grants: ['kb.folders.manage']),
                    new RelationDefinition('member', impliedBy: ['owner', 'guest'], grants: ['kb.folders.view']),
                    new RelationDefinition('guest', impliedBy: ['member']),
                ]);
                yield new ResourceType('doc', [
                    new RelationDefinition('folder', grants: ['kb.docs.move']),
                    new RelationDefinition('reader', fromParents: ['folder' => 'member'], grants: ['kb.docs.read']),
                    new RelationDefinition('lister', fromParents: ['folder' => 'member'], grants: ['kb.docs.list']),
                    new RelationDefinition('peeker', fromParents: ['folder' => 'guest'], grants: ['kb.docs.peek']),
                    new RelationDefinition('auditor', fromParents: ['folder' => 'secret'], grants: ['kb.docs.audit']),
                ]);
                yield new ResourceType('note', [new RelationDefinition('parent')]);
            }
        }, new GroupRelations()]);

        $catalogue = PolicyCatalogue::of([new YorvoIDDeclarations(), new class(self::ABILITIES) implements PolicyDeclarations {
            /**
             * @param array<string, string> $abilities By the role that has it
             */
            public function __construct(
                private array $abilities,
            ) {
            }

            public function abilities(): iterable
            {
                foreach ($this->abilities as $role => $ability) {
                    yield new Ability($ability, 'An ability', 'kb', [$role]);
                }
            }

            public function roles(): iterable
            {
                foreach (array_keys($this->abilities) as $role) {
                    yield new Role($role, 'A role', 'kb');
                }

                yield new Role('boss', 'Boss', 'kb', super: true);
            }
        }]);

        return new Roles($catalogue, $this->policy, [], new Relations($schema, $this->relations, $maxDepth));
    }
}
