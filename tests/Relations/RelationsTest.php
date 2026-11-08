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

        $this->expectException(IntegratorError::class);
        Subject::parse('group:editors#Bad');
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
