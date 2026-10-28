<?php

declare(strict_types=1);

namespace YorvoID\Authn\Setup;

use YorvoID\Core\Duration;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * Creates the code that claims a fresh realm, replacing an earlier one. Run by whoever installs the
 * application, e.g. on the server's command line.
 *
 * @api
 */
#[Operation('yorvoid.setup.create_code', 'Create the code that claims a fresh installation')]
final readonly class CreateSetupCode
{
    public function __construct(
        public Realm $realm,
        public Duration $lifetime,
    ) {
    }
}
