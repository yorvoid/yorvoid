<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * Relations that go deeper than they're followed (D-08): what depends on them can't be told, and a
 * decision is never a Permit for it.
 *
 * @api
 */
final class TooDeep extends \RuntimeException implements YorvoIDException
{
    public function __construct(string $what)
    {
        parent::__construct(\sprintf('%s go deeper than the %d levels relations are followed.', $what, Relations::MAX_DEPTH));
    }
}
