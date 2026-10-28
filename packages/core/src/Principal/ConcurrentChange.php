<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * Someone else changed the principal between reading and writing it; nothing was changed. Trying again
 * works on the current state.
 *
 * @api
 */
final class ConcurrentChange extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly Principal $principal,
    ) {
        parent::__construct(\sprintf('The principal %s was changed by someone else meanwhile; try again.', $principal->id));
    }
}
