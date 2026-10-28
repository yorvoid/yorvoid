<?php

declare(strict_types=1);

namespace YorvoID\Authn\Invitation;

use YorvoID\Core\Exception\YorvoIDException;
use YorvoID\Core\Principal\Principal;

/**
 * The principal isn't invited: it accepted already, or never was.
 *
 * @api
 */
final class NotInvited extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly Principal $principal,
    ) {
        parent::__construct(\sprintf('The principal %s is %s, not invited.', $principal->id, $principal->status->value));
    }
}
