<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Stack;

/**
 * Every SecurityLogStore keeps the same promises (PLT-025).
 */
final class SecurityLogStoreTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testNumbersAndLinksEntries(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $realm = Realm::default();
        $seen = [];

        $first = $this->append($stack, $realm, $seen);
        $second = $this->append($stack, $realm, $seen);
        $third = $this->append($stack, $realm, $seen);

        self::assertSame([[1, null], [2, $first->hash], [3, $second->hash]], $seen);
        self::assertSame(['sequence' => 3, 'hash' => $third->hash], $stack->log->head($realm));
    }

    #[DataProvider('implementations')]
    public function testKeepsEveryField(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $realm = new Realm('acme');
        $details = ['kind' => 'human', 'nested' => ['b' => [1, 2, 3], 'a' => true, 'none' => null], 'text' => 'Ünïcödé "quoted" / slashed'];

        $entry = $stack->transactions->run(static fn (): LogEntry => $stack->log->append($realm, static fn (int $sequence, ?string $previous): LogEntry => LogEntry::create(
            $realm, $sequence, new UuidV7(), new \DateTimeImmutable('2026-10-27 18:40:59.987654 UTC'), 'yorvoid.principal.create', Outcome::Denied,
            new Origin(OriginKind::Console, 'yorvoid:principal:create', 'req-42'), new PrincipalId(new UuidV7()), 'principal', 'abc-123', $details, $previous ?? 'genesis',
        )));
        $bare = $stack->transactions->run(static fn (): LogEntry => $stack->log->append($realm, static fn (int $sequence, ?string $previous): LogEntry => LogEntry::create(
            $realm, $sequence, new UuidV7(), new \DateTimeImmutable('2026-10-27 18:41:00 UTC'), 'yorvoid.log.verify', Outcome::Success,
            new Origin(OriginKind::System, null, 'x'), null, null, null, [], (string) $previous,
        )));

        $read = iterator_to_array($stack->log->entries($realm, 1), false);

        self::assertEquals([$entry, $bare], $read);
        self::assertSame($entry->hash, $read[0]->computeHash(), 'A stored entry no longer matches its hash.');
        self::assertSame($bare->hash, $read[1]->computeHash());
    }

    #[DataProvider('implementations')]
    public function testKeepsTextThatWasntValidUtf8(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $realm = Realm::default();

        $entry = $stack->transactions->run(static fn (): LogEntry => $stack->log->append($realm, static fn (int $sequence, ?string $previous): LogEntry => LogEntry::create(
            $realm, $sequence, new UuidV7(), new \DateTimeImmutable(), 'yorvoid.session.sign_in', Outcome::Denied,
            new Origin(OriginKind::Web, "/login\xFF", 'req-1'), null, 'identifier', "grace\xC3", ['typed' => "grace\xC3\x28", "k\x80" => "\xE9"], $previous ?? 'genesis',
        )));

        $read = iterator_to_array($stack->log->entries($realm, 1), false);
        self::assertEquals([$entry], $read);
        self::assertSame($entry->hash, $read[0]->computeHash());
    }

    #[DataProvider('implementations')]
    public function testListsEntriesInOrderFromANumber(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $realm = Realm::default();
        $seen = [];

        for ($i = 0; $i < 5; ++$i) {
            $this->append($stack, $realm, $seen);
        }

        self::assertSame([1, 2, 3, 4, 5], array_map(static fn (LogEntry $entry): int => $entry->sequence, iterator_to_array($stack->log->entries($realm, 1), false)));
        self::assertSame([4, 5], array_map(static fn (LogEntry $entry): int => $entry->sequence, iterator_to_array($stack->log->entries($realm, 4), false)));
        self::assertSame([], iterator_to_array($stack->log->entries($realm, 6), false));
    }

    #[DataProvider('implementations')]
    public function testRealmsHaveChainsOfTheirOwn(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $seen = [];

        $this->append($stack, new Realm('acme'), $seen);
        $this->append($stack, new Realm('acme'), $seen);
        $this->append($stack, new Realm('globex'), $seen);

        self::assertSame([[1, null], [2, $seen[1][1]], [1, null]], [$seen[0], $seen[1], $seen[2]]);
        self::assertCount(2, iterator_to_array($stack->log->entries(new Realm('acme'), 1), false));
        self::assertCount(1, iterator_to_array($stack->log->entries(new Realm('globex'), 1), false));
        self::assertNull($stack->log->head(new Realm('initech')));
        self::assertSame([], iterator_to_array($stack->log->entries(new Realm('initech'), 1), false));
    }

    #[DataProvider('implementations')]
    public function testARolledBackAppendLeavesNothing(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $realm = Realm::default();
        $seen = [];
        $first = $this->append($stack, $realm, $seen);

        try {
            $stack->transactions->run(function () use ($stack, $realm, &$seen): void {
                $this->append($stack, $realm, $seen);

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(['sequence' => 1, 'hash' => $first->hash], $stack->log->head($realm));
        self::assertCount(1, iterator_to_array($stack->log->entries($realm, 1), false));
        self::assertSame(2, $this->append($stack, $realm, $seen)->sequence);
    }

    #[DataProvider('platforms')]
    public function testRefusesToAppendOutsideATransaction(string $platform): void
    {
        $stack = $this->stack($platform);

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('outside a transaction');

        $stack->log->append(Realm::default(), static fn (): LogEntry => throw new \LogicException('Not reached.'));
    }

    /**
     * @param list<array{int, ?string}> $seen What the store passed to the callback
     */
    private function append(Stack $stack, Realm $realm, array &$seen): LogEntry
    {
        return $stack->transactions->run(static function () use ($stack, $realm, &$seen): LogEntry {
            return $stack->log->append($realm, static function (int $sequence, ?string $previous) use ($realm, &$seen): LogEntry {
                $seen[] = [$sequence, $previous];

                return LogEntry::create($realm, $sequence, new UuidV7(), new \DateTimeImmutable(), 'yorvoid.test.append', Outcome::Success, new Origin(OriginKind::System), null, null, null, [], $previous ?? 'genesis');
            });
        });
    }
}
