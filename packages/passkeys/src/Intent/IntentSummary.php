<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Intent;

/**
 * What the confirming page shows before someone signs an intent (INT-011): the operation in words, and
 * every parameter, in full.
 *
 * @api
 */
final readonly class IntentSummary
{
    /**
     * @param string                $text       E.g. "Remove the site jane"
     * @param array<string, string> $parameters Name => value as text, every one of the intent's
     */
    public function __construct(
        public string $text,
        public array $parameters,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
