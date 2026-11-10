<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

use Psr\Clock\ClockInterface;

/**
 * The verifier's log, raising the alert when an entry can't be written (AUD-006, AU-5): the owners get
 * an "audit_failed" notice, and the failure goes on to refuse what the entry was for, as before. A
 * notifier that fails too doesn't hide the log's failure.
 *
 * @internal
 */
final readonly class AlertingVerifierLog implements VerifierLog
{
    public function __construct(
        private VerifierLog $log,
        private VerifierNotifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    public function record(string $event, array $details, \DateTimeImmutable $at): void
    {
        try {
            $this->log->record($event, $details, $at);
        } catch (\Throwable $e) {
            try {
                $this->notifier->notify(new VerifierNotice(VerifierNotice::AUDIT_FAILED, null, null, $this->clock->now(), error: \sprintf('The "%s" entry couldn\'t be written: %s', $event, $e->getMessage())));
            } catch (\Throwable) {
                // The log's failure is the one to report.
            }

            throw $e;
        }
    }

    public function usedNonce(string $nonce, \DateTimeImmutable $since): bool
    {
        return $this->log->usedNonce($nonce, $since);
    }
}
