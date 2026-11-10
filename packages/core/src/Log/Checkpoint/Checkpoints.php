<?php

declare(strict_types=1);

namespace YorvoID\Core\Log\Checkpoint;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Crypto\CryptoPolicy;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Key\KeyAlgorithm;
use YorvoID\Core\Key\KeyRings;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Log\AuditAlarm;
use YorvoID\Core\Log\Event\AuditFailed;
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
 * An anchor is a checkpoint signed only once the chain and every earlier checkpoint verify: moving from
 * Ed25519 to ES256 keys anchors each realm, so that under a FIPS provider without EdDSA, where the
 * Ed25519 checkpoints can't be checked, the anchor covers what they did. It certifies the tail after the
 * last Ed25519 checkpoint just as any new checkpoint does. A checkpoint that can't be signed raises the
 * audit alert (AUD-006): a missing checkpoint is a lost audit record.
 *
 * @api
 */
final readonly class Checkpoints
{
    private AuditAlarm $alarm;

    /**
     * @param list<CheckpointSink> $sinks  Every one gets every checkpoint
     * @param CryptoPolicy|null    $crypto Which keys this OpenSSL can verify; the standard policy's if not given
     */
    public function __construct(
        private SecurityLogStore $store,
        private SecurityLog $log,
        private KeyRings $keys,
        private array $sinks,
        private ClockInterface $clock = new NativeClock(),
        private ?CryptoPolicy $crypto = null,
        EventDispatcherInterface $events = new NoEventListeners(),
        LoggerInterface $logger = new NullLogger(),
    ) {
        $this->alarm = new AuditAlarm($events, $logger);
    }

    /**
     * Signs where the realm's log stands, and gives it to every sink.
     *
     * @return Checkpoint|null Null while the realm's log is empty
     */
    public function take(Realm $realm): ?Checkpoint
    {
        return $this->sign($realm, false);
    }

    /**
     * Verifies the realm's log against all its checkpoints, every one of which this OpenSSL must be able
     * to check, and only then signs an anchor of where it stands.
     *
     * @param iterable<Checkpoint> $checkpoints The realm's, e.g. from a CheckpointSource
     *
     * @return Checkpoint|null Null while the realm's log is empty
     *
     * @throws IntegratorError When the log doesn't verify, or a checkpoint can't be checked here
     */
    public function anchor(Realm $realm, iterable $checkpoints): ?Checkpoint
    {
        $verification = $this->verify($realm, $checkpoints);

        if ($verification->uncheckable > 0) {
            throw new IntegratorError(\sprintf('The realm "%s" can\'t be anchored: %d of its checkpoints are signed with keys this OpenSSL can\'t verify.', $realm->name, $verification->uncheckable), 'Anchor in the standard crypto mode, where every checkpoint can be checked.', 'concepts/keys.md#moving-to-es256');
        }

        if (!$verification->isIntact()) {
            throw new IntegratorError(\sprintf('The realm "%s" can\'t be anchored: %s', $realm->name, $verification->problem), 'Find out why the log doesn\'t verify before anchoring it: an anchor would certify it as it is.', 'concepts/keys.md#moving-to-es256');
        }

        return $this->sign($realm, true);
    }

    /**
     * The chain's own verification, then each checkpoint: signed by a key in the realm's ring, and its entry
     * still in the log, with the hash it had. Entries removed from the end, or changed up to a
     * checkpoint and the chain recomputed, are found.
     *
     * A checkpoint signed with a key this OpenSSL can't verify isn't checked, and counts only if an anchor
     * at or after it covers it.
     *
     * @param iterable<Checkpoint> $checkpoints The realm's, e.g. from a CheckpointSource
     */
    public function verify(Realm $realm, iterable $checkpoints): Verification
    {
        $chain = $this->log->verify($realm);

        if (!$chain->isIntact()) {
            return $chain;
        }

        $keys = $this->keys->forRealm($realm);
        $uncheckable = 0;
        $uncovered = null;
        foreach ($checkpoints as $checkpoint) {
            $key = $checkpoint->realm->equals($realm) ? $keys->get($checkpoint->keyId) : null;

            if (null !== $key && !$this->canVerify($key)) {
                ++$uncheckable;
                $uncovered = max($uncovered ?? $checkpoint->sequence, $checkpoint->sequence);

                continue;
            }

            if (null === $key || !$checkpoint->isSignedBy($keys)) {
                return new Verification($chain->entriesChecked, $checkpoint->sequence, \sprintf('A checkpoint of entry %d isn\'t signed by a key in the ring: the checkpoints were changed, or the key is missing.', $checkpoint->sequence));
            }

            $entry = self::first($this->store->entries($realm, $checkpoint->sequence));

            if (null === $entry || $entry->sequence !== $checkpoint->sequence) {
                return new Verification($chain->entriesChecked, $checkpoint->sequence, \sprintf('Entry %d, checkpointed at %s, isn\'t in the log: entries at the end were removed.', $checkpoint->sequence, $checkpoint->takenAt->format(\DATE_ATOM)));
            }

            if ($entry->hash !== $checkpoint->hash) {
                return new Verification($chain->entriesChecked, $checkpoint->sequence, \sprintf('Entry %d isn\'t what was checkpointed at %s: the log up to it was rewritten.', $checkpoint->sequence, $checkpoint->takenAt->format(\DATE_ATOM)));
            }

            if ($checkpoint->anchor && null !== $uncovered && $checkpoint->sequence >= $uncovered) {
                $uncovered = null;
            }
        }

        // An uncheckable checkpoint after the last anchor, the newest one included, is uncovered.
        if (null !== $uncovered) {
            return new Verification($chain->entriesChecked, $uncovered, \sprintf('Checkpoints up to entry %d are signed with keys this OpenSSL can\'t verify, and no anchor after them covers them: verify in the standard crypto mode, or anchor the realm there (yorvoid:log:anchor --realm %s).', $uncovered, $realm->name), $uncheckable);
        }

        return new Verification($chain->entriesChecked, uncheckable: $uncheckable);
    }

    /**
     * The newest of the realm's checkpoints signed with Ed25519 that no anchor after it covers: what moving
     * to the FIPS mode without EdDSA would fail on. Null if there's none.
     *
     * @param iterable<Checkpoint> $checkpoints The realm's
     */
    public function unanchored(Realm $realm, iterable $checkpoints): ?int
    {
        $keys = $this->keys->forRealm($realm);
        $uncovered = null;

        foreach ($checkpoints as $checkpoint) {
            $algorithm = $keys->get($checkpoint->keyId)?->algorithm;

            if (KeyAlgorithm::EdDSA === $algorithm) {
                $uncovered = max($uncovered ?? $checkpoint->sequence, $checkpoint->sequence);
            } elseif (null !== $algorithm && $checkpoint->anchor && null !== $uncovered && $checkpoint->sequence >= $uncovered) {
                $uncovered = null;
            }
        }

        return $uncovered;
    }

    private function canVerify(SigningKey $key): bool
    {
        return match ($key->algorithm) {
            KeyAlgorithm::ES256 => true,
            KeyAlgorithm::EdDSA => ($this->crypto ?? CryptoPolicy::standard())->allowsEdDsa(),
        };
    }

    private function sign(Realm $realm, bool $anchor): ?Checkpoint
    {
        $head = $this->store->head($realm);

        if (null === $head) {
            return null;
        }

        $takenAt = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());

        try {
            $signer = $this->keys->forRealm($realm)->signer();
            $checkpoint = new Checkpoint($realm, $head['sequence'], $head['hash'], $takenAt, $signer->id, $signer->sign(Checkpoint::message($realm, $head['sequence'], $head['hash'], $takenAt, $anchor)), $anchor);

            foreach ($this->sinks as $sink) {
                $sink->write($checkpoint);
            }
        } catch (\Throwable $e) {
            $this->alarm->raise(new AuditFailed(AuditFailed::CHECKPOINTS, $realm, 1, false, $e));

            throw $e;
        }

        return $checkpoint;
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
