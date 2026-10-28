<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Exception\YorvoIDException;
use YorvoID\Core\Realm;

/**
 * No principal has this ID in this realm.
 *
 * @api
 */
final class UnknownPrincipal extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly Realm $realm,
        public readonly PrincipalId $id,
    ) {
        parent::__construct(\sprintf('There is no principal %s in the realm "%s".', $id, $realm->name));
    }
}
