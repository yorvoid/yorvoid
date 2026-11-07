<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * The actor may not run the operation: the decision was Deny or Indeterminate, and nothing ran. The
 * denial is in the security log.
 *
 * @api
 */
final class NotPermitted extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly string $operation,
        public readonly Decision $decision,
    ) {
        parent::__construct(\sprintf('Not permitted to run "%s".', $operation));
    }
}
