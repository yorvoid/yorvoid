<?php

declare(strict_types=1);

namespace YorvoID\E2e;

use Symfony\Component\HttpFoundation\RequestStack;
use YorvoID\Flows\Event\FlowCompleted;
use YorvoID\IntentVerifier\IntentRefused;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\Passkeys\Flow\IntentConfirmationStep;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Passkeys\Intent\IntentSigning;
use YorvoID\Symfony\Security\CurrentSession;

/**
 * Sends a signed operation to the verifier, as an application's console would, and runs it.
 */
final readonly class SignedOperations
{
    public const string BOOTSTRAP = 'e2e.bootstrap';

    public const string RESULT = 'e2e.signed';

    public function __construct(
        private Verifier $verifier,
        private IntentSigning $signing,
        private CurrentSession $current,
        private RequestStack $requests,
    ) {
    }

    public function __invoke(FlowCompleted $completed): void
    {
        $state = $completed->state;

        if (PasskeyFlows::SIGN_INTENT !== $state->flow) {
            return;
        }

        $nonce = (string) $state->string(IntentConfirmationStep::NONCE);
        $assertion = (string) $state->string(IntentConfirmationStep::ASSERTION);
        $secret = $state->string(self::BOOTSTRAP);

        try {
            if (null !== $secret) {
                $result = \sprintf('Key added: %s', $this->verifier->bootstrap($secret, $nonce, $assertion)->label);
            } else {
                $verified = $this->verifier->verify($nonce, $assertion);
                $session = $this->current->get();
                \assert(null !== $session);
                $this->signing->ran($session, $verified->intent, $verified->key->id(), $completed->origin);
                $result = \sprintf('Ran %s: site=%s', $verified->intent->action, json_encode($verified->intent->parameters['site'] ?? null));
            }
        } catch (IntentRefused $refused) {
            $result = \sprintf('Refused: %s', $refused->refusal->value);
        }

        $this->requests->getSession()->set(self::RESULT, $result);
    }
}
