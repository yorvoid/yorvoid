<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use YorvoID\Authn\Session\SessionStore;

/**
 * An application's service that asks for one of YorvoID's stores: the bridge doesn't give it one.
 */
final readonly class AsksForTheSessionStore
{
    public function __construct(
        public SessionStore $store,
    ) {
    }
}
