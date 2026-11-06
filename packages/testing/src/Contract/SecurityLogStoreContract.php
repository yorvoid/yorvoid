<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;

/**
 * What every SecurityLogStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must
 * too. Appends and locks run in a transaction, so the store comes with the Transactions it takes part in:
 *
 * ```php
 * final class MySecurityLogStoreTest extends TestCase
 * {
 *     use SecurityLogStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function securityLogStore(string $implementation): SecurityLogStore
 *     {
 *         return $this->log ??= new MySecurityLogStore(...); // empty, new for each test
 *     }
 *
 *     protected function transactions(string $implementation): Transactions
 *     {
 *         return $this->transactions ??= new MyTransactions(...); // the store's, for the same test
 *     }
 * }
 * ```
 *
 * @api
 */
trait SecurityLogStoreContract
{
    /**
     * The store to check, empty: a new one for each test, the same within it.
     */
    abstract protected function securityLogStore(string $implementation): SecurityLogStore;

    /**
     * The transactions the store takes part in, for the same test.
     */
    abstract protected function transactions(string $implementation): Transactions;

    #[DataProvider('implementations')]
    public function testNumbersAndLinksEntries(string $implementation): void
    {
        $log = $this->securityLogStore($implementation);
        $transactions = $this->transactions($implementation);
        $realm = Realm::default();
        $seen = [];

        $first = $this->appendTo($log, $transactions, $realm, $seen);
        $second = $this->appendTo($log, $transactions, $realm, $seen);
        $third = $this->appendTo($log, $transactions, $realm, $seen);

        self::assertSame([[1, null], [2, $first->hash], [3, $second->hash]], $seen);
        self::assertSame(['sequence' => 3, 'hash' => $third->hash], $log->head($realm));
    }

    #[DataProvider('implementations')]
    public function testKeepsEveryField(string $implementation): void
    {
        $log = $this->securityLogStore($implementation);
        $transactions = $this->transactions($implementation);
        $realm = new Realm('acme');
        $details = ['kind' => 'human', 'nested' => ['b' => [1, 2, 3], 'a' => true, 'none' => null], 'text' => 'Ünïcödé "quoted" / slashed'];

        $entry = $transactions->run(static fn (): LogEntry => $log->append($realm, static fn (int $sequence, ?string $previous): LogEntry => LogEntry::create(
            $realm, $sequence, new UuidV7(), new \DateTimeImmutable('2026-10-27 18:40:59.987654 UTC'), 'yorvoid.principal.create', Outcome::Denied,
            new Origin(OriginKind::Console, 'yorvoid:principal:create', 'req-42'), new PrincipalId(new UuidV7()), 'principal', 'abc-123', $details, $previous ?? 'genesis',
        )));
        $bare = $transactions->run(static fn (): LogEntry => $log->append($realm, static fn (int $sequence, ?string $previous): LogEntry => LogEntry::create(
            $realm, $sequence, new UuidV7(), new \DateTimeImmutable('2026-10-27 18:41:00 UTC'), 'yorvoid.log.verify', Outcome::Success,
            new Origin(OriginKind::System, null, 'x'), null, null, null, [], (string) $previous,
        )));

        $read = iterator_to_array($log->entries($realm, 1), false);

        self::assertEquals([$entry, $bare], $read);
        self::assertSame($entry->hash, $read[0]->computeHash(), 'A stored entry no longer matches its hash.');
        self::assertSame($bare->hash, $read[1]->computeHash());
    }

    #[DataProvider('implementations')]
    public function testKeepsTextThatWasntValidUtf8(string $implementation): void
    {
        $log = $this->securityLogStore($implementation);
        $transactions = $this->transactions($implementation);
        $realm = Realm::default();

        $entry = $transactions->run(static fn (): LogEntry => $log->append($realm, static fn (int $sequence, ?string $previous): LogEntry => LogEntry::create(
            $realm, $sequence, new UuidV7(), new \DateTimeImmutable(), 'yorvoid.session.sign_in', Outcome::Denied,
            new Origin(OriginKind::Web, "/login\xFF", 'req-1'), null, 'identifier', "grace\xC3", ['typed' => "grace\xC3\x28", "k\x80" => "\xE9"], $previous ?? 'genesis',
        )));

        $read = iterator_to_array($log->entries($realm, 1), false);
        self::assertEquals([$entry], $read);
        self::assertSame($entry->hash, $read[0]->computeHash());
    }

    /**
     * What PostgreSQL can't hold as text, NUL, and a detail longer than its column, are recorded all the
     * same, and the chain holds.
     */
    #[DataProvider('implementations')]
    public function testKeepsNulsAndLongDetails(string $implementation): void
    {
        $log = $this->securityLogStore($implementation);
        $transactions = $this->transactions($implementation);
        $realm = Realm::default();
        $origin = new Origin(OriginKind::Web, "/login\0".str_repeat('é', 300), 'req-1');

        $entry = $transactions->run(static fn (): LogEntry => $log->append($realm, static fn (int $sequence, ?string $previous): LogEntry => LogEntry::create(
            $realm, $sequence, new UuidV7(), new \DateTimeImmutable(), 'yorvoid.session.sign_in', Outcome::Denied,
            $origin, null, 'identifier', "grace\0", ['typed' => "a\0b", "k\0" => ["\0"]], $previous ?? 'genesis',
        )));

        self::assertSame([255, "/login\u{FFFD}".str_repeat('é', 248)], [mb_strlen((string) $origin->detail), $origin->detail]);
        self::assertSame(["grace\u{FFFD}", ['k'."\u{FFFD}" => ["\u{FFFD}"], 'typed' => "a\u{FFFD}b"]], [$entry->subjectId, $entry->details]);
        $read = iterator_to_array($log->entries($realm, 1), false);
        self::assertEquals([$entry], $read);
        self::assertSame($entry->hash, $read[0]->computeHash());
    }

    #[DataProvider('implementations')]
    public function testListsEntriesInOrderFromANumber(string $implementation): void
    {
        $log = $this->securityLogStore($implementation);
        $transactions = $this->transactions($implementation);
        $realm = Realm::default();
        $seen = [];

        for ($i = 0; $i < 5; ++$i) {
            $this->appendTo($log, $transactions, $realm, $seen);
        }

        self::assertSame([1, 2, 3, 4, 5], array_map(static fn (LogEntry $entry): int => $entry->sequence, iterator_to_array($log->entries($realm, 1), false)));
        self::assertSame([4, 5], array_map(static fn (LogEntry $entry): int => $entry->sequence, iterator_to_array($log->entries($realm, 4), false)));
        self::assertSame([], iterator_to_array($log->entries($realm, 6), false));
    }

    #[DataProvider('implementations')]
    public function testRealmsHaveChainsOfTheirOwn(string $implementation): void
    {
        $log = $this->securityLogStore($implementation);
        $transactions = $this->transactions($implementation);
        $seen = [];

        $this->appendTo($log, $transactions, new Realm('acme'), $seen);
        $this->appendTo($log, $transactions, new Realm('acme'), $seen);
        $this->appendTo($log, $transactions, new Realm('globex'), $seen);

        self::assertSame([[1, null], [2, $seen[1][1]], [1, null]], [$seen[0], $seen[1], $seen[2]]);
        self::assertCount(2, iterator_to_array($log->entries(new Realm('acme'), 1), false));
        self::assertCount(1, iterator_to_array($log->entries(new Realm('globex'), 1), false));
        self::assertNull($log->head(new Realm('initech')));
        self::assertSame([], iterator_to_array($log->entries(new Realm('initech'), 1), false));
    }

    #[DataProvider('implementations')]
    public function testARolledBackAppendLeavesNothing(string $implementation): void
    {
        $log = $this->securityLogStore($implementation);
        $transactions = $this->transactions($implementation);
        $realm = Realm::default();
        $seen = [];
        $first = $this->appendTo($log, $transactions, $realm, $seen);

        try {
            $transactions->run(function () use ($log, $transactions, $realm, &$seen): void {
                $this->appendTo($log, $transactions, $realm, $seen);

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(['sequence' => 1, 'hash' => $first->hash], $log->head($realm));
        self::assertCount(1, iterator_to_array($log->entries($realm, 1), false));
        self::assertSame(2, $this->appendTo($log, $transactions, $realm, $seen)->sequence);
    }

    #[DataProvider('implementations')]
    public function testLockingLeavesTheChainAsItWas(string $implementation): void
    {
        $log = $this->securityLogStore($implementation);
        $transactions = $this->transactions($implementation);
        $realm = new Realm('acme');
        $seen = [];

        $transactions->run(static fn () => $log->lock($realm));
        self::assertNull($log->head($realm), 'Locking a realm without entries adds none.');

        $first = $this->appendTo($log, $transactions, $realm, $seen);
        $transactions->run(static fn () => $log->lock($realm));
        $second = $this->appendTo($log, $transactions, $realm, $seen);

        self::assertSame([[1, null], [2, $first->hash]], $seen);
        self::assertSame(['sequence' => 2, 'hash' => $second->hash], $log->head($realm));
    }

    /**
     * @param list<array{int, ?string}> $seen What the store passed to the callback
     */
    private function appendTo(SecurityLogStore $log, Transactions $transactions, Realm $realm, array &$seen): LogEntry
    {
        return $transactions->run(static function () use ($log, $realm, &$seen): LogEntry {
            return $log->append($realm, static function (int $sequence, ?string $previous) use ($realm, &$seen): LogEntry {
                $seen[] = [$sequence, $previous];

                return LogEntry::create($realm, $sequence, new UuidV7(), new \DateTimeImmutable(), 'yorvoid.test.append', Outcome::Success, new Origin(OriginKind::System), null, null, null, [], $previous ?? 'genesis');
            });
        });
    }
}
