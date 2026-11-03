<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier\Storage;

use YorvoID\IntentVerifier\VerifierLog;

/**
 * Keeps the log's entries in memory: for tests.
 *
 * @api
 */
final class InMemoryVerifierLog implements VerifierLog
{
    /** @var list<array{event: string, details: array<string, mixed>, at: \DateTimeImmutable}> */
    public array $entries = [];

    public function record(string $event, array $details, \DateTimeImmutable $at): void
    {
        $this->entries[] = ['event' => $event, 'details' => $details, 'at' => $at];
    }

    public function usedNonce(string $nonce, \DateTimeImmutable $since): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry['at'] >= $since && 'prepared' !== $entry['event'] && $nonce === ($entry['details']['nonce'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
