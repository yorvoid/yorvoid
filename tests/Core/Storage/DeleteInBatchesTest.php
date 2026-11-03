<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Session\Storage\DbalKnownDeviceStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\StatementLog;

final class DeleteInBatchesTest extends DatabaseTestCase
{
    #[DataProvider('platforms')]
    public function testDeletesEveryMatchingRowABatchAtATime(string $platform): void
    {
        $statements = new StatementLog();
        $stack = $this->stack($platform, $statements);
        $database = $stack->database;
        \assert(null !== $database);
        $principal = new PrincipalId(new UuidV7());

        // Two rows for each day, one in each realm.
        foreach (['acme', 'globex'] as $realm) {
            foreach (range(1, 5) as $i) {
                $stack->devices->recordUse(new Realm($realm), $principal, hash('sha256', (string) $i), new \DateTimeImmutable(\sprintf('2026-10-%02d 12:00:00 UTC', $i)));
            }
        }

        $statements->take();
        $deleted = $database->deleteInBatches(DbalKnownDeviceStore::TABLE, 'last_seen_at < ?', [new \DateTimeImmutable('2026-10-04 00:00:00 UTC')], [Types::DATETIMETZ_IMMUTABLE], 4);

        self::assertSame(6, $deleted);
        self::assertCount(2, $statements->take(), 'A batch of 4, then one of 2: a batch smaller than the limit is the last.');
        // What was used on the 4th and 5th, in both realms, stays.
        $left = $database->connection->fetchFirstColumn(\sprintf('SELECT cookie_hash FROM %s ORDER BY cookie_hash', $database->table(DbalKnownDeviceStore::TABLE)));
        $expected = [hash('sha256', '4'), hash('sha256', '4'), hash('sha256', '5'), hash('sha256', '5')];
        sort($expected);
        self::assertSame($expected, $left);
    }

    #[DataProvider('platforms')]
    public function testAFullLastBatchTakesOneMoreStatementToKnow(string $platform): void
    {
        $statements = new StatementLog();
        $stack = $this->stack($platform, $statements);
        $database = $stack->database;
        \assert(null !== $database);

        foreach (range(1, 4) as $i) {
            $stack->devices->recordUse(new Realm('acme'), new PrincipalId(new UuidV7()), hash('sha256', (string) $i), new \DateTimeImmutable('2026-10-01 12:00:00 UTC'));
        }

        $statements->take();

        self::assertSame(4, $database->deleteInBatches(DbalKnownDeviceStore::TABLE, 'last_seen_at < ?', [new \DateTimeImmutable('2026-10-02 00:00:00 UTC')], [Types::DATETIMETZ_IMMUTABLE], 2));
        self::assertCount(3, $statements->take());
        self::assertSame(0, $database->deleteInBatches(DbalKnownDeviceStore::TABLE, '1 = 1'));
    }
}
