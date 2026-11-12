<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;
use YorvoID\Core\Migrations\CoreMigrations;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * The installation's ID: made once, by the migration, kept in the database, and random, so two
 * installations sharing a cache never share its entries.
 */
final class InstallationTest extends DatabaseTestCase
{
    #[DataProvider('platforms')]
    public function testTheMigrationMakesARandomId(string $platform): void
    {
        $database = $this->stack($platform)->database;
        \assert(null !== $database);
        $id = Installation::stored($database)->id();

        self::assertTrue(Uuid::isValid($id), $id);
        self::assertSame($id, Installation::stored($this->database($platform))->id(), 'Every process reads the same.');

        // Another installation beside it, with a prefix of its own.
        $another = $this->database($platform, 'other_');
        new Migrator($another, CoreMigrations::all())->migrate();
        self::assertNotSame($id, Installation::stored($another)->id(), 'Another installation has another.');
    }

    #[DataProvider('platforms')]
    public function testAnInstallationWithoutItsIdCantBeTold(string $platform): void
    {
        $database = $this->stack($platform)->database;
        \assert(null !== $database);
        $database->connection->executeStatement(\sprintf('DELETE FROM %s', $database->table(Installation::TABLE)));
        $installation = Installation::stored($database);

        try {
            $installation->id();
            self::fail('An ID was made up.');
        } catch (StorageException $e) {
            self::assertSame('The database has no installation ID: run YorvoID\'s migrations.', $e->getMessage());
        }

        // A cache whose installation can't be told keeps nothing in the pool.
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $pool = new ArrayAdapter();
        $reads = 0;
        new VersionedCache($pool, $installation, $logger)->get(new Realm('acme'), 'policy', 1, 'roles', [], static function () use (&$reads): array {
            ++$reads;

            return ['read'];
        }, \is_array(...));

        self::assertSame(1, $reads, 'Read from the store.');
        self::assertSame([], $pool->getValues());
        self::assertCount(1, $logger->messages);
    }

    public function testANamedInstallation(): void
    {
        self::assertSame('mine', Installation::named('mine')->id());
    }
}
