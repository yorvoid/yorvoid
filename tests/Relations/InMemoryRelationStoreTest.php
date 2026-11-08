<?php

declare(strict_types=1);

namespace YorvoID\Tests\Relations;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Storage\InMemoryRelationStore;
use YorvoID\Relations\Subject;

/**
 * A unit of work that fails puts the tuples and the version back.
 */
final class InMemoryRelationStoreTest extends TestCase
{
    public function testASnapshotRestoresTuplesAndVersion(): void
    {
        $store = new InMemoryRelationStore();
        $acme = new Realm('acme');
        $tuple = new RelationTuple($acme, new ResourceRef('page', '1'), 'editor', new Subject(new ResourceRef('principal', 'ada')));
        $store->add($tuple);
        $snapshot = $store->snapshot();

        $store->remove($tuple);
        $store->restore($snapshot);

        self::assertCount(1, $store->of($acme, $tuple->object));
        self::assertSame(1, $store->version($acme));
    }
}
