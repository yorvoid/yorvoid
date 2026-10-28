<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * A principal's status can't change that way: a deleted principal can't come back, an invited one can't
 * be suspended, and nothing changes to the status it already has.
 *
 * @api
 */
final class InvalidStatusChange extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly Principal $principal,
        public readonly PrincipalStatus $to,
    ) {
        parent::__construct(\sprintf('The principal %s is %s and can\'t become %s.', $principal->id, $principal->status->value, $to->value));
    }
}
