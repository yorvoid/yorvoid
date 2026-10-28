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
}
