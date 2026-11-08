<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
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
 *   That's best effort: a process that dies before then loses them, and a write that fails is reported
 *   to the logger, not to the decision.
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

    /**
     * @param float        $sampleRate   With PermitRecording::Sampled, the share of requests whose permits are recorded, from 0 to 1
     * @param list<string> $alwaysRecord Abilities whose permits are recorded whatever the configuration, e.g. explaining someone else's access
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
    ) {
        if ($sampleRate < 0.0 || $sampleRate > 1.0) {
            throw new IntegratorError(\sprintf('The decision log\'s sample rate is %s.', $sampleRate), 'Give a share between 0 and 1, e.g. 0.01 for one request in a hundred.', 'concepts/security-log.md#the-decision-log');
        }

        $this->alwaysRecord = array_fill_keys($alwaysRecord, true);
    }

    /**
     * Records the decision if it's to be recorded.
     *
     * @return Decision The decision, with its record's hash when it's recorded
     */
    public function record(Request $request, Decision $decision): Decision
    {
        $operation = null !== $request->operation;

        if (!$operation && !$this->records($request, $decision)) {
            return $decision;
        }

        $record = DecisionRecord::of($request, $decision);

        if ($operation) {
            $this->store->add([$record]);
        } else {
            $this->buffer[] = $record;

            if (\count($this->buffer) >= self::BUFFER) {
                $this->flush();
            }
        }

        return $decision->recorded($record->hash());
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
            $this->logger->error('YorvoID couldn\'t record {count} decisions: {message}', ['count' => \count($records), 'message' => $e->getMessage(), 'exception' => $e]);
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
