<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp;

use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Operation;

/**
 * What a sensitive operation needs of the session asking for it (ATN-070, ATN-071): a proof of who they
 * are within a time, optionally a strong one. When it isn't met, it's the remedy: what to prove to go on.
 *
 * A proof from signing in counts for any operation; a proof from a step-up counts only for the operation
 * it was for, so confirming one action doesn't open a window for every other.
 *
 * @api
 */
final readonly class AuthenticationRequirement
{
    /**
     * @param Duration    $within            How recent the proof must be, e.g. 5 minutes
     * @param bool        $multiFactor       Whether the proof must be two factors, e.g. a passkey with user verification (AAL2)
     * @param bool        $phishingResistant Whether the proof must be phishing-resistant, e.g. a passkey
     * @param string|null $operation         The operation it's for, e.g. "yorvoid.credential.remove"; a step-up for it counts
     * @param bool        $heldAfterRecovery Whether the operation waits out the cool-down after an account recovery (ATN-029)
     */
    public function __construct(
        public Duration $within,
        public bool $multiFactor = false,
        public bool $phishingResistant = false,
        public ?string $operation = null,
        public bool $heldAfterRecovery = false,
    ) {
        if (null !== $operation && (\strlen($operation) > 191 || 1 !== preg_match(Operation::NAME, $operation))) {
            throw new IntegratorError(\sprintf('The operation "%s" is invalid.', $operation), 'Use the operation\'s name, e.g. "yorvoid.credential.remove": at most 191 characters, in at least three dot-separated lowercase segments.', 'concepts/step-up.md#requirements');
        }
    }

    /**
     * Whether one of the context's proofs, at that moment, is recent and strong enough, and counts for the
     * operation.
     */
    public function isMetBy(AuthenticationContext $context, \DateTimeImmutable $now): bool
    {
        foreach ($context->factors as $factor) {
            if ($this->isMetByFactor($factor, $now)) {
                return true;
            }
        }

        return false;
    }

    private function isMetByFactor(AuthenticationFactor $factor, \DateTimeImmutable $now): bool
    {
        return (null === $factor->operation || $factor->operation === $this->operation)
            && $now < $this->within->after($factor->at)
            && ($factor->multiFactor || !$this->multiFactor)
            && ($factor->phishingResistant || !$this->phishingResistant);
    }
}
