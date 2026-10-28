<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * The verifier refused: nothing ran, and the nonce, if any, is used up.
 *
 * @api
 */
final class IntentRefused extends \RuntimeException
{
    public function __construct(
        public readonly Refusal $refusal,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('The verifier refused the intent: %s.', str_replace('_', ' ', $refusal->value)), previous: $previous);
    }
}
