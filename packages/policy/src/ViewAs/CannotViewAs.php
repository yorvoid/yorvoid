<?php

declare(strict_types=1);

namespace YorvoID\Policy\ViewAs;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * A view as this principal can't begin: it isn't active, or it's the viewer.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's view-as uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final class CannotViewAs extends \RuntimeException implements YorvoIDException
{
}
