<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog;

use YorvoID\Core\Crypto\Digest;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\Transactions;

/**
 * Digests of the decision records in each realm's chain (D-42): refusals and failed attempts have no
 * chain entry of their own, so one entry, taken before each checkpoint, covers every record written
 * apart from a caller's transaction since the last. The checkpoint signs the chain, so it covers the
 * digest, and the digest every record in its window.
 *
 * A record younger than the window's end (the grace before now) isn't covered until the next digest: a
 * database writer can delete such a record, unnoticed (I3).
 *
 * @api
 */
final readonly class DecisionDigests
{
    public const string ACTION = 'yorvoid.decision.digest';

    public const string ALGORITHM = 'sha256';

    private Duration $grace;

    /**
     * @param Duration|null $grace How long a record may take to be written after it's decided, e.g. a
     *                             request's buffered records: a minute by default
     */
    public function __construct(
        private DecisionLogStore $store,
        private SecurityLog $log,
        private SecurityLogStore $entries,
        private Transactions $transactions,
        ?Duration $grace = null,
    ) {
        $this->grace = $grace ?? Duration::minutes(1);
    }

    /**
     * Appends the realm's digest of the records written since the last one, up to the grace before
     * now, and notes how far it got; nothing when there's nothing new.
     *
     * @return int How many records the digest covers: 0 when nothing was appended
     *
     * @phpstan-impure
     */
    public function take(Realm $realm): int
    {
        $until = $this->store->now() - $this->grace->seconds * 1_000_000;
        $after = $this->store->digestedUntil($realm);

        if ($until <= $after) {
            return 0;
        }

        $records = $this->store->written($realm, $after, $until);

        $this->transactions->run(function () use ($realm, $after, $until, $records): void {
            if ([] !== $records) {
                $this->log->record($realm, self::ACTION, Outcome::Success, Origin::system('decision-digest'), null, null, null, [
                    'after' => $after,
                    'until' => $until,
                    'count' => \count($records),
                    'algorithm' => self::ALGORITHM,
                    'root' => self::root($records),
                ]);
            }

            $this->store->digested($realm, $until);
        });

        return \count($records);
    }

    /**
     * Recomputes each digest in the realm's chain from the records the store holds.
     *
     * @phpstan-impure
     */
    public function verify(Realm $realm): DigestVerification
    {
        $prunedBefore = $this->store->prunedBefore();
        [$windows, $pruned, $last] = [0, 0, 0];
        $problems = [];

        foreach ($this->entries->entries($realm, 1) as $entry) {
            if (self::ACTION !== $entry->action) {
                continue;
            }

            ++$windows;
            $details = [];

            foreach ($entry->details as $key => $value) {
                $details[(string) $key] = $value;
            }

            $after = Row::int($details, 'after');
            $until = Row::int($details, 'until');
            $last = max($last, $until);

            if ($after < $prunedBefore) {
                ++$pruned;

                continue;
            }

            $records = $this->store->written($realm, $after, $until);
            $count = Row::int($details, 'count');

            if ($count !== \count($records) || Row::string($details, 'root') !== self::root($records)) {
                $problems[] = \sprintf('Entry %d digests %d record(s); %d are there, and they %s it.', $entry->sequence, $count, \count($records), Row::string($details, 'root') === self::root($records) ? 'match' : 'don\'t match');
            }
        }

        return new DigestVerification($windows, $problems, $pruned, \count($this->store->written($realm, $last, $this->store->now())));
    }

    /**
     * Whether each entry of the realm's chain that cites a decision cites one the decision log has: a
     * Permit, with the hash cited (D-50). An entry citing a decision that isn't there was permitted by
     * a decision point that records nothing, e.g. a second Operations; one written before the records
     * were pruned is passed over.
     *
     * @return list<string> Each entry whose citation doesn't hold
     *
     * @phpstan-impure
     */
    public function citations(Realm $realm): array
    {
        $prunedBefore = $this->store->prunedBefore();
        $problems = [];

        foreach ($this->entries->entries($realm, 1) as $entry) {
            $cited = $entry->details['decision'] ?? null;

            if (!\is_array($cited) || !\is_string($cited['id'] ?? null)) {
                continue;
            }

            $record = $this->store->find($realm, $cited['id']);

            if (null === $record) {
                if (self::microseconds($entry->recordedAt) >= $prunedBefore) {
                    $problems[] = \sprintf('Entry %d (%s) cites the decision %s, which the decision log doesn\'t have.', $entry->sequence, $entry->action, $cited['id']);
                }

                continue;
            }

            if (!\in_array($record->verdict, ['permit', 'permit_offline'], true)) {
                $problems[] = \sprintf('Entry %d (%s) cites the decision %s, which is a %s.', $entry->sequence, $entry->action, $cited['id'], $record->verdict);
            } elseif (\is_string($cited['hash'] ?? null) && !hash_equals($cited['hash'], $record->hash())) {
                $problems[] = \sprintf('Entry %d (%s) cites the decision %s with another hash than its record\'s.', $entry->sequence, $entry->action, $cited['id']);
            }
        }

        return $problems;
    }

    /**
     * An entry's time as the decision log counts it: microseconds since the epoch.
     */
    private static function microseconds(\DateTimeImmutable $at): int
    {
        return (int) $at->format('Uu');
    }

    /**
     * @param list<array{string, string}> $records Each one's ID and hash, in the order written
     */
    private static function root(array $records): string
    {
        return Digest::of(self::ALGORITHM, implode("\n", array_map(static fn (array $record): string => $record[0].':'.$record[1], $records)));
    }
}
