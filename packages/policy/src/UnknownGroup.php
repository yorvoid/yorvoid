<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Exception\YorvoIDException;
use YorvoID\Core\Realm;

/**
 * @api
 */
final class UnknownGroup extends \InvalidArgumentException implements YorvoIDException
{
    public function __construct(
        public readonly Realm $realm,
        public readonly string $group,
    ) {
        parent::__construct(\sprintf('There is no group "%s" in the realm "%s".', $group, $realm->name));
    }
}
