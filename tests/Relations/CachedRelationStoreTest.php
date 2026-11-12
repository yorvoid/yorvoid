<?php

declare(strict_types=1);

namespace YorvoID\Tests\Relations;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Policy\GroupRelations;
use YorvoID\Relations\Relations;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Storage\CachedRelationStore;
use YorvoID\Relations\Storage\InMemoryRelationStore;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Relations\Fixtures\CountingRelationStore;
use YorvoID\Tests\Relations\Fixtures\PoolOnlyRelationStore;

/**
 * M-4 (RELATIONS-04): no two reads share an entry, in a request or in the pool, whatever the IDs hold;
 * nor two realms, nor two versions of a realm's relations. What's read must answer the read.
 */
final class CachedRelationStoreTest extends TestCase
{
    private ArrayAdapter $pool;

    private InMemoryRelationStore $store;

    private Realm $realm;

    private Subject $ada;

    private Subject $bob;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->store = new InMemoryRelationStore();
        $this->realm = new Realm('acme');
        $this->ada = Subject::principal(new PrincipalId(new UuidV7()));
        $this->bob = Subject::principal(new PrincipalId(new UuidV7()));
    }

    /**
     * The audit's case. Bob is in admins and eng; Ada in a group whose ID spells both: joined with "|", her
     * second level read Bob's.
     */
    public function testAnIdThatSpellsTwoSubjectsIsntThem(): void
    {
        $this->crafted();

        self::assertSame(['admins', 'eng', 'superusers'], $this->groupsOf($this->bob));
        self::assertSame(['admins#member|group:eng'], $this->groupsOf($this->ada), 'Not Bob\'s groups.');
    }

    /**
     * The other way round, what only an unambiguous key prevents: Ada's read, which found nothing, answers
     * Bob's as well as any other empty one would, and Bob's groups would go missing (C2).
     */
    public function testAReadThatFoundNothingIsntAnothersAnswer(): void
    {
        $this->crafted();

        self::assertSame(['admins#member|group:eng'], $this->groupsOf($this->ada));
        self::assertSame(['admins', 'eng', 'superusers'], $this->groupsOf($this->bob), 'Bob\'s groups are all there.');
    }

    /**
     * One pool and one installation: each realm reads its own, and each version of a realm its own, in
     * the pool and in a request.
     */
    public function testTwoRealmsOrTwoVersionsNeverShareAnEntry(): void
    {
        $beta = new Realm('beta');
        $page = new ResourceRef('page', '1');
        $this->store->add(new RelationTuple($this->realm, $page, 'editor', $this->ada));
        $this->store->add(new RelationTuple($beta, $page, 'editor', $this->bob));
        $request = $this->cached();

        self::assertEquals([$this->ada], $this->editorsOf($request, $this->realm, $page));
        self::assertEquals([$this->bob], $this->editorsOf($request, $beta, $page), 'In a request.');
        self::assertEquals([$this->bob], $this->editorsOf($this->cached(), $beta, $page), 'In the pool.');

        // A request at version n, still reading; the change makes n + 1.
        $old = $this->cached();
        self::assertEquals([$this->ada], $this->editorsOf($old, $this->realm, $page));
        $n = $this->store->version($this->realm);
        $this->store->add(new RelationTuple($this->realm, $page, 'editor', $this->bob));

        self::assertEquals([$this->ada, $this->bob], $this->editorsOf($this->cached(), $this->realm, $page), 'The next request reads the new version.');
        self::assertEquals([$this->ada], $this->editorsOf($old, $this->realm, $page), 'The one at n still reads n, in its request.');
        self::assertEquals([$this->ada, $this->bob], $this->editorsOf($this->cached(), $this->realm, $page), 'And the pool holds n + 1.');

        $atOld = new CachedRelationStore(new PoolOnlyRelationStore($n), $this->cache());
        self::assertEquals([$this->ada], $this->editorsOf($atOld, $this->realm, $page), 'Another server at n reads n from the pool.');
    }

    /**
     * A pool's entry is read only if every tuple answers the read: of the relation and a subject asked
     * about, of a pair asked about, of the object asked about.
     */
    public function testAnEntryForAnotherReadIsReadAgain(): void
    {
        $page = new ResourceRef('page', '1');
        $other = new ResourceRef('page', '2');
        $this->store->add($tuple = new RelationTuple($this->realm, $page, 'editor', $this->ada));
        $version = $this->store->version($this->realm);
        $reads = [
            'objects' => [['editor', 'principal', $this->ada->resource->id, ''], fn (RelationStore $store): array => $store->objects($this->realm, [$this->ada], 'editor'), [new RelationTuple($this->realm, $page, 'viewer', $this->ada), new RelationTuple($this->realm, $page, 'editor', $this->bob)]],
            'subjects' => [['page:1', 'editor'], fn (RelationStore $store): array => $store->subjects($this->realm, [[$page, 'editor']]), [new RelationTuple($this->realm, $other, 'editor', $this->ada), new RelationTuple($this->realm, $page, 'viewer', $this->ada)]],
            'of' => [['page:1'], fn (RelationStore $store): array => $store->of($this->realm, $page), [new RelationTuple($this->realm, $other, 'editor', $this->ada)]],
        ];

        foreach ($reads as $read => [$of, $get, $planted]) {
            foreach ($planted as $i => $foreign) {
                $this->pool->clear();
                $this->pool->save($this->pool->getItem(\sprintf('yorvoid.v2.test.acme.relations.%d.%s-%s', $version, $read, hash('sha256', json_encode($of, \JSON_THROW_ON_ERROR))))->set([$tuple, $foreign]));

                self::assertEquals([$tuple], $get($this->cached()), $read.' '.$i);
            }

            // An entry that answers the read is what's read, from the pool.
            $answer = 'objects' === $read ? new RelationTuple($this->realm, $other, 'editor', $this->ada) : new RelationTuple($this->realm, $page, 'editor', $this->bob);
            $this->pool->clear();
            $this->pool->save($this->pool->getItem(\sprintf('yorvoid.v2.test.acme.relations.%d.%s-%s', $version, $read, hash('sha256', json_encode($of, \JSON_THROW_ON_ERROR))))->set([$answer]));
            self::assertEquals([$answer], $get($this->cached()), $read.' from the pool');
        }
    }

    /**
     * Subjects that differ only by their type, by having a relation, or by spelling one, are other reads.
     */
    public function testEachPartOfASubjectMakesItsOwnRead(): void
    {
        $subjects = [new Subject(new ResourceRef('group', 'a')), new Subject(new ResourceRef('team', 'a')), new Subject(new ResourceRef('group', 'a'), 'member'), new Subject(new ResourceRef('group', 'a#member'))];

        foreach ($subjects as $i => $subject) {
            $this->store->add(new RelationTuple($this->realm, new ResourceRef('group', 'g'.$i), 'member', $subject));
        }

        foreach ([$this->cached(), $this->cached()] as $request) {
            foreach ($subjects as $i => $subject) {
                self::assertSame(['g'.$i], array_map(static fn (RelationTuple $tuple): string => $tuple->object->id, $request->objects($this->realm, [$subject], 'member')), (string) $subject);
            }
        }
    }

    /**
     * Memberships take only what answers the read, even from a store that gives more.
     */
    public function testMembershipsAreOnlyThoseAskedAbout(): void
    {
        $counting = new CountingRelationStore($this->store);
        $this->store->add(new RelationTuple($this->realm, new ResourceRef('group', 'writers'), 'member', $this->ada));
        $counting->extra = [
            new RelationTuple($this->realm, new ResourceRef('group', 'admins'), 'member', $this->bob),
            new RelationTuple($this->realm, new ResourceRef('group', 'owners'), 'owner', $this->ada),
            new RelationTuple($this->realm, new ResourceRef('group', 'aliased'), 'member', new Subject(new ResourceRef('group', 'writers#member'))),
            new RelationTuple($this->realm, new ResourceRef('group', 'robots'), 'member', new Subject(new ResourceRef('robot', $this->ada->resource->id))),
        ];
        $relations = new Relations(RelationSchema::of([new GroupRelations()]), $counting, Relations::MAX_DEPTH);

        $groups = $relations->memberships($this->realm, $this->ada, GroupRelations::TYPE, GroupRelations::MEMBER) ?? [];
        self::assertSame(['writers'], array_map(static fn (ResourceRef $group): string => $group->id, $groups));
    }

    /**
     * Bob in admins and eng, superusers within admins; Ada in group:"admins#member|group:eng", made
     * straight in the store, as only a store of one's own could now.
     */
    private function crafted(): void
    {
        $group = static fn (string $id): ResourceRef => new ResourceRef(GroupRelations::TYPE, $id);
        $this->store->add(new RelationTuple($this->realm, $group('admins'), 'member', $this->bob));
        $this->store->add(new RelationTuple($this->realm, $group('eng'), 'member', $this->bob));
        $this->store->add(new RelationTuple($this->realm, $group('superusers'), 'member', new Subject($group('admins'), 'member')));
        $this->store->add(new RelationTuple($this->realm, $group('admins#member|group:eng'), 'member', $this->ada));
    }

    /**
     * In a request of its own, through the shared pool.
     *
     * @return list<string>
     */
    private function groupsOf(Subject $subject): array
    {
        $relations = new Relations(RelationSchema::of([new GroupRelations()]), $this->cached(), Relations::MAX_DEPTH);
        $groups = array_map(static fn (ResourceRef $group): string => $group->id, $relations->memberships($this->realm, $subject, GroupRelations::TYPE, GroupRelations::MEMBER) ?? []);
        sort($groups);

        return $groups;
    }

    /**
     * @return list<Subject>
     */
    private function editorsOf(RelationStore $store, Realm $realm, ResourceRef $page): array
    {
        return array_map(static fn (RelationTuple $tuple): Subject => $tuple->subject, $store->subjects($realm, [[$page, 'editor']]));
    }

    private function cached(): CachedRelationStore
    {
        return new CachedRelationStore($this->store, $this->cache());
    }

    private function cache(): VersionedCache
    {
        return new VersionedCache($this->pool, Installation::named('test'));
    }
}
