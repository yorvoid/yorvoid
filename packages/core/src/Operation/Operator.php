<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * The host's operator: whoever runs a console command or a job on the host, or YorvoID itself (e.g.
 * pruning). Someone with the host's root can change the database anyway, so YorvoID lets them run the
 * operations that allow it (AuthorizedBy::Operator), from a console, job or system origin only.
 *
 * @api
 */
final readonly class Operator implements Actor
{
    public ?PrincipalId $principalId;

    public ?Realm $realm;

    public function __construct()
    {
        $this->principalId = null;
        $this->realm = null;
    }
}
