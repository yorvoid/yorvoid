<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

/**
 * @api
 *
 * @experimental
 */
enum DelegationStatus: string
{
    case Active = 'active';

    case Revoked = 'revoked';
}
