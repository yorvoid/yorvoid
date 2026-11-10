<?php

declare(strict_types=1);

namespace YorvoID\Core\Log;

/**
 * The result of checking a realm's security log.
 *
 * @api
 */
final readonly class Verification
{
    public function __construct(
        public int $entriesChecked,
        public ?int $brokenAt = null,
        public ?string $problem = null,
        /** Checkpoints signed with a key this OpenSSL can't verify, e.g. Ed25519 under a FIPS provider without EdDSA, covered by an anchor */
        public int $uncheckable = 0,
    ) {
    }

    public function isIntact(): bool
    {
        return null === $this->problem;
    }
}
