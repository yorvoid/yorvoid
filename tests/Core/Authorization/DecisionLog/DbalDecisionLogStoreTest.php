<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\DecisionLog;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * A row whose stored record names another decision is refused, not taken for the one asked for.
 */
final class DbalDecisionLogStoreTest extends DatabaseTestCase
{
    #[DataProvider('platforms')]
    public function testARecordMovedToAnotherRowIsRefused(string $platform): void
    {
        $database = $this->stack($platform)->database;
        \assert($database instanceof Database);
        $store = new DbalDecisionLogStore($database);
        $acme = new Realm('acme');
        $at = new \DateTimeImmutable('2026-11-08 18:00:00 UTC');
        $records = [];

        foreach (['acme', 'globex'] as $i => $realm) {
            $records[] = new DecisionRecord('01a1bb94-1a77-7914-84c3-24e0b5f324c'.$i, new Realm($realm), $at, 'ability', 'acme.pages.edit', null, null, 'deny', null, 'No.', [], [], [], [], ['web', 'r']);
        }

        $records[] = new DecisionRecord('01a1bb94-1a77-7914-84c3-24e0b5f324c2', $acme, $at, 'ability', 'acme.pages.edit', null, null, 'deny', null, 'No.', [], [], [], [], ['web', 'r']);
        $store->add($records);
        $table = $database->table(DbalDecisionLogStore::TABLE);

        // The first row now holds the record of another decision of its realm; the third, a record with its ID in another realm.
        $database->connection->update($table, ['content' => $records[2]->json()], ['id' => $records[0]->id]);
        $elsewhere = new DecisionRecord($records[2]->id, new Realm('globex'), $at, 'ability', 'acme.pages.edit', null, null, 'deny', null, 'No.', [], [], [], [], ['web', 'r']);
        $database->connection->update($table, ['content' => $elsewhere->json()], ['id' => $records[2]->id]);

        foreach ([$records[0]->id, $records[2]->id] as $id) {
            try {
                $store->find($acme, $id);
                self::fail(\sprintf('The record of %s was taken as its own.', $id));
            } catch (\UnexpectedValueException $e) {
                self::assertSame(\sprintf('The stored decision %s doesn\'t match its row.', $id), $e->getMessage());
            }
        }
    }

    /**
     * With AuditFailure::Refuse, a record must be durable when the decision is returned: on a connection
     * of its own, a rollback of the application's doesn't take it back; on the application's, it does.
     */
    #[DataProvider('platforms')]
    public function testARecordWrittenApartOutlivesTheApplicationsRollback(string $platform): void
    {
        $database = $this->stack($platform)->database;
        \assert($database instanceof Database);
        $apart = new DbalDecisionLogStore($database, $this->connect($platform));
        $shared = new DbalDecisionLogStore($database);
        $acme = new Realm('acme');
        $at = new \DateTimeImmutable('2026-11-10 18:00:00 UTC');
        $kept = new DecisionRecord('01a1bb94-1a77-7914-84c3-24e0b5f324d0', $acme, $at, 'ability', 'acme.pages.edit', null, null, 'permit', 'ability', 'A permit applies.', [], [], [], [], ['web', 'r']);
        $lost = new DecisionRecord('01a1bb94-1a77-7914-84c3-24e0b5f324d1', $acme, $at, 'ability', 'acme.pages.edit', null, null, 'permit', 'ability', 'A permit applies.', [], [], [], [], ['web', 'r']);

        self::assertTrue($apart->writesApart());
        self::assertFalse($shared->writesApart());

        $database->connection->beginTransaction();
        $apart->add([$kept]);
        $shared->add([$lost]);
        $database->connection->rollBack();

        self::assertSame($kept->hash(), $shared->find($acme, $kept->id)?->hash());
        self::assertNull($shared->find($acme, $lost->id), 'On the application\'s connection, the rollback took it.');
    }
}
