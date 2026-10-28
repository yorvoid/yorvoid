<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * What someone entered isn't a valid identifier of that kind. The message says what a valid one looks
 * like, for the person who entered it; it doesn't repeat what they entered.
 *
 * @api
 */
final class InvalidIdentifier extends \InvalidArgumentException implements YorvoIDException
{
    public function __construct(
        public readonly IdentifierKind $kind,
        string $message,
    ) {
        parent::__construct($message);
    }
}
