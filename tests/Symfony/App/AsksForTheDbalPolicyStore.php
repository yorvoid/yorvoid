<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use YorvoID\Policy\Storage\DbalPolicyStore;

/**
 * An application's service that asks for one of YorvoID's stores: the bridge doesn't give it one.
 */
final readonly class AsksForTheDbalPolicyStore
{
    public function __construct(
        public DbalPolicyStore $store,
    ) {
    }
}
