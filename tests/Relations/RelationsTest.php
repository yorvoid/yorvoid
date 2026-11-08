<?php

declare(strict_types=1);

namespace YorvoID\Tests\Relations;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;
use YorvoID\Policy\GroupRelations;
use YorvoID\Relations\RelationDeclarations;
use YorvoID\Relations\RelationDefinition;
use YorvoID\Relations\Relations;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\ResourceType;
use YorvoID\Relations\Storage\InMemoryRelationStore;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Relations\Fixtures\AcmeRelations;
use YorvoID\Tests\Relations\Fixtures\CountingRelationStore;

/**
 * Relations (AUZ-011): direct, implied, through subject sets and inherited from parents, a level at a
 * time and bounded.
 */
final class RelationsTest extends TestCase
{
    private InMemoryRelationStore $store;

    private Realm $realm;

    protected function setUp(): void
    {
        $this->store = new InMemoryRelationStore();
        $this->realm = new Realm('acme');
    }

    public function testDirectAndImpliedRelations(): void
    {
        $relations = $this->relations();
        $page = new ResourceRef('page', '1');
        $this->relate($page, 'editor', self::principal('ada'));

        self::assertTrue($relations->check($this->realm, $page, 'editor', self::principal('ada')));
        self::assertTrue($relations->check($this->realm, $page, 'viewer', self::principal('ada')), 'An editor is a viewer.');
        self::assertFalse($relations->check($this->realm, $page, 'editor', self::principal('bob')));
        self::assertFalse($relations->check($this->realm, new ResourceRef('page', '2'), 'viewer', self::principal('ada')));
        self::assertFalse($relations->check($this->realm, $page, 'owner', self::principal('ada')), 'Not a declared relation.');
        self::assertFalse($relations->check($this->realm, new ResourceRef('photo', '1'), 'viewer', self::principal('ada')), 'Not a declared type.');
        self::assertFalse($relations->check(new Realm('other'), $page, 'editor', self::principal('ada')));
    }

    public function testThroughSubjectSetsAndParents(): void
    {
        $relations = $this->relations();
        $page = new ResourceRef('page', '1');
        $site = new ResourceRef('site', 'a');
        $server = new ResourceRef('server', 's');
        $this->relate($page, 'editor', GroupRelations::members('writers'));
        $this->relate(GroupRelations::group('writers'), 'member', GroupRelations::members('juniors'));
        $this->relate(GroupRelations::group('juniors'), 'member', self::principal('ada'));
        $this->relate($page, 'parent', new Subject($site));
        $this->relate($site, 'parent', new Subject($server));
        $this->relate($server, 'admin', self::principal('bob'));

        self::assertTrue($relations->check($this->realm, $page, 'editor', self::principal('ada')), 'A member of a group within the editors\' group.');
        self::assertTrue($relations->check($this->realm, $page, 'viewer', self::principal('bob')), 'The server\'s admin views its sites\' pages.');
        self::assertFalse($relations->check($this->realm, $page, 'editor', self::principal('bob')), 'Only viewing is inherited.');
        self::assertTrue($relations->check($this->realm, $page, 'editor', GroupRelations::members('juniors')), 'A subject set asks as itself.');
        self::assertSame([$site, $server], $relations->ancestors($this->realm, $page));
        self::assertSame([], $relations->ancestors($this->realm, $server));
        self::assertSame([], $relations->ancestors($this->realm, $page, 'owner'));
        self::assertEquals([GroupRelations::group('juniors'), GroupRelations::group('writers')], $relations->memberships($this->realm, self::principal('ada'), 'group', 'member'));
        self::assertSame([], $relations->memberships($this->realm, self::principal('bob'), 'group', 'member'));
        self::assertSame([], $relations->memberships($this->realm, self::principal('ada'), 'team', 'member'), 'Only the type asked for.');
    }

    public function testCyclesEnd(): void
    {
        $relations = $this->relations();
        $this->relate(GroupRelations::group('a'), 'member', GroupRelations::members('b'));
        $this->relate(GroupRelations::group('b'), 'member', GroupRelations::members('a'));
        $this->relate(new ResourceRef('site', 'x'), 'parent', new Subject(new ResourceRef('site', 'y')));
        $this->relate(new ResourceRef('site', 'y'), 'parent', new Subject(new ResourceRef('site', 'x')));

        self::assertFalse($relations->check($this->realm, GroupRelations::group('a'), 'member', self::principal('ada')));
        self::assertEquals([GroupRelations::group('b'), GroupRelations::group('a')], $relations->memberships($this->realm, GroupRelations::members('a'), 'group', 'member'));
        self::assertEquals([new ResourceRef('site', 'y')], $relations->ancestors($this->realm, new ResourceRef('site', 'x')));
        self::assertFalse($relations->check($this->realm, new ResourceRef('site', 'x'), 'viewer', self::principal('ada')));
    }

    public function testManyAtOnceAsOneByOne(): void
    {
        $this->relate(new ResourceRef('page', '1'), 'editor', self::principal('ada'));
        $this->relate(new ResourceRef('page', '2'), 'parent', new Subject(new ResourceRef('site', 'a')));
        $this->relate(new ResourceRef('site', 'a'), 'parent', new Subject(new ResourceRef('server', 's')));
        $this->relate(new ResourceRef('server', 's'), 'admin', self::principal('bob'));
        $this->relate(new ResourceRef('page', '3'), 'editor', GroupRelations::members('writers'));
        $this->relate(GroupRelations::group('writers'), 'member', self::principal('ada'));
        $this->relate(new ResourceRef('page', '4'), 'parent', new Subject(new ResourceRef('site', 'x')));
        $this->relate(new ResourceRef('site', 'x'), 'parent', new Subject(new ResourceRef('site', 'y')));
        $this->relate(new ResourceRef('site', 'y'), 'parent', new Subject(new ResourceRef('site', 'x')));

        for ($i = 0; $i < 4; ++$i) {
            $this->relate(new ResourceRef('site', 's'.$i), 'parent', new Subject(new ResourceRef('site', 's'.($i + 1))));
        }

        $this->relate(new ResourceRef('page', '5'), 'parent', new Subject(new ResourceRef('site', 's0')));
        $objects = [...array_map(static fn (int $i): ResourceRef => new ResourceRef('page', (string) $i), range(1, 6)), new ResourceRef('site', 'a'), new ResourceRef('photo', '1'), new ResourceRef('page', '1')];

        foreach ([Relations::MAX_DEPTH, 3] as $depth) {
            $store = new CountingRelationStore($this->store);
            $relations = new Relations(RelationSchema::of([new AcmeRelations(), new GroupRelations()]), $store, $depth);

            foreach (['ada', 'bob', 'carl'] as $who) {
                foreach (['editor', 'viewer'] as $relation) {
                    $store->reads = 0;
                    $many = $relations->checkMany($this->realm, $objects, $relation, self::principal($who));
                    self::assertLessThanOrEqual($depth, $store->reads, 'One read per level, for all of them.');

                    foreach ($objects as $object) {
                        self::assertSame($relations->check($this->realm, $object, $relation, self::principal($who)), $many[(string) $object], \sprintf('%s %s of %s, %d deep', $who, $relation, $object, $depth));
                    }

                    self::assertCount(8, $many);
                }
            }

            $store->reads = 0;
            $ancestors = $relations->ancestorsOfMany($this->realm, $objects);
            self::assertLessThanOrEqual($depth, $store->reads);
            self::assertCount(8, $ancestors);

            foreach ($objects as $object) {
                self::assertEquals($relations->ancestors($this->realm, $object), $ancestors[(string) $object], (string) $object);
            }
        }

        self::assertSame([], $this->relations()->checkMany($this->realm, [], 'editor', self::principal('ada')));
        self::assertSame(['page:1' => false], $this->relations(maxDepth: 1)->checkMany($this->realm, [new ResourceRef('page', '1')], 'editor', self::principal('bob')), 'Told within the last level.');
        self::assertEquals([new ResourceRef('site', 'y')], $this->relations()->ancestorsOfMany($this->realm, [new ResourceRef('site', 'x')])['site:x'], 'A cycle ends.');

        // A subject set of an undeclared type is passed over, and the rest of its level read.
        $this->relate(new ResourceRef('page', '7'), 'editor', new Subject(new ResourceRef('album', '1'), 'viewer'));
        $this->relate(new ResourceRef('page', '7'), 'editor', GroupRelations::members('writers'));
        self::assertTrue($this->relations()->checkMany($this->realm, [new ResourceRef('page', '7')], 'editor', self::principal('ada'))['page:7']);

        // A parent isn't a subject of the relation it leads to.
        self::assertFalse($this->relations()->check($this->realm, new ResourceRef('page', '2'), 'viewer', new Subject(new ResourceRef('site', 'a'))));

        // A cycle further up ends too, each ancestor once.
        $this->relate(new ResourceRef('site', 'c1'), 'parent', new Subject(new ResourceRef('site', 'c2')));
        $this->relate(new ResourceRef('site', 'c2'), 'parent', new Subject(new ResourceRef('site', 'c3')));
        $this->relate(new ResourceRef('site', 'c3'), 'parent', new Subject(new ResourceRef('site', 'c2')));
        self::assertEquals([new ResourceRef('site', 'c2'), new ResourceRef('site', 'c3')], $this->relations()->ancestors($this->realm, new ResourceRef('site', 'c1')));
        self::assertSame([], $this->relations()->ancestorsOfMany($this->realm, []));
        self::assertNull($this->relations(maxDepth: 3)->checkMany($this->realm, $objects, 'viewer', self::principal('ada'))['page:5'], 'Deeper than the bound.');
        self::assertTrue($this->relations()->checkMany($this->realm, $objects, 'viewer', self::principal('bob'))['page:2']);
    }

    public function testDeeperThanTheBoundCantBeTold(): void
    {
        $relations = $this->relations(maxDepth: 3);

        for ($i = 0; $i < 4; ++$i) {
            $this->relate(GroupRelations::group('g'.$i), 'member', GroupRelations::members('g'.($i + 1)));
            $this->relate(new ResourceRef('site', 's'.$i), 'parent', new Subject(new ResourceRef('site', 's'.($i + 1))));
        }

        $this->relate(GroupRelations::group('g4'), 'member', self::principal('ada'));

        self::assertNull($relations->check($this->realm, GroupRelations::group('g0'), 'member', self::principal('ada')));
        self::assertTrue($relations->check($this->realm, GroupRelations::group('g2'), 'member', self::principal('ada')), 'Within the bound.');
        self::assertNull($relations->ancestors($this->realm, new ResourceRef('site', 's0')));
        self::assertCount(2, $relations->ancestors($this->realm, new ResourceRef('site', 's2')) ?? [], 'Within the bound.');
        self::assertNull($relations->memberships($this->realm, self::principal('ada'), 'group', 'member'));
    }

    public function testTheBoundIsExact(): void
    {
        $chain = function (int $length): void {
            $this->store = new InMemoryRelationStore();

            for ($i = 0; $i < $length; ++$i) {
                $this->relate(GroupRelations::group('g'.$i), 'member', GroupRelations::members('g'.($i + 1)));
                $this->relate(new ResourceRef('site', 's'.$i), 'parent', new Subject(new ResourceRef('site', 's'.($i + 1))));
            }

            $this->relate(GroupRelations::group('g'.$length), 'member', self::principal('ada'));
        };

        $chain(2);
        $relations = $this->relations(maxDepth: 3);
        self::assertTrue($relations->check($this->realm, GroupRelations::group('g0'), 'member', self::principal('ada')));
        self::assertCount(2, $relations->ancestors($this->realm, new ResourceRef('site', 's0')) ?? []);
        self::assertNull($relations->memberships($this->realm, self::principal('ada'), 'group', 'member'));

        $chain(1);
        self::assertCount(2, $this->relations(maxDepth: 3)->memberships($this->realm, self::principal('ada'), 'group', 'member') ?? []);

        $chain(3);
        $relations = $this->relations(maxDepth: 3);
        self::assertNull($relations->check($this->realm, GroupRelations::group('g0'), 'member', self::principal('ada')));
        self::assertNull($relations->ancestors($this->realm, new ResourceRef('site', 's0')));

        $chain(15);
        self::assertTrue($this->relations()->check($this->realm, GroupRelations::group('g0'), 'member', self::principal('ada')), 'Sixteen levels by default.');
        $chain(16);
        self::assertNull($this->relations()->check($this->realm, GroupRelations::group('g0'), 'member', self::principal('ada')));
    }

    public function testImpliedRelationsInACycleEnd(): void
    {
        $schema = RelationSchema::of([self::declaring(new ResourceType('doc', [new RelationDefinition('a', impliedBy: ['b'], grants: ['acme.docs.read']), new RelationDefinition('b', impliedBy: ['a'], grants: ['acme.docs.read', 'acme.docs.write'])]))]);
        $relations = new Relations($schema, $this->store);
        $this->relate(new ResourceRef('doc', '1'), 'b', self::principal('ada'));

        self::assertTrue($relations->check($this->realm, new ResourceRef('doc', '1'), 'a', self::principal('ada')));
        self::assertFalse($relations->check($this->realm, new ResourceRef('doc', '1'), 'a', self::principal('bob')));
        self::assertSame(['acme.docs.read', 'acme.docs.write'], $schema->abilitiesGivenBy('doc', 'a'), 'Each once.');
        self::assertSame(['acme.docs.read', 'acme.docs.write'], $schema->abilities());
        self::assertSame(['a', 'b'], $schema->granting('doc', 'acme.docs.read'));
    }

    public function testTheSchemaSaysWhatGivesWhat(): void
    {
        $schema = RelationSchema::of([new AcmeRelations()]);

        self::assertSame(['editor'], $schema->granting('page', 'acme.pages.publish'));
        self::assertSame(['viewer'], $schema->granting('page', 'acme.pages.view_drafts'));
        self::assertSame([], $schema->granting('photo', 'acme.pages.view_drafts'));
        self::assertSame(['acme.pages.publish', 'acme.pages.view_drafts'], $schema->abilitiesGivenBy('page', 'editor'), 'With what the relations it implies give.');
        self::assertSame(['acme.pages.view_drafts'], $schema->abilitiesGivenBy('page', 'viewer'));
        self::assertSame([], $schema->abilitiesGivenBy('page', 'owner'));
        self::assertSame(['acme.sites.update', 'acme.pages.publish', 'acme.pages.view_drafts'], $schema->abilities());
        self::assertSame('page', $schema->type('page')?->name);
        self::assertNull($schema->type('photo'));
        self::assertSame(['parent' => 'viewer'], $schema->relation('page', 'viewer')?->fromParents);
    }

    public function testRefusesASchemaThatDoesntFit(): void
    {
        $cases = [
            'The resource type "page" is declared twice.' => [new AcmeRelations(), self::declaring(new ResourceType('page', []))],
            'The relation "viewer" of "doc" refers to "editor", which "doc" doesn\'t declare.' => [self::declaring(new ResourceType('doc', [new RelationDefinition('viewer', impliedBy: ['editor'])]))],
            'The relation "viewer" of "doc" refers to "folder", which "doc" doesn\'t declare.' => [self::declaring(new ResourceType('doc', [new RelationDefinition('viewer', fromParents: ['folder' => 'viewer'])]))],
        ];

        foreach ($cases as $message => $declarations) {
            try {
                RelationSchema::of($declarations);
                self::fail(\sprintf('Built despite: %s', $message));
            } catch (IntegratorError $error) {
                self::assertStringStartsWith($message, $error->getMessage());
            }
        }

        foreach ([static fn () => new ResourceType('doc', [new RelationDefinition('a'), new RelationDefinition('a')]), static fn () => new RelationDefinition('Bad'), static fn () => new RelationDefinition('a', impliedBy: ['Bad']), static fn () => new RelationDefinition('a', fromParents: ['parent' => 'Bad']), static fn () => new RelationDefinition('a', fromParents: ['Bad' => 'viewer']), static fn () => new RelationDefinition(str_repeat('a', 65)), static fn () => new ResourceType('Doc', [])] as $invalid) {
            try {
                $invalid();
                self::fail('An invalid declaration was taken.');
            } catch (IntegratorError) {
                $this->addToAssertionCount(1);
            }
        }

        new RelationDefinition(str_repeat('a', 64));
    }

    public function testSubjectsAndTuplesAreWrittenAndRead(): void
    {
        $set = Subject::parse('group:editors#member');
        self::assertSame('group:editors#member', (string) $set);
        self::assertSame('member', $set->relation);
        self::assertSame('principal:ada', (string) Subject::parse('principal:ada'));
        self::assertNull(Subject::parse('principal:ada')->relation);
        self::assertFalse($set->equals(new Subject($set->resource)));
        self::assertFalse($set->equals(new Subject(new ResourceRef('group', 'other'), 'member')));
        $tuple = new RelationTuple($this->realm, new ResourceRef('page', '1'), 'editor', $set);
        self::assertSame('page:1#editor@group:editors#member', (string) $tuple);
        self::assertTrue($tuple->equals(new RelationTuple($this->realm, new ResourceRef('page', '1'), 'editor', $set)));
        self::assertFalse($tuple->equals(new RelationTuple(new Realm('other'), new ResourceRef('page', '1'), 'editor', $set)));
        self::assertFalse($tuple->equals(new RelationTuple($this->realm, new ResourceRef('page', '2'), 'editor', $set)));
        self::assertFalse($tuple->equals(new RelationTuple($this->realm, new ResourceRef('page', '1'), 'viewer', $set)));
        self::assertFalse($tuple->equals(new RelationTuple($this->realm, new ResourceRef('page', '1'), 'editor', self::principal('ada'))));

        foreach ([static fn () => Subject::parse('group:editors#Bad'), static fn () => Subject::parse('group:editors#member#more'), static fn () => new RelationTuple(new Realm('acme'), new ResourceRef('page', '1'), 'Bad', Subject::parse('principal:ada'))] as $invalid) {
            try {
                $invalid();
                self::fail('It was taken.');
            } catch (IntegratorError $error) {
                self::assertStringStartsWith('The relation name "', $error->getMessage());
            }
        }

        self::assertSame('The groups of a principal go deeper than the 16 levels relations are followed.', new \YorvoID\Relations\TooDeep('The groups of a principal')->getMessage());
    }

    private function relations(int $maxDepth = Relations::MAX_DEPTH): Relations
    {
        return new Relations(RelationSchema::of([new AcmeRelations(), new GroupRelations()]), $this->store, $maxDepth);
    }

    private function relate(ResourceRef $object, string $relation, Subject $subject): void
    {
        $this->store->add(new RelationTuple($this->realm, $object, $relation, $subject));
    }

    private static function principal(string $id): Subject
    {
        return new Subject(new ResourceRef('principal', $id));
    }

    private static function declaring(ResourceType ...$types): RelationDeclarations
    {
        return new readonly class(array_values($types)) implements RelationDeclarations {
            /**
             * @param list<ResourceType> $types
             */
            public function __construct(
                private array $types,
            ) {
            }

            public function types(): iterable
            {
                return $this->types;
            }
        };
    }
}
