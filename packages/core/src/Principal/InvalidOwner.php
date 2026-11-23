<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * An agent's owner isn't one (AGT-001): an agent has an owner, an active principal of its realm that
 * isn't an agent itself; and only an agent has one.
 *
 * @api
 */
final class InvalidOwner extends \RuntimeException implements YorvoIDException
{
    public function __construct(string $why)
    {
        parent::__construct($why);
    }
}
