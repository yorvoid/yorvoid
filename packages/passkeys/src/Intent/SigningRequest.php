<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Intent;

/**
 * What the confirming page needs: the summary to show, and the options for the browser.
 *
 * @api
 */
final readonly class SigningRequest
{
    /**
     * @param string $options The verifier's WebAuthn request options, for navigator.credentials.get()
     */
    public function __construct(
        public string $nonce,
        public IntentSummary $summary,
        public string $options,
    ) {
    }
}
