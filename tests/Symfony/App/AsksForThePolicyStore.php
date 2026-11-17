<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use YorvoID\Policy\PolicyStore;

/**
 * An application's service that asks for one of YorvoID's stores: the bridge doesn't give it one.
 */
final readonly class AsksForThePolicyStore
{
    public function __construct(
        public PolicyStore $store,
    ) {
    }
}
