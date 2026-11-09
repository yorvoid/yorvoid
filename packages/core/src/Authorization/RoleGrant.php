<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * How a role has the ability asked for: by its declared default, granted in the realm, or as a super
 * role, which has every ability that isn't strict.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's explainer uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
enum RoleGrant: string
{
    case Default = 'default';
    case Granted = 'granted';
    case Super = 'super';
}
