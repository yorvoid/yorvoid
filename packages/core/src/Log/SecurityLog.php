<?php

declare(strict_types=1);

namespace YorvoID\Core\Log;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Event\SecurityLogEntryRecorded;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;

/**
 * The security log: an append-only, hash-chained record of every security-relevant event of a realm
 * (AUD-001, AUD-002). Entries are written in the transaction of the change they describe, so the log
 * holds exactly what was committed; each one is dispatched as an event once committed (AUD-010).
 *
 * The chain shows any change, removal, insertion or reordering of entries to whoever verifies it. On
 * its own it doesn't stop someone who can write the database from rewriting the whole chain: signed
 * checkpoints kept outside the database close that gap (AUD-003).
 *
 * @api
 */
final readonly class SecurityLog
{
    public function __construct(
        private SecurityLogStore $store,
        private Transactions $transactions,
        private EventDispatcherInterface $events = new NoEventListeners(),
        private IdGenerator $ids = new UuidV7Generator(),
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Records an entry, in the current transaction if one is open.
     *
     * @param string               $action  The operation, e.g. "yorvoid.principal.create"
     * @param array<string, mixed> $details Facts that help reconstruct what happened; never secrets
     */
    public function record(
        Realm $realm,
        string $action,
        Outcome $outcome,
        Origin $origin,
        ?PrincipalId $actor = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $details = [],
    ): LogEntry {
        return $this->transactions->run(function () use ($realm, $action, $outcome, $origin, $actor, $subjectType, $subjectId, $details): LogEntry {
            $entry = $this->store->append($realm, fn (int $sequence, ?string $previousHash): LogEntry => LogEntry::create(
                $realm,
                $sequence,
                $this->ids->next(),
                $this->clock->now(),
                $action,
                $outcome,
                $origin,
                $actor,
                $subjectType,
                $subjectId,
                $details,
                $previousHash ?? self::genesis($realm),
            ));

            $this->transactions->afterCommit(function () use ($entry): void {
                $this->events->dispatch(new SecurityLogEntryRecorded($entry));
            });

            return $entry;
        });
    }

    /**
     * Checks the realm's whole chain: numbering, links, every entry's hash, and that it ends where
     * the realm's head says.
     */
    public function verify(Realm $realm): Verification
    {
        $expected = 1;
        $previous = self::genesis($realm);
        $checked = 0;

        // Every entry, also any numbered below 1, which only someone tampering could have added.
        foreach ($this->store->entries($realm, \PHP_INT_MIN) as $entry) {
            $problem = match (true) {
                $entry->sequence !== $expected => \sprintf('expected entry %d, found entry %d: entries are missing or reordered', $expected, $entry->sequence),
                $entry->previousHash !== $previous => 'it doesn\'t link to the entry before it',
                !\in_array($entry->algorithm, LogEntry::ALGORITHMS, true) => \sprintf('it names the hash algorithm "%s", which isn\'t accepted', $entry->algorithm),
                $entry->computeHash() !== $entry->hash => 'its content doesn\'t match its hash: it was changed',
                default => null,
            };

            if (null !== $problem) {
                return new Verification($checked, $expected, \sprintf('Entry %d: %s.', $expected, $problem));
            }

            $previous = $entry->hash;
            ++$expected;
            ++$checked;
        }

        $head = $this->store->head($realm);
        $last = 0 === $checked ? null : ['sequence' => $expected - 1, 'hash' => $previous];

        if ($head !== $last) {
            return new Verification($checked, $expected, \sprintf('The log ends at entry %d, but the realm\'s head says entry %d: entries at the end were removed, or added outside YorvoID.', $expected - 1, $head['sequence'] ?? 0));
        }

        return new Verification($checked);
    }

    /**
     * What a realm's first entry links to.
     */
    public static function genesis(Realm $realm): string
    {
        return hash(LogEntry::ALGORITHM, 'yorvoid-security-log:'.$realm->name);
    }
}
