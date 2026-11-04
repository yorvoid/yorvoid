<?php

declare(strict_types=1);

namespace YorvoID\Core\Log\Checkpoint;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Log\Verification;
use YorvoID\Core\Realm;

/**
 * Takes checkpoints of the security log, and verifies the log against them (AUD-003). Take one
 * regularly, e.g. every few minutes from cron: what's recorded after the last one has only the chain's
 * protection until the next.
 *
 * @api
 */
final readonly class Checkpoints
{
    /**
     * @param list<CheckpointSink> $sinks Every one gets every checkpoint
     */
    public function __construct(
        private SecurityLogStore $store,
        private SecurityLog $log,
        private KeyRing $keys,
        private array $sinks,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Signs where the realm's log stands, and gives it to every sink.
     *
     * @return Checkpoint|null Null while the realm's log is empty
     */
    public function take(Realm $realm): ?Checkpoint
    {
        $head = $this->store->head($realm);

        if (null === $head) {
            return null;
        }

        $takenAt = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        $signer = $this->keys->signer();
        $checkpoint = new Checkpoint($realm, $head['sequence'], $head['hash'], $takenAt, $signer->id, $signer->sign(Checkpoint::message($realm, $head['sequence'], $head['hash'], $takenAt)));

        foreach ($this->sinks as $sink) {
            $sink->write($checkpoint);
        }

        return $checkpoint;
    }

    /**
     * The chain's own verification, then each checkpoint: signed by a key in the ring, and its entry
     * still in the log, with the hash it had. Entries removed from the end, or changed up to a
     * checkpoint and the chain recomputed, are found.
     *
     * @param iterable<Checkpoint> $checkpoints The realm's, e.g. from a CheckpointSource
     */
    public function verify(Realm $realm, iterable $checkpoints): Verification
    {
        $chain = $this->log->verify($realm);

        if (!$chain->isIntact()) {
            return $chain;
        }

        foreach ($checkpoints as $checkpoint) {
            if (!$checkpoint->realm->equals($realm) || !$checkpoint->isSignedBy($this->keys)) {
                return new Verification($chain->entriesChecked, $checkpoint->sequence, \sprintf('A checkpoint of entry %d isn\'t signed by a key in the ring: the checkpoints were changed, or the key is missing.', $checkpoint->sequence));
            }

            $entry = self::first($this->store->entries($realm, $checkpoint->sequence));

            if (null === $entry || $entry->sequence !== $checkpoint->sequence) {
                return new Verification($chain->entriesChecked, $checkpoint->sequence, \sprintf('Entry %d, checkpointed at %s, isn\'t in the log: entries at the end were removed.', $checkpoint->sequence, $checkpoint->takenAt->format(\DATE_ATOM)));
            }

            if ($entry->hash !== $checkpoint->hash) {
                return new Verification($chain->entriesChecked, $checkpoint->sequence, \sprintf('Entry %d isn\'t what was checkpointed at %s: the log up to it was rewritten.', $checkpoint->sequence, $checkpoint->takenAt->format(\DATE_ATOM)));
            }
        }

        return $chain;
    }

    /**
     * @param iterable<LogEntry> $entries
     */
    private static function first(iterable $entries): ?LogEntry
    {
        foreach ($entries as $entry) {
            return $entry;
        }

        return null;
    }
}
