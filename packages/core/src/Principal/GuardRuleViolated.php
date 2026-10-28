<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * A guard rule refused a change; nothing was changed. The reason is the rule's, for the person who tried.
 *
 * @api
 */
final class GuardRuleViolated extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $reason,
    ) {
        parent::__construct(\sprintf('The operation "%s" was refused: %s', $operation, $reason));
    }
}
