<?php

declare(strict_types=1);

// An application's class that claims YorvoID's namespace is still the application's.

namespace YorvoID\Policy;

use YorvoID\Core\Realm;

final readonly class NotReallyYorvoIDs
{
    public function __construct(
        private PolicyStore $policy,
    ) {
    }

    public function handle(Realm $realm): int
    {
        return $this->policy->version($realm);
    }
}
