<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Someone not signed in, e.g. on a sign-in page or following an invitation link: they run only the
 * operations that are authentication, whose handlers check the secret they bring.
 *
 * @api
 */
final readonly class Anonymous implements Actor
{
    public ?PrincipalId $principalId;

    public ?Realm $realm;

    public function __construct()
    {
        $this->principalId = null;
        $this->realm = null;
    }
}
