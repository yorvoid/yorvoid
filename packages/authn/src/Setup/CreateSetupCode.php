<?php

declare(strict_types=1);

namespace YorvoID\Authn\Setup;

use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * Creates the code that claims a fresh realm, replacing an earlier one. Run by whoever installs the
 * application, e.g. on the server's command line.
 *
 * @implements Command<\YorvoID\Core\Secret>
 *
 * @api
 */
#[Operation('yorvoid.setup.create_code', 'Create the code that claims a fresh installation', AuthorizedBy::Operator)]
final readonly class CreateSetupCode implements Command
{
    /**
     * @param Duration $lifetime How long it works: at most 7 days
     */
    public function __construct(
        public Realm $realm,
        public Duration $lifetime,
    ) {
        if ($lifetime->isLongerThan(Duration::days(7))) {
            throw new IntegratorError(\sprintf('A setup code for %s works too long.', $lifetime), 'Give it at most 7 days: it claims the installation for whoever has it. 24 hours is usual.', 'concepts/setup-codes.md');
        }
    }
}
