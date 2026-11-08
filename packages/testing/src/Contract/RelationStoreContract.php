<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Subject;

/**
 * What every RelationStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyRelationStoreTest extends TestCase
 * {
 *     use RelationStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function relationStore(string $implementation): RelationStore
 *     {
 *         return new MyRelationStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait RelationStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function relationStore(string $implementation): RelationStore;

    /**
     * The same store as another process has it: a new instance over the same data, e.g. on a second
     * connection. Override it for a store that processes share; one that lives in a single process,
     * as the in-memory one does, gives itself.
     */
    protected function sameRelationStore(string $implementation, RelationStore $store): RelationStore
    {
        return $store;
    }

    /**
     * Every process reads the version from what the store holds, never from a counter of its own.
     */
    #[DataProvider('implementations')]
    public function testEveryProcessReadsTheSameVersion(string $implementation): void
    {
        $store = $this->relationStore($implementation);
        $acme = new Realm('acme');
        $page = new ResourceRef('page', '1');
        $store->add(new RelationTuple($acme, $page, 'editor', new Subject(new ResourceRef('principal', 'ada'))));
        $other = $this->sameRelationStore($implementation, $store);

        self::assertSame(1, $other->version($acme), 'Another process reads the version the store holds.');
        self::assertCount(1, $other->of($acme, $page));
        $other->forget($acme, $page);
        self::assertSame(2, $store->version($acme), 'And this one reads its change.');
    }

    #[DataProvider('implementations')]
    public function testFindsTuplesByObjectAndBySubject(string $implementation): void
    {
        $store = $this->relationStore($implementation);
        $acme = new Realm('acme');
        $page = new ResourceRef('page', '1');
        $other = new ResourceRef('page', '2');
        $ada = new Subject(new ResourceRef('principal', 'ada'));
        $editors = new Subject(new ResourceRef('group', 'editors'), 'member');

        self::assertSame([], $store->subjects($acme, [[$page, 'editor']]));

        // Out of order: they come back by object, relation, then subject.
        $store->add(new RelationTuple($acme, $page, 'viewer', $ada));
        $store->add(new RelationTuple($acme, $page, 'editor', $editors));
        $store->add(new RelationTuple($acme, $page, 'editor', $ada));
        $store->add(new RelationTuple($acme, $page, 'editor', $ada));
        $store->add(new RelationTuple($acme, $other, 'editor', $ada));
        $store->add(new RelationTuple(new Realm('other'), $page, 'editor', $ada));

        self::assertSame(['page:1#editor@group:editors#member', 'page:1#editor@principal:ada'], self::strings($store->subjects($acme, [[$page, 'editor']])), 'Adding one again changes nothing.');
        self::assertSame(['page:1#editor@group:editors#member', 'page:1#editor@principal:ada', 'page:1#viewer@principal:ada', 'page:2#editor@principal:ada'], self::strings($store->subjects($acme, [[$other, 'editor'], [$page, 'viewer'], [$page, 'editor']])));
        self::assertSame(['page:1#editor@principal:ada', 'page:2#editor@principal:ada'], self::strings($store->objects($acme, [$ada], 'editor')));
        self::assertSame(['page:1#editor@group:editors#member', 'page:1#editor@principal:ada', 'page:2#editor@principal:ada'], self::strings($store->objects($acme, [$editors, $ada, new Subject(new ResourceRef('principal', 'bob'))], 'editor')));
        self::assertSame(['page:1#viewer@principal:ada'], self::strings($store->objects($acme, [$ada], 'viewer')));
        self::assertSame([], $store->objects($acme, [new Subject(new ResourceRef('group', 'editors'))], 'editor'), 'A group isn\'t its members.');
        self::assertSame(['page:1#editor@group:editors#member', 'page:1#editor@principal:ada', 'page:1#viewer@principal:ada'], self::strings($store->of($acme, $page)));
        self::assertSame(['page:1#editor@principal:ada'], self::strings($store->of(new Realm('other'), $page)));

        $tuple = $store->subjects($acme, [[$page, 'editor']])[0];
        self::assertTrue($tuple->realm->equals($acme));
        self::assertTrue($tuple->subject->equals($editors));
    }

    #[DataProvider('implementations')]
    public function testEveryChangeIncrementsTheRealmsVersion(string $implementation): void
    {
        $store = $this->relationStore($implementation);
        $acme = new Realm('acme');
        $page = new ResourceRef('page', '1');
        $ada = new Subject(new ResourceRef('principal', 'ada'));
        $editor = new RelationTuple($acme, $page, 'editor', $ada);

        self::assertSame(0, $store->version($acme));

        $store->add($editor);
        self::assertSame(1, $store->version($acme));
        $store->add($editor);
        self::assertSame(1, $store->version($acme), 'Adding one again changes nothing.');

        $store->add(new RelationTuple($acme, $page, 'viewer', $ada));
        self::assertTrue($store->remove($editor));
        self::assertSame(3, $store->version($acme));
        self::assertFalse($store->remove($editor));
        self::assertSame(3, $store->version($acme), 'Removing what isn\'t there changes nothing.');

        $store->forget($acme, new ResourceRef('page', '2'));
        self::assertSame(3, $store->version($acme), 'Forgetting what has no tuples changes nothing.');
        $store->forget($acme, $ada->resource);
        self::assertSame(4, $store->version($acme));

        $other = new Realm('other');
        $store->add(new RelationTuple($other, $page, 'viewer', $ada));
        $store->add(new RelationTuple($acme, $page, 'viewer', new Subject(new ResourceRef('principal', 'bob'))));
        $store->forget($acme, $page);
        self::assertSame(6, $store->version($acme), 'An add and a forget.');
        self::assertSame(1, $store->version($other));
        self::assertCount(1, $store->of($other, $page), 'Forgetting a resource in one realm leaves it in another.');
        self::assertSame(0, $store->version(new Realm('globex')), 'Each realm has its own.');
    }

    #[DataProvider('implementations')]
    public function testFindsManyPairsAtOnce(string $implementation): void
    {
        $store = $this->relationStore($implementation);
        $acme = new Realm('acme');
        $pairs = [];

        for ($i = 0; $i < 250; ++$i) {
            $store->add(new RelationTuple($acme, new ResourceRef('page', (string) $i), 'parent', new Subject(new ResourceRef('site', 'a'))));
            $pairs[] = [new ResourceRef('page', (string) $i), 'parent'];
        }

        self::assertCount(250, $store->subjects($acme, $pairs));
        self::assertCount(250, $store->objects($acme, [new Subject(new ResourceRef('site', 'a'))], 'parent'));
    }

    #[DataProvider('implementations')]
    public function testRemovesATupleAndForgetsAResource(string $implementation): void
    {
        $store = $this->relationStore($implementation);
        $acme = new Realm('acme');
        $page = new ResourceRef('page', '1');
        $ada = new Subject(new ResourceRef('principal', 'ada'));
        $bob = new Subject(new ResourceRef('principal', 'bob'));
        $store->add(new RelationTuple($acme, $page, 'editor', $ada));
        $store->add(new RelationTuple($acme, $page, 'editor', $bob));
        $store->add(new RelationTuple($acme, new ResourceRef('principal', 'ada'), 'manager', $bob));
        $store->add(new RelationTuple(new Realm('other'), $page, 'editor', $ada));

        $store->add(new RelationTuple(new Realm('other'), $page, 'editor', $bob));
        $store->add(new RelationTuple(new Realm('other'), new ResourceRef('page', '9'), 'editor', $ada));
        self::assertTrue($store->remove(new RelationTuple($acme, $page, 'editor', $bob)));
        self::assertSame(['page:1#editor@principal:ada', 'page:1#editor@principal:bob'], self::strings($store->of(new Realm('other'), $page)), 'Another realm\'s stays.');
        self::assertFalse($store->remove(new RelationTuple($acme, $page, 'editor', $bob)));
        self::assertFalse($store->remove(new RelationTuple($acme, $page, 'editor', new Subject(new ResourceRef('principal', 'ada'), 'member'))), 'A subject set isn\'t its resource.');
        self::assertSame(['page:1#editor@principal:ada'], self::strings($store->of($acme, $page)));

        $store->forget($acme, $ada->resource);
        self::assertSame([], $store->of($acme, $page), 'As a subject.');
        self::assertSame([], $store->of($acme, new ResourceRef('principal', 'ada')), 'As an object.');
        self::assertCount(2, $store->of(new Realm('other'), $page), 'Another realm\'s stay.');
        self::assertCount(1, $store->of(new Realm('other'), new ResourceRef('page', '9')), 'As a subject too.');
    }

    /**
     * @param list<RelationTuple> $tuples
     *
     * @return list<string>
     */
    private static function strings(array $tuples): array
    {
        return array_map(static fn (RelationTuple $tuple): string => (string) $tuple, $tuples);
    }
}
