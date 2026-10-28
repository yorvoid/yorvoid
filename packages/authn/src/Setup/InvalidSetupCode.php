<?php

declare(strict_types=1);

namespace YorvoID\Authn\Setup;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * The setup code doesn't work: it's wrong, expired, replaced, or the realm was claimed. Which one isn't
 * said, to whoever is guessing.
 *
 * @api
 */
final class InvalidSetupCode extends \RuntimeException implements YorvoIDException
{
    public function __construct()
    {
        parent::__construct('The setup code doesn\'t work. Ask whoever installed the application for a new one.');
    }
}
