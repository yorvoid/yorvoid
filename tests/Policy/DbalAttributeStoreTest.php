<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Policy\Storage\DbalAttributeStore;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * A stored value that isn't one of an attribute's types is refused, never read as something else.
 */
final class DbalAttributeStoreTest extends DatabaseTestCase
{
    public function testRefusesAValueThatIsntOne(): void
    {
        $stack = $this->stackOf('sqlite');
        \assert(null !== $stack->database);
        $store = new DbalAttributeStore($stack->database);
        $ada = new PrincipalId(Ids::next());

        foreach (['{"a":"b"}', '[1]', '1.5', 'null', '["a",null]'] as $value) {
            $stack->database->connection->delete($stack->database->table(DbalAttributeStore::TABLE), ['realm' => 'acme']);
            $stack->database->connection->insert($stack->database->table(DbalAttributeStore::TABLE), ['realm' => 'acme', 'principal_id' => $ada->id->toString(), 'name' => 'acme.x', 'value' => $value]);

            try {
                $store->of(new Realm('acme'), $ada);
                self::fail(\sprintf('%s was read.', $value));
            } catch (StorageException $error) {
                self::assertSame('The attribute "acme.x" holds something that isn\'t a value.', $error->getMessage());
            }
        }

        $stack->database->connection->delete($stack->database->table(DbalAttributeStore::TABLE), ['realm' => 'acme']);
        $store->set(new Realm('acme'), $ada, 'acme.x', 'a/ü');
        self::assertSame(['acme.x' => 'a/ü'], $store->of(new Realm('acme'), $ada));
    }
}
