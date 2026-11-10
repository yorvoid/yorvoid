<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\AuditAlarm;
use YorvoID\Core\Log\Event\AuditFailed;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Verdict;

/**
 * Records decisions apart from the security log (AUZ-040, ADR-09):
 *
 * - **An operation's decision** is written at once, before the operation runs, in a statement of its
 *   own: the security-log entries of what it permits cite it, and a change rolled back still has it.
 *   If it can't be written, the operation doesn't run.
 * - **An ability's** refusal or Indeterminate is recorded, and its permit as configured. They're kept
 *   for the request and written together: at its end ({@see flush()}), and whenever 100 are waiting.
 *   That's best effort: a process that dies before then loses them, and a write that fails raises the
 *   audit alert, not an error.
 * - **In the Refuse mode** ({@see AuditFailure}), every decision that's recorded is written before it's
 *   returned, and one that would permit without its record is Indeterminate (audit_unavailable).
 *
 * @api
 */
final class DecisionLog
{
    public const int BUFFER = 100;

    /** @var list<DecisionRecord> */
    private array $buffer = [];

    /** @var array<string, true> */
    private array $alwaysRecord;

    private readonly AuditAlarm $alarm;

    /**
     * @param float        $sampleRate   With PermitRecording::Sampled, the share of requests whose permits are recorded, from 0 to 1
     * @param list<string> $alwaysRecord Abilities whose permits are recorded whatever the configuration, e.g. explaining someone else's access
     * @param AuditFailure $onFailure    What a decision whose record can't be written does (AUD-006)
     *
     * @throws IntegratorError When the rate isn't between 0 and 1
     */
    public function __construct(
        private readonly DecisionLogStore $store,
        private readonly PermitRecording $permits = PermitRecording::None,
        private readonly float $sampleRate = 0.01,
        array $alwaysRecord = [],
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly AuditFailure $onFailure = AuditFailure::Continue,
        EventDispatcherInterface $events = new NoEventListeners(),
    ) {
        if ($sampleRate < 0.0 || $sampleRate > 1.0) {
            throw new IntegratorError(\sprintf('The decision log\'s sample rate is %s.', $sampleRate), 'Give a share between 0 and 1, e.g. 0.01 for one request in a hundred.', 'concepts/security-log.md#the-decision-log');
        }

        $this->alwaysRecord = array_fill_keys($alwaysRecord, true);
        $this->alarm = new AuditAlarm($events, $logger);
    }

    public function onFailure(): AuditFailure
    {
        return $this->onFailure;
    }

    /**
     * Records the decision if it's to be recorded.
     *
     * @return Decision The decision, with its record's hash when it's recorded; in the Refuse mode, an
     *                  Indeterminate instead of a Permit whose record couldn't be written
     *
     * @throws \Throwable In the Continue mode, when an operation's decision can't be written
     */
    public function record(Request $request, Decision $decision): Decision
    {
        return $this->recordAll([[$request, $decision]])[0];
    }

    /**
     * Records the decisions that are to be recorded: in the Refuse mode, those to be written now in one
     * write.
     *
     * @param list<array{Request, Decision}> $decided
     *
     * @return list<Decision> The decisions, in the same order, as record() returns them
     */
    public function recordAll(array $decided): array
    {
        /** @var array<int, DecisionRecord> $records The records of those to be recorded, by their place */
        $records = [];
        $now = [];

        foreach ($decided as $i => [$request, $decision]) {
            $operation = null !== $request->operation;

            if (!$operation && !$this->records($request, $decision)) {
                continue;
            }

            $records[$i] = DecisionRecord::of($request, $decision);

            if ($operation || AuditFailure::Refuse === $this->onFailure) {
                $now[] = $records[$i];
            } else {
                $this->buffer[] = $records[$i];
            }
        }

        $lost = false;

        if ([] !== $now) {
            try {
                $this->store->add($now);
            } catch (\Throwable $e) {
                $this->alarm->raise(new AuditFailed(AuditFailed::DECISION_LOG, $now[0]->realm, \count($now), true, $e));

                if (AuditFailure::Continue === $this->onFailure) {
                    throw $e;
                }

                // Refusing, all of them were to be written now.
                $lost = true;
            }
        }

        if (\count($this->buffer) >= self::BUFFER) {
            $this->flush();
        }

        return array_map(static function (int $i, array $one) use ($records, $lost): Decision {
            $record = $records[$i] ?? null;

            return match (true) {
                null === $record => $one[1],
                $lost => self::unrecorded($one[1]),
                default => $one[1]->recorded($record->hash()),
            };
        }, array_keys($decided), $decided);
    }

    /**
     * Writes what's waiting: at the end of every request, command and job.
     */
    public function flush(): void
    {
        if ([] === $this->buffer) {
            return;
        }

        $records = $this->buffer;
        $this->buffer = [];

        try {
            $this->store->add($records);
        } catch (\Throwable $e) {
            $this->alarm->raise(new AuditFailed(AuditFailed::DECISION_LOG, $records[0]->realm, \count($records), false, $e));
        }
    }

    /**
     * Removes the records older than that, in every realm: the security-log entries that cite them keep
     * their hashes, and reconstructing such a decision reports it as pruned (AUD-007).
     *
     * @return int How many were removed
     */
    public function purge(Duration $keep): int
    {
        return $this->store->prune(new \DateTimeImmutable('@'.($this->clock->now()->getTimestamp() - $keep->seconds)));
    }

    /**
     * Between requests of a long-running process: writes what's waiting.
     */
    public function reset(): void
    {
        $this->flush();
    }

    /**
     * A decision whose record couldn't be written, in the Refuse mode: it can't permit.
     */
    private static function unrecorded(Decision $decision): Decision
    {
        $permits = match ($decision->verdict) {
            Verdict::Permit, Verdict::PermitOffline => true,
            Verdict::Deny, Verdict::Indeterminate => false,
        };

        if (!$permits) {
            return $decision;
        }

        // A recorded decision has its ID and its time (DecisionRecord::of()).
        \assert(null !== $decision->id && null !== $decision->at);

        return Decision::indeterminate([IndeterminateReason::AuditUnavailable], 'its record couldn\'t be written', $decision->reasons, $decision->precedence)->identified($decision->id, $decision->at, $decision->versions);
    }

    private function records(Request $request, Decision $decision): bool
    {
        // Only a Permit is volume: anything else, Permit-offline too, is always recorded.
        $permitted = match ($decision->verdict) {
            Verdict::Permit => true,
            Verdict::PermitOffline, Verdict::Deny, Verdict::Indeterminate => false,
        };

        if (!$permitted) {
            return true;
        }

        if (isset($this->alwaysRecord[$request->name()])) {
            return true;
        }

        return match ($this->permits) {
            PermitRecording::All => true,
            PermitRecording::None => false,
            // The same for every decision of a request.
            PermitRecording::Sampled => hexdec(substr(hash('sha256', $request->origin->correlationId), 0, 8)) < $this->sampleRate * 0x100000000,
        };
    }
}
