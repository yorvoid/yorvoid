<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * An attribute that isn't declared, or a value that isn't of its type.
 *
 * @api
 */
final class UnknownAttribute extends \InvalidArgumentException implements YorvoIDException
{
}
