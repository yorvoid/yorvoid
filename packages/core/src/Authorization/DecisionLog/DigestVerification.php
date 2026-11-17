<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog;

/**
 * What verifying a realm's decision digests found (D-42).
 *
 * @api
 */
final readonly class DigestVerification
{
    /**
     * @param int          $windows    The digests checked
     * @param list<string> $problems   Each digest whose records changed or went missing
     * @param int          $pruned     Digests of records since pruned: kept by their digest only
     * @param int          $undigested Records written since the last digest, protected by none yet
     */
    public function __construct(
        public int $windows,
        public array $problems,
        public int $pruned,
        public int $undigested,
    ) {
    }

    public function isIntact(): bool
    {
        return [] === $this->problems;
    }
}
