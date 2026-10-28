<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Exception\YorvoIDException;
use YorvoID\Core\Principal\Principal;

/**
 * The principal's status doesn't allow it: only an active principal can have a session, and a suspended
 * or deleted one can't be sent a link.
 *
 * @api
 */
final class InactivePrincipal extends \RuntimeException implements YorvoIDException
{
    /**
     * @param string $refused What it can't do, e.g. "have a session"
     */
    public function __construct(
        public readonly Principal $principal,
        string $refused = 'have a session',
    ) {
        parent::__construct(\sprintf('The principal %s is %s, and can\'t %s.', $principal->id, $principal->status->value, $refused));
    }
}
