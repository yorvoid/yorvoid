<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * The verifier's own record of what it prepared, verified, refused, and of every change to its keys
 * (INT-010): the console keeps its side in YorvoID's security log.
 *
 * @api
 */
interface VerifierLog
{
    /**
     * @param string               $event   E.g. "verified"
     * @param array<string, mixed> $details No secrets: actions, nonces, key IDs, reasons
     */
    public function record(string $event, array $details, \DateTimeImmutable $at): void;

    /**
     * Whether an entry recorded at or after $since used the nonce: any entry with it but its "prepared"
     * one. The verifier asks before it takes an intent, so a state restored from an older copy can't have
     * an intent signed twice: the log goes on where the state went back. Entries before $since needn't
     * be read.
     */
    public function usedNonce(string $nonce, \DateTimeImmutable $since): bool;
}
