<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Log\Checkpoint;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Log\Checkpoint\Checkpoint;
use YorvoID\Core\Log\Checkpoint\Checkpoints;
use YorvoID\Core\Log\Checkpoint\FileCheckpoints;
use YorvoID\Core\Log\Checkpoint\LoggerCheckpoints;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Stack;

/**
 * What the chain can't find against a database writer, checkpoints kept outside the database find
 * (AUD-003, H-2): entries cut off from the end, and a history rewritten with its chain recomputed.
 */
final class CheckpointsTest extends DatabaseTestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = \sprintf('%s/yorvoid-checkpoints-%s.jsonl', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->file);
        parent::tearDown();
    }

    #[DataProvider('platforms')]
    public function testFindsEntriesCutOffTheEnd(string $platform): void
    {
        [$stack, $log, $checkpoints, $file] = $this->setUpLog($platform);
        $realm = new Realm('acme');
        self::assertNull($checkpoints->take($realm), 'An empty log was checkpointed.');
        self::record($log, 3);
        $checkpoints->take($realm);
        self::assertTrue($checkpoints->verify($realm, $file->read($realm))->isIntact());

        // A database writer removes the last entry and moves the head back: the chain alone can't tell.
        $database = $stack->database;
        \assert($database instanceof Database);
        $last = iterator_to_array($stack->log->entries($realm, 2), false)[0];
        $database->connection->executeStatement(\sprintf('DELETE FROM %s WHERE sequence = 3', $database->table(DbalSecurityLogStore::TABLE)));
        $database->connection->executeStatement(\sprintf('UPDATE %s SET sequence = 2, hash = ? WHERE realm = ?', $database->table(DbalSecurityLogStore::HEAD_TABLE)), [$last->hash, 'acme']);
        self::assertTrue($log->verify($realm)->isIntact(), 'The chain alone found it: this test tests nothing.');

        $verification = $checkpoints->verify($realm, $file->read($realm));
        self::assertFalse($verification->isIntact());
        self::assertSame(3, $verification->brokenAt);
        self::assertStringContainsString('Entry 3, checkpointed at 2026-11-04T18:00:00+00:00, isn\'t in the log: entries at the end were removed.', (string) $verification->problem);
    }

    #[DataProvider('platforms')]
    public function testFindsAHistoryRewrittenWithItsChain(string $platform): void
    {
        [$stack, $log, $checkpoints, $file] = $this->setUpLog($platform);
        $realm = new Realm('acme');
        self::record($log, 3);
        $checkpoints->take($realm);
        self::record($log, 1);

        // Everything deleted and recorded again, differently: a whole new, consistent chain.
        $database = $stack->database;
        \assert($database instanceof Database);
        $database->connection->executeStatement(\sprintf('DELETE FROM %s', $database->table(DbalSecurityLogStore::TABLE)));
        $database->connection->executeStatement(\sprintf('DELETE FROM %s', $database->table(DbalSecurityLogStore::HEAD_TABLE)));
        self::record($log, 4, 'yorvoid.principal.activate');
        self::assertTrue($log->verify($realm)->isIntact());

        $verification = $checkpoints->verify($realm, $file->read($realm));
        self::assertSame(3, $verification->brokenAt);
        self::assertStringContainsString('Entry 3 isn\'t what was checkpointed at 2026-11-04T18:00:00+00:00: the log up to it was rewritten.', (string) $verification->problem);
        self::assertSame('Entry 2: its content doesn\'t match its hash: it was changed.', $this->brokenChain($stack, $checkpoints, $file), 'The chain\'s own verification comes first.');
    }

    public function testRefusesCheckpointsTheRingDidntSign(): void
    {
        [, $log, $checkpoints, $file] = $this->setUpLog('sqlite');
        $realm = new Realm('acme');
        self::record($log, 2);
        $taken = $checkpoints->take($realm);
        \assert(null !== $taken);

        $other = new KeyRing([SigningKey::generate('elsewhere')]);
        $forged = new Checkpoint($realm, 2, $taken->hash, $taken->takenAt, 'elsewhere', $other->signer()->sign(Checkpoint::message($realm, 2, $taken->hash, $taken->takenAt)));
        $moved = new Checkpoint($realm, 1, $taken->hash, $taken->takenAt, $taken->keyId, $taken->signature);
        $elsewhere = new Checkpoint(new Realm('globex'), 2, $taken->hash, $taken->takenAt, $taken->keyId, $taken->signature);

        foreach ([$forged, $moved, $elsewhere] as $checkpoint) {
            self::assertStringContainsString('isn\'t signed by a key in the ring', (string) $checkpoints->verify($realm, [$checkpoint])->problem);
        }

        // A line of the file that isn't a checkpoint counts as a changed one.
        file_put_contents($this->file, "not a checkpoint\n", \FILE_APPEND);
        self::assertStringContainsString('isn\'t signed by a key in the ring', (string) $checkpoints->verify($realm, $file->read($realm))->problem);
        self::assertSame([], iterator_to_array(new FileCheckpoints($this->file.'.missing')->read($realm)), 'A file that isn\'t there has checkpoints.');
    }

    public function testCheckpointsGoToEverySinkAndRoundTrip(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string|\Stringable, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        [, $log, $checkpoints, $file] = $this->setUpLog('sqlite', [new LoggerCheckpoints($logger)]);
        $realm = new Realm('acme');
        self::record($log, 1);
        $taken = $checkpoints->take($realm);
        \assert(null !== $taken);

        self::assertEquals([$taken], iterator_to_array($file->read($realm), false));
        self::assertSame([], iterator_to_array($file->read(new Realm('globex')), false));
        self::assertSame([['notice', 'yorvoid.log.checkpoint', $taken->toArray()]], $logger->records);
        self::assertEquals($taken, Checkpoint::fromArray($taken->toArray()));
        self::assertNull(Checkpoint::fromArray(['signature' => '!'] + $taken->toArray()));
        self::assertNull(Checkpoint::fromArray('text'));
    }

    /**
     * @param list<\YorvoID\Core\Log\Checkpoint\CheckpointSink> $sinks
     *
     * @return array{Stack, SecurityLog, Checkpoints, FileCheckpoints}
     */
    private function setUpLog(string $platform, array $sinks = []): array
    {
        $stack = $this->stack($platform);
        $log = new SecurityLog($stack->log, $stack->transactions);
        $file = new FileCheckpoints($this->file);

        return [$stack, $log, new Checkpoints($stack->log, $log, new KeyRing([SigningKey::generate('2026-11-04')]), [$file, ...$sinks], new MockClock('2026-11-04 18:00:00 UTC')), $file];
    }

    private static function record(SecurityLog $log, int $entries, string $action = 'yorvoid.principal.create'): void
    {
        for ($i = 0; $i < $entries; ++$i) {
            $log->record(new Realm('acme'), $action, Outcome::Success, new Origin(OriginKind::Console));
        }
    }

    private function brokenChain(Stack $stack, Checkpoints $checkpoints, FileCheckpoints $file): string
    {
        $database = $stack->database;
        \assert($database instanceof Database);
        $database->connection->executeStatement(\sprintf('UPDATE %s SET outcome = ? WHERE sequence = 2', $database->table(DbalSecurityLogStore::TABLE)), ['failed']);

        return (string) $checkpoints->verify(new Realm('acme'), $file->read(new Realm('acme')))->problem;
    }
}
