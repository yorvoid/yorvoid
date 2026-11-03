<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * What SigningPages answers: give the status, the headers and the body to the browser as they are.
 *
 * @api
 */
final readonly class SigningResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }
}
