<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

/**
 * A change to a principal, as guard rules see it.
 *
 * @api
 */
final readonly class PrincipalChange
{
    /**
     * @param string $operation The operation making it, e.g. "yorvoid.principal.suspend"
     */
    public function __construct(
        public string $operation,
        public Principal $before,
        public Principal $after,
    ) {
    }
}
