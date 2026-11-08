<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * A role that can't be made or changed as asked: its name or label isn't valid, or it's declared.
 *
 * @api
 */
final class InvalidRole extends \InvalidArgumentException implements YorvoIDException
{
    public function __construct(
        public readonly string $role,
        string $message,
    ) {
        parent::__construct($message);
    }
}
