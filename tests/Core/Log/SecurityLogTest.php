<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Log;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\Event\SecurityLogEntryRecorded;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\Stack;

final class SecurityLogTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testRecordsAnIntactChain(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $log = new SecurityLog($stack->log, $stack->transactions);
        $realm = new Realm('acme');

        self::assertTrue($log->verify($realm)->isIntact(), 'An empty log is intact.');

        $first = $log->record($realm, 'yorvoid.principal.create', Outcome::Success, new Origin(OriginKind::Console), subjectType: 'principal', subjectId: 'p-1', details: ['kind' => 'human']);
        $second = $log->record($realm, 'yorvoid.principal.suspend', Outcome::Denied, new Origin(OriginKind::Web, 'admin_users'));

        self::assertSame(1, $first->sequence);
        self::assertSame(SecurityLog::genesis($realm), $first->previousHash);
        self::assertSame($first->hash, $second->previousHash);

        $verification = $log->verify($realm);
        self::assertTrue($verification->isIntact());
        self::assertSame(2, $verification->entriesChecked);
        self::assertNull($verification->brokenAt);
        self::assertNotSame(SecurityLog::genesis($realm), SecurityLog::genesis(new Realm('globex')));
    }

    #[DataProvider('implementations')]
    public function testEntriesAreDispatchedOnlyOnceCommitted(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $events = new RecordingDispatcher();
        $log = new SecurityLog($stack->log, $stack->transactions, $events);
        $realm = Realm::default();

        $stack->transactions->run(static function () use ($log, $realm, $events): void {
            $log->record($realm, 'yorvoid.test.one', Outcome::Success, new Origin(OriginKind::System));
            self::assertSame([], $events->dispatched, 'The entry was dispatched before the commit.');
        });
        self::assertSame([SecurityLogEntryRecorded::class], $events->classes());

        try {
            $stack->transactions->run(static function () use ($log, $realm): void {
                $log->record($realm, 'yorvoid.test.two', Outcome::Success, new Origin(OriginKind::System));

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        self::assertCount(1, $events->dispatched, 'A rolled-back entry was dispatched.');
        self::assertTrue($log->verify($realm)->isIntact());
        self::assertSame(1, $log->verify($realm)->entriesChecked);
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function tampering(): iterable
    {
        foreach (self::platforms() as $name => [$platform]) {
            yield $name.': changed details' => [$platform, 'UPDATE %s SET details = \'{"kind":"agent"}\' WHERE sequence = 2', 2, 'its content doesn\'t match its hash: it was changed'];
            yield $name.': changed outcome' => [$platform, 'UPDATE %s SET outcome = \'success\' WHERE sequence = 2', 2, 'its content doesn\'t match its hash'];
            yield $name.': removed entry' => [$platform, 'DELETE FROM %s WHERE sequence = 2', 2, 'expected entry 2, found entry 3'];
            yield $name.': removed last entry' => [$platform, 'DELETE FROM %s WHERE sequence = 3', 3, 'The log ends at entry 2, but the realm\'s head says entry 3'];
            yield $name.': reordered entries' => [$platform, 'UPDATE %1$s SET sequence = -sequence WHERE sequence IN (2, 3); UPDATE %1$s SET sequence = 5 + sequence WHERE sequence IN (-2, -3)', 2, 'it doesn\'t link to the entry before it'];
            yield $name.': entry inserted before the first' => [$platform, 'INSERT INTO %1$s SELECT realm, 0, \'01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c\', recorded_at, action, outcome, origin_kind, origin_detail, correlation_id, actor, subject_type, subject_id, details, algorithm, previous_hash, hash FROM %1$s WHERE sequence = 1', 1, 'expected entry 1, found entry 0'];
            yield $name.': weaker algorithm' => [$platform, 'UPDATE %s SET algorithm = \'md5\' WHERE sequence = 2', 2, 'names the hash algorithm "md5", which isn\'t accepted'];
        }
    }

    #[DataProvider('tampering')]
    public function testDetectsTampering(string $platform, string $sql, int $brokenAt, string $problem): void
    {
        $stack = $this->stack($platform);
        $log = $this->threeEntries($stack);
        $database = $stack->database;
        \assert($database instanceof Database);

        foreach (explode('; ', \sprintf($sql, $database->table(DbalSecurityLogStore::TABLE))) as $statement) {
            $database->connection->executeStatement($statement);
        }

        $verification = $log->verify(Realm::default());
        self::assertFalse($verification->isIntact());
        self::assertSame($brokenAt, $verification->brokenAt);
        self::assertStringContainsString($problem, (string) $verification->problem);
    }

    #[DataProvider('platforms')]
    public function testDetectsAMissingHead(string $platform): void
    {
        $stack = $this->stack($platform);
        $log = $this->threeEntries($stack);
        $database = $stack->database;
        \assert($database instanceof Database);
        $database->connection->executeStatement(\sprintf('DELETE FROM %s', $database->table(DbalSecurityLogStore::HEAD_TABLE)));

        $verification = $log->verify(Realm::default());
        self::assertFalse($verification->isIntact());
        self::assertSame(3, $verification->entriesChecked);
        self::assertStringContainsString('The log ends at entry 3, but the realm\'s head says entry 0', (string) $verification->problem);
    }

    private function threeEntries(Stack $stack): SecurityLog
    {
        $log = new SecurityLog($stack->log, $stack->transactions);

        foreach (['yorvoid.principal.create', 'yorvoid.principal.suspend', 'yorvoid.principal.activate'] as $action) {
            $log->record(Realm::default(), $action, Outcome::Denied, new Origin(OriginKind::Console), details: ['kind' => 'human']);
        }

        self::assertTrue($log->verify(Realm::default())->isIntact());

        return $log;
    }
}
