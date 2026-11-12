<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\GroupRoleAssignment;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\Storage\CachedPolicyStore;
use YorvoID\Policy\Storage\InMemoryPolicyStore;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Storage\CachedRelationStore;
use YorvoID\Relations\Storage\InMemoryRelationStore;
use YorvoID\Relations\Subject;

/**
 * The cached stores read what the pool holds at the realm's version, and only what's of the shape the
 * store gives, for the realm and principal asked about; a request that changed a realm reads it from
 * the store, and the version once, until reset.
 */
final class CachedPolicyStoreTest extends TestCase
{
    private ArrayAdapter $pool;

    private Realm $realm;

    private PrincipalId $ada;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->realm = new Realm('acme');
        $this->ada = new PrincipalId(new UuidV7());
    }

    public function testWhatThePoolHoldsIsRead(): void
    {
        $store = $this->policy(new InMemoryPolicyStore());
        $at = new \DateTimeImmutable('2026-11-08 20:00:00');
        $this->hold('policy.0.roles', ['helper' => 'Helper']);
        $this->hold('policy.0.groups', ['writers' => 'Writers']);
        $this->hold('policy.0.changes', new AbilityChanges(['acme.pages.view' => ['helper']]));
        $this->hold('policy.0.'.self::read('assignments', [(string) $this->ada]), [new RoleAssignment($this->realm, $this->ada, 'helper', $at)]);
        $this->hold('policy.0.'.self::read('principals', ['helper']), [$this->ada]);
        $this->hold('policy.0.'.self::read('group_assignments', ['writers', 'admins']), [new GroupRoleAssignment($this->realm, 'writers', 'helper', $at)]);

        self::assertSame(['helper' => 'Helper'], $store->roles($this->realm));
        self::assertSame(['writers' => 'Writers'], $store->groups($this->realm));
        self::assertFalse($store->changes($this->realm)->isEmpty());
        self::assertCount(1, $store->assignments($this->realm, $this->ada));
        self::assertCount(1, $store->principalsWith($this->realm, 'helper'));
        self::assertCount(1, $store->groupAssignments($this->realm, ['writers', 'admins']));
        $this->hold('policy.0.'.self::read('roles_on', ['page:a', 'page:b|c']), ['helper']);
        self::assertSame(['helper'], $store->rolesOn($this->realm, [new ResourceRef('page', 'a'), new ResourceRef('page', 'b|c')]));
        self::assertSame([], $store->rolesOn($this->realm, [new ResourceRef('page', 'a|page:b'), new ResourceRef('page', 'c')]), 'Another list of resources.');
    }

    public function testWhatsNotWhatTheStoreGivesIsReadAgain(): void
    {
        $at = new \DateTimeImmutable('2026-11-08 20:00:00');
        $bob = new PrincipalId(new UuidV7());
        $other = new Realm('other');
        $cases = [
            'roles' => [['helper' => 1], [7 => 'Helper'], 'not labels'],
            'groups' => [['writers' => null]],
            'changes' => [['grant' => []]],
            self::read('assignments', [(string) $this->ada]) => [[new RoleAssignment($this->realm, $bob, 'admin', $at)], [new RoleAssignment($other, $this->ada, 'admin', $at)], ['x' => new RoleAssignment($this->realm, $this->ada, 'admin', $at)], [new GroupRoleAssignment($this->realm, 'admins', 'admin', $at)]],
            self::read('principals', ['admin']) => [['not an id']],
            self::read('roles_on', ['page:a']) => [[7], ['x' => 'helper']],
            self::read('group_assignments', ['writers', 'admins']) => [[new GroupRoleAssignment($this->realm, 'admins2', 'admin', $at)], [new GroupRoleAssignment($other, 'writers', 'admin', $at)], [new RoleAssignment($this->realm, $this->ada, 'admin', $at)]],
        ];

        foreach ($cases as $what => $values) {
            foreach ($values as $value) {
                $this->pool->clear();
                $this->hold('policy.0.'.$what, $value);
                $store = $this->policy(new InMemoryPolicyStore());

                self::assertSame([], $store->roles($this->realm), $what);
                self::assertSame([], $store->groups($this->realm), $what);
                self::assertTrue($store->changes($this->realm)->isEmpty(), $what);
                self::assertSame([], $store->assignments($this->realm, $this->ada), $what);
                self::assertSame([], $store->principalsWith($this->realm, 'admin'), $what);
                self::assertSame([], $store->groupAssignments($this->realm, ['writers', 'admins']), $what);
                self::assertSame([], $store->rolesOn($this->realm, [new ResourceRef('page', 'a')]), $what);
            }
        }
    }

    public function testARequestThatChangesARealmReadsItFromTheStore(): void
    {
        $inner = new InMemoryPolicyStore();
        $store = $this->policy($inner);
        $other = new Realm('other');
        $at = new \DateTimeImmutable('2026-11-08 20:00:00');
        $changes = [
            static fn (PolicyStore $store, Realm $realm) => $store->saveRole($realm, 'helper', 'Helper'),
            static fn (PolicyStore $store, Realm $realm) => $store->deleteRole($realm, 'helper'),
            static fn (PolicyStore $store, Realm $realm) => $store->saveChanges($realm, new AbilityChanges()),
            fn (PolicyStore $store, Realm $realm) => $store->assign(new RoleAssignment($realm, $this->ada, 'helper', $at)),
            fn (PolicyStore $store, Realm $realm) => $store->unassign($realm, $this->ada, 'helper', null),
            static fn (PolicyStore $store, Realm $realm) => $store->unassignRole($realm, 'helper'),
            fn (PolicyStore $store, Realm $realm) => $store->forgetPrincipal($realm, $this->ada),
            static fn (PolicyStore $store, Realm $realm) => $store->saveGroup($realm, 'writers', 'Writers'),
            static fn (PolicyStore $store, Realm $realm) => $store->deleteGroup($realm, 'writers'),
            static fn (PolicyStore $store, Realm $realm) => $store->assignGroup(new GroupRoleAssignment($realm, 'writers', 'helper', $at)),
            static fn (PolicyStore $store, Realm $realm) => $store->unassignGroup($realm, 'writers', 'helper', new ResourceRef('page', '1')),
            static fn (PolicyStore $store, Realm $realm) => $store->bump($realm),
        ];

        foreach ($changes as $i => $change) {
            $this->pool->clear();
            $store = $this->policy($inner);
            $inner->saveRole($this->realm, 'helper', 'Helper');
            $change($store, $this->realm);
            $inner->saveRole($this->realm, 'kept', 'Kept');

            $this->hold(\sprintf('policy.%d.roles', $inner->version($this->realm)), ['cached' => 'Cached']);
            self::assertArrayHasKey('kept', $store->roles($this->realm), 'Change '.$i.': not the pool\'s.');
            self::assertSame(['cached' => 'Cached'], $this->policy($inner)->roles($this->realm), 'Change '.$i.': another request reads the pool.');
            $inner->deleteRole($this->realm, 'kept');
        }

        $store = $this->policy($inner);
        $version = $inner->version($other);
        self::assertSame($version, $store->version($other));
        $inner->bump($other);
        self::assertSame($version, $store->version($other), 'The version is read once a request.');
        $store->reset();
        self::assertSame($version + 1, $store->version($other));
        $store->bump($other);
        self::assertSame($version + 2, $store->version($other), 'Its own change is read at once.');
        $inner->bump($other);
        self::assertSame($version + 3, $store->version($other), 'And from then on the store\'s.');
    }

    public function testTheRelationsAreReadAsThePolicyIs(): void
    {
        $inner = new InMemoryRelationStore();
        $page = new ResourceRef('page', '1');
        $tuple = new RelationTuple($this->realm, $page, 'editor', Subject::principal($this->ada));
        $this->hold('relations.0.'.self::read('of', ['page:1']), [$tuple]);
        $this->hold('relations.0.'.self::read('subjects', ['page:1', 'editor']), [new RelationTuple(new Realm('other'), $page, 'editor', Subject::principal($this->ada))]);
        $this->hold('relations.0.'.self::read('objects', ['editor', 'principal', (string) $this->ada, '']), 'not a list');
        $this->hold('relations.0.'.self::read('objects', ['viewer', 'principal', (string) $this->ada, '']), ['not a tuple']);
        $store = new CachedRelationStore($inner, $this->cache());

        self::assertSame([], $store->objects($this->realm, [Subject::principal($this->ada)], 'editor'), 'Not a list.');
        self::assertSame([], $store->objects($this->realm, [Subject::principal($this->ada)], 'viewer'), 'Not tuples.');
        self::assertEquals([$tuple], $store->of($this->realm, $page), 'What the pool holds: it\'s still asked.');
        self::assertSame([], $store->subjects($this->realm, [[$page, 'editor']]), 'Not of the realm.');

        // Each read is its own: other pairs, relations and subjects aren't the same read.
        $other = new CachedRelationStore($inner, new VersionedCache());
        $inner->add(new RelationTuple($this->realm, $page, 'viewer', Subject::principal($this->ada)));
        $inner->add(new RelationTuple($this->realm, new ResourceRef('page', '2'), 'editor', Subject::principal($this->ada)));
        $bob = Subject::principal(new PrincipalId(new UuidV7()));

        foreach ([[[$page, 'editor']], [[$page, 'viewer']], [[new ResourceRef('page', '2'), 'editor']], [[new ResourceRef('editor', '1'), 'editor']]] as $of) {
            self::assertEquals($inner->subjects($this->realm, $of), $other->subjects($this->realm, $of));
        }

        foreach ([[[Subject::principal($this->ada)], 'editor'], [[Subject::principal($this->ada)], 'viewer'], [[$bob], 'editor']] as [$subjects, $relation]) {
            self::assertEquals($inner->objects($this->realm, $subjects, $relation), $other->objects($this->realm, $subjects, $relation));
        }

        $inner->add($tuple);
        self::assertSame(0, $store->version($this->realm), 'Read once a request.');
        $store->reset();
        self::assertSame($inner->version($this->realm), $store->version($this->realm));

        foreach ([
            static fn (CachedRelationStore $store) => $store->add($tuple),
            static fn (CachedRelationStore $store) => $store->remove($tuple),
            fn (CachedRelationStore $store) => $store->forget($this->realm, $page),
        ] as $i => $change) {
            $store = new CachedRelationStore($inner, $this->cache());
            $this->hold(\sprintf('relations.%d.%s', $store->version($this->realm), self::read('of', ['page:1'])), []);
            $change($store);
            $this->hold(\sprintf('relations.%d.%s', $inner->version($this->realm), self::read('of', ['page:1'])), []);
            $inner->add(new RelationTuple($this->realm, $page, 'viewer', Subject::principal($this->ada)));
            self::assertNotSame([], $store->of($this->realm, $page), 'Change '.$i.': not the pool\'s.');
            self::assertCount(\count($inner->of($this->realm, $page)), $store->of($this->realm, $page), 'Change '.$i);
            self::assertSame($inner->version($this->realm), $store->version($this->realm), 'Change '.$i);
        }
    }

    private function policy(PolicyStore $store): CachedPolicyStore
    {
        return new CachedPolicyStore($store, $this->cache());
    }

    private function cache(): VersionedCache
    {
        return new VersionedCache($this->pool, Installation::named('test'));
    }

    private function hold(string $what, mixed $value): void
    {
        $this->pool->save($this->pool->getItem('yorvoid.v2.test.acme.'.$what)->set($value));
    }

    /**
     * A read's place in the pool: its name, and its arguments hashed.
     *
     * @param list<string> $of
     */
    private static function read(string $read, array $of): string
    {
        return [] === $of ? $read : $read.'-'.hash('sha256', json_encode($of, \JSON_THROW_ON_ERROR));
    }
}
