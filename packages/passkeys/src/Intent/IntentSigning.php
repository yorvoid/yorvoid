<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Intent;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Session\Session;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Storage\Transactions;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\PreparedIntent;
use YorvoID\IntentVerifier\Refusal;
use YorvoID\Passkeys\Intent\Event\SignedOperationRan;
use YorvoID\Throttle\Limit;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;

/**
 * The console's side of signed intents (INT-010…012): asking a signed-in person to sign an intent the
 * verifier prepared, and recording what became of it. The signature itself is checked by the verifier,
 * not here.
 *
 * - Each request is limited per principal (20 an hour by default; T-INT-3), recorded, and comes with the
 *   summary the page shows.
 * - Each result the verifier sends back is recorded; an operation that ran is announced with
 *   SignedOperationRan, for the notification through a channel the console doesn't control.
 *
 * @api
 */
final readonly class IntentSigning
{
    private Limit $limit;

    /**
     * @param Limit|null $limit How many signatures a principal can be asked for: 20 an hour if not given
     */
    public function __construct(
        private IntentSummaries $summaries,
        private SecurityLog $log,
        private Transactions $transactions,
        private Throttle $throttle,
        ?Limit $limit = null,
        private EventDispatcherInterface $events = new NoEventListeners(),
        private ClockInterface $clock = new NativeClock(),
    ) {
        $this->limit = $limit ?? new Limit(20, Duration::hours(1));
    }

    /**
     * Asks the session's principal to sign an intent the verifier prepared. Ask only in response to the
     * person's own action (T-INT-3).
     *
     * @param string $locale The person's language, for the summary
     *
     * @throws Throttled       When the principal was asked too often
     * @throws IntegratorError When the operation has no summary, or a parameter is too long to show
     */
    public function request(Session $session, PreparedIntent $prepared, string $locale, Origin $origin): SigningRequest
    {
        $summary = $this->summaries->summarize($prepared->intent, $locale);
        $this->throttle->hit(\sprintf('intent_request:%s:%s', $session->realm->name, $session->principalId), $this->limit);
        $this->record($session, 'yorvoid.intent.request', Outcome::Success, $prepared->intent, $origin);

        return new SigningRequest($prepared->intent->nonce, $summary, $prepared->options);
    }

    /**
     * Records that the verifier ran the operation the session's principal signed, and announces it.
     *
     * @param string $key The verifier's ID of the key that signed, VerifiedIntent::$key->id()
     */
    public function ran(Session $session, Intent $intent, string $key, Origin $origin): void
    {
        $this->transactions->run(function () use ($session, $intent, $key, $origin): void {
            $this->record($session, 'yorvoid.intent.run', Outcome::Success, $intent, $origin, ['key' => $key]);
            $at = $this->clock->now();

            $this->transactions->afterCommit(function () use ($session, $intent, $key, $at, $origin): void {
                $this->events->dispatch(new SignedOperationRan($session->realm, $session->principalId, $intent, $key, $at, $origin));
            });
        });
    }

    /**
     * Records that the verifier refused the signature.
     */
    public function refused(Session $session, Intent $intent, Refusal $refusal, Origin $origin): void
    {
        $this->record($session, 'yorvoid.intent.run', Outcome::Failed, $intent, $origin, ['reason' => $refusal->value]);
    }

    /**
     * @param array<string, string> $details
     */
    private function record(Session $session, string $action, Outcome $outcome, Intent $intent, Origin $origin, array $details = []): void
    {
        $this->log->record($session->realm, $action, $outcome, $origin, $session->principalId, 'intent', $intent->nonce, [
            'operation' => $intent->action,
            'principal' => (string) $session->principalId,
            ...$details,
        ]);
    }
}
