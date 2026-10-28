<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * Generates the signed-in principal's recovery codes, replacing any they had (ATN-025).
 *
 * @api
 */
#[Operation('yorvoid.recovery_codes.generate', 'Generate new recovery codes, replacing the old ones')]
final readonly class GenerateRecoveryCodes
{
    public function __construct(
        public Realm $realm,
    ) {
    }
}
