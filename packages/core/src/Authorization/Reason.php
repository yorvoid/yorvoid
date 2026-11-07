<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * What one element, or the authority check, gave in a decision.
 *
 * @api
 */
final readonly class Reason
{
    /**
     * @param string $source "authority", or the element's name
     * @param string $effect "permit", "forbid" or "authority"
     */
    public function __construct(
        public string $source,
        public string $effect,
        public Truth $truth,
    ) {
    }
}
