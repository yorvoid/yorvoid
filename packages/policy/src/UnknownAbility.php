<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * @api
 */
final class UnknownAbility extends \InvalidArgumentException implements YorvoIDException
{
    public function __construct(
        public readonly string $ability,
    ) {
        parent::__construct(\sprintf('There is no ability "%s".', $ability));
    }
}
