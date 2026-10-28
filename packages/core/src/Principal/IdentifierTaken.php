<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * Another principal in the realm has this identifier; nothing was changed.
 *
 * Telling someone that an email address is taken tells them that it has an account. Flows that anyone
 * can reach, such as self-registration, answer the same way either way and send the email instead.
 *
 * @api
 */
final class IdentifierTaken extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly IdentifierKind $kind,
    ) {
        parent::__construct(\sprintf('Another principal has this %s.', $kind->value));
    }
}
