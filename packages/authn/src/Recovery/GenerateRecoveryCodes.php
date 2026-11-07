<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Realm;

/**
 * Generates the signed-in principal's recovery codes, replacing any they had (ATN-025).
 *
 * @implements SelfOperation<list<\YorvoID\Core\Secret>>
 *
 * @api
 */
#[Operation('yorvoid.recovery_codes.generate', 'Generate new recovery codes, replacing the old ones', AuthorizedBy::Self, causes: ['yorvoid.credential.add'])]
final readonly class GenerateRecoveryCodes implements SelfOperation
{
    public function __construct(
        public Realm $realm,
    ) {
    }

    public function concerns(Actor $actor): bool
    {
        return null !== $actor->principalId;
    }
}
