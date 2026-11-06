<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Who asks for an operation: a signed-in session (`YorvoID\Authn\Session\Session`), or the host's
 * {@see Operator}. {@see Operations::run()} decides whether the actor may run it.
 *
 * @api
 */
interface Actor
{
    /** The principal acting; null for the host's operator */
    public ?PrincipalId $principalId { get; }

    /** The realm the actor acts in; null for the host's operator, who acts in every realm */
    public ?Realm $realm { get; }
}
