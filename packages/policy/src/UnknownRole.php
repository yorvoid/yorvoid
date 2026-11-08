<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Exception\YorvoIDException;
use YorvoID\Core\Realm;

/**
 * @api
 */
final class UnknownRole extends \InvalidArgumentException implements YorvoIDException
{
    public function __construct(
        public readonly Realm $realm,
        public readonly string $role,
    ) {
        parent::__construct(\sprintf('There is no role "%s" in the realm "%s".', $role, $realm->name));
    }
}
